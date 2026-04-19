<?php
/**
 * 注文アイテムのサーバー側再検証 (P0 #1 + #2 — 改ざん防止)
 *
 * 目的:
 *   ブラウザから送られてくる items[].price / items[].name / items[].qty を
 *   そのまま信用せず、サーバー側で `resolve_store_menu()` (もしくは plan_menu_items) と
 *   突き合わせて正規価格・存在確認・数量妥当性を強制する。
 *
 * 攻撃シナリオ:
 *   DevTools で items[].price = 1 / items[].qty = 9999 を送信 →
 *   従来は orders.items / order_items に書き込まれ、Stripe の amount にもそのまま渡っていた。
 *
 * 仕様:
 *   - 不明な item_id  → INVALID_ITEM (404)
 *   - qty が 1〜100 の整数でない → INVALID_QUANTITY (400)
 *   - 売り切れ品目 → SOLD_OUT (409)
 *   - 価格不一致は **サーバー側の正規価格で上書き** (UX 維持)
 *   - オプション (option_choices) も同様に検証
 *   - 食べ放題プラン中 ($isPlanSession=true かつ $planId 指定) は
 *     plan_menu_items に登録された品目のみ受け付け、価格は常に 0 とする
 *
 * 戻り値: [
 *   'items'        => 検証・正規化済みの items 配列 (DB 保存用),
 *   'total_amount' => サーバー再計算済みの合計金額 (整数, 円)
 * ]
 *
 * 失敗時: json_error() で即終了 (関数は戻らない)
 */

require_once __DIR__ . '/menu-resolver.php';
require_once __DIR__ . '/response.php';

/**
 * @param PDO         $pdo
 * @param string      $storeId
 * @param array       $items           クライアントから受領した items 配列
 * @param bool        $isPlanSession   食べ放題プラン中なら true
 * @param string|null $planId          プラン中の場合は time_limit_plans.id
 * @return array{items: array, total_amount: int}
 */
