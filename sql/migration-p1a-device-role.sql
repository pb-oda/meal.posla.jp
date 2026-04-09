-- migration-p1a-device-role.sql
-- P1a: device ロール追加
--
-- 目的:
--   KDS / レジ端末専用アカウント用の "device" ロールを users.role ENUM に追加する。
--   audit_log.role にも同じ ENUM 値を追加し、監査ログとの整合性を保つ。
--
-- 影響範囲:
--   - users テーブル: ENUM 値 'device' を追加（既存レコードへの影響なし）
--   - audit_log テーブル: ENUM 値 'device' を追加（既存レコードへの影響なし）
--
-- 注意:
--   - schema.sql は触らない（CLAUDE.md 規約）
--   - 既存マイグレーションファイルも編集しない
--   - 既存の owner/manager/staff レコードは一切変更されない
--
-- ロールバック:
--   ALTER TABLE users
--     MODIFY COLUMN role ENUM('owner','manager','staff') NOT NULL DEFAULT 'staff';
--   ALTER TABLE audit_log
--     MODIFY COLUMN role ENUM('owner','manager','staff') DEFAULT NULL;
--   ※ ロールバック前に device ロールのレコードが存在しないことを確認すること。

-- 1. users.role に 'device' 追加
ALTER TABLE users
    MODIFY COLUMN role ENUM('owner','manager','staff','device') NOT NULL DEFAULT 'staff';

-- 2. audit_log.role に 'device' 追加（テーブル名は単数形）
ALTER TABLE audit_log
    MODIFY COLUMN role ENUM('owner','manager','staff','device') DEFAULT NULL;
