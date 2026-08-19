-- CHORTKE MIGRATION PART 37: BLACKLISTS & VIEWS
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `device_blacklist`;
CREATE TABLE `device_blacklist` (
  `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `fingerprint` VARCHAR(64) NOT NULL,
  `reason` TEXT DEFAULT NULL,
  `blocked_by` INT(10) UNSIGNED DEFAULT NULL,
  `auto_blocked` TINYINT(1) DEFAULT 0,
  `expires_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `ip_blacklist`;
CREATE TABLE `ip_blacklist` (
  `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ip_address` VARCHAR(45) NOT NULL,
  `reason` TEXT DEFAULT NULL,
  `blocked_by` INT(10) UNSIGNED DEFAULT NULL,
  `auto_blocked` TINYINT(1) DEFAULT 0,
  `expires_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `seo_blacklist`;
CREATE TABLE `seo_blacklist` (
  `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `rule_type` VARCHAR(30) NOT NULL,
  `rule_value` VARCHAR(255) NOT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `expires_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Views (Senior QA: Using standard SQL syntax for views)
DROP VIEW IF EXISTS `v_high_risk_users`;
CREATE VIEW `v_high_risk_users` AS 
SELECT `u`.`id`, `u`.`full_name`, `u`.`email`, `u`.`fraud_score`, `u`.`is_blacklisted`, count(distinct `fl`.`id`) AS `fraud_incidents`, max(`fl`.`created_at`) AS `last_fraud_incident` 
FROM (`users` `u` left join `fraud_logs` `fl` on(`u`.`id` = `fl`.`user_id`)) 
WHERE `u`.`fraud_score` >= 50 OR `u`.`is_blacklisted` = 1 
GROUP BY `u`.`id`, `u`.`full_name`, `u`.`email`, `u`.`fraud_score`, `u`.`is_blacklisted`;

DROP VIEW IF EXISTS `v_today_suspicious_activities`;
CREATE VIEW `v_today_suspicious_activities` AS 
SELECT `fl`.`id`, `fl`.`user_id`, `fl`.`session_id`, `fl`.`fraud_type`, `fl`.`risk_score`, `fl`.`details`, `fl`.`action_taken`, `fl`.`ip_address`, `fl`.`user_agent`, `fl`.`created_at`, `u`.`full_name`, `u`.`email`, `u`.`fraud_score` 
FROM (`fraud_logs` `fl` left join `users` `u` on(`fl`.`user_id` = `u`.`id`)) 
WHERE DATE(`fl`.`created_at`) = DATE('now') 
ORDER BY `fl`.`created_at` DESC;

SET FOREIGN_KEY_CHECKS = 1;
