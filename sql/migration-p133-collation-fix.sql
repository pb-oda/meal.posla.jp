-- migration-p133-collation-fix.sql
-- 照合順序ミスマッチ修正 (B-2026-04-08-09)
--
-- 背景:
--   B-PROC-01a lurking bug スキャン (2026-04-08) で、3 テーブルが
--   utf8mb4_0900_ai_ci (MySQL 8.0 デフォルト) で作成されており、
--   プロジェクト標準 utf8mb4_unicode_ci の他テーブルとの JOIN で
--   "Illegal mix of collations" エラーが発生していた。
--
-- 影響:
--   - api/store/daily-recommendations.php GET → 500 (matsunoya / torimaru 両方)
--   - api/store/staff-report.php avgRating 集計 → try/catch でサイレント失敗 (常に null)
--   - call_alerts は現状 JOIN なしで未発症だが latent 地雷
--
-- 適用日: 2026-04-08
-- 変換方法: ALTER TABLE ... CONVERT TO CHARACTER SET ...
--   (各文字列を utf8mb4 のまま再格納するだけ。バイト列は同一なのでデータ消失なし)
--
-- 件数チェック (before == after):
--   daily_recommendations: 1 件
--   call_alerts          : 129 件
--   satisfaction_ratings : 33 件

ALTER TABLE daily_recommendations
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE call_alerts
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE satisfaction_ratings
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 検証 SQL (適用後に手動実行):
--   SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES
--    WHERE TABLE_SCHEMA = DATABASE()
--      AND TABLE_NAME IN ('daily_recommendations','call_alerts','satisfaction_ratings');
--   → 3 行すべて utf8mb4_unicode_ci を期待
--
--   SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES
--    WHERE TABLE_SCHEMA = DATABASE()
--      AND TABLE_COLLATION <> 'utf8mb4_unicode_ci';
--   → 0 行を期待 (DB 全テーブルが unicode_ci 統一)
--
-- Rollback (もし戻す必要があれば):
--   ALTER TABLE daily_recommendations CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
--   ALTER TABLE call_alerts           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
--   ALTER TABLE satisfaction_ratings  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
--   または: mysql ... < _backup_20260408_p133/p133-collation-backup.sql
