-- migration-pwa4d-pin-entered-client.sql
-- PWA Phase 4d-1: 緊急会計台帳に pin_entered_client を永続化 (2026-04-20)
--
-- 目的:
--   端末側で PIN 入力欄に入力があったかどうか (pinEnteredClient) をサーバー側台帳に残し、
--   台帳 UI で「確認済 / 入力不一致 / 入力なし / 未確認」の 4 状態を明示できるようにする。
--   既存の staff_pin_verified (bcrypt 検証 0/1) とは独立した別軸。
--
-- 重要な設計方針:
--   - DEFAULT NULL を選ぶ。NOT NULL DEFAULT 0 にすると既存行が全て「入力なし」に見えてしまい、
--     実際には「PIN 入力したが不一致」「旧仕様で必須だった」「カラム未追加時代」が混在している既存行の
--     意味を誤らせる。NULL = 旧データ / 不明 を明示する。
--
--   - backfill は「明らかに PIN 入力していた」ケースだけ 1 を埋める。
--     (a) staff_pin_verified=1: PIN 入力して検証通った => 入力あり確定
--     (b) conflict_reason LIKE '%pin_entered_but_not_verified%': PIN 入力したが検証 NG => 入力あり確定
--     それ以外は NULL のまま残す。
--
-- 追加カラム:
--   - pin_entered_client TINYINT(1) DEFAULT NULL
--
-- MySQL 5.7:
--   ADD COLUMN IF NOT EXISTS が使えないため、既存 migration-pwa4c / pwa4c2 と同じ
--   INFORMATION_SCHEMA + PROCEDURE 方式で冪等 (2 回実行しても安全) にする。

DELIMITER //
DROP PROCEDURE IF EXISTS _mpwa4d_add_pin_entered_client//
CREATE PROCEDURE _mpwa4d_add_pin_entered_client()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'emergency_payments'
       AND column_name  = 'pin_entered_client'
  ) THEN
    ALTER TABLE emergency_payments
      ADD COLUMN pin_entered_client TINYINT(1) DEFAULT NULL
                   COMMENT 'クライアントが PIN 入力したか。NULL=旧データ/不明、0=入力なし、1=入力あり'
                   AFTER staff_pin_verified;

    -- backfill: 明らかに PIN 入力していた行のみ 1 を埋める (初回のみ)
    --   (a) bcrypt 検証済み
    UPDATE emergency_payments
       SET pin_entered_client = 1
     WHERE pin_entered_client IS NULL
       AND staff_pin_verified = 1;

    --   (b) PIN 入力したが検証 NG として pending_review に落ちた行
    UPDATE emergency_payments
       SET pin_entered_client = 1
     WHERE pin_entered_client IS NULL
       AND conflict_reason LIKE '%pin_entered_but_not_verified%';
  END IF;
END//
DELIMITER ;

CALL _mpwa4d_add_pin_entered_client();
DROP PROCEDURE IF EXISTS _mpwa4d_add_pin_entered_client;

-- 検証用 SELECT (実行時の目視用、エラーではない)
SELECT
  COUNT(*)                                                                       AS total_rows,
  SUM(CASE WHEN pin_entered_client IS NULL THEN 1 ELSE 0 END)                    AS null_rows,
  SUM(CASE WHEN pin_entered_client = 0    THEN 1 ELSE 0 END)                    AS entered_0,
  SUM(CASE WHEN pin_entered_client = 1    THEN 1 ELSE 0 END)                    AS entered_1,
  SUM(CASE WHEN staff_pin_verified = 1 AND pin_entered_client = 1 THEN 1 ELSE 0 END) AS verified_backfill,
  SUM(CASE WHEN conflict_reason LIKE '%pin_entered_but_not_verified%' AND pin_entered_client = 1 THEN 1 ELSE 0 END) AS pin_mismatch_backfill
  FROM emergency_payments;
