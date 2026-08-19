-- Route /admin/bank-cards/verify and /admin/bank-cards/reject require
-- finance.card.approve. The finance permission seed only created finance.card.view.

INSERT INTO `permissions` (`name`, `slug`, `group_name`, `description`, `created_at`, `updated_at`)
VALUES ('تأیید/رد کارت‌های بانکی', 'finance.card.approve', 'finance', 'Approve or reject user bank cards', NOW(), NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `group_name` = VALUES(`group_name`), `description` = VALUES(`description`), `updated_at` = NOW();

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r
JOIN `permissions` p ON p.slug = 'finance.card.approve'
WHERE r.slug IN ('admin', 'super_admin');
