-- Optional user-provided sender wallet. It is validated on submission and can
-- later be compared with the sender returned by a trusted chain provider.
ALTER TABLE `crypto_deposits`
  ADD COLUMN IF NOT EXISTS `from_wallet` VARCHAR(255) NULL AFTER `wallet_address`;
