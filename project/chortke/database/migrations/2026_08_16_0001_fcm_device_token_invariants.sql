-- FCM token ownership and concurrent upsert invariants.
-- One device platform row per user; dead-token purge also needs an indexed token lookup.
CREATE UNIQUE INDEX IF NOT EXISTS `uq_user_devices_user_platform`
  ON `user_devices` (`user_id`, `platform`);
CREATE INDEX IF NOT EXISTS `idx_user_devices_fcm_token`
  ON `user_devices` (`fcm_token`(191));
