SET FOREIGN_KEY_CHECKS = 0;

-- ─── 2026_06_12_0046_full_enterprise_schema_reconciliation.sql ───
-- Permanent SQL Migration to Reconcile All Enterprise Bounded Domain Schemas

-- 1. users table schema fixes
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `kyc_status` VARCHAR(50) DEFAULT 'unverified' NULL;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `tier` VARCHAR(50) DEFAULT 'SILVER' NULL;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `avatar` VARCHAR(255) DEFAULT '/images/default-avatar.png' NULL;

-- 1.5 kyc_verifications table fixes
ALTER TABLE `kyc_verifications` ADD COLUMN IF NOT EXISTS `rejection_reason` TEXT NULL;
ALTER TABLE `kyc_verifications` ADD COLUMN IF NOT EXISTS `admin_note` TEXT NULL;
ALTER TABLE `kyc_verifications` ADD COLUMN IF NOT EXISTS `first_name` VARCHAR(100) NULL;
ALTER TABLE `kyc_verifications` ADD COLUMN IF NOT EXISTS `last_name` VARCHAR(100) NULL;
ALTER TABLE `kyc_verifications` MODIFY COLUMN `national_code` TEXT NULL;
ALTER TABLE `kyc_verifications` ADD COLUMN IF NOT EXISTS `national_code_hash` VARCHAR(128) NULL;
ALTER TABLE `kyc_verifications` ADD COLUMN IF NOT EXISTS `birth_date` VARCHAR(255) NULL;
ALTER TABLE `kyc_verifications` ADD COLUMN IF NOT EXISTS `verification_image` VARCHAR(255) NULL;
ALTER TABLE `kyc_verifications` ADD COLUMN IF NOT EXISTS `under_review_by` INT(10) UNSIGNED NULL;
ALTER TABLE `kyc_verifications` ADD COLUMN IF NOT EXISTS `review_started_at` TIMESTAMP NULL;
ALTER TABLE `kyc_verifications` ADD COLUMN IF NOT EXISTS `reviewed_by` INT(10) UNSIGNED NULL;
ALTER TABLE `kyc_verifications` ADD COLUMN IF NOT EXISTS `reviewed_at` TIMESTAMP NULL;

-- 2. bank_cards table schema fixes
ALTER TABLE `bank_cards` ADD COLUMN IF NOT EXISTS `owner_name` VARCHAR(255) NULL AFTER `card_number`;
ALTER TABLE `bank_cards` ADD COLUMN IF NOT EXISTS `iban` VARCHAR(30) NULL AFTER `sheba`;

