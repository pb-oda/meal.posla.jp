-- ============================================================
-- L-4: 多言語対応 — メニュー翻訳テーブル
-- ============================================================

SET NAMES utf8mb4;
-- 既存の name_en / description_en カラムは維持。
-- 追加言語（zh-Hans, zh-Hant, ko）は本テーブルで管理。
-- entity_type + entity_id + lang でユニーク制約。
-- Gemini API で一括翻訳した結果をキャッシュ保存する。
-- ============================================================

CREATE TABLE IF NOT EXISTS menu_translations (
    id            VARCHAR(36) NOT NULL PRIMARY KEY,
    tenant_id     VARCHAR(36) NOT NULL,
    entity_type   ENUM('menu_item','local_item','category','option_group','option_choice') NOT NULL,
    entity_id     VARCHAR(36) NOT NULL,
    lang          VARCHAR(10) NOT NULL COMMENT 'zh-Hans, zh-Hant, ko',
    name          VARCHAR(200) DEFAULT NULL,
    description   TEXT DEFAULT NULL,
    translated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_entity_lang (entity_type, entity_id, lang),
    KEY idx_tenant (tenant_id),
    KEY idx_entity (entity_type, entity_id),

    CONSTRAINT fk_mt_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- UI翻訳テーブル（セルフオーダー画面のUI文言）
-- 静的に初期投入。フロントエンドJSに埋め込んでも良いが、
-- DB管理することでオーナーが将来カスタマイズ可能にする。
CREATE TABLE IF NOT EXISTS ui_translations (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    lang       VARCHAR(10) NOT NULL,
    msg_key    VARCHAR(100) NOT NULL,
    msg_value  TEXT NOT NULL,

    UNIQUE KEY uk_lang_key (lang, msg_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- UI翻訳の初期データ
-- ============================================================

-- 中国語（簡体字）
INSERT INTO ui_translations (lang, msg_key, msg_value) VALUES
('zh-Hans', 'welcome_prefix', '欢迎光临'),
('zh-Hans', 'welcome_suffix', '！'),
('zh-Hans', 'welcome_default', '欢迎！请从菜单中点餐'),
('zh-Hans', 'btn_add', '添加'),
('zh-Hans', 'btn_order', '下单'),
('zh-Hans', 'btn_cart', '购物车'),
('zh-Hans', 'cart_title', '购物车'),
('zh-Hans', 'cart_empty', '购物车为空'),
('zh-Hans', 'cart_total', '合计'),
('zh-Hans', 'order_confirm', '确认下单'),
('zh-Hans', 'order_success', '下单成功！'),
('zh-Hans', 'order_fail', '下单失败，请重试'),
('zh-Hans', 'sold_out', '售罄'),
('zh-Hans', 'included', '自助餐'),
('zh-Hans', 'search_placeholder', '搜索菜单'),
('zh-Hans', 'category_all', '全部'),
('zh-Hans', 'wait_time', '预计等待约{min}分钟'),
('zh-Hans', 'ask_ai', '向AI服务员提问'),
('zh-Hans', 'allergen_label', '过敏原'),
('zh-Hans', 'option_required', '必选'),
('zh-Hans', 'option_select', '请选择'),
('zh-Hans', 'recommended', '推荐')
ON DUPLICATE KEY UPDATE msg_value = VALUES(msg_value);

-- 中国語（繁体字）
INSERT INTO ui_translations (lang, msg_key, msg_value) VALUES
('zh-Hant', 'welcome_prefix', '歡迎光臨'),
('zh-Hant', 'welcome_suffix', '！'),
('zh-Hant', 'welcome_default', '歡迎！請從菜單中點餐'),
('zh-Hant', 'btn_add', '加入'),
('zh-Hant', 'btn_order', '下單'),
('zh-Hant', 'btn_cart', '購物車'),
('zh-Hant', 'cart_title', '購物車'),
('zh-Hant', 'cart_empty', '購物車為空'),
('zh-Hant', 'cart_total', '合計'),
('zh-Hant', 'order_confirm', '確認下單'),
('zh-Hant', 'order_success', '下單成功！'),
('zh-Hant', 'order_fail', '下單失敗，請重試'),
('zh-Hant', 'sold_out', '售罄'),
('zh-Hant', 'included', '吃到飽'),
('zh-Hant', 'search_placeholder', '搜尋菜單'),
('zh-Hant', 'category_all', '全部'),
('zh-Hant', 'wait_time', '預計等待約{min}分鐘'),
('zh-Hant', 'ask_ai', '詢問AI服務員'),
('zh-Hant', 'allergen_label', '過敏原'),
('zh-Hant', 'option_required', '必選'),
('zh-Hant', 'option_select', '請選擇'),
('zh-Hant', 'recommended', '推薦')
ON DUPLICATE KEY UPDATE msg_value = VALUES(msg_value);

-- 韓国語
INSERT INTO ui_translations (lang, msg_key, msg_value) VALUES
('ko', 'welcome_prefix', ''),
('ko', 'welcome_suffix', '에 오신 것을 환영합니다!'),
('ko', 'welcome_default', '환영합니다! 메뉴에서 주문해 주세요'),
('ko', 'btn_add', '추가'),
('ko', 'btn_order', '주문하기'),
('ko', 'btn_cart', '장바구니'),
('ko', 'cart_title', '장바구니'),
('ko', 'cart_empty', '장바구니가 비어있습니다'),
('ko', 'cart_total', '합계'),
('ko', 'order_confirm', '주문 확인'),
('ko', 'order_success', '주문이 완료되었습니다!'),
('ko', 'order_fail', '주문에 실패했습니다. 다시 시도해 주세요'),
('ko', 'sold_out', '품절'),
('ko', 'included', '뷔페'),
('ko', 'search_placeholder', '메뉴 검색'),
('ko', 'category_all', '전체'),
('ko', 'wait_time', '약 {min}분 대기 예상'),
('ko', 'ask_ai', 'AI 웨이터에게 질문'),
('ko', 'allergen_label', '알레르기'),
('ko', 'option_required', '필수'),
('ko', 'option_select', '선택해 주세요'),
('ko', 'recommended', '추천')
ON DUPLICATE KEY UPDATE msg_value = VALUES(msg_value);

-- 英語（既存の _en カラムがあるが、UI文言はここで統一管理）
INSERT INTO ui_translations (lang, msg_key, msg_value) VALUES
('en', 'welcome_prefix', 'Welcome to '),
('en', 'welcome_suffix', '!'),
('en', 'welcome_default', 'Welcome! Please order from the menu.'),
('en', 'btn_add', 'Add'),
('en', 'btn_order', 'Order'),
('en', 'btn_cart', 'Cart'),
('en', 'cart_title', 'Cart'),
('en', 'cart_empty', 'Your cart is empty'),
('en', 'cart_total', 'Total'),
('en', 'order_confirm', 'Confirm Order'),
('en', 'order_success', 'Order placed!'),
('en', 'order_fail', 'Order failed. Please try again.'),
('en', 'sold_out', 'Sold Out'),
('en', 'included', 'Included'),
('en', 'search_placeholder', 'Search menu'),
('en', 'category_all', 'All'),
('en', 'wait_time', 'Approx. {min} min wait'),
('en', 'ask_ai', 'Ask AI Waiter'),
('en', 'allergen_label', 'Allergens'),
('en', 'option_required', 'Required'),
('en', 'option_select', 'Please select'),
('en', 'recommended', 'Recommended')
ON DUPLICATE KEY UPDATE msg_value = VALUES(msg_value);

-- 日本語（フォールバック用 — JSハードコードの代替）
INSERT INTO ui_translations (lang, msg_key, msg_value) VALUES
('ja', 'welcome_prefix', ''),
('ja', 'welcome_suffix', 'へようこそ！'),
('ja', 'welcome_default', 'いらっしゃいませ！ご注文はメニューからどうぞ'),
('ja', 'btn_add', '追加'),
('ja', 'btn_order', '注文する'),
('ja', 'btn_cart', 'カート'),
('ja', 'cart_title', 'カート'),
('ja', 'cart_empty', 'カートは空です'),
('ja', 'cart_total', '合計'),
('ja', 'order_confirm', '注文を確定'),
('ja', 'order_success', 'ご注文ありがとうございます！'),
('ja', 'order_fail', '注文に失敗しました。もう一度お試しください'),
('ja', 'sold_out', '品切れ'),
('ja', 'included', '食べ放題'),
('ja', 'search_placeholder', 'メニュー検索'),
('ja', 'category_all', 'すべて'),
('ja', 'wait_time', '約{min}分お待ちいただきます'),
('ja', 'ask_ai', 'AIウェイターに聞く'),
('ja', 'allergen_label', 'アレルゲン'),
('ja', 'option_required', '必須'),
('ja', 'option_select', '選択してください'),
('ja', 'recommended', 'おすすめ')
ON DUPLICATE KEY UPDATE msg_value = VALUES(msg_value);
