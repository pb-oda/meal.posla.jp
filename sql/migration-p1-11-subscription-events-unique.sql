-- P1-11: subscription_events.stripe_event_id に UNIQUE INDEX 追加
-- 背景: Stripe の webhook 再送で同一イベントが2回処理されると、
--       customer.subscription.updated 等で plan が誤ロールバックする可能性がある。
-- 対応: stripe_event_id に UNIQUE 制約を貼り、webhook.php 側で重複検知時は 200 即返却。
--
-- 注意: 既存の idx_stripe_event_id (non-unique) は最小変更原則のため温存。
--       同一カラムに2つの INDEX が並ぶが、行数小・性能影響皆無のため許容。
--       INDEX 整理は別タスク (P1-XX) で検討。
--
-- 注意: stripe_event_id は NULL 許容のままだが、MySQL の UNIQUE INDEX は
--       複数の NULL を許す挙動なので NULL 行があっても ALTER 可能。

ALTER TABLE subscription_events
  ADD UNIQUE INDEX uniq_stripe_event_id (stripe_event_id);