-- 3. tickets table schema fixes
ALTER TABLE `tickets` ADD COLUMN IF NOT EXISTS `category_id` INT(10) UNSIGNED NULL AFTER `user_id`;
ALTER TABLE `tickets` ADD COLUMN IF NOT EXISTS `last_reply_by` VARCHAR(50) DEFAULT 'user' NULL;
ALTER TABLE `tickets` ADD COLUMN IF NOT EXISTS `last_reply_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE `ticket_categories` ADD COLUMN IF NOT EXISTS `icon` VARCHAR(50) DEFAULT 'support_agent' NULL;

-- 4. lottery_rounds table schema fixes
ALTER TABLE `lottery_rounds` MODIFY COLUMN `status` VARCHAR(50) DEFAULT 'active' NULL;
ALTER TABLE `lottery_rounds` ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) DEFAULT 0 NOT NULL;
ALTER TABLE `lottery_rounds` ADD COLUMN IF NOT EXISTS `start_date` TIMESTAMP NULL;
ALTER TABLE `lottery_rounds` ADD COLUMN IF NOT EXISTS `end_date` TIMESTAMP NULL;
ALTER TABLE `lottery_rounds` ADD COLUMN IF NOT EXISTS `prize_amount` DECIMAL(24,4) DEFAULT 0 NULL;

-- 4. lottery_participations table schema fixes
ALTER TABLE `lottery_participations` ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) DEFAULT 0 NOT NULL;

-- 4.5 vitrine_listings table fixes
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `description` TEXT NULL;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `category` VARCHAR(100) NULL;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `platform` VARCHAR(100) NULL;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `type` VARCHAR(50) DEFAULT 'sell' NULL;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `deleted_at` TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `rejection_reason` TEXT NULL;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `escrow_hold_id` VARCHAR(100) NULL;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `escrow_status` VARCHAR(50) NULL;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `buyer_id` INT(10) UNSIGNED NULL;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `bought_at` TIMESTAMP NULL;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `seller_id` INT(10) UNSIGNED NULL;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `price_usdt` DECIMAL(24,4) DEFAULT 100 NULL;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `member_count` INT(10) UNSIGNED DEFAULT 50000 NULL;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `listing_type` VARCHAR(50) DEFAULT 'sell' NULL;

-- 5. influencer_verifications table schema fixes
ALTER TABLE `influencer_verifications` ADD COLUMN IF NOT EXISTS `profile_id` INT(10) UNSIGNED NULL AFTER `influencer_id`;

-- 6. influencer_profiles table schema fixes
ALTER TABLE `influencer_profiles` ADD COLUMN IF NOT EXISTS `deleted_at` TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `influencer_profiles` ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) DEFAULT 1 NOT NULL;
ALTER TABLE `influencer_profiles` ADD COLUMN IF NOT EXISTS `priority` INT(10) UNSIGNED DEFAULT 0 NOT NULL;
ALTER TABLE `influencer_profiles` ADD COLUMN IF NOT EXISTS `completed_orders` INT(10) UNSIGNED DEFAULT 0 NOT NULL;

-- 7. investments table schema fixes
ALTER TABLE `investments` ADD COLUMN IF NOT EXISTS `deleted_at` TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `investments` ADD COLUMN IF NOT EXISTS `current_balance` DECIMAL(24,4) DEFAULT 1025.5000 NULL AFTER `amount`;
ALTER TABLE `investments` ADD COLUMN IF NOT EXISTS `last_profit_date` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE `investments` ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- 8. investment_plans table schema fixes
ALTER TABLE `investment_plans` ADD COLUMN IF NOT EXISTS `deleted_at` TIMESTAMP NULL DEFAULT NULL;

-- 9. investment_profits table schema fixes
ALTER TABLE `investment_profits` ADD COLUMN IF NOT EXISTS `user_id` INT(10) UNSIGNED NULL AFTER `investment_id`;
ALTER TABLE `investment_profits` ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) DEFAULT 0 NOT NULL;

-- 10. investment_withdrawals table schema fixes
ALTER TABLE `investment_withdrawals` ADD COLUMN IF NOT EXISTS `user_id` INT(10) UNSIGNED NULL AFTER `investment_id`;
ALTER TABLE `investment_withdrawals` ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) DEFAULT 0 NOT NULL;

-- 11. trading_records table schema fixes
ALTER TABLE `trading_records` ADD COLUMN IF NOT EXISTS `status` VARCHAR(50) DEFAULT 'open' NULL;
ALTER TABLE `trading_records` ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) DEFAULT 0 NOT NULL;
ALTER TABLE `trading_records` ADD COLUMN IF NOT EXISTS `open_time` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE `trading_records` ADD COLUMN IF NOT EXISTS `close_time` TIMESTAMP NULL DEFAULT NULL;

-- 11.5 prediction games
ALTER TABLE `prediction_games` ADD COLUMN IF NOT EXISTS `deleted_at` TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `prediction_games` ADD COLUMN IF NOT EXISTS `team1_logo` VARCHAR(255) NULL;
ALTER TABLE `prediction_games` ADD COLUMN IF NOT EXISTS `team2_logo` VARCHAR(255) NULL;
ALTER TABLE `prediction_games` ADD COLUMN IF NOT EXISTS `total_pool` DECIMAL(24,4) DEFAULT 10000000 NULL;
ALTER TABLE `prediction_games` ADD COLUMN IF NOT EXISTS `bet_deadline` TIMESTAMP NULL DEFAULT DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 2 DAY);
ALTER TABLE `prediction_games` ADD COLUMN IF NOT EXISTS `match_date` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE `prediction_games` ADD COLUMN IF NOT EXISTS `min_bet_usdt` DECIMAL(24,4) DEFAULT 1 NULL;
ALTER TABLE `prediction_games` ADD COLUMN IF NOT EXISTS `max_bet_usdt` DECIMAL(24,4) DEFAULT 1000 NULL;
ALTER TABLE `prediction_bets` ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) DEFAULT 0 NOT NULL;
ALTER TABLE `prediction_bets` ADD COLUMN IF NOT EXISTS `amount_usdt` DECIMAL(24,4) DEFAULT 10 NULL;

-- 12. login_attempts table
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NULL,
    `email` VARCHAR(255) NULL,
    `ip_address` VARCHAR(64) NULL,
    `user_agent` TEXT NULL,
    `status` VARCHAR(50) DEFAULT 'success' NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. sentry monitoring error logs & failed jobs tables
CREATE TABLE IF NOT EXISTS `error_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `exception_class` VARCHAR(255) NULL,
    `message` TEXT NULL,
    `file` VARCHAR(255) NULL,
    `line` INT NULL,
    `trace` TEXT NULL,
    `status` VARCHAR(50) DEFAULT 'unresolved' NULL,
    `occurrences` INT UNSIGNED DEFAULT 1 NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `failed_jobs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `connection` VARCHAR(255) NULL,
    `queue` VARCHAR(255) NULL,
    `payload` LONGTEXT NULL,
    `exception` LONGTEXT NULL,
    `failed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `performance_transactions` ADD COLUMN IF NOT EXISTS `duration` DECIMAL(10,2) DEFAULT 50 NULL;
