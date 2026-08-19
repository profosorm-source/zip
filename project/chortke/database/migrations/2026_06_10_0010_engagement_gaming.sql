-- CHORTKE MIGRATION: ENGAGEMENT & GAMING
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `lottery_rounds`;
CREATE TABLE `lottery_rounds` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255),
    `prize_pool` DECIMAL(24,4) DEFAULT 0,
    `status` ENUM('open', 'closed', 'finished') DEFAULT 'open',
    `winner_user_id` INT(10) UNSIGNED,
    `draw_at` TIMESTAMP NULL,
    `deleted_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `lottery_participations`;
CREATE TABLE `lottery_participations` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `round_id` INT(10) UNSIGNED NOT NULL,
    `ticket_number` VARCHAR(50),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_lp_round` (`round_id`),
    KEY `idx_lp_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `lottery_votes`;
CREATE TABLE `lottery_votes` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `round_id` INT(10) UNSIGNED NOT NULL,
    `voted_number` INT(10),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_lv_round` (`round_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `prediction_games`;
CREATE TABLE `prediction_games` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `sport_type` VARCHAR(50) DEFAULT 'football',
    `team_home` VARCHAR(100),
    `team_away` VARCHAR(100),
    `start_time` TIMESTAMP NULL,
    `status` ENUM('open', 'locked', 'finished', 'cancelled') DEFAULT 'open',
    `result` VARCHAR(50),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `prediction_bets`;
CREATE TABLE `prediction_bets` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `game_id` INT(10) UNSIGNED NOT NULL,
    `prediction` VARCHAR(50),
    `amount` DECIMAL(24,4) NOT NULL,
    `currency` VARCHAR(10) DEFAULT 'irt',
    `status` ENUM('pending', 'won', 'lost', 'refunded') DEFAULT 'pending',
    `payout_amount` DECIMAL(24,4),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_pb_user` (`user_id`),
    KEY `idx_pb_game` (`game_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `interactions`;
CREATE TABLE `interactions` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `interactable_type` VARCHAR(191) NOT NULL,
    `interactable_id` INT(10) UNSIGNED NOT NULL,
    `interaction_type` VARCHAR(50) NOT NULL COMMENT 'like, favorite, view',
    `value` INT(11) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_interact_target` (`interactable_type`, `interactable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `ratings`;
CREATE TABLE `ratings` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `target_id` INT(10) UNSIGNED NOT NULL,
    `target_type` VARCHAR(50) NOT NULL,
    `rating` TINYINT UNSIGNED,
    `comment` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `user_rating_unique` (`user_id`, `target_id`, `target_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
