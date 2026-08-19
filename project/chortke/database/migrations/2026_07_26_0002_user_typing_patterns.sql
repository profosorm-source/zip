-- Canonical storage consumed by IpAndDeviceModel and BehavioralBiometricsService.
CREATE TABLE IF NOT EXISTS user_typing_patterns (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    avg_interval DECIMAL(12,4) NOT NULL,
    stddev_interval DECIMAL(12,4) NOT NULL,
    avg_hold_time DECIMAL(12,4) NOT NULL,
    keystroke_count INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_typing_patterns_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
