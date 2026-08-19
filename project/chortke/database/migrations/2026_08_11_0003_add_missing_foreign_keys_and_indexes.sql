-- 2026_08_11_0003_add_missing_foreign_keys_and_indexes.sql
-- Account Deletion Log Enum Fix, Missing Indexes & Foreign Keys (Findings #12, #13, #14, #15)

-- 1. account_deletion_logs Status & Indexes (Findings #14 & #15)
ALTER TABLE account_deletion_logs MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'requested';
CREATE INDEX IF NOT EXISTS idx_adl_status_expires ON account_deletion_logs (status, expires_at);
CREATE INDEX IF NOT EXISTS idx_adl_user ON account_deletion_logs (user_id);

-- 2. user_devices Index (Finding #13)
CREATE INDEX IF NOT EXISTS idx_ud_user_id ON user_devices (user_id);

-- 3. Foreign Keys on Sensitive Relational Tables (Finding #12)
ALTER TABLE api_tokens ADD CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE user_devices ADD CONSTRAINT fk_user_devices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE direct_messages ADD CONSTRAINT fk_direct_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE direct_messages ADD CONSTRAINT fk_direct_messages_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE data_exports ADD CONSTRAINT fk_data_exports_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE account_deletion_logs ADD CONSTRAINT fk_account_deletion_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE notifications ADD CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE realtime_messages ADD CONSTRAINT fk_realtime_messages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
