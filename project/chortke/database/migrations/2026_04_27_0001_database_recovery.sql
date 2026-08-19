SET FOREIGN_KEY_CHECKS = 0;

-- ═══════════════════════════════════════════════════════════════════
-- Database Recovery: Minimal safe version
-- Only creates the wallets table without complex logic
-- ═══════════════════════════════════════════════════════════════════

DROP TABLE IF EXISTS `wallets`;

CREATE TABLE `wallets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `balance_irt` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `balance_usdt` decimal(18,8) NOT NULL DEFAULT 0.00000000,
  `locked_irt` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `locked_usdt` decimal(18,8) NOT NULL DEFAULT 0.00000000,
  `last_withdrawal_at` timestamp NULL DEFAULT NULL,
  `is_frozen` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user` (`user_id`),
  KEY `idx_wallet_is_frozen` (`is_frozen`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User wallet balances and locked funds for withdrawals';

-- ═══════════════════════════════════════════════════════════════════
-- Recovery Complete (minimal version)
-- ═══════════════════════════════════════════════════════════════════

SET FOREIGN_KEY_CHECKS = 1;
