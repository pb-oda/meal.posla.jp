-- ============================================================
-- SELF-MENU-4: セルフメニュー検索/絞り込み UI 翻訳キー
-- ============================================================
-- 顧客画面 public/customer/menu.html の新規 _t() キーを正式登録する。
-- ja / en / zh-Hans / zh-Hant / ko の5言語。
-- ============================================================

SET NAMES utf8mb4;

INSERT INTO ui_translations (lang, msg_key, msg_value) VALUES
('ja', 'filter_all', 'すべて'),
('en', 'filter_all', 'All'),
('zh-Hans', 'filter_all', '全部'),
('zh-Hant', 'filter_all', '全部'),
('ko', 'filter_all', '전체'),

('ja', 'filter_popular', '人気'),
('en', 'filter_popular', 'Popular'),
('zh-Hans', 'filter_popular', '人气'),
('zh-Hant', 'filter_popular', '熱門'),
('ko', 'filter_popular', '인기'),

('ja', 'filter_fast', '早く出る'),
('en', 'filter_fast', 'Quick'),
('zh-Hans', 'filter_fast', '快速上菜'),
('zh-Hant', 'filter_fast', '快速上菜'),
('ko', 'filter_fast', '빠른 제공'),

('ja', 'filter_spicy', '辛い'),
('en', 'filter_spicy', 'Spicy'),
('zh-Hans', 'filter_spicy', '辣'),
('zh-Hant', 'filter_spicy', '辣'),
('ko', 'filter_spicy', '매운맛'),

('ja', 'filter_allergy', 'アレルギー'),
('en', 'filter_allergy', 'Allergy'),
('zh-Hans', 'filter_allergy', '过敏原'),
('zh-Hant', 'filter_allergy', '過敏原'),
('ko', 'filter_allergy', '알레르기'),

('ja', 'filter_veg', 'ベジ'),
('en', 'filter_veg', 'Vegetarian'),
('zh-Hans', 'filter_veg', '素食'),
('zh-Hant', 'filter_veg', '素食'),
('ko', 'filter_veg', '채식'),

('ja', 'filter_kids', '子ども向け'),
('en', 'filter_kids', 'Kids'),
('zh-Hans', 'filter_kids', '儿童'),
('zh-Hant', 'filter_kids', '兒童'),
('ko', 'filter_kids', '어린이'),

('ja', 'filter_reset', '解除'),
('en', 'filter_reset', 'Reset'),
('zh-Hans', 'filter_reset', '重置'),
('zh-Hant', 'filter_reset', '重設'),
('ko', 'filter_reset', '해제'),

('ja', 'menu_search_placeholder', 'メニュー名・説明で検索'),
('en', 'menu_search_placeholder', 'Search menu or description'),
('zh-Hans', 'menu_search_placeholder', '搜索菜品名或说明'),
('zh-Hant', 'menu_search_placeholder', '搜尋菜品名稱或說明'),
('ko', 'menu_search_placeholder', '메뉴명 또는 설명 검색'),

('ja', 'current_wait_hint', '現在の目安: 約{min}分。注文後の進捗はこの画面で確認できます。'),
('en', 'current_wait_hint', 'Current estimate: about {min} min. You can track progress on this screen after ordering.'),
('zh-Hans', 'current_wait_hint', '当前预计约{min}分钟。下单后可在此页面查看进度。'),
('zh-Hant', 'current_wait_hint', '目前預估約{min}分鐘。下單後可在此頁面查看進度。'),
('ko', 'current_wait_hint', '현재 예상: 약 {min}분. 주문 후 이 화면에서 진행 상황을 확인할 수 있습니다.'),

('ja', 'allergy_exclude_title', '含まれるものを除外'),
('en', 'allergy_exclude_title', 'Exclude items containing'),
('zh-Hans', 'allergy_exclude_title', '排除包含以下内容的菜品'),
('zh-Hant', 'allergy_exclude_title', '排除包含以下內容的菜品'),
('ko', 'allergy_exclude_title', '포함된 항목 제외'),

('ja', 'approx_wait_min', '約{min}分'),
('en', 'approx_wait_min', 'About {min} min'),
('zh-Hans', 'approx_wait_min', '约{min}分钟'),
('zh-Hant', 'approx_wait_min', '約{min}分鐘'),
('ko', 'approx_wait_min', '약 {min}분'),

('ja', 'fast_tag', '早く出る'),
('en', 'fast_tag', 'Quick'),
('zh-Hans', 'fast_tag', '快速上菜'),
('zh-Hant', 'fast_tag', '快速上菜'),
('ko', 'fast_tag', '빠른 제공'),

