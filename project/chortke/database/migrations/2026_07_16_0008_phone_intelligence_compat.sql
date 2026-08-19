-- Compatibility table used by VelocityAndScoreModel phone intelligence cache
CREATE TABLE IF NOT EXISTS `phone_intelligence` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `phone` VARCHAR(32) NOT NULL UNIQUE,
  `country_code` VARCHAR(8) NULL,
  `line_type` VARCHAR(50) NULL,
  `is_voip` TINYINT(1) DEFAULT 0,
  `is_valid` TINYINT(1) DEFAULT 1,
  `last_checked_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_phone_intelligence_checked` (`last_checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
