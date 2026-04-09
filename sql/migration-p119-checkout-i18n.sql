-- P1-19: セルフメニュー「お会計」翻訳キー漏れ対応
-- 対象: アレルギーチップ7種 + 領収書ハードコード13種 + ギャップ補完2種 = 22 keys × 4 langs = 88 rows
-- すべて INSERT IGNORE で再実行安全

-- ============================================================
-- Group A: アレルギーチップ (7 keys)
-- 対象: public/customer/menu.html L251-L257
-- ============================================================

INSERT IGNORE INTO ui_translations (lang, msg_key, msg_value) VALUES
('ja', 'allergen_egg', '卵'),
('en', 'allergen_egg', 'Egg'),
('zh-Hans', 'allergen_egg', '鸡蛋'),
('ko', 'allergen_egg', '계란'),

('ja', 'allergen_milk', '乳'),
('en', 'allergen_milk', 'Milk'),
('zh-Hans', 'allergen_milk', '乳制品'),
('ko', 'allergen_milk', '유제품'),

('ja', 'allergen_wheat', '小麦'),
('en', 'allergen_wheat', 'Wheat'),
('zh-Hans', 'allergen_wheat', '小麦'),
('ko', 'allergen_wheat', '밀'),

('ja', 'allergen_shrimp', 'えび'),
('en', 'allergen_shrimp', 'Shrimp'),
('zh-Hans', 'allergen_shrimp', '虾'),
('ko', 'allergen_shrimp', '새우'),

('ja', 'allergen_crab', 'かに'),
('en', 'allergen_crab', 'Crab'),
('zh-Hans', 'allergen_crab', '蟹'),
('ko', 'allergen_crab', '게'),

('ja', 'allergen_buckwheat', 'そば'),
('en', 'allergen_buckwheat', 'Buckwheat'),
('zh-Hans', 'allergen_buckwheat', '荞麦'),
('ko', 'allergen_buckwheat', '메밀'),

('ja', 'allergen_peanut', '落花生'),
('en', 'allergen_peanut', 'Peanut'),
('zh-Hans', 'allergen_peanut', '花生'),
('ko', 'allergen_peanut', '땅콩');

-- ============================================================
-- Group B: 領収書ハードコード (13 keys)
-- 対象: public/customer/menu.html L2323, L2345, L2351, L2366-L2371, L2379-L2380, L2385-L2386, L2397
-- ============================================================

INSERT IGNORE INTO ui_translations (lang, msg_key, msg_value) VALUES
('ja', 'receipt_title', 'お会計明細'),
('en', 'receipt_title', 'Receipt'),
('zh-Hans', 'receipt_title', '账单明细'),
('ko', 'receipt_title', '영수증'),

('ja', 'receipt_qualified_invoice', '適格簡易請求書'),
('en', 'receipt_qualified_invoice', 'Qualified Simplified Invoice'),
('zh-Hans', 'receipt_qualified_invoice', '合格简易发票'),
('ko', 'receipt_qualified_invoice', '적격 간이 청구서'),

('ja', 'receipt_registration_number_label', '登録番号'),
('en', 'receipt_registration_number_label', 'Registration No.'),
('zh-Hans', 'receipt_registration_number_label', '登记号'),
('ko', 'receipt_registration_number_label', '등록번호'),

('ja', 'receipt_subtotal_10', '10%対象'),
('en', 'receipt_subtotal_10', '10% taxable'),
('zh-Hans', 'receipt_subtotal_10', '10%税额对象'),
('ko', 'receipt_subtotal_10', '10% 과세 대상'),

('ja', 'receipt_tax_10', ' 消費税(10%)'),
('en', 'receipt_tax_10', ' Tax (10%)'),
('zh-Hans', 'receipt_tax_10', ' 消费税(10%)'),
('ko', 'receipt_tax_10', ' 소비세(10%)'),

('ja', 'receipt_subtotal_8', '8%対象 ※'),
('en', 'receipt_subtotal_8', '8% taxable *'),
('zh-Hans', 'receipt_subtotal_8', '8%税额对象 ※'),
('ko', 'receipt_subtotal_8', '8% 과세 대상 ※'),

('ja', 'receipt_tax_8', ' 消費税(8%)'),
('en', 'receipt_tax_8', ' Tax (8%)'),
('zh-Hans', 'receipt_tax_8', ' 消费税(8%)'),
('ko', 'receipt_tax_8', ' 소비세(8%)'),

('ja', 'receipt_payment_label', 'お支払い'),
('en', 'receipt_payment_label', 'Payment'),
('zh-Hans', 'receipt_payment_label', '付款方式'),
('ko', 'receipt_payment_label', '결제'),

('ja', 'receipt_payment_card', 'カード'),
('en', 'receipt_payment_card', 'Card'),
('zh-Hans', 'receipt_payment_card', '信用卡'),
('ko', 'receipt_payment_card', '카드'),

('ja', 'receipt_datetime_label', '日時'),
('en', 'receipt_datetime_label', 'Date & Time'),
('zh-Hans', 'receipt_datetime_label', '日期时间'),
('ko', 'receipt_datetime_label', '일시'),

('ja', 'receipt_screenshot_note', 'この画面をスクリーンショットで保存してください'),
('en', 'receipt_screenshot_note', 'Please save a screenshot of this page'),
('zh-Hans', 'receipt_screenshot_note', '请截屏保存此页面'),
('ko', 'receipt_screenshot_note', '이 화면을 스크린샷으로 저장해 주세요'),

('ja', 'receipt_reduced_tax_note', '※ は軽減税率(8%)対象品目です'),
('en', 'receipt_reduced_tax_note', '* indicates reduced tax rate (8%) items'),
('zh-Hans', 'receipt_reduced_tax_note', '※ 为减免税率(8%)对象品项'),
('ko', 'receipt_reduced_tax_note', '※ 는 경감세율(8%) 대상 품목입니다'),

('ja', 'receipt_popup_blocked', 'ポップアップがブロックされました。ブラウザの設定をご確認ください。'),
('en', 'receipt_popup_blocked', 'Popup was blocked. Please check your browser settings.'),
('zh-Hans', 'receipt_popup_blocked', '弹出窗口被阻止。请检查浏览器设置。'),
('ko', 'receipt_popup_blocked', '팝업이 차단되었습니다. 브라우저 설정을 확인해 주세요.');

-- ============================================================
-- Group C: ギャップ補完 (2 keys) — menu.html で _t() 参照済みだが辞書未登録
-- 対象: L2252 (checkout_not_confirmed), L2291 (btn_close)
-- ============================================================

INSERT IGNORE INTO ui_translations (lang, msg_key, msg_value) VALUES
('ja', 'checkout_not_confirmed', '決済が確認できませんでした。しばらくしてから再度お試しください'),
('en', 'checkout_not_confirmed', 'Payment could not be confirmed. Please try again later.'),
('zh-Hans', 'checkout_not_confirmed', '无法确认付款。请稍后重试。'),
('ko', 'checkout_not_confirmed', '결제를 확인할 수 없습니다. 잠시 후 다시 시도해 주세요.'),

('ja', 'btn_close', '閉じる'),
('en', 'btn_close', 'Close'),
('zh-Hans', 'btn_close', '关闭'),
('ko', 'btn_close', '닫기');

-- 検証クエリ:
-- SELECT lang, COUNT(*) FROM ui_translations WHERE msg_key LIKE 'allergen_%' OR msg_key LIKE 'receipt_%' OR msg_key IN ('checkout_not_confirmed','btn_close') GROUP BY lang;
-- 期待: ja=22, en=22, zh-Hans=22, ko=22 (合計88)
