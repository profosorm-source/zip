-- 2026_08_11_0002_optimize_indexes_and_foreign_keys.sql
-- Performance Optimization Indexes & Foreign Keys (Findings #6, #8, #9, #10, #11)

-- 1. Realtime Messages Indexes (Finding #8)
CREATE INDEX IF NOT EXISTS idx_realtime_room_id ON realtime_messages (room, id);
CREATE INDEX IF NOT EXISTS idx_realtime_user_read ON realtime_messages (user_id, is_read, id);
CREATE INDEX IF NOT EXISTS idx_realtime_expires ON realtime_messages (expires_at);

-- 2. Queue Job Claim Index (Finding #9)
CREATE INDEX IF NOT EXISTS idx_queue_claim ON queues (queue, available_at, reserved_at, id);

-- 3. Rate Limit Requests Lookup Index (Finding #10)
CREATE INDEX IF NOT EXISTS idx_rate_limit_lookup ON rate_limit_requests (identifier_key, action, created_at);

-- 4. Outbox Events Composite Pickup Index (Finding #6)
CREATE INDEX IF NOT EXISTS idx_outbox_pickup ON outbox_events (status, available_at, attempts);

-- 5. Foreign Key Constraints (Finding #11)
ALTER TABLE social_task_executions ADD CONSTRAINT fk_social_task_executions_ad_id FOREIGN KEY (ad_id) REFERENCES social_ads(id) ON DELETE CASCADE;
ALTER TABLE message_attachments ADD CONSTRAINT fk_message_attachments_message_id FOREIGN KEY (message_id) REFERENCES direct_messages(id) ON DELETE CASCADE;