ALTER TABLE `performance_transactions` ADD COLUMN IF NOT EXISTS `query_count` INT(11) DEFAULT 2 NULL;

-- 13. site settings data for deposit verification
ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `user_id` INT(10) UNSIGNED DEFAULT 0 NULL;

ALTER TABLE `api_tokens` ADD COLUMN IF NOT EXISTS `secret_version` VARCHAR(20) DEFAULT 'v2';
ALTER TABLE `api_tokens` ADD COLUMN IF NOT EXISTS `scopes` VARCHAR(255) DEFAULT '*';
ALTER TABLE `api_tokens` ADD COLUMN IF NOT EXISTS `revoked` TINYINT(1) DEFAULT 0;
ALTER TABLE `api_tokens` ADD COLUMN IF NOT EXISTS `use_count` INT(10) UNSIGNED DEFAULT 0;
ALTER TABLE `api_tokens` ADD COLUMN IF NOT EXISTS `expires_at` TIMESTAMP NULL;
ALTER TABLE `api_tokens` ADD COLUMN IF NOT EXISTS `secret_version_expires_at` TIMESTAMP NULL;

ALTER TABLE `lottery_rounds` ADD COLUMN IF NOT EXISTS `ticket_price` DECIMAL(24,4) DEFAULT 5000.0000;
ALTER TABLE `lottery_rounds` ADD COLUMN IF NOT EXISTS `max_capacity` INT(10) UNSIGNED DEFAULT 1000;

ALTER TABLE `lottery_participations` ADD COLUMN IF NOT EXISTS `status` VARCHAR(50) DEFAULT 'active';
ALTER TABLE `lottery_participations` ADD COLUMN IF NOT EXISTS `chance_score` DECIMAL(24,4) DEFAULT 100.0000;
ALTER TABLE `lottery_participations` ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE `prediction_games` MODIFY COLUMN `status` ENUM('open','locked','closed','finished','cancelled') DEFAULT 'open';
ALTER TABLE `prediction_games` ADD COLUMN IF NOT EXISTS `commission_percent` DECIMAL(5,2) DEFAULT 5.00;
ALTER TABLE `prediction_games` ADD COLUMN IF NOT EXISTS `cancelled_at` TIMESTAMP NULL;
ALTER TABLE `prediction_games` ADD COLUMN IF NOT EXISTS `cancelled_by` INT(10) UNSIGNED NULL;
ALTER TABLE `prediction_games` ADD COLUMN IF NOT EXISTS `paid_at` TIMESTAMP NULL;
ALTER TABLE `prediction_games` ADD COLUMN IF NOT EXISTS `finished_at` TIMESTAMP NULL;
ALTER TABLE `prediction_games` ADD COLUMN IF NOT EXISTS `settled_by` INT(10) UNSIGNED NULL;
ALTER TABLE `prediction_games` ADD COLUMN IF NOT EXISTS `winners_paid` TINYINT(1) DEFAULT 0;

