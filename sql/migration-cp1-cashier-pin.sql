-- ============================================================================
-- CP1: Cashier PIN 機能 (2026-04-19)
--
-- 用途: POSレジで会計実行時に「担当スタッフ」を PIN 認証で特定
-- - 各スタッフ (role='staff' / 'manager' / 'owner') に 4-8 桁の PIN を設定可能
-- - 会計確定時に PIN 入力 → 一致したスタッフを orders.staff_id に記録
-- - 出勤中スタッフのみ PIN 受付 (退勤後は PIN 無効化、悪用防止)
--
-- 設計方針:
-- - bcrypt ハッシュで保存 (password_hash カラムと同じ仕組み)
-- - PIN は数字のみ 4〜8 桁 (覚えやすさと不正対策のバランス)
-- - device ロールには PIN なし (人間ではない)
-- ============================================================================

ALTER TABLE users
  ADD COLUMN cashier_pin_hash VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'CP1: POSレジ担当者識別用 PIN (bcrypt ハッシュ、4-8 桁数字)' AFTER password_hash,
  ADD COLUMN cashier_pin_updated_at TIMESTAMP NULL DEFAULT NULL
    COMMENT 'CP1: 最終 PIN 変更時刻' AFTER cashier_pin_hash;

-- インデックスは不要 (PIN 検索は店舗内出勤中スタッフのみが対象、件数少ない)

-- 監査ログイベントタイプ追加 (event_type ENUM 拡張)
-- 既存の audit_log.event カラムは VARCHAR の場合は ALTER 不要
-- 'cashier_pin_used' / 'cashier_pin_set' / 'cashier_pin_failed' を新規追加
