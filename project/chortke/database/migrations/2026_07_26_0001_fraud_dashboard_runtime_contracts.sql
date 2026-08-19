-- The fraud dashboard records the assigned reviewer and the intelligence services
-- require durable stores for their explicit domain cache.
ALTER TABLE fraud_alerts
    ADD COLUMN IF NOT EXISTS assigned_to INT UNSIGNED NULL AFTER status,
    ADD KEY IF NOT EXISTS idx_fraud_alerts_assigned_status (assigned_to, status);

CREATE TABLE IF NOT EXISTS disposable_domains (
    domain VARCHAR(255) NOT NULL PRIMARY KEY,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
