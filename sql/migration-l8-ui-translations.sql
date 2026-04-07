-- L-8: セルフレジ UI翻訳データ
-- 実行方法: mysql -h mysql80.odah.sakura.ne.jp -u odah_eat-posla -p odah_eat-posla < sql/migration-l8-ui-translations.sql

INSERT IGNORE INTO ui_translations (lang, msg_key, msg_value) VALUES
-- 日本語
('ja', 'btn_checkout', 'お会計'),
('ja', 'checkout_title', 'お会計'),
('ja', 'checkout_subtotal_10', '10%対象 小計'),
('ja', 'checkout_tax_10', '(税10%)'),
('ja', 'checkout_subtotal_8', '8%対象 小計'),
('ja', 'checkout_tax_8', '(税8%)'),
('ja', 'checkout_total', '合計'),
('ja', 'checkout_pay_card', 'カードで支払う'),
('ja', 'checkout_stripe_note', 'Stripeの安全な決済ページに移動します'),
('ja', 'checkout_processing', '処理中...'),
('ja', 'checkout_done_title', 'お支払い完了'),
('ja', 'checkout_done_thanks', 'ありがとうございました'),
('ja', 'checkout_view_receipt', '領収書を表示'),
('ja', 'checkout_receipt_note', '紙の領収書が必要な場合はスタッフにお申し付けください'),
('ja', 'checkout_cancel_msg', 'お支払いがキャンセルされました'),
-- English
('en', 'btn_checkout', 'Pay Now'),
('en', 'checkout_title', 'Checkout'),
('en', 'checkout_subtotal_10', 'Subtotal (10% tax)'),
('en', 'checkout_tax_10', '(Tax 10%)'),
('en', 'checkout_subtotal_8', 'Subtotal (8% tax)'),
('en', 'checkout_tax_8', '(Tax 8%)'),
('en', 'checkout_total', 'Total'),
('en', 'checkout_pay_card', 'Pay by Card'),
('en', 'checkout_stripe_note', 'You will be redirected to Stripe secure payment page'),
('en', 'checkout_processing', 'Processing...'),
('en', 'checkout_done_title', 'Payment Complete'),
('en', 'checkout_done_thanks', 'Thank you!'),
('en', 'checkout_view_receipt', 'View Receipt'),
('en', 'checkout_receipt_note', 'Please ask staff if you need a paper receipt'),
('en', 'checkout_cancel_msg', 'Payment was cancelled'),
-- 简体中文
('zh-Hans', 'btn_checkout', '结账'),
('zh-Hans', 'checkout_title', '结账'),
('zh-Hans', 'checkout_subtotal_10', '10%税率 小计'),
('zh-Hans', 'checkout_tax_10', '(税10%)'),
('zh-Hans', 'checkout_subtotal_8', '8%税率 小计'),
('zh-Hans', 'checkout_tax_8', '(税8%)'),
('zh-Hans', 'checkout_total', '合计'),
('zh-Hans', 'checkout_pay_card', '银行卡支付'),
('zh-Hans', 'checkout_stripe_note', '将跳转至Stripe安全支付页面'),
('zh-Hans', 'checkout_processing', '处理中...'),
('zh-Hans', 'checkout_done_title', '支付完成'),
('zh-Hans', 'checkout_done_thanks', '谢谢！'),
('zh-Hans', 'checkout_view_receipt', '查看收据'),
('zh-Hans', 'checkout_receipt_note', '如需纸质收据，请联系工作人员'),
('zh-Hans', 'checkout_cancel_msg', '支付已取消'),
-- 繁體中文
('zh-Hant', 'btn_checkout', '結帳'),
('zh-Hant', 'checkout_title', '結帳'),
('zh-Hant', 'checkout_subtotal_10', '10%稅率 小計'),
('zh-Hant', 'checkout_tax_10', '(稅10%)'),
('zh-Hant', 'checkout_subtotal_8', '8%稅率 小計'),
('zh-Hant', 'checkout_tax_8', '(稅8%)'),
('zh-Hant', 'checkout_total', '合計'),
('zh-Hant', 'checkout_pay_card', '信用卡付款'),
('zh-Hant', 'checkout_stripe_note', '將跳轉至Stripe安全付款頁面'),
('zh-Hant', 'checkout_processing', '處理中...'),
('zh-Hant', 'checkout_done_title', '付款完成'),
('zh-Hant', 'checkout_done_thanks', '謝謝！'),
('zh-Hant', 'checkout_view_receipt', '查看收據'),
('zh-Hant', 'checkout_receipt_note', '如需紙本收據，請洽工作人員'),
('zh-Hant', 'checkout_cancel_msg', '付款已取消'),
-- 한국어
('ko', 'btn_checkout', '결제하기'),
('ko', 'checkout_title', '결제'),
('ko', 'checkout_subtotal_10', '10% 세율 소계'),
('ko', 'checkout_tax_10', '(세금 10%)'),
('ko', 'checkout_subtotal_8', '8% 세율 소계'),
('ko', 'checkout_tax_8', '(세금 8%)'),
('ko', 'checkout_total', '합계'),
('ko', 'checkout_pay_card', '카드로 결제'),
('ko', 'checkout_stripe_note', 'Stripe 보안 결제 페이지로 이동합니다'),
('ko', 'checkout_processing', '처리 중...'),
('ko', 'checkout_done_title', '결제 완료'),
('ko', 'checkout_done_thanks', '감사합니다!'),
('ko', 'checkout_view_receipt', '영수증 보기'),
('ko', 'checkout_receipt_note', '종이 영수증이 필요하시면 직원에게 말씀해 주세요'),
('ko', 'checkout_cancel_msg', '결제가 취소되었습니다');