('ja', 'spicy_tag', '辛い'),
('en', 'spicy_tag', 'Spicy'),
('zh-Hans', 'spicy_tag', '辣'),
('zh-Hant', 'spicy_tag', '辣'),
('ko', 'spicy_tag', '매운맛'),

('ja', 'veg_tag', 'ベジ'),
('en', 'veg_tag', 'Vegetarian'),
('zh-Hans', 'veg_tag', '素食'),
('zh-Hant', 'veg_tag', '素食'),
('ko', 'veg_tag', '채식'),

('ja', 'kids_tag', '子ども向け'),
('en', 'kids_tag', 'Kids'),
('zh-Hans', 'kids_tag', '儿童'),
('zh-Hant', 'kids_tag', '兒童'),
('ko', 'kids_tag', '어린이'),

('ja', 'sold_out_removed_with_suggestions', 'が品切れになりました。代わりに頼める候補を表示しました。'),
('en', 'sold_out_removed_with_suggestions', 'is now sold out. We showed available alternatives.'),
('zh-Hans', 'sold_out_removed_with_suggestions', '已售罄。已显示可点的替代菜品。'),
('zh-Hant', 'sold_out_removed_with_suggestions', '已售罄。已顯示可點的替代菜品。'),
('ko', 'sold_out_removed_with_suggestions', '품절되었습니다. 대신 주문할 수 있는 후보를 표시했습니다.'),

('ja', 'availability_suggest_title', 'こちらなら提供できます'),
('en', 'availability_suggest_title', 'Available alternatives'),
('zh-Hans', 'availability_suggest_title', '这些现在可以提供'),
('zh-Hant', 'availability_suggest_title', '這些現在可以提供'),
('ko', 'availability_suggest_title', '대신 주문할 수 있어요'),

('ja', 'availability_suggest_sub', '品切れで外れた品の代わりに、今注文できる候補です。'),
('en', 'availability_suggest_sub', 'These are available now instead of the sold-out item.'),
('zh-Hans', 'availability_suggest_sub', '这是售罄菜品的可点替代选择。'),
('zh-Hant', 'availability_suggest_sub', '這是售罄菜品的可點替代選擇。'),
('ko', 'availability_suggest_sub', '품절된 메뉴 대신 지금 주문할 수 있는 후보입니다.'),

('ja', 'smart_suggest_title', 'もう一品いかがですか'),
('en', 'smart_suggest_title', 'Add one more?'),
('zh-Hans', 'smart_suggest_title', '要不要再加一道？'),
('zh-Hant', 'smart_suggest_title', '要不要再加一道？'),
('ko', 'smart_suggest_title', '하나 더 추가할까요?'),

('ja', 'smart_suggest_sub', 'お店全体でよく出ているおすすめです。'),
('en', 'smart_suggest_sub', 'Popular recommendations from this store.'),
('zh-Hans', 'smart_suggest_sub', '这是本店人气推荐。'),
('zh-Hant', 'smart_suggest_sub', '這是本店人氣推薦。'),
('ko', 'smart_suggest_sub', '매장에서 자주 주문되는 추천 메뉴입니다.'),

('ja', 'filter_empty', '条件に合うメニューがありません'),
('en', 'filter_empty', 'No menu items match these filters'),
('zh-Hans', 'filter_empty', '没有符合条件的菜品'),
('zh-Hant', 'filter_empty', '沒有符合條件的菜品'),
('ko', 'filter_empty', '조건에 맞는 메뉴가 없습니다'),

('ja', 'plan_order_limit', '1回{count}品まで'),
('en', 'plan_order_limit', 'Up to {count} items per order'),
('zh-Hans', 'plan_order_limit', '每次最多{count}项'),
('zh-Hant', 'plan_order_limit', '每次最多{count}項'),
('ko', 'plan_order_limit', '1회 최대 {count}개'),

('ja', 'lo_remaining_min', 'LOまで {min}分'),
('en', 'lo_remaining_min', '{min} min until last order'),
('zh-Hans', 'lo_remaining_min', '距离最后点单{min}分钟'),
('zh-Hant', 'lo_remaining_min', '距離最後點餐{min}分鐘'),
('ko', 'lo_remaining_min', '라스트오더까지 {min}분'),

('ja', 'course_progress', '現在 {current}/{total}品目・残り{remaining}品目・閲覧専用'),
('en', 'course_progress', 'Now {current}/{total}, {remaining} remaining, view only'),
('zh-Hans', 'course_progress', '当前 {current}/{total} 道，还剩 {remaining} 道，仅查看'),
('zh-Hant', 'course_progress', '目前 {current}/{total} 道，還剩 {remaining} 道，僅查看'),
('ko', 'course_progress', '현재 {current}/{total}품목, 남은 {remaining}품목, 보기 전용')
ON DUPLICATE KEY UPDATE msg_value = VALUES(msg_value);
