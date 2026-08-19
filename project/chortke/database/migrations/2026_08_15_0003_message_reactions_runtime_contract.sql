-- Align the schema with DirectMessage::addReaction(): one current reaction
-- per user/message, updated atomically by ON DUPLICATE KEY UPDATE.
ALTER TABLE `message_reactions`
  CHANGE COLUMN `reaction` `emoji` VARCHAR(32) NOT NULL;

DROP INDEX IF EXISTS `uq_msg_user_reaction` ON `message_reactions`;
CREATE UNIQUE INDEX IF NOT EXISTS `uq_message_reaction_user`
  ON `message_reactions` (`message_id`, `user_id`);

CREATE INDEX IF NOT EXISTS `idx_message_reactions_message`
  ON `message_reactions` (`message_id`);
