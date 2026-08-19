-- Compat/performance index for duplicate bank-card checks.
-- BankCardService checks active duplicates by card_hash before insert; keep the
-- lookup indexed on fresh and upgraded installs.
ALTER TABLE `bank_cards`
    ADD INDEX IF NOT EXISTS `idx_bank_cards_card_hash` (`card_hash`);
