-- プラン再設計: lite廃止（3段階: standard/pro/enterprise）
-- 実行方法: mysql -h mysql80.odah.sakura.ne.jp -u odah_eat-posla -p odah_eat-posla < sql/migration-plan-remove-lite.sql

-- 1. liteテナントをstandardに移行
UPDATE tenants SET plan = 'standard' WHERE plan = 'lite';

-- 2. plan_featuresからlite行を削除
DELETE FROM plan_features WHERE plan = 'lite';

-- 3. plan_features ENUMからliteを除去
ALTER TABLE plan_features MODIFY COLUMN plan ENUM('standard','pro','enterprise') NOT NULL;

-- 4. tenants ENUMからliteを除去
ALTER TABLE tenants MODIFY COLUMN plan ENUM('standard','pro','enterprise') NOT NULL DEFAULT 'standard';