function validate_and_recompute_items(PDO $pdo, string $storeId, array $items, bool $isPlanSession = false, ?string $planId = null): array
{
    if (empty($items) || !is_array($items)) {
        json_error('NO_ITEMS', '注文に品目がありません', 400);
    }

    // ── 1. 正規メニューを構築 (menuItemId => normalized item) ──
    $menuMap = [];

    if ($isPlanSession && $planId) {
        // プランモード: plan_menu_items だけが正規メニュー (全品 ¥0)
        try {
            $stmt = $pdo->prepare(
                'SELECT pmi.id, pmi.name, pmi.category_id
                 FROM plan_menu_items pmi
                 JOIN time_limit_plans tlp ON tlp.id = pmi.plan_id
                 WHERE pmi.plan_id = ? AND pmi.is_active = 1 AND tlp.store_id = ?'
            );
            $stmt->execute([$planId, $storeId]);
            while ($row = $stmt->fetch()) {
                $menuMap[$row['id']] = [
                    'menuItemId'   => $row['id'],
                    'name'         => $row['name'],
                    'price'        => 0,
                    'soldOut'      => false,
                    'optionGroups' => [],
                    'source'       => 'plan',
                ];
            }
        } catch (PDOException $e) {
            error_log('[order-validator] load_plan_menu_items: ' . $e->getMessage());
        }
    } else {
        // 通常モード: resolve_store_menu の戻り値をフラット化
        $resolved = resolve_store_menu($pdo, $storeId);
        foreach ($resolved as $cat) {
            foreach ($cat['items'] as $mi) {
                $menuMap[$mi['menuItemId']] = $mi;
            }
        }
    }

    // ── 2. items を 1 件ずつ検証・正規化 ──
    $validatedItems = [];
    $totalAmount = 0;
    $soldOutNames = [];

    foreach ($items as $idx => $clientItem) {
        $itemId = isset($clientItem['id']) ? (string)$clientItem['id'] : '';
        $rawQty = $clientItem['qty'] ?? 1;

        // 数量検証: 1〜100 の整数のみ許可 (小数・負数・文字列・0 を弾く)
        if (!is_numeric($rawQty)) {
            json_error('INVALID_QUANTITY', '数量が不正です', 400);
        }
        $qtyFloat = (float)$rawQty;
        $qty = (int)$rawQty;
        if ($qty < 1 || $qty > 100 || $qtyFloat !== (float)$qty) {
            json_error('INVALID_QUANTITY', '数量は1〜100の整数で指定してください', 400);
        }

        // メニュー存在確認
        if ($itemId === '' || !isset($menuMap[$itemId])) {
            json_error('INVALID_ITEM', '存在しないメニュー品目が含まれています', 404);
        }

        $menuItem = $menuMap[$itemId];

        // 売り切れ
        if (!empty($menuItem['soldOut'])) {
            $soldOutNames[] = $menuItem['name'];
            continue;
        }

        // 正規価格 (プラン中は常に 0)
        $unitBasePrice = $isPlanSession ? 0 : (int)($menuItem['price'] ?? 0);

        // ── オプション検証 ──
        $optionPriceDiff = 0;
        $validatedOptions = null;
        if (!empty($clientItem['options']) && is_array($clientItem['options'])) {
            // option_choices を choiceId キーで引けるマップに展開
            $choiceMap = [];
            if (!empty($menuItem['optionGroups'])) {
                foreach ($menuItem['optionGroups'] as $og) {
                    if (empty($og['choices'])) continue;
                    foreach ($og['choices'] as $ch) {
                        $choiceMap[$ch['choiceId']] = [
                            'choiceId'  => $ch['choiceId'],
                            'name'      => $ch['name'],
                            'priceDiff' => (int)($ch['priceDiff'] ?? 0),
                            'groupId'   => $og['groupId'],
                            'groupName' => $og['groupName'],
                        ];
                    }
                }
            }

            $validatedOptions = [];
            foreach ($clientItem['options'] as $opt) {
                $choiceId = isset($opt['choiceId']) ? (string)$opt['choiceId'] : '';
                if ($choiceId === '' || !isset($choiceMap[$choiceId])) {
                    json_error('INVALID_OPTION', '存在しないオプションが含まれています', 404);
                }
                $canon = $choiceMap[$choiceId];
                // プラン中はオプション加算も 0
                $diff = $isPlanSession ? 0 : $canon['priceDiff'];
                $optionPriceDiff += $diff;
                $validatedOptions[] = [
                    'groupId'    => $canon['groupId'],
                    'groupName'  => $canon['groupName'],
                    'choiceId'   => $canon['choiceId'],
                    'choiceName' => $canon['name'],
                    'price'      => $diff,
                ];
            }
        }

        $unitPrice = $unitBasePrice + $optionPriceDiff;
        $lineTotal = $unitPrice * $qty;
        $totalAmount += $lineTotal;

        // 正規化済みアイテム (DB 保存用)
        $normalized = [
            'id'    => $menuItem['menuItemId'],
            'name'  => $menuItem['name'],
            'price' => $unitPrice,
            'qty'   => $qty,
        ];
        if ($validatedOptions !== null) {
            $normalized['options']   = $validatedOptions;
            $normalized['basePrice'] = $unitBasePrice;
        }
        // アレルギー選択は表示用情報のため値はそのまま透過 (サーバー側の真実ソースなし)
        if (!empty($clientItem['allergen_selections']) && is_array($clientItem['allergen_selections'])) {
            $normalized['allergen_selections'] = $clientItem['allergen_selections'];
        }

        $validatedItems[] = $normalized;
    }

    if (!empty($soldOutNames)) {
        json_error('SOLD_OUT', implode('、', $soldOutNames) . 'は品切れです。カートから削除してください。', 409);
    }

    if (empty($validatedItems)) {
        json_error('NO_ITEMS', '注文に有効な品目がありません', 400);
    }

    return [
        'items'        => $validatedItems,
        'total_amount' => $totalAmount,
    ];
}
