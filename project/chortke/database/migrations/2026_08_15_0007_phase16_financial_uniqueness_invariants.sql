-- Database-owned concurrency invariants for Phase 16 financial lifecycles.
-- Service pre-checks and row locks improve messages, but uniqueness must survive
-- concurrent requests that both observe an initially empty result set.

ALTER TABLE `investments`
  ADD COLUMN IF NOT EXISTS `active_user_id` INT(10) UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN `status` = 'active' AND `deleted_at` IS NULL THEN `user_id` ELSE NULL END) STORED;
CREATE UNIQUE INDEX IF NOT EXISTS `uq_investments_one_active_per_user`
  ON `investments` (`active_user_id`);

CREATE UNIQUE INDEX IF NOT EXISTS `uq_prediction_bets_user_game`
  ON `prediction_bets` (`user_id`, `game_id`);
