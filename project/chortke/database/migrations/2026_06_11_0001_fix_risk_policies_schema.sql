SET FOREIGN_KEY_CHECKS = 0;

-- Repair: add missing columns for risk_policies
-- تاریخ: 2026-06-11

ALTER TABLE `risk_policies`
    ADD COLUMN IF NOT EXISTS `value_type` VARCHAR(20) NOT NULL DEFAULT 'string',
    ADD COLUMN IF NOT EXISTS `updated_by` INT(10) UNSIGNED DEFAULT NULL;

SET FOREIGN_KEY_CHECKS = 1;
