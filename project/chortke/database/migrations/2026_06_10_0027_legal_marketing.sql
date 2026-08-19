-- CHORTKE MIGRATION PART 20: LEGAL CONTENT & MARKETING
SET FOREIGN_KEY_CHECKS = 0;

-- [REMOVED DUPLICATE content_agreements]
-- [REMOVED DUPLICATE content_revenues]
DROP TABLE IF EXISTS `contact_messages`;
CREATE TABLE `contact_messages` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `full_name` VARCHAR(255),
    `email` VARCHAR(255),
    `subject` VARCHAR(255),
    `message` TEXT,
    `is_read` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;-- [REMOVED DUPLICATE coupon_redemptions]
-- [REMOVED DUPLICATE pages]


SET FOREIGN_KEY_CHECKS = 1;
