-- migration-r1-rating-reason.sql
-- 低評価理由を保存するためのカラム追加 (R1)
--
-- satisfaction_ratings (migration-i3) に reason_code / reason_text を追加。
-- rating <= 2 の評価に対してのみ顧客側で理由を任意入力できるようにする。
-- MySQL 5.7 前提のため CHECK 制約は使わず、PHP 側で許可値を検証する。
--
-- 既存データは影響を受けない (NULL 許可で追加)。
-- このマイグレーションは再実行安全 (IF NOT EXISTS は MySQL の ALTER COLUMN 単体には無いが、
-- 既存の運用パターンに合わせて単純な ALTER TABLE のみとする。
-- 二重適用された場合は "Duplicate column name" エラーで安全に止まる)。

ALTER TABLE satisfaction_ratings
  ADD COLUMN reason_code VARCHAR(50) DEFAULT NULL COMMENT 'rating<=2 の場合の理由コード (許可リストはPHP側 api/lib/rating-reasons.php)',
  ADD COLUMN reason_text VARCHAR(255) DEFAULT NULL COMMENT 'rating<=2 の場合の自由記述コメント (任意・最大255文字)';

-- 低評価集計を高速化するためのインデックス
-- (満足度分析レポートでは rating <= 2 で頻繁にフィルタするため)
ALTER TABLE satisfaction_ratings
  ADD INDEX idx_store_rating_created (store_id, rating, created_at);
