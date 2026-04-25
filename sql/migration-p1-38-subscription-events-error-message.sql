-- P1-38: subscription_events に Stripe webhook 失敗理由を保持する
-- monitor-health.php が tenant 単位で Google Chat 通知するために利用

SET NAMES utf8mb4;

SET @has_error_message := (
  SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'subscription_events'
     AND COLUMN_NAME = 'error_message'
);

SET @sql := IF(
  @has_error_message = 0,
  'ALTER TABLE subscription_events ADD COLUMN error_message VARCHAR(500) DEFAULT NULL AFTER data',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
