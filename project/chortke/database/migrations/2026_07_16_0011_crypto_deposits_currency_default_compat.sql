-- Fresh-install compatibility for direct crypto deposit store path.
-- Runtime code stores USDT deposits; keep currency non-null with a safe default.
ALTER TABLE `crypto_deposits`
  MODIFY COLUMN `currency` VARCHAR(10) NOT NULL DEFAULT 'usdt';
