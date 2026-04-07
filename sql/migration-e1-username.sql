-- migration-e1-username.sql
-- usersテーブルにusernameカラム追加、emailをNULLABLEに変更

ALTER TABLE users
  ADD COLUMN username VARCHAR(50) NULL AFTER email;

-- 既存ユーザーのusernameをテナントslug + email@前で自動生成（重複回避）
UPDATE users u
  JOIN tenants t ON t.id = u.tenant_id
  SET u.username = CONCAT(t.slug, '-', SUBSTRING_INDEX(u.email, '@', 1))
  WHERE u.username IS NULL;

-- usernameをNOT NULLに変更 + UNIQUEインデックス
ALTER TABLE users
  MODIFY COLUMN username VARCHAR(50) NOT NULL;

ALTER TABLE users
  ADD UNIQUE INDEX idx_user_username (username);

-- emailをNULLABLEに変更（既存のUNIQUEインデックスはNULL許容）
ALTER TABLE users
  MODIFY COLUMN email VARCHAR(254) NULL;
