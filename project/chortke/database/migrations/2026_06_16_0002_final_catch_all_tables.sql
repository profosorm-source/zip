-- ═══════════════════════════════════════════════════════════════════
-- FINAL CATCH-ALL TABLES
-- Any table referenced in Controllers, Services, Commands, Jobs, etc.
-- that might have been missed in previous migrations
-- ═══════════════════════════════════════════════════════════════════

SET FOREIGN_KEY_CHECKS = 0;

-- Alert Rules
CREATE TABLE IF NOT EXISTS `alert_rules` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `condition` JSON,
    `action` VARCHAR(100),
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Banner
CREATE TABLE IF NOT EXISTS `banner` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255),
    `image` VARCHAR(255),
    `link` VARCHAR(500),
    `position` VARCHAR(50),
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Banner Placements
CREATE TABLE IF NOT EXISTS `banner_placements` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `banner_id` INT UNSIGNED,
    `placement` VARCHAR(100),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Coupons
CREATE TABLE IF NOT EXISTS `coupons` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) UNIQUE NOT NULL,
    `discount_type` ENUM('percent','fixed') DEFAULT 'percent',
    `discount_value` DECIMAL(10,2),
    `max_uses` INT DEFAULT 0,
    `used_count` INT DEFAULT 0,
    `expires_at` TIMESTAMP NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Error Logs
CREATE TABLE IF NOT EXISTS `error_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `level` VARCHAR(20),
    `message` TEXT,
    `context` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Events (for event sourcing if used)
CREATE TABLE IF NOT EXISTS `events` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `event_type` VARCHAR(100) NOT NULL,
    `payload` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Failed Jobs
CREATE TABLE IF NOT EXISTS `failed_jobs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `uuid` VARCHAR(255) UNIQUE,
    `connection` TEXT,
    `queue` TEXT,
    `payload` LONGTEXT,
    `exception` LONGTEXT,
    `failed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Message Attachments
CREATE TABLE IF NOT EXISTS `message_attachments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `message_id` BIGINT UNSIGNED,
    `file_path` VARCHAR(500),
    `file_type` VARCHAR(50),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notification Channels
CREATE TABLE IF NOT EXISTS `notification_channels` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `type` VARCHAR(50),
    `config` JSON,
    `is_active` TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notification History
CREATE TABLE IF NOT EXISTS `notification_history` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED,
    `channel` VARCHAR(50),
    `title` VARCHAR(255),
    `body` TEXT,
    `status` VARCHAR(20) DEFAULT 'sent',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notification Templates
CREATE TABLE IF NOT EXISTS `notification_templates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) UNIQUE NOT NULL,
    `title` VARCHAR(255),
    `body` TEXT,
    `variables` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payment Logs
CREATE TABLE IF NOT EXISTS `payment_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED,
    `gateway` VARCHAR(50),
    `amount` DECIMAL(24,8),
    `status` VARCHAR(20),
    `response` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Performance Logs
CREATE TABLE IF NOT EXISTS `performance_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `metric` VARCHAR(100),
    `value` DECIMAL(12,4),
    `context` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Queues
CREATE TABLE IF NOT EXISTS `queues` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `queue` VARCHAR(255),
    `payload` LONGTEXT,
    `attempts` INT DEFAULT 0,
    `reserved_at` TIMESTAMP NULL,
    `available_at` TIMESTAMP,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rate Limits
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(255) NOT NULL,
    `attempts` INT DEFAULT 0,
    `expires_at` TIMESTAMP NULL,
    UNIQUE KEY `uq_rate_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SEO Ads
CREATE TABLE IF NOT EXISTS `seo_ads` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED,
    `keyword` VARCHAR(255),
    `url` VARCHAR(500),
    `status` VARCHAR(20) DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Social Ads
CREATE TABLE IF NOT EXISTS `social_ads` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED,
    `platform` VARCHAR(50),
    `content` TEXT,
    `status` VARCHAR(20) DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Story Orders
CREATE TABLE IF NOT EXISTS `story_orders` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT UNSIGNED,
    `influencer_id` INT UNSIGNED,
    `price` DECIMAL(24,4),
    `status` VARCHAR(50) DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User Blocks
CREATE TABLE IF NOT EXISTS `user_blocks` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `blocker_id` INT UNSIGNED NOT NULL,
    `blocked_id` INT UNSIGNED NOT NULL,
    `reason` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_block` (`blocker_id`, `blocked_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User Conversations
CREATE TABLE IF NOT EXISTS `user_conversations` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user1_id` INT UNSIGNED NOT NULL,
    `user2_id` INT UNSIGNED NOT NULL,
    `last_message_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ═══════════════════════════════════════════════════════════════════
-- END OF FINAL CATCH-ALL
-- ═══════════════════════════════════════════════════════════════════