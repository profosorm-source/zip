-- CHORTKE MIGRATION PART 34: EXECUTION & LEVEL EXTENSIONS
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `task_executions`;
CREATE TABLE `task_executions` (
  `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `advertisement_id` INT(10) UNSIGNED NOT NULL,
  `executor_id` INT(10) UNSIGNED NOT NULL,
  `executor_social_account_id` INT(10) UNSIGNED DEFAULT NULL,
  `reward_amount` DECIMAL(24,4) NOT NULL,
  `reward_currency` ENUM('irt','usdt') NOT NULL DEFAULT 'irt',
  `idempotency_key` VARCHAR(100) NOT NULL,
  `status` ENUM('started','submitted','approved','rejected','expired','disputed','cancelled') NOT NULL DEFAULT 'started',
  `proof_image` VARCHAR(500) DEFAULT NULL,
  `proof_text` TEXT DEFAULT NULL,
  `reward_transaction_id` VARCHAR(64) DEFAULT NULL,
  `reviewed_by` INT(10) UNSIGNED DEFAULT NULL,
  `reviewed_at` TIMESTAMP NULL,
  `started_at` TIMESTAMP NULL,
  `submitted_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `ticket_assignment_history`;
CREATE TABLE `ticket_assignment_history` (
  `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT(10) UNSIGNED NOT NULL,
  `old_admin_id` INT(10) UNSIGNED DEFAULT NULL,
  `new_admin_id` INT(10) UNSIGNED DEFAULT NULL,
  `changed_by` INT(10) UNSIGNED NOT NULL,
  `changed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;-- [REMOVED DUPLICATE ticket_status_history]
DROP TABLE IF EXISTS `user_level_purchases`;
CREATE TABLE `user_level_purchases` (
  `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `level_slug` VARCHAR(60) NOT NULL,
  `amount` DECIMAL(24,4) NOT NULL,
  `currency` ENUM('irt','usdt') NOT NULL DEFAULT 'irt',
  `duration_days` INT(10) UNSIGNED NOT NULL DEFAULT 30,
  `status` ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
  `transaction_id` VARCHAR(64) DEFAULT NULL,
  `idempotency_key` VARCHAR(100) NOT NULL,
  `starts_at` TIMESTAMP NULL,
  `expires_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `user_scores`;
CREATE TABLE `user_scores` (
  `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `domain` VARCHAR(100) NOT NULL,
  `score` DECIMAL(24,4) NOT NULL DEFAULT 0.0000,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `user_score_adjustments`;
CREATE TABLE `user_score_adjustments` (
  `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `score_adjustment` DECIMAL(10,2) NOT NULL,
  `operation` VARCHAR(255) NOT NULL,
  `reason` TEXT NOT NULL,
  `domain` VARCHAR(32) NOT NULL DEFAULT 'fraud',
  `created_by` INT(10) UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `user_ticket_scores`;
CREATE TABLE `user_ticket_scores` (
  `user_id` INT(10) UNSIGNED PRIMARY KEY,
  `score` INT(10) NOT NULL DEFAULT 100,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
