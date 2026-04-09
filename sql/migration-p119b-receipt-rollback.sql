-- P1-19b: 領収書翻訳キーロールバック
-- 背景: P1-19 で追加した receipt_* 13キー（×4lang = 52行）は、Hiro 実機確認の結果
--      「領収書は日本語のままで良い」と判明したため削除する
-- 影響範囲: ui_translations の 13 キー × 4 lang = 52 行を削除
-- menu.html 側はハードコード日本語に戻す（同時デプロイ）

DELETE FROM ui_translations WHERE msg_key IN (
  'receipt_title',
  'receipt_qualified_invoice',
  'receipt_registration_number_label',
  'receipt_subtotal_10',
  'receipt_tax_10',
  'receipt_subtotal_8',
  'receipt_tax_8',
  'receipt_payment_label',
  'receipt_payment_card',
  'receipt_datetime_label',
  'receipt_screenshot_note',
  'receipt_reduced_tax_note',
  'receipt_popup_blocked'
);

-- 検証クエリ（実行後）:
-- SELECT COUNT(*) FROM ui_translations WHERE msg_key LIKE 'receipt_%';
-- 期待値: 0
