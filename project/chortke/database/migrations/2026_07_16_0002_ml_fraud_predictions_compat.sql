-- Compatibility columns used by VelocityAndScoreModel::storePrediction
ALTER TABLE `ml_fraud_predictions`
  ADD COLUMN IF NOT EXISTS `risk_score` DECIMAL(8,4) NULL AFTER `user_id`,
  ADD COLUMN IF NOT EXISTS `features` JSON NULL AFTER `risk_score`;

CREATE INDEX IF NOT EXISTS `idx_ml_fraud_user_risk` ON `ml_fraud_predictions` (`user_id`, `risk_score`);
