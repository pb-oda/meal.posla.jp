-- U-2 レシートプリンター連携
-- 2026-04-18 / Star mC-Print3 + Epson TM-m30III 対応
-- 両機種とも HTTP API (WebPRNT / ePOS-Print) でブラウザから直接印刷可能

SET NAMES utf8mb4;

ALTER TABLE store_settings
  ADD COLUMN printer_type ENUM('none','browser','star','epson') NOT NULL DEFAULT 'browser' AFTER receipt_footer,
  ADD COLUMN printer_ip VARCHAR(45) DEFAULT NULL AFTER printer_type,
  ADD COLUMN printer_port SMALLINT UNSIGNED NOT NULL DEFAULT 80 AFTER printer_ip,
  ADD COLUMN printer_paper_width SMALLINT UNSIGNED NOT NULL DEFAULT 80 AFTER printer_port,
  ADD COLUMN printer_auto_kitchen TINYINT(1) NOT NULL DEFAULT 0 AFTER printer_paper_width,
  ADD COLUMN printer_auto_receipt TINYINT(1) NOT NULL DEFAULT 1 AFTER printer_auto_kitchen;

-- 説明:
-- printer_type:
--   'none'    = プリンタ無効 (印刷機能を隠す)
--   'browser' = ブラウザの標準印刷ダイアログ (window.print) — デフォルト
--   'star'    = Star mC-Print3 (WebPRNT)
--   'epson'   = Epson TM-m30 系 (ePOS-Print)
-- printer_ip/port: Wi-Fi/Ethernet 接続時の LAN IP
-- printer_paper_width: 58 or 80 mm
-- printer_auto_kitchen: 注文確定時に自動でキッチン伝票印刷
-- printer_auto_receipt: 会計完了時に自動でレシート印刷