ALTER TABLE `prediction_bets` ADD COLUMN IF NOT EXISTS `payout_usdt` DECIMAL(24,4) DEFAULT 0.0000;
ALTER TABLE `prediction_bets` ADD COLUMN IF NOT EXISTS `settled_at` TIMESTAMP NULL;
ALTER TABLE `prediction_bets` ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE `audit_trail` ADD COLUMN IF NOT EXISTS `hash` VARCHAR(100) NULL;

ALTER TABLE `referral_commissions` ADD COLUMN IF NOT EXISTS `referred_user_id` INT(10) UNSIGNED NULL;
ALTER TABLE `referral_commissions` ADD COLUMN IF NOT EXISTS `source_type` VARCHAR(50) DEFAULT 'general';
ALTER TABLE `referral_commissions` ADD COLUMN IF NOT EXISTS `context` LONGTEXT NULL;
ALTER TABLE `referral_commissions` ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE `influencer_profiles` ADD COLUMN IF NOT EXISTS `follower_count` INT(10) UNSIGNED DEFAULT 0;
ALTER TABLE `influencer_profiles` ADD COLUMN IF NOT EXISTS `story_price_24h` DECIMAL(24,4) DEFAULT 0;
ALTER TABLE `influencer_profiles` ADD COLUMN IF NOT EXISTS `bio` TEXT NULL;
ALTER TABLE `influencer_profiles` ADD COLUMN IF NOT EXISTS `category` VARCHAR(100) NULL;
ALTER TABLE `influencer_profiles` ADD COLUMN IF NOT EXISTS `verification_code` VARCHAR(50) NULL;
ALTER TABLE `influencer_profiles` ADD COLUMN IF NOT EXISTS `verification_post_url` VARCHAR(2048) NULL;
ALTER TABLE `influencer_profiles` ADD COLUMN IF NOT EXISTS `page_url` VARCHAR(2048) NULL;
ALTER TABLE `influencer_profiles` ADD COLUMN IF NOT EXISTS `profile_image` VARCHAR(255) NULL;
ALTER TABLE `influencer_profiles` ADD COLUMN IF NOT EXISTS `engagement_rate` DECIMAL(5,2) DEFAULT 0;
ALTER TABLE `influencer_profiles` ADD COLUMN IF NOT EXISTS `post_price_24h` DECIMAL(24,4) DEFAULT 0;
ALTER TABLE `influencer_profiles` ADD COLUMN IF NOT EXISTS `post_price_48h` DECIMAL(24,4) DEFAULT 0;
ALTER TABLE `influencer_profiles` ADD COLUMN IF NOT EXISTS `post_price_72h` DECIMAL(24,4) DEFAULT 0;
ALTER TABLE `influencer_profiles` ADD COLUMN IF NOT EXISTS `currency` VARCHAR(10) DEFAULT 'irt';
ALTER TABLE `influencer_profiles` ADD COLUMN IF NOT EXISTS `total_orders` INT(10) UNSIGNED DEFAULT 0;
ALTER TABLE `influencer_profiles` ADD COLUMN IF NOT EXISTS `average_rating` DECIMAL(3,2) DEFAULT 5.00;
ALTER TABLE `influencer_profiles` ADD COLUMN IF NOT EXISTS `rejection_reason` TEXT NULL;
ALTER TABLE `influencer_profiles` ADD COLUMN IF NOT EXISTS `verified_by` INT(10) UNSIGNED NULL;
ALTER TABLE `influencer_profiles` ADD COLUMN IF NOT EXISTS `verified_at` TIMESTAMP NULL;
ALTER TABLE `influencer_profiles` ADD COLUMN IF NOT EXISTS `suspended_at` TIMESTAMP NULL;
ALTER TABLE `influencer_profiles` ADD COLUMN IF NOT EXISTS `suspended_reason` TEXT NULL;

