-- ============================================================
-- P1-58: シフト変更時の再確認フラグ
-- - 確認済みシフトが変更された時だけ、対象スタッフの確認状態を戻す
-- - 変更理由は店長の追加入力なしで自動生成する
-- ============================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS posla_add_shift_assignment_column;

DELIMITER //
CREATE PROCEDURE posla_add_shift_assignment_column(IN p_column VARCHAR(64), IN p_ddl TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'shift_assignments'
          AND COLUMN_NAME = p_column
    ) THEN
        SET @posla_sql = p_ddl;
        PREPARE posla_stmt FROM @posla_sql;
        EXECUTE posla_stmt;
        DEALLOCATE PREPARE posla_stmt;
    END IF;
END//
DELIMITER ;

CALL posla_add_shift_assignment_column(
    'confirmed_at',
    'ALTER TABLE shift_assignments ADD COLUMN confirmed_at DATETIME NULL AFTER status'
);

CALL posla_add_shift_assignment_column(
    'confirmation_reset_at',
    'ALTER TABLE shift_assignments ADD COLUMN confirmation_reset_at DATETIME NULL AFTER confirmed_at'
);

CALL posla_add_shift_assignment_column(
    'confirmation_reset_by',
    'ALTER TABLE shift_assignments ADD COLUMN confirmation_reset_by VARCHAR(36) NULL AFTER confirmation_reset_at'
);

CALL posla_add_shift_assignment_column(
    'confirmation_reset_reason',
    'ALTER TABLE shift_assignments ADD COLUMN confirmation_reset_reason VARCHAR(120) NULL AFTER confirmation_reset_by'
);

DROP PROCEDURE IF EXISTS posla_add_shift_assignment_column;

UPDATE shift_assignments
SET confirmed_at = COALESCE(confirmed_at, updated_at)
WHERE status = 'confirmed'
  AND confirmed_at IS NULL;
