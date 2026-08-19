-- Package 145: AdTube incomplete/fraud rejection stores a reason.
-- AdTubeExecutionModel::markRejected writes reject_reason, so keep the schema explicit.

ALTER TABLE `adtube_views`
  ADD COLUMN IF NOT EXISTS `reject_reason` TEXT NULL DEFAULT NULL AFTER `reviewed_at`;