-- ============================================================
-- P1-7b (2026-04-07): セルフメニュー決済UI改善
-- ・checkout_pay_card / checkout_stripe_note の文言を中立的表現に変更
-- ・新規キー checkout_payment_methods_label を追加（ロゴ列ラベル）
-- 注: ui_translations の実カラムは msg_key / msg_value（プロンプトの tkey/text ではない）
-- ============================================================

-- 既存キーの文言更新
UPDATE ui_translations SET msg_value = 'お支払いに進む'    WHERE lang = 'ja'      AND msg_key = 'checkout_pay_card';
UPDATE ui_translations SET msg_value = 'Proceed to Payment' WHERE lang = 'en'      AND msg_key = 'checkout_pay_card';
UPDATE ui_translations SET msg_value = '前往支付'           WHERE lang = 'zh-Hans' AND msg_key = 'checkout_pay_card';
UPDATE ui_translations SET msg_value = '前往付款'           WHERE lang = 'zh-Hant' AND msg_key = 'checkout_pay_card';
UPDATE ui_translations SET msg_value = '결제 진행'          WHERE lang = 'ko'      AND msg_key = 'checkout_pay_card';

UPDATE ui_translations SET msg_value = 'Stripeの安全な決済画面でお支払いいただけます' WHERE lang = 'ja'      AND msg_key = 'checkout_stripe_note';
UPDATE ui_translations SET msg_value = 'Securely powered by Stripe'                  WHERE lang = 'en'      AND msg_key = 'checkout_stripe_note';
UPDATE ui_translations SET msg_value = '由 Stripe 安全支付'                           WHERE lang = 'zh-Hans' AND msg_key = 'checkout_stripe_note';
UPDATE ui_translations SET msg_value = '由 Stripe 安全付款'                           WHERE lang = 'zh-Hant' AND msg_key = 'checkout_stripe_note';
UPDATE ui_translations SET msg_value = 'Stripe 안전 결제'                             WHERE lang = 'ko'      AND msg_key = 'checkout_stripe_note';

-- 新規キー追加（INSERT IGNORE で再実行に強くする）
INSERT IGNORE INTO ui_translations (lang, msg_key, msg_value) VALUES
('ja',      'checkout_payment_methods_label', 'ご利用可能'),
('en',      'checkout_payment_methods_label', 'Available'),
('zh-Hans', 'checkout_payment_methods_label', '可用支付方式'),
('zh-Hant', 'checkout_payment_methods_label', '可用付款方式'),
('ko',      'checkout_payment_methods_label', '사용 가능');
