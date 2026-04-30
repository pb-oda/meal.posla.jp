SET NAMES utf8mb4;

DELIMITER $$

DROP PROCEDURE IF EXISTS posla_add_shift_assignment_detail_column $$
CREATE PROCEDURE posla_add_shift_assignment_detail_column()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'shift_assignments'
          AND COLUMN_NAME = 'confirmation_reset_detail'
    ) THEN
        ALTER TABLE shift_assignments
            ADD COLUMN confirmation_reset_detail TEXT NULL AFTER confirmation_reset_reason;
    END IF;
END $$

CALL posla_add_shift_assignment_detail_column() $$
DROP PROCEDURE IF EXISTS posla_add_shift_assignment_detail_column $$

DELIMITER ;
