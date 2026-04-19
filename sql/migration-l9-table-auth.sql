-- L-9 テーブル開放認証 (スタッフホワイトリスト方式)
-- 2026-04-18
-- 仕組み: スタッフがハンディで「テーブル開放」 → next_session_token 発行 (5分有効、ワンタイム)
--         客が QR 読み込み → next_session_token 一致でセッション作成、消費後即消去

SET NAMES utf8mb4;

ALTER TABLE tables
  ADD COLUMN next_session_token VARCHAR(64) DEFAULT NULL AFTER session_token_expires_at,
  ADD COLUMN next_session_token_expires_at DATETIME DEFAULT NULL AFTER next_session_token,
  ADD COLUMN next_session_opened_by_user_id VARCHAR(36) DEFAULT NULL AFTER next_session_token_expires_at,
  ADD COLUMN next_session_opened_at DATETIME DEFAULT NULL AFTER next_session_opened_by_user_id;
ALTER TABLE tables
  ADD INDEX idx_next_token_expires (next_session_token_expires_at);