ALTER TABLE `coupons` ADD COLUMN IF NOT EXISTS `usage_limit` INT(10) UNSIGNED NULL;
ALTER TABLE `coupons` ADD COLUMN IF NOT EXISTS `usage_count` INT(10) UNSIGNED DEFAULT 0;
ALTER TABLE `coupons` ADD COLUMN IF NOT EXISTS `applicable_to` VARCHAR(50) DEFAULT 'all';
ALTER TABLE `coupons` ADD COLUMN IF NOT EXISTS `max_discount` DECIMAL(24,4) NULL;
ALTER TABLE `coupons` ADD COLUMN IF NOT EXISTS `start_date` TIMESTAMP NULL;
ALTER TABLE `coupons` ADD COLUMN IF NOT EXISTS `end_date` TIMESTAMP NULL;
ALTER TABLE `coupons` ADD COLUMN IF NOT EXISTS `active` TINYINT(1) DEFAULT 1;
ALTER TABLE `coupons` ADD COLUMN IF NOT EXISTS `min_purchase` DECIMAL(24,4) DEFAULT 0;
ALTER TABLE `coupons` ADD COLUMN IF NOT EXISTS `deleted_at` TIMESTAMP NULL;

ALTER TABLE `coupon_redemptions` ADD COLUMN IF NOT EXISTS `original_amount` DECIMAL(24,4) DEFAULT 0;
ALTER TABLE `coupon_redemptions` ADD COLUMN IF NOT EXISTS `discount_amount` DECIMAL(24,4) DEFAULT 0;
ALTER TABLE `coupon_redemptions` ADD COLUMN IF NOT EXISTS `final_amount` DECIMAL(24,4) DEFAULT 0;
ALTER TABLE `coupon_redemptions` ADD COLUMN IF NOT EXISTS `currency` VARCHAR(10) DEFAULT 'irt';
ALTER TABLE `coupon_redemptions` ADD COLUMN IF NOT EXISTS `entity_type` VARCHAR(50) NULL;
ALTER TABLE `coupon_redemptions` ADD COLUMN IF NOT EXISTS `entity_id` INT(10) UNSIGNED NULL;
ALTER TABLE `coupon_redemptions` ADD COLUMN IF NOT EXISTS `ip_address` VARCHAR(50) NULL;
ALTER TABLE `coupon_redemptions` ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE `scheduled_payments` MODIFY COLUMN `frequency` VARCHAR(50) DEFAULT 'one_time';
ALTER TABLE `scheduled_payments` ADD COLUMN IF NOT EXISTS `description` VARCHAR(255) NULL;
ALTER TABLE `scheduled_payments` ADD COLUMN IF NOT EXISTS `metadata` LONGTEXT NULL;
ALTER TABLE `scheduled_payments` ADD COLUMN IF NOT EXISTS `idempotency_key` VARCHAR(128) NULL;

ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `order_type` VARCHAR(50) DEFAULT 'story';
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `duration_hours` INT(10) UNSIGNED DEFAULT 24;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `media_path` VARCHAR(255) NULL;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `caption` TEXT NULL;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `link` VARCHAR(2048) NULL;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `preferred_publish_time` VARCHAR(100) NULL;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `site_fee_percent` DECIMAL(5,2) DEFAULT 15.00;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `site_fee_amount` DECIMAL(24,4) DEFAULT 0;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `influencer_earning` DECIMAL(24,4) DEFAULT 0;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `payment_transaction_id` VARCHAR(100) NULL;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `payout_transaction_id` VARCHAR(100) NULL;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `peer_resolution_started_at` TIMESTAMP NULL;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `proof_screenshot` VARCHAR(255) NULL;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `proof_link` VARCHAR(2048) NULL;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `proof_notes` TEXT NULL;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `proof_submitted_at` TIMESTAMP NULL;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `buyer_check_notified_at` TIMESTAMP NULL;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `buyer_check_deadline` TIMESTAMP NULL;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `buyer_confirmed_at` TIMESTAMP NULL;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `admin_note` TEXT NULL;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `reviewed_by` INT(10) UNSIGNED NULL;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `reviewed_at` TIMESTAMP NULL;

