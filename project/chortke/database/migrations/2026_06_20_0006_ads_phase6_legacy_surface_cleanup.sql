-- Ads phase 6: active specialized admin surfaces compatibility cleanup

ALTER TABLE `ads`
  ADD COLUMN IF NOT EXISTS `banner_type` VARCHAR(50) NULL DEFAULT 'system' AFTER `placement`,
  ADD COLUMN IF NOT EXISTS `category` VARCHAR(100) NULL DEFAULT NULL AFTER `banner_type`,
  ADD COLUMN IF NOT EXISTS `sort_order` INT(10) NOT NULL DEFAULT 0 AFTER `category`,
  ADD COLUMN IF NOT EXISTS `target` VARCHAR(20) NULL DEFAULT '_blank' AFTER `sort_order`,
  ADD COLUMN IF NOT EXISTS `alt_text` VARCHAR(255) NULL DEFAULT NULL AFTER `target`;

CREATE INDEX IF NOT EXISTS `idx_ads_banner_admin` ON `ads` (`type`, `banner_type`, `placement`, `status`, `sort_order`);
