-- migration-b3-course-session.sql
-- コースセッション対応: table_sessions にコース関連カラム追加
-- 依存: migration-b-phase-b.sql（table_sessions, course_templates, course_phases）

ALTER TABLE table_sessions ADD COLUMN course_id VARCHAR(36) DEFAULT NULL
  COMMENT 'course_templatesのID' AFTER plan_id;

ALTER TABLE table_sessions ADD COLUMN current_phase_number INT DEFAULT NULL
  COMMENT '現在の提供フェーズ番号' AFTER course_id;

ALTER TABLE table_sessions ADD COLUMN phase_fired_at DATETIME DEFAULT NULL
  COMMENT '現フェーズの注文投入時刻' AFTER current_phase_number;

ALTER TABLE table_sessions ADD INDEX idx_ts_course (store_id, course_id);
