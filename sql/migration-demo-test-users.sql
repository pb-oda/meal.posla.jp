-- migration-demo-test-users.sql
-- デモ用テストユーザー（店長権限）5名追加
-- username: test1〜test5 / password: Demo1234（P1-5新ポリシー対応）

INSERT INTO users (id, tenant_id, username, email, password_hash, display_name, role) VALUES
('u-test-001', 't-matsunoya-001', 'test1', NULL, '$2y$10$A0KbdNz9DAnUMXJjLFOD/eSgzslbQIrkkv3tO4C4Lr4iGA50Inm0a', 'test1', 'manager'),
('u-test-002', 't-matsunoya-001', 'test2', NULL, '$2y$10$A0KbdNz9DAnUMXJjLFOD/eSgzslbQIrkkv3tO4C4Lr4iGA50Inm0a', 'test2', 'manager'),
('u-test-003', 't-matsunoya-001', 'test3', NULL, '$2y$10$A0KbdNz9DAnUMXJjLFOD/eSgzslbQIrkkv3tO4C4Lr4iGA50Inm0a', 'test3', 'manager'),
('u-test-004', 't-matsunoya-001', 'test4', NULL, '$2y$10$A0KbdNz9DAnUMXJjLFOD/eSgzslbQIrkkv3tO4C4Lr4iGA50Inm0a', 'test4', 'manager'),
('u-test-005', 't-matsunoya-001', 'test5', NULL, '$2y$10$A0KbdNz9DAnUMXJjLFOD/eSgzslbQIrkkv3tO4C4Lr4iGA50Inm0a', 'test5', 'manager');

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
