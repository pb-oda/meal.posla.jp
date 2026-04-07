<?php
/**
 * L-15: POSLA注文 → スマレジ仮販売（layaway）送信
 *
 * ■ 二重構造:
 *   - require_once で読み込み: sync_order_to_smaregi() 関数として利用可能
 *   - 直接POSTアクセス: APIエンドポイントとして動作
 *
 * POST /api/smaregi/sync-order.php
 * body: { "order_id": "xxx" }
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/smaregi-client.php';

/**
 * 注文をスマレジに仮販売として送信する
 *
 * @param PDO $pdo
 * @param string $storeId   POSLA店舗ID
 * @param string $orderId   POSLA注文ID
 * @return array ['ok' => bool, 'smaregi_transaction_id' => string|null, 'synced_items' => int, 'skipped_items' => int, 'warnings' => array, 'error' => string|null]
 */
function sync_order_to_smaregi($pdo, $storeId, $orderId) {
    $result = [
        'ok' => false,
        'smaregi_transaction_id' => null,
        'synced_items' => 0,
        'skipped_items' => 0,
        'warnings' => [],
        'error' => null,
    ];

    // テナントID取得
    $stmt = $pdo->prepare('SELECT tenant_id FROM stores WHERE id = ?');
    $stmt->execute([$storeId]);
    $storeRow = $stmt->fetch();
    if (!$storeRow) {
        $result['error'] = '店舗が見つかりません';
        return $result;
    }
    $tenantId = $storeRow['tenant_id'];

    // スマレジ連携チェック
    $smaregi = get_tenant_smaregi($pdo, $tenantId);
    if (!$smaregi) {
        $result['error'] = 'スマレジ未連携';
        return $result;
    }

    // 店舗マッピングチェック
    $stmt = $pdo->prepare(
        'SELECT smaregi_store_id, sync_enabled FROM smaregi_store_mapping
         WHERE tenant_id = ? AND store_id = ?'
    );
    $stmt->execute([$tenantId, $storeId]);
    $mapping = $stmt->fetch();
    if (!$mapping) {
        $result['error'] = '店舗マッピング未設定';
        return $result;
    }
    if (!(int)$mapping['sync_enabled']) {
        $result['error'] = '同期無効';
        return $result;
    }
    $smaregiStoreId = $mapping['smaregi_store_id'];

    // 注文情報取得
    $stmt = $pdo->prepare('SELECT id, items, total_amount, created_at FROM orders WHERE id = ? AND store_id = ?');
    $stmt->execute([$orderId, $storeId]);
    $order = $stmt->fetch();
    if (!$order) {
        $result['error'] = '注文が見つかりません';
        return $result;
    }

    // 既にスマレジ送信済みチェック
    $stmt = $pdo->prepare('SELECT smaregi_transaction_id FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $txRow = $stmt->fetch();
    if ($txRow && $txRow['smaregi_transaction_id']) {
        $result['ok'] = true;
        $result['smaregi_transaction_id'] = $txRow['smaregi_transaction_id'];
        $result['warnings'][] = '既に送信済みです';
        return $result;
    }

    // 注文品目を解析
    $items = json_decode($order['items'], true);
    if (!$items || !is_array($items)) {
        $result['error'] = '注文品目が空です';
        return $result;
    }

    // 品目IDからスマレジ商品IDへのマッピング取得
    $menuItemIds = [];
    foreach ($items as $item) {
        if (isset($item['id']) && $item['id']) {
            $menuItemIds[] = $item['id'];
        }
    }

    $productMap = [];
    if (!empty($menuItemIds)) {
        $placeholders = implode(',', array_fill(0, count($menuItemIds), '?'));
        $stmt = $pdo->prepare(
            'SELECT menu_template_id, smaregi_product_id FROM smaregi_product_mapping
             WHERE tenant_id = ? AND smaregi_store_id = ? AND menu_template_id IN (' . $placeholders . ')'
        );
        $params = array_merge([$tenantId, $smaregiStoreId], $menuItemIds);
        $stmt->execute($params);
        while ($row = $stmt->fetch()) {
            $productMap[$row['menu_template_id']] = $row['smaregi_product_id'];
        }
    }

    // スマレジ取引明細を構築
    $details = [];
    $detailId = 1;
    $syncedItems = 0;
    $skippedItems = 0;
    $subtotal = 0;

    foreach ($items as $item) {
        $itemId = isset($item['id']) ? $item['id'] : null;
        $itemName = isset($item['name']) ? $item['name'] : '';
        $itemPrice = isset($item['price']) ? (int)$item['price'] : 0;
        $itemQty = isset($item['qty']) ? (int)$item['qty'] : 1;

        if (!$itemId || !isset($productMap[$itemId])) {
            $skippedItems++;
            $result['warnings'][] = 'マッピングなし: ' . $itemName;
            continue;
        }

        $details[] = [
            'transactionDetailId'       => (string)$detailId,
            'transactionDetailDivision' => '1',
            'productId'                 => (string)$productMap[$itemId],
            'salesPrice'                => (string)$itemPrice,
            'quantity'                  => (string)$itemQty,
        ];
        $subtotal += $itemPrice * $itemQty;
        $detailId++;
        $syncedItems++;
    }

    if (empty($details)) {
        $result['error'] = '送信可能な品目がありません（全品目マッピングなし）';
        $result['skipped_items'] = $skippedItems;
        return $result;
    }

    // 注文日時フォーマット（ISO 8601 + TZD）
    $orderDateTime = date('Y-m-d\TH:i:s+09:00', strtotime($order['created_at']));

    // 内税計算（税率10%、税込価格前提）: 内税額 = subtotal / 110 * 10（切り捨て）
    $taxInclude = (int)floor($subtotal / 110 * 10);

    // 端末取引IDを生成（10文字以内のユニーク値）
    $terminalTranId = substr($orderId, 0, 10);

    // スマレジAPIに仮販売送信（フラット構造、全値文字列型）
    $body = [
        'transactionHeadDivision' => '1',
        'status'                  => '0',
        'storeId'                 => (string)$smaregiStoreId,
        'terminalId'              => '1',
        'terminalTranId'          => $terminalTranId,
        'terminalTranDateTime'    => $orderDateTime,
        'subtotal'                => (string)$subtotal,
        'total'                   => (string)$subtotal,
        'taxInclude'              => (string)$taxInclude,
        'taxExclude'              => '0',
        'roundingDivision'        => '00',
        'roundingPrice'           => '0',
        'memo'                    => 'POSLA Order #' . $orderId,
        'details'                 => $details,
    ];

    $apiResult = smaregi_api_request($pdo, $tenantId, 'POST', '/pos/transactions/temporaries', $body);

    if (!$apiResult['ok']) {
        $errMsg = isset($apiResult['data']['error']) ? $apiResult['data']['error'] : 'スマレジAPI送信失敗';
        $detail = json_encode($apiResult['data'], JSON_UNESCAPED_UNICODE);
        $result['error'] = $errMsg . ' (HTTP=' . $apiResult['status'] . ' detail=' . $detail . ')';
        $result['synced_items'] = $syncedItems;
        $result['skipped_items'] = $skippedItems;
        return $result;
    }

    // スマレジの取引IDを保存
    $smaregiTxId = null;
    if (is_array($apiResult['data'])) {
        $smaregiTxId = isset($apiResult['data']['transactionHeadId'])
            ? $apiResult['data']['transactionHeadId']
            : (isset($apiResult['data']['id']) ? $apiResult['data']['id'] : null);
    }

    if ($smaregiTxId) {
        try {
            $stmt = $pdo->prepare('UPDATE orders SET smaregi_transaction_id = ? WHERE id = ?');
            $stmt->execute([$smaregiTxId, $orderId]);
        } catch (PDOException $e) {
            // smaregi_transaction_id カラム未存在時はスキップ
            if (strpos($e->getMessage(), 'smaregi_transaction_id') === false) {
                throw $e;
            }
        }
    }

    $result['ok'] = true;
    $result['smaregi_transaction_id'] = $smaregiTxId;
    $result['synced_items'] = $syncedItems;
    $result['skipped_items'] = $skippedItems;

    return $result;
}

// ============================================================
// 直接アクセス時: APIエンドポイントとして動作
// ============================================================
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    require_once __DIR__ . '/../lib/auth.php';

    $user = require_auth();
    require_method(['POST']);

    $pdo = get_db();
    $input = get_json_body();
    $orderId = isset($input['order_id']) ? $input['order_id'] : '';

    if (!$orderId) {
        json_error('MISSING_ORDER_ID', 'order_id は必須です', 400);
    }

    // 注文のstore_idを取得し、テナント所属チェック
    $stmt = $pdo->prepare(
        'SELECT o.store_id FROM orders o
         INNER JOIN stores s ON s.id = o.store_id
         WHERE o.id = ? AND s.tenant_id = ?'
    );
    $stmt->execute([$orderId, $user['tenant_id']]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('ORDER_NOT_FOUND', '注文が見つかりません', 404);
    }

    $result = sync_order_to_smaregi($pdo, $row['store_id'], $orderId);

    if (!$result['ok']) {
        json_error('SYNC_FAILED', $result['error'], 400);
    }

    json_response([
        'smaregi_transaction_id' => $result['smaregi_transaction_id'],
        'synced_items'           => $result['synced_items'],
        'skipped_items'          => $result['skipped_items'],
        'warnings'               => $result['warnings'],
    ]);
}
