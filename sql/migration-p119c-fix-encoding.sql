-- P1-19c: P1-19 で投入した Group A (allergen_*) + Group C (checkout_not_confirmed, btn_close) の文字化け修正
-- 原因: migration-p119 実行時の MySQL 接続が utf8mb4 ではなく Windows-1252 系で扱われ、
--       UTF-8 バイト列を Latin-1 として解釈→utf8mb4 へ二重エンコードされて格納された
-- 影響範囲: ja / zh-Hans / ko の 9キー（en は ASCII safe で無事）
-- 対象行数: 9 keys × 3 langs = 27 行を DELETE → 27 行を再 INSERT
-- 検証: 診断クエリで Windows-1252 → UTF-8 二重エンコードパターンを確認済み (例: 「关」=E585B3 → C3A5E280A6C2B3)

SET NAMES utf8mb4;

-- 壊れた ja / zh-Hans / ko を一旦削除
DELETE FROM ui_translations
WHERE msg_key IN (
  'allergen_egg','allergen_milk','allergen_wheat','allergen_shrimp',
  'allergen_crab','allergen_buckwheat','allergen_peanut',
  'checkout_not_confirmed','btn_close'
)
AND lang IN ('ja', 'zh-Hans', 'ko');

-- 正しい UTF-8 で再 INSERT
INSERT INTO ui_translations (lang, msg_key, msg_value) VALUES
-- ja
('ja', 'allergen_egg', '卵'),
('ja', 'allergen_milk', '乳'),
('ja', 'allergen_wheat', '小麦'),
('ja', 'allergen_shrimp', 'えび'),
('ja', 'allergen_crab', 'かに'),
('ja', 'allergen_buckwheat', 'そば'),
('ja', 'allergen_peanut', '落花生'),
('ja', 'checkout_not_confirmed', '決済が確認できませんでした。しばらくしてから再度お試しください'),
('ja', 'btn_close', '閉じる'),
-- zh-Hans
('zh-Hans', 'allergen_egg', '鸡蛋'),
('zh-Hans', 'allergen_milk', '牛奶'),
('zh-Hans', 'allergen_wheat', '小麦'),
('zh-Hans', 'allergen_shrimp', '虾'),
('zh-Hans', 'allergen_crab', '蟹'),
('zh-Hans', 'allergen_buckwheat', '荞麦'),
('zh-Hans', 'allergen_peanut', '花生'),
('zh-Hans', 'checkout_not_confirmed', '无法确认付款。请稍后重试。'),
('zh-Hans', 'btn_close', '关闭'),
-- ko
('ko', 'allergen_egg', '계란'),
('ko', 'allergen_milk', '우유'),
('ko', 'allergen_wheat', '밀'),
('ko', 'allergen_shrimp', '새우'),
('ko', 'allergen_crab', '게'),
('ko', 'allergen_buckwheat', '메밀'),
('ko', 'allergen_peanut', '땅콩'),
('ko', 'checkout_not_confirmed', '결제를 확인할 수 없습니다. 잠시 후 다시 시도해 주세요.'),
('ko', 'btn_close', '닫기');

-- 検証クエリ:
-- SELECT msg_key, lang, msg_value, HEX(msg_value) FROM ui_translations
--   WHERE msg_key = 'btn_close' AND lang IN ('ja','zh-Hans','ko')
--   ORDER BY lang;
-- 期待 hex:
--   ja:      閉じる   = E996893058B E3828B  (実際: E99689 E38198 E3828B)
--   zh-Hans: 关闭    = E585B3 E997AD
--   ko:      닫기    = EB8BAB EAB8B0
