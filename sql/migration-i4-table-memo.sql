-- ============================================================
-- Migration i4: テーブルセッション メモ機能
-- O-4: テーブルメモ（セッション単位のメモ）
-- ============================================================

-- table_sessions にメモカラム追加
ALTER TABLE table_sessions ADD COLUMN memo TEXT DEFAULT NULL
  COMMENT 'セッションメモ（アレルギー・VIP・特記事項等）'
  AFTER closed_at;
