SET FOREIGN_KEY_CHECKS = 0;
DROP VIEW IF EXISTS `tasks`;
CREATE VIEW `tasks` AS
SELECT id, creator_id, title, description, 'social' as type, status, updated_at as completed_at, target_url as link, price_per_task as amount, platform, deleted_at FROM social_tasks
UNION ALL
SELECT id, creator_id, title, description, 'custom' as type, status, updated_at as completed_at, '' as link, price_per_task as amount, 'custom' as platform, deleted_at FROM custom_tasks
UNION ALL
SELECT id, creator_id, title, CONCAT('Keyword: ', keyword) as description, 'seo' as type, status, updated_at as completed_at, target_url as link, price_per_click as amount, 'google' as platform, deleted_at FROM seo_tasks;
SET FOREIGN_KEY_CHECKS = 1;
