-- ============================================================
-- POSLA — ローカル開発用シード（最小セット）
-- ============================================================
-- 本ファイルは Docker 初回起動時のみ流れる。
-- 既存の /sql/seed.sql とは別物。スモークテスト用の最小データ。
--
-- パスワード: 全アカウント "Demo1234"
--   $2y$12$Uc6mt.QvmCoudghTsDIG.evZVn7z50jJ/hDksVd9TaEFDRSusfjMm
--   ↑ password_hash('Demo1234', PASSWORD_DEFAULT) で生成 (PHP 8.4, cost=12)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------
-- POSLA 共通設定（API キーは空文字 = ローカルでは AI 機能無効）
-- -----------------------------------------------------------
INSERT INTO posla_settings (setting_key, setting_value) VALUES
  ('gemini_api_key',        ''),
  ('google_places_api_key', ''),
  ('google_chat_webhook_url', ''),
  ('ops_notify_email', 'info@posla.jp'),
  ('monitor_cron_secret', 'local-monitor-secret-20260424'),
  ('monitor_last_heartbeat', '')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- -----------------------------------------------------------
-- POSLA 管理者（/posla-admin/ ログイン用）
-- email: admin@posla.local / password: Demo1234
-- -----------------------------------------------------------
INSERT INTO posla_admins (id, email, password_hash, display_name, is_active) VALUES
  ('pa-local-001', 'admin@posla.local',
   '$2y$12$Uc6mt.QvmCoudghTsDIG.evZVn7z50jJ/hDksVd9TaEFDRSusfjMm',
   'POSLA Local Admin', 1)
ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash);

-- -----------------------------------------------------------
-- テナント（matsunoya のみ。本番複製ではないローカル ID）
-- -----------------------------------------------------------
INSERT INTO tenants (id, slug, name, name_en, plan, is_active, hq_menu_broadcast) VALUES
  ('t-local-matsu-001', 'matsunoya', '松の屋（ローカル）', 'Matsunoya (local)', 'standard', 1, 0)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- -----------------------------------------------------------
-- 店舗（渋谷店 1 店）
-- -----------------------------------------------------------
INSERT INTO stores (id, tenant_id, slug, name, name_en, timezone, is_active) VALUES
  ('s-local-shibuya-001', 't-local-matsu-001', 'shibuya', '松の屋 渋谷店（ローカル）', 'Matsunoya Shibuya (local)', 'Asia/Tokyo', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- store_settings はカラム数が多いので最低限のみ（FK 用）
INSERT INTO store_settings (store_id) VALUES ('s-local-shibuya-001')
ON DUPLICATE KEY UPDATE store_id = store_id;

-- -----------------------------------------------------------
-- ユーザー 4 種（owner / manager / staff / device）
-- すべて username + password = Demo1234 でログイン可能
-- -----------------------------------------------------------
INSERT INTO users (id, tenant_id, email, username, password_hash, display_name, role, is_active) VALUES
  ('u-local-owner-001',   't-local-matsu-001', 'owner@local.test',   'owner',   '$2y$12$Uc6mt.QvmCoudghTsDIG.evZVn7z50jJ/hDksVd9TaEFDRSusfjMm', 'ローカルオーナー',   'owner',   1),
  ('u-local-manager-001', 't-local-matsu-001', 'manager@local.test', 'manager', '$2y$12$Uc6mt.QvmCoudghTsDIG.evZVn7z50jJ/hDksVd9TaEFDRSusfjMm', 'ローカル店長',     'manager', 1),
  ('u-local-staff-001',   't-local-matsu-001', 'staff@local.test',   'staff',   '$2y$12$Uc6mt.QvmCoudghTsDIG.evZVn7z50jJ/hDksVd9TaEFDRSusfjMm', 'ローカルスタッフ', 'staff',   1),
  ('u-local-device-001',  't-local-matsu-001', NULL,                 'kds01',   '$2y$12$Uc6mt.QvmCoudghTsDIG.evZVn7z50jJ/hDksVd9TaEFDRSusfjMm', 'KDS 端末 #1',     'device',  1)
ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash);

-- ユーザー × 店舗（owner は user_stores 不要、それ以外を渋谷店に紐付け）
INSERT INTO user_stores (user_id, store_id, visible_tools) VALUES
  ('u-local-manager-001', 's-local-shibuya-001', NULL),
  ('u-local-staff-001',   's-local-shibuya-001', NULL),
  ('u-local-device-001',  's-local-shibuya-001', 'kds,register')
ON DUPLICATE KEY UPDATE visible_tools = VALUES(visible_tools);

-- -----------------------------------------------------------
-- カテゴリ + メニュー（最小: 注文 API テスト用）
-- -----------------------------------------------------------
INSERT INTO categories (id, tenant_id, name, name_en, sort_order) VALUES
  ('c-local-teishoku-001', 't-local-matsu-001', '定食',   'Set Meals',  1),
  ('c-local-donburi-001',  't-local-matsu-001', '丼もの', 'Rice Bowls', 2),
  ('c-local-drink-001',    't-local-matsu-001', 'ドリンク','Drinks',     3)
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO menu_templates (id, tenant_id, category_id, name, name_en, base_price, description, calories, allergens, is_sold_out, is_active, sort_order) VALUES
  ('m-local-001', 't-local-matsu-001', 'c-local-teishoku-001', 'ロースかつ定食', 'Pork Cutlet Set',  990, '揚げたてロースかつ定食', 850, '["wheat","egg"]', 0, 1, 1),
  ('m-local-002', 't-local-matsu-001', 'c-local-teishoku-001', 'ヒレかつ定食',   'Fillet Cutlet Set', 1090, 'ジューシーなヒレかつ',   780, '["wheat","egg"]', 0, 1, 2),
  ('m-local-003', 't-local-matsu-001', 'c-local-donburi-001',  'カツ丼',         'Katsu Don',         750, 'ふんわり卵のカツ丼',     720, '["wheat","egg"]', 0, 1, 3),
  ('m-local-004', 't-local-matsu-001', 'c-local-drink-001',    'ウーロン茶',     'Oolong Tea',        200, '冷たいウーロン茶',         0, '[]',             0, 1, 4)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- -----------------------------------------------------------
-- テーブル（QR テスト用）
-- -----------------------------------------------------------
INSERT INTO tables (id, store_id, table_code, capacity, is_active, pos_x, pos_y, width, height, shape, floor) VALUES
  ('tbl-local-001', 's-local-shibuya-001', 'T01', 4, 1,  20,  20, 80, 80, 'rect',   '1F'),
  ('tbl-local-002', 's-local-shibuya-001', 'T02', 4, 1, 120,  20, 80, 80, 'rect',   '1F'),
  ('tbl-local-003', 's-local-shibuya-001', 'T03', 2, 1, 220,  20, 60, 60, 'circle', '1F'),
  ('tbl-local-004', 's-local-shibuya-001', 'T04', 6, 1,  20, 120, 80, 80, 'rect',   '1F')
ON DUPLICATE KEY UPDATE table_code = VALUES(table_code);

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------
-- 完了メッセージ
-- -----------------------------------------------------------
SELECT '[posla-seed] local seed data loaded' AS status,
       (SELECT COUNT(*) FROM users WHERE tenant_id='t-local-matsu-001') AS users,
       (SELECT COUNT(*) FROM stores WHERE tenant_id='t-local-matsu-001') AS stores,
       (SELECT COUNT(*) FROM menu_templates WHERE tenant_id='t-local-matsu-001') AS menus,
       (SELECT COUNT(*) FROM tables WHERE store_id='s-local-shibuya-001') AS tables_;