ALTER TABLE `disputes` ADD COLUMN IF NOT EXISTS `role` VARCHAR(50) DEFAULT 'customer';
ALTER TABLE `disputes` ADD COLUMN IF NOT EXISTS `admin_note` TEXT NULL;
ALTER TABLE `disputes` ADD COLUMN IF NOT EXISTS `read_at` TIMESTAMP NULL;

ALTER TABLE `influencer_verifications` ADD COLUMN IF NOT EXISTS `code` VARCHAR(50) NULL;
ALTER TABLE `influencer_verifications` ADD COLUMN IF NOT EXISTS `expires_at` TIMESTAMP NULL;
ALTER TABLE `influencer_verifications` ADD COLUMN IF NOT EXISTS `proof_url` VARCHAR(2048) NULL;
ALTER TABLE `influencer_verifications` ADD COLUMN IF NOT EXISTS `submitted_at` TIMESTAMP NULL;

ALTER TABLE `idempotency_keys` MODIFY COLUMN `key` VARCHAR(191) NOT NULL;
INSERT IGNORE INTO `system_settings` (`key`, `value`) VALUES 
('site_irt_card_number', '6037991122334455'),
('site_irt_account_number', '0123456789'),
('site_irt_sheba', 'IR120140040000012345678901'),
('site_irt_bank_name', 'بانک ملی ایران'),
('site_usdt_bnb20_address', '0x1234567890123456789012345678901234567890'),
('site_usdt_trc20_address', 'T12345678901234567890123456789012');

INSERT IGNORE INTO `settings` (`key`, `value`) VALUES 
('site_irt_card_number', '6037991122334455'),
('site_irt_account_number', '0123456789'),
('site_irt_sheba', 'IR120140040000012345678901'),
('site_irt_bank_name', 'بانک ملی ایران'),
('site_usdt_bnb20_address', '0x1234567890123456789012345678901234567890'),
('site_usdt_trc20_address', 'T12345678901234567890123456789012');

ALTER TABLE `disputes` ADD COLUMN IF NOT EXISTS `peer_deadline` TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `disputes` ADD COLUMN IF NOT EXISTS `resolution_note` TEXT NULL DEFAULT NULL;
ALTER TABLE `disputes` ADD COLUMN IF NOT EXISTS `admin_decision` VARCHAR(50) NULL DEFAULT NULL;
ALTER TABLE `disputes` ADD COLUMN IF NOT EXISTS `penalty_amount` DECIMAL(24,4) NULL DEFAULT NULL;
ALTER TABLE `disputes` ADD COLUMN IF NOT EXISTS `penalty_currency` VARCHAR(10) NULL DEFAULT NULL;
ALTER TABLE `disputes` ADD COLUMN IF NOT EXISTS `penalty_target` VARCHAR(50) NULL DEFAULT NULL;
ALTER TABLE `disputes` ADD COLUMN IF NOT EXISTS `site_tax_amount` DECIMAL(24,4) NULL DEFAULT NULL;
ALTER TABLE `disputes` ADD COLUMN IF NOT EXISTS `refund_percent` DECIMAL(5,2) NULL DEFAULT NULL;
ALTER TABLE `disputes` ADD COLUMN IF NOT EXISTS `resolved_by` INT(10) UNSIGNED NULL DEFAULT NULL;

ALTER TABLE `dispute_messages` ADD COLUMN IF NOT EXISTS `role` VARCHAR(50) NULL DEFAULT NULL;
ALTER TABLE `dispute_messages` ADD COLUMN IF NOT EXISTS `attachment` VARCHAR(255) NULL DEFAULT NULL;
ALTER TABLE `dispute_messages` ADD COLUMN IF NOT EXISTS `is_read` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `actual_publish_time` TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `proof_video` VARCHAR(255) NULL DEFAULT NULL;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `rejection_reason` TEXT NULL DEFAULT NULL;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `customer_rating` TINYINT NULL DEFAULT NULL;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `customer_review` TEXT NULL DEFAULT NULL;
ALTER TABLE `story_orders` ADD COLUMN IF NOT EXISTS `metadata` LONGTEXT NULL DEFAULT NULL;

