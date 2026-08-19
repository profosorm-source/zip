-- CHORTKE MIGRATION PART 12: RBAC & SOCIAL CORE
SET FOREIGN_KEY_CHECKS = 0;

-- [REMOVED DUPLICATE roles]
-- [REMOVED DUPLICATE permissions]
-- [REMOVED DUPLICATE role_permissions]
-- [REMOVED DUPLICATE user_roles]
DROP TABLE IF EXISTS `social_accounts`;
CREATE TABLE `social_accounts` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `platform` VARCHAR(50) NOT NULL,
    `username` VARCHAR(100) NOT NULL,
    `provider_id` VARCHAR(191),
    `avatar` VARCHAR(255),
    `status` VARCHAR(20) DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_sa_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;-- [REMOVED DUPLICATE seo_executions]


SET FOREIGN_KEY_CHECKS = 1;
