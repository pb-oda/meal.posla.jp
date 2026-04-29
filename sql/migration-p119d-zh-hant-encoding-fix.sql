-- ============================================================
-- P1-19d: ui_translations zh-Hant 文字化け補正
-- ============================================================
-- P1-19c では zh-Hans / ko を補正済み。
-- 2026-04-29 の現物確認で zh-Hant の checkout_* に二重エンコード痕跡が残っていたため、
-- POSLA の翻訳データとして正しい繁体字に上書きする。
-- ============================================================

SET NAMES utf8mb4;

INSERT INTO ui_translations (lang, msg_key, msg_value) VALUES
('zh-Hant', 'btn_close', '關閉'),
('zh-Hant', 'btn_checkout', '結帳'),
('zh-Hant', 'checkout_cancel_msg', '付款已取消'),
('zh-Hant', 'checkout_done_thanks', '謝謝！'),
('zh-Hant', 'checkout_done_title', '付款完成'),
('zh-Hant', 'checkout_not_confirmed', '無法確認付款。請稍後再試。'),
('zh-Hant', 'checkout_pay_card', '前往付款'),
('zh-Hant', 'checkout_payment_methods_label', '可用付款方式'),
('zh-Hant', 'checkout_processing', '處理中...'),
('zh-Hant', 'checkout_receipt_note', '如需紙本收據，請洽工作人員'),
('zh-Hant', 'checkout_stripe_note', '由 Stripe 安全付款'),
('zh-Hant', 'checkout_subtotal_10', '10%稅率 小計'),
('zh-Hant', 'checkout_subtotal_8', '8%稅率 小計'),
('zh-Hant', 'checkout_tax_10', '(稅10%)'),
('zh-Hant', 'checkout_tax_8', '(稅8%)'),
('zh-Hant', 'checkout_title', '結帳'),
('zh-Hant', 'checkout_total', '合計'),
('zh-Hant', 'checkout_view_receipt', '查看收據')
ON DUPLICATE KEY UPDATE msg_value = VALUES(msg_value);
