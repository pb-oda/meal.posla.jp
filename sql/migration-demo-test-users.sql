-- migration-demo-test-users.sql
-- デモ用テストユーザー（店長権限）5名追加
-- username: test1〜test5 / password: password

INSERT INTO users (id, tenant_id, username, email, password_hash, display_name, role) VALUES
('u-test-001', 't-matsunoya-001', 'test1', NULL, '$2y$12$WbkmuOgdyT4IcoWjPy/ypO4EiXEpbX1vTu/qpepkpARpLdzKq9siC', 'test1', 'manager'),
('u-test-002', 't-matsunoya-001', 'test2', NULL, '$2y$12$WbkmuOgdyT4IcoWjPy/ypO4EiXEpbX1vTu/qpepkpARpLdzKq9siC', 'test2', 'manager'),
('u-test-003', 't-matsunoya-001', 'test3', NULL, '$2y$12$WbkmuOgdyT4IcoWjPy/ypO4EiXEpbX1vTu/qpepkpARpLdzKq9siC', 'test3', 'manager'),
('u-test-004', 't-matsunoya-001', 'test4', NULL, '$2y$12$WbkmuOgdyT4IcoWjPy/ypO4EiXEpbX1vTu/qpepkpARpLdzKq9siC', 'test4', 'manager'),
('u-test-005', 't-matsunoya-001', 'test5', NULL, '$2y$12$WbkmuOgdyT4IcoWjPy/ypO4EiXEpbX1vTu/qpepkpARpLdzKq9siC', 'test5', 'manager');

-- 両店舗へのアクセス権付与
INSERT INTO user_stores (user_id, store_id) VALUES
('u-test-001', 's-shibuya-001'),
('u-test-001', 's-shinjuku-001'),
('u-test-002', 's-shibuya-001'),
('u-test-002', 's-shinjuku-001'),
('u-test-003', 's-shibuya-001'),
('u-test-003', 's-shinjuku-001'),
('u-test-004', 's-shibuya-001'),
('u-test-004', 's-shinjuku-001'),
('u-test-005', 's-shibuya-001'),
('u-test-005', 's-shinjuku-001');
