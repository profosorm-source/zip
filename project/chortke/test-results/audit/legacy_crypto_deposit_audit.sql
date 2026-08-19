-- READ-ONLY legacy crypto deposit audit. No mutation.

-- A. Legacy deposits not linked to a server-generated intent.
SELECT 'deposit_without_intent' AS finding, id, user_id, network, amount, tx_hash,
       wallet_address, verification_status, auto_check_deadline, created_at
FROM crypto_deposits
WHERE intent_id IS NULL
ORDER BY id;

-- B. Deposit linked to an intent but divergent from the immutable snapshot.
SELECT 'intent_snapshot_mismatch' AS finding,
       d.id AS deposit_id, d.user_id, d.network AS deposit_network,
       d.amount AS deposit_amount, d.wallet_address AS deposit_wallet,
       i.id AS intent_id, i.network AS intent_network,
       i.expected_amount, i.to_wallet, i.status AS intent_status, i.expires_at
FROM crypto_deposits d
JOIN crypto_deposit_intents i ON i.id = d.intent_id
WHERE UPPER(d.network) <> UPPER(i.network)
   OR ABS(d.amount - i.expected_amount) > 0.00000001
   OR d.wallet_address <> i.to_wallet
ORDER BY d.id;

-- C. Claimed intents without a corresponding deposit row.
SELECT 'claimed_intent_without_deposit' AS finding,
       i.id, i.user_id, i.network, i.expected_amount, i.to_wallet, i.claimed_at
FROM crypto_deposit_intents i
LEFT JOIN crypto_deposits d ON d.intent_id = i.id
WHERE i.status = 'claimed' AND d.id IS NULL
ORDER BY i.id;

-- D. Pending deposits whose auto-check deadline passed but remain pending.
SELECT 'expired_pending_deposit' AS finding,
       id, user_id, intent_id, network, amount, tx_hash, auto_check_deadline,
       auto_check_attempts, verification_status
FROM crypto_deposits
WHERE verification_status = 'pending'
  AND auto_check_deadline IS NOT NULL
  AND auto_check_deadline < NOW()
ORDER BY auto_check_deadline;

-- E. Same tx hash claimed by multiple network rows (cross-network conflict).
SELECT 'duplicate_tx_hash_cross_network' AS finding,
       tx_hash, COUNT(*) AS rows_count, GROUP_CONCAT(DISTINCT network ORDER BY network) AS networks
FROM crypto_deposits
WHERE tx_hash IS NOT NULL AND tx_hash <> ''
GROUP BY tx_hash
HAVING COUNT(*) > 1;
