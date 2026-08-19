SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- Migration: Create adtube_views execution table
-- Purpose: Separate execution tracking for AdTube (video watch) ads
-- FK: ad_id → ads.id (unified catalog)
-- ============================================================================

CREATE TABLE IF NOT EXISTS adtube_views (
    id                  INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ad_id               INT(10) UNSIGNED NOT NULL,
    executor_id         INT(10) UNSIGNED NOT NULL,
    status              VARCHAR(50) DEFAULT 'pending' COMMENT 'pending, watching, completed, rejected, disputed',
    watch_time          INT(10) UNSIGNED DEFAULT 0 COMMENT 'seconds actually watched',
    progress_percent    TINYINT UNSIGNED DEFAULT 0 COMMENT '0-100 playback progress',
    video_duration      INT(10) UNSIGNED DEFAULT 0 COMMENT 'total video length in seconds',
    playback_speed      DECIMAL(3,2) DEFAULT 1.00 COMMENT 'detected playback speed',
    ip_address          VARCHAR(45) DEFAULT NULL,
    user_agent          TEXT DEFAULT NULL,
    idempotency_key     VARCHAR(100) DEFAULT NULL UNIQUE,
    reward_amount       DECIMAL(24,4) DEFAULT 0.0000,
    reward_currency     VARCHAR(10) DEFAULT 'irt',
    reward_paid         TINYINT(1) DEFAULT 0,
    started_at          TIMESTAMP NULL DEFAULT NULL,
    completed_at        TIMESTAMP NULL DEFAULT NULL,
    reviewed_at         TIMESTAMP NULL DEFAULT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Virtual column for conditional unique constraint compatible with MySQL/MariaDB
    active_executor_id  INT(10) UNSIGNED GENERATED ALWAYS AS (IF(status IN ('pending', 'watching'), executor_id, NULL)) VIRTUAL,

    -- Foreign key to unified ads catalog
    CONSTRAINT fk_adtube_views_ad_id FOREIGN KEY (ad_id) REFERENCES ads(id) ON DELETE CASCADE,
    -- Indexing for performance
    INDEX idx_adtube_views_ad_executor (ad_id, executor_id),
    INDEX idx_adtube_views_status (status),
    INDEX idx_adtube_views_executor (executor_id),
    -- Prevent duplicate active executions per user per ad
    UNIQUE KEY idx_adtube_views_unique_active (ad_id, active_executor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Execution records for AdTube (video watch) advertisements — separate from social_task_executions due to domain-specific metrics (watch_time, progress_percent)';

SET FOREIGN_KEY_CHECKS = 1;
