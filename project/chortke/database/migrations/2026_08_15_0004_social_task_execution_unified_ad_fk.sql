-- SocialTask runtime reads and creates campaigns in the unified `ads` table.
-- The legacy FK still referenced `social_ads`, causing every real execution
-- start for a unified ad to fail with SQLSTATE 23000.
ALTER TABLE `social_task_executions`
  DROP FOREIGN KEY IF EXISTS `fk_social_task_executions_ad_id`;

ALTER TABLE `social_task_executions`
  ADD CONSTRAINT `fk_social_task_executions_ad_id`
  FOREIGN KEY (`ad_id`) REFERENCES `ads` (`id`) ON DELETE CASCADE;
