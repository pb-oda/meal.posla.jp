-- ============================================================
-- SELF-MENU-GUIDANCE: セルフメニュー安全確認/代替/併売導線
-- ============================================================
-- 目的:
--   - 品切れ時の手動代替設定を店舗側で持てるようにする
--   - 顧客画面のアレルギー確認/オプション/最終確認/併売UI翻訳を登録する
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS menu_alternatives (
  id VARCHAR(36) NOT NULL PRIMARY KEY,
  store_id VARCHAR(36) NOT NULL,
  source_item_id VARCHAR(36) NOT NULL,
  source_type ENUM('template','local') NOT NULL DEFAULT 'template',
  alternative_item_id VARCHAR(36) NOT NULL,
  alternative_source ENUM('template','local') NOT NULL DEFAULT 'template',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_store_source_alt (store_id, source_item_id, alternative_item_id),
  KEY idx_store_source (store_id, source_item_id),
  KEY idx_store_alt (store_id, alternative_item_id),
  CONSTRAINT menu_alternatives_store_fk FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ui_translations (lang, msg_key, msg_value) VALUES
('ja', 'allergy_staff_check_desc', 'アレルギーは店舗での確認が必要です。不安な場合は注文前にスタッフへ確認してください。'),
('en', 'allergy_staff_check_desc', 'Allergy safety must be confirmed by the restaurant. If unsure, please ask staff before ordering.'),
('zh-Hans', 'allergy_staff_check_desc', '过敏原安全需要由店员确认。如有不安，请在下单前咨询店员。'),
('zh-Hant', 'allergy_staff_check_desc', '過敏原安全需要由店員確認。如有疑慮，請在下單前詢問店員。'),
('ko', 'allergy_staff_check_desc', '알레르기 안전은 매장 확인이 필요합니다. 불안하면 주문 전에 직원에게 확인해 주세요.'),

('ja', 'allergy_staff_check_button', 'スタッフに確認する'),
('en', 'allergy_staff_check_button', 'Ask staff'),
('zh-Hans', 'allergy_staff_check_button', '咨询店员'),
('zh-Hant', 'allergy_staff_check_button', '詢問店員'),
('ko', 'allergy_staff_check_button', '직원에게 확인'),

('ja', 'allergy_call_staff_hint', 'ヘッダーの呼び出しからスタッフへ確認してください'),
('en', 'allergy_call_staff_hint', 'Please use the call button in the header to ask staff.'),
('zh-Hans', 'allergy_call_staff_hint', '请使用顶部的呼叫按钮咨询店员。'),
('zh-Hant', 'allergy_call_staff_hint', '請使用上方的呼叫按鈕詢問店員。'),
('ko', 'allergy_call_staff_hint', '상단 호출 버튼으로 직원에게 확인해 주세요.'),

('ja', 'cs_reason_allergy_check', 'アレルギー確認をお願いします'),
('en', 'cs_reason_allergy_check', 'Please check allergy information'),
('zh-Hans', 'cs_reason_allergy_check', '请确认过敏原信息'),
('zh-Hant', 'cs_reason_allergy_check', '請確認過敏原資訊'),
('ko', 'cs_reason_allergy_check', '알레르기 확인 부탁드립니다'),

('ja', 'option_required_detail', '必須: {min}つ選択'),
('en', 'option_required_detail', 'Required: choose {min}'),
('zh-Hans', 'option_required_detail', '必选：选择{min}项'),
('zh-Hant', 'option_required_detail', '必選：選擇{min}項'),
('ko', 'option_required_detail', '필수: {min}개 선택'),

('ja', 'option_optional_detail', '任意'),
('en', 'option_optional_detail', 'Optional'),
('zh-Hans', 'option_optional_detail', '可选'),
('zh-Hant', 'option_optional_detail', '可選'),
('ko', 'option_optional_detail', '선택'),

('ja', 'option_max_detail', '{max}つまで'),
('en', 'option_max_detail', 'Up to {max}'),
('zh-Hans', 'option_max_detail', '最多{max}项'),
('zh-Hant', 'option_max_detail', '最多{max}項'),
('ko', 'option_max_detail', '최대 {max}개'),

('ja', 'option_select_count', '選択 {count}'),
('en', 'option_select_count', 'Selected {count}'),
('zh-Hans', 'option_select_count', '已选{count}'),
('zh-Hant', 'option_select_count', '已選{count}'),
('ko', 'option_select_count', '선택 {count}'),

('ja', 'option_sold_out', '売り切れ'),
('en', 'option_sold_out', 'Sold out'),
('zh-Hans', 'option_sold_out', '售罄'),
('zh-Hant', 'option_sold_out', '售罄'),
('ko', 'option_sold_out', '품절'),

('ja', 'option_required_none_available', '選択できる候補がありません'),
('en', 'option_required_none_available', 'No available choices'),
('zh-Hans', 'option_required_none_available', '没有可选项'),
('zh-Hant', 'option_required_none_available', '沒有可選項'),
('ko', 'option_required_none_available', '선택 가능한 항목이 없습니다'),

('ja', 'option_required_missing', '必須項目を選択してください'),
('en', 'option_required_missing', 'Please choose the required option'),
('zh-Hans', 'option_required_missing', '请选择必选项'),
('zh-Hant', 'option_required_missing', '請選擇必選項'),
('ko', 'option_required_missing', '필수 항목을 선택해 주세요'),

('ja', 'option_max_reached', '選択上限に達しています'),
('en', 'option_max_reached', 'Selection limit reached'),
('zh-Hans', 'option_max_reached', '已达到选择上限'),
('zh-Hant', 'option_max_reached', '已達選擇上限'),
('ko', 'option_max_reached', '선택 한도에 도달했습니다'),

('ja', 'option_unavailable_selected', '選択できないオプションが含まれています'),
('en', 'option_unavailable_selected', 'Unavailable option selected'),
('zh-Hans', 'option_unavailable_selected', '包含不可选择的选项'),
('zh-Hant', 'option_unavailable_selected', '包含不可選擇的選項'),
('ko', 'option_unavailable_selected', '선택할 수 없는 옵션이 포함되어 있습니다'),

('ja', 'final_check_title', '送信前の確認'),
('en', 'final_check_title', 'Before sending'),
('zh-Hans', 'final_check_title', '提交前确认'),
('zh-Hant', 'final_check_title', '送出前確認'),
('ko', 'final_check_title', '전송 전 확인'),

('ja', 'final_check_last_order', 'ラストオーダー終了のため送信できません'),
('en', 'final_check_last_order', 'Ordering is closed'),
('zh-Hans', 'final_check_last_order', '已停止点单，无法提交'),
('zh-Hant', 'final_check_last_order', '已停止點餐，無法送出'),
('ko', 'final_check_last_order', '라스트오더가 종료되어 전송할 수 없습니다'),

('ja', 'final_check_sold_out', '品切れ・提供停止: {items}'),
('en', 'final_check_sold_out', 'Sold out or unavailable: {items}'),
('zh-Hans', 'final_check_sold_out', '售罄或暂停供应：{items}'),
('zh-Hant', 'final_check_sold_out', '售罄或暫停供應：{items}'),
('ko', 'final_check_sold_out', '품절/제공 중지: {items}'),

('ja', 'final_check_option_sold_out', '選択できないオプション: {items}'),
('en', 'final_check_option_sold_out', 'Unavailable options: {items}'),
('zh-Hans', 'final_check_option_sold_out', '不可选择的选项：{items}'),
('zh-Hant', 'final_check_option_sold_out', '不可選擇的選項：{items}'),
('ko', 'final_check_option_sold_out', '선택할 수 없는 옵션: {items}'),

('ja', 'final_check_max_items', '1回の注文上限 {max}品を超えています'),
('en', 'final_check_max_items', 'This exceeds the limit of {max} items per order'),
('zh-Hans', 'final_check_max_items', '超过每次订单上限{max}项'),
('zh-Hant', 'final_check_max_items', '超過每次訂單上限{max}項'),
('ko', 'final_check_max_items', '1회 주문 한도 {max}개를 초과했습니다'),

('ja', 'final_check_max_amount', '1回の注文上限金額 {max} を超えています'),
('en', 'final_check_max_amount', 'This exceeds the per-order amount limit of {max}'),
('zh-Hans', 'final_check_max_amount', '超过每次订单金额上限{max}'),
('zh-Hant', 'final_check_max_amount', '超過每次訂單金額上限{max}'),
('ko', 'final_check_max_amount', '1회 주문 금액 한도 {max}를 초과했습니다'),

('ja', 'final_check_allergy', 'アレルギー情報: {items}。不安な場合はスタッフに確認してください。'),
('en', 'final_check_allergy', 'Allergy information: {items}. If unsure, please ask staff.'),
('zh-Hans', 'final_check_allergy', '过敏原信息：{items}。如有不安请咨询店员。'),
('zh-Hant', 'final_check_allergy', '過敏原資訊：{items}。如有疑慮請詢問店員。'),
('ko', 'final_check_allergy', '알레르기 정보: {items}. 불안하면 직원에게 확인해 주세요.'),

('ja', 'final_check_long_wait', '提供時間が長めの品目: {items}'),
('en', 'final_check_long_wait', 'Longer wait items: {items}'),
('zh-Hans', 'final_check_long_wait', '等待时间较长的菜品：{items}'),
('zh-Hant', 'final_check_long_wait', '等待時間較長的品項：{items}'),
('ko', 'final_check_long_wait', '제공 시간이 긴 메뉴: {items}'),

('ja', 'final_check_ok', '品切れ・上限・アレルギー表示を確認済みです'),
('en', 'final_check_ok', 'Availability, limits, and allergy notes checked'),
('zh-Hans', 'final_check_ok', '已确认售罄、上限和过敏原提示'),
('zh-Hant', 'final_check_ok', '已確認售罄、上限與過敏原提示'),
('ko', 'final_check_ok', '품절, 한도, 알레르기 표시를 확인했습니다'),

('ja', 'final_check_blocking_alert', '送信前に確認が必要です。'),
('en', 'final_check_blocking_alert', 'Please fix this before sending.'),
('zh-Hans', 'final_check_blocking_alert', '提交前需要确认。'),
('zh-Hant', 'final_check_blocking_alert', '送出前需要確認。'),
('ko', 'final_check_blocking_alert', '전송 전에 확인이 필요합니다.'),

('ja', 'pair_suggest_title', 'この料理に合う一品'),
('en', 'pair_suggest_title', 'Pairs well with this'),
('zh-Hans', 'pair_suggest_title', '适合搭配这道菜'),
('zh-Hant', 'pair_suggest_title', '適合搭配這道菜'),
('ko', 'pair_suggest_title', '이 메뉴와 잘 어울려요'),

('ja', 'pair_suggest_sub', '一緒に注文されることが多い組み合わせです。'),
('en', 'pair_suggest_sub', 'Often ordered together based on past orders.'),
('zh-Hans', 'pair_suggest_sub', '基于订单记录，经常一起点的组合。'),
('zh-Hant', 'pair_suggest_sub', '根據訂單紀錄，常一起點的組合。'),
('ko', 'pair_suggest_sub', '이전 주문에서 함께 주문된 경우가 많은 조합입니다。')
ON DUPLICATE KEY UPDATE msg_value = VALUES(msg_value);
