-- P1-6d: POSLA管理者セッション管理テーブル
-- 背景: api/posla/change-password.php にて「他セッション無効化処理は未実装のためスキップ」
--       となっており、パスワード変更後に旧セッションが残存し、攻撃者がセッションを
--       窃取済みの場合にアカウント乗っ取りを防げない。
-- 対応: posla_admin_sessions テーブルを新設し、login.php で INSERT、
--       change-password.php で admin_id 単位 DELETE を行う。
--
-- 設計方針:
--  - 既存 user_sessions テーブルのカラム構成を踏襲（一貫性維持）
--  - admin_id は posla_admins.id (varchar(36) UUID) に合わせる
--  - 照合順序は utf8mb4_unicode_ci（プロジェクト統一、JOIN失敗対策）
--  - tenant_id は不要（POSLA管理者はテナント横断のため）
--  - session_id（PHP native session_id() 値）に UNIQUE 制約

CREATE TABLE IF NOT EXISTS `posla_admin_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `session_id` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'PHP session_id() 値',
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `login_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_active_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `is_active` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_posla_session` (`session_id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_admin_active` (`admin_id`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