ALTER TABLE `custom_task_submissions` ADD COLUMN IF NOT EXISTS `user_id` INT(10) UNSIGNED NULL DEFAULT NULL;
ALTER TABLE `custom_task_submissions` ADD COLUMN IF NOT EXISTS `deadline_at` TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `custom_task_submissions` ADD COLUMN IF NOT EXISTS `worker_ip` VARCHAR(45) NULL DEFAULT NULL;
ALTER TABLE `custom_task_submissions` ADD COLUMN IF NOT EXISTS `worker_fingerprint` VARCHAR(128) NULL DEFAULT NULL;
ALTER TABLE `custom_task_submissions` ADD COLUMN IF NOT EXISTS `proof_file` VARCHAR(255) NULL DEFAULT NULL;
ALTER TABLE `custom_task_submissions` ADD COLUMN IF NOT EXISTS `proof_file_hash` VARCHAR(100) NULL DEFAULT NULL;
ALTER TABLE `custom_task_submissions` ADD COLUMN IF NOT EXISTS `reviewed_at` TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `custom_task_submissions` ADD COLUMN IF NOT EXISTS `rejection_reason` TEXT NULL DEFAULT NULL;
ALTER TABLE `custom_task_submissions` ADD COLUMN IF NOT EXISTS `reward_paid` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `custom_task_submissions` ADD COLUMN IF NOT EXISTS `reward_transaction_id` VARCHAR(100) NULL DEFAULT NULL;
ALTER TABLE `custom_task_submissions` ADD COLUMN IF NOT EXISTS `metadata` LONGTEXT NULL DEFAULT NULL;

ALTER TABLE `ads` ADD COLUMN IF NOT EXISTS `link` VARCHAR(2048) NULL DEFAULT NULL;
ALTER TABLE `ads` ADD COLUMN IF NOT EXISTS `proof_type` VARCHAR(50) NULL DEFAULT 'screenshot';
ALTER TABLE `ads` ADD COLUMN IF NOT EXISTS `proof_description` TEXT NULL DEFAULT NULL;
ALTER TABLE `ads` ADD COLUMN IF NOT EXISTS `sample_image` VARCHAR(255) NULL DEFAULT NULL;
ALTER TABLE `ads` ADD COLUMN IF NOT EXISTS `currency` VARCHAR(10) NULL DEFAULT 'irt';
ALTER TABLE `ads` ADD COLUMN IF NOT EXISTS `deadline_hours` INT(10) UNSIGNED NULL DEFAULT 24;
ALTER TABLE `ads` ADD COLUMN IF NOT EXISTS `country_restriction` VARCHAR(100) NULL DEFAULT NULL;
ALTER TABLE `ads` ADD COLUMN IF NOT EXISTS `device_restriction` VARCHAR(50) NULL DEFAULT 'all';
ALTER TABLE `ads` ADD COLUMN IF NOT EXISTS `os_restriction` VARCHAR(50) NULL DEFAULT NULL;
ALTER TABLE `ads` ADD COLUMN IF NOT EXISTS `site_commission_percent` DECIMAL(5,2) NULL DEFAULT 10.00;
ALTER TABLE `ads` ADD COLUMN IF NOT EXISTS `restrictions` LONGTEXT NULL DEFAULT NULL;

ALTER TABLE `search_projections` ADD COLUMN IF NOT EXISTS `owner_id` INT(10) UNSIGNED NULL DEFAULT NULL;
ALTER TABLE `search_projections` ADD COLUMN IF NOT EXISTS `scope` VARCHAR(50) NOT NULL DEFAULT 'module';
ALTER TABLE `search_projections` ADD COLUMN IF NOT EXISTS `module` VARCHAR(50) NULL DEFAULT NULL;
ALTER TABLE `search_projections` ADD COLUMN IF NOT EXISTS `ref` VARCHAR(191) NULL DEFAULT NULL;

