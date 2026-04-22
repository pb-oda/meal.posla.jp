-- ============================================================================
-- H-06: audit_log を append-only 化する BEFORE UPDATE / BEFORE DELETE trigger
-- ============================================================================
--
-- 目的:
--   audit_log テーブルは操作履歴の記録 (改ざん不可) として運用しているが、
--   app 層では INSERT のみ、UPDATE/DELETE は想定していない。
--   しかし DB レベルで防御されていないため、DB 直叩き可能な悪意ある admin が
--   `DELETE FROM audit_log WHERE user_id='自分'` で操作履歴を消せる。
--
--   本 migration は BEFORE UPDATE / BEFORE DELETE で SIGNAL SQLSTATE '45000'
--   を発行し、全 UPDATE / DELETE を拒否する。
--   app 層は INSERT のみ使用するため、runtime 影響なし (非回帰確認済)。
--
-- rollback:
--   DROP TRIGGER IF EXISTS audit_log_no_update;
--   DROP TRIGGER IF EXISTS audit_log_no_delete;
--
-- 将来 GDPR 右を理由とした legitimate cleanup が必要になった場合:
--   上記 rollback でトリガーを外した上で cleanup を実行し、完了後に本 migration
--   を再適用する (migration file は冪等なので IF EXISTS / DROP IF EXISTS で安全)。
--
-- 2026-04-22 (H-06)
-- ============================================================================

-- 既存 trigger があれば先に削除 (再適用安全化)
DROP TRIGGER IF EXISTS audit_log_no_update;
DROP TRIGGER IF EXISTS audit_log_no_delete;

DELIMITER $$

CREATE TRIGGER audit_log_no_update
BEFORE UPDATE ON audit_log
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'audit_log is append-only: UPDATE denied (H-06)';
END$$

CREATE TRIGGER audit_log_no_delete
BEFORE DELETE ON audit_log
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'audit_log is append-only: DELETE denied (H-06)';
END$$

DELIMITER ;

-- ============================================================================
-- 検証 SQL (migration 適用後に実行推奨):
--
-- 1. trigger が追加されたか確認
-- SELECT TRIGGER_NAME, EVENT_MANIPULATION, ACTION_STATEMENT
--   FROM information_schema.TRIGGERS
--  WHERE EVENT_OBJECT_TABLE = 'audit_log';
--
-- 2. INSERT が通ることを確認 (正常系)
-- INSERT INTO audit_log (tenant_id, user_id, action, entity_type)
--   VALUES ('test', 'test', 'test', 'test');
-- SELECT LAST_INSERT_ID();
--
-- 3. UPDATE が拒否されることを確認 (期待: ERROR 1644)
-- UPDATE audit_log SET action = 'modified' WHERE id = <LAST_INSERT_ID>;
--   -- ERROR 1644 (45000): audit_log is append-only: UPDATE denied (H-06)
--
-- 4. DELETE が拒否されることを確認 (期待: ERROR 1644)
-- DELETE FROM audit_log WHERE id = <LAST_INSERT_ID>;
--   -- ERROR 1644 (45000): audit_log is append-only: DELETE denied (H-06)
--
-- 5. test 行だけ削除したい場合は一時的に rollback を適用してから cleanup
-- ============================================================================
