-- P1-34: プラン構成 α-1 移行 — tenants.hq_menu_broadcast カラム追加
--
-- 背景:
--   2026-04-09 確定の α-1 プラン構成 (単一プラン + アドオン) では、
--   差別化機能は「本部一括メニュー配信」のみ。これまで plan_features テーブルで
--   plan ('standard'/'pro'/'enterprise') ごとに 18 機能を ON/OFF していたが、
--   全機能を全契約者に標準提供する方針に変更。
--
--   「本部一括メニュー配信」だけはアドオン契約 (Stripe で別 Price ID) として
--   継続。tenants テーブルに hq_menu_broadcast カラムを新設し、
--   plan_features テーブルへの依存を論理的に廃止する。
--
--   plan_features テーブルは本マイグレーションでは DROP しない (rollback 容易性
--   確保 + 既存 migration スクリプト群との互換性維持)。動作確認後、別の cleanup
--   マイグレーションで物理 DROP する 2 段階方式。
--
-- 実行方法:
--   mysql -h mysql80.odah.sakura.ne.jp -u odah_eat-posla -p odah_eat-posla \
--     < sql/migration-p1-34-tenant-hq-broadcast.sql
--
-- ロールバック:
--   ALTER TABLE tenants DROP COLUMN hq_menu_broadcast;
--   (auth.php を git revert すれば plan_features 経路に戻る)

-- ==========================================================================
-- 1. tenants にカラム追加 (デフォルト 0 = アドオン未契約)
-- ==========================================================================
ALTER TABLE tenants
  ADD COLUMN hq_menu_broadcast TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'α-1 本部一括メニュー配信アドオン契約フラグ (0=未契約, 1=契約)';

-- ==========================================================================
-- 2. 既存テナントのアドオン状態を plan_features から転記
--    plan_features に hq_menu_broadcast=1 が定義された plan のテナントは
--    既存運用との互換性のため、そのままアドオン契約とみなす。
--    (現状: enterprise プランのテナントのみ該当)
-- ==========================================================================
UPDATE tenants t
INNER JOIN plan_features pf
        ON pf.plan = t.plan
       AND pf.feature_key = 'hq_menu_broadcast'
       AND pf.enabled = 1
SET t.hq_menu_broadcast = 1;

-- ==========================================================================
-- 3. 検証クエリ (実行後に手動で確認)
-- ==========================================================================
-- SHOW COLUMNS FROM tenants LIKE 'hq_menu_broadcast';
-- SELECT id, slug, name, plan, hq_menu_broadcast FROM tenants ORDER BY plan, slug;
--
-- 期待結果:
--   matsunoya  (plan=pro)         hq_menu_broadcast=0
--   momonoya   (plan=pro)         hq_menu_broadcast=0
--   torimaru   (plan=enterprise)  hq_menu_broadcast=1

-- ==========================================================================
-- 4. plan_features テーブルは DROP しない
-- ==========================================================================
-- 理由:
--   (a) auth.php 修正後の動作確認期間 (1〜2週間) を確保するため
--   (b) 既存 migration スクリプトに plan_features への INSERT が複数残っており、
--       CI / 復旧シナリオで DROP すると流せなくなる
--   (c) 論理的廃止 (auth.php が読まない) で運用上は廃止扱いにできる
--
--   動作確認後、別の cleanup マイグレーション
--   (sql/migration-p1-34b-drop-plan-features.sql) で
--   `DROP TABLE plan_features;` を実行する予定。