ALTER TABLE `search_projections` ADD UNIQUE INDEX IF NOT EXISTS `entity_type_id_unique` (`entity_type`, `entity_id`);
ALTER TABLE `search_projections` ADD FULLTEXT INDEX IF NOT EXISTS `projection_fulltext` (`title`, `content`, `ref`);
ALTER TABLE `search_projections` ADD INDEX IF NOT EXISTS `owner_scope_module_idx` (`owner_id`, `scope`, `module`);

ALTER TABLE `interactions` ADD COLUMN IF NOT EXISTS `context` VARCHAR(50) NULL DEFAULT NULL;
ALTER TABLE `interactions` ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `description` TEXT NULL DEFAULT NULL;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `enabled_percentage` INT NULL DEFAULT 100;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `enabled_for_roles` TEXT NULL DEFAULT NULL;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `enabled_for_users` TEXT NULL DEFAULT NULL;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `enabled_for_countries` TEXT NULL DEFAULT NULL;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `enabled_for_devices` TEXT NULL DEFAULT NULL;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `enabled_for_routes` TEXT NULL DEFAULT NULL;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `metadata` LONGTEXT NULL DEFAULT NULL;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `enabled_from` TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `enabled_until` TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `depends_on` TEXT NULL DEFAULT NULL;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `environments` TEXT NULL DEFAULT NULL;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `priority` INT NULL DEFAULT 0;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `tags` TEXT NULL DEFAULT NULL;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `min_age` INT NULL DEFAULT NULL;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `max_age` INT NULL DEFAULT NULL;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `targeted_user_ids` TEXT NULL DEFAULT NULL;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `targeted_roles` TEXT NULL DEFAULT NULL;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `targeted_countries` TEXT NULL DEFAULT NULL;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `targeted_plans` TEXT NULL DEFAULT NULL;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `targeted_devices` TEXT NULL DEFAULT NULL;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `targeted_routes` TEXT NULL DEFAULT NULL;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `target_age_min` INT NULL DEFAULT NULL;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `target_age_max` INT NULL DEFAULT NULL;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `percentage_rollout` INT NULL DEFAULT 100;
ALTER TABLE `feature_flags` ADD COLUMN IF NOT EXISTS `rollout_seed` VARCHAR(100) NULL DEFAULT NULL;

ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `user_id` INT(10) UNSIGNED NULL DEFAULT NULL;
ALTER TABLE `vitrine_listings` MODIFY COLUMN `user_id` INT(10) UNSIGNED NULL DEFAULT NULL;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `specs` TEXT NULL DEFAULT NULL;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `username` VARCHAR(100) NULL DEFAULT NULL;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `creation_date` VARCHAR(100) NULL DEFAULT NULL;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `min_price_usdt` DECIMAL(24,4) NULL DEFAULT NULL;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `admin_note` TEXT NULL DEFAULT NULL;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `escrow_locked_at` TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `escrow_deadline` TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `seller_info_sent` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `auto_confirmed` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `offer_price_usdt` DECIMAL(24,4) NULL DEFAULT NULL;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `expires_at` TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `hold_amount` DECIMAL(24,4) NOT NULL DEFAULT 0.0000;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `currency` VARCHAR(10) NOT NULL DEFAULT 'usdt';
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `hold_released` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `vitrine_listings` ADD COLUMN IF NOT EXISTS `hold_released_at` TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE `vitrine_requests` ADD COLUMN IF NOT EXISTS `requester_id` INT(10) UNSIGNED NULL DEFAULT NULL;
ALTER TABLE `vitrine_requests` ADD COLUMN IF NOT EXISTS `offer_price` DECIMAL(24,4) NULL DEFAULT NULL;
ALTER TABLE `vitrine_requests` ADD COLUMN IF NOT EXISTS `responded_at` TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `vitrine_requests` ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;


ALTER TABLE `email_queue` ADD COLUMN IF NOT EXISTS `scheduled_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE `email_queue` ADD COLUMN IF NOT EXISTS `priority` INT(10) UNSIGNED NOT NULL DEFAULT 0;

SET FOREIGN_KEY_CHECKS = 1;
