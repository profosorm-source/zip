-- READ-ONLY legacy financial audit. Run on a production read replica or
-- maintenance transaction; it performs no data change.

-- 1. Legacy Vitrine escrow rows created by the retired flow.
SELECT e.id, e.order_id, e.buyer_id, e.seller_id, e.amount, e.currency, e.status,
       w.balance_irt, w.locked_irt, w.balance_usdt, w.locked_usdt
FROM escrow_transactions e
LEFT JOIN wallets w ON w.user_id = e.buyer_id
WHERE e.order_type = 'vitrine_purchase'
ORDER BY e.id;

-- 2. Active/pending escrow with no matching pending hold transaction.
SELECT e.id, e.order_id, e.order_type, e.buyer_id, e.amount, e.currency, e.status
FROM escrow_transactions e
WHERE e.status IN ('pending', 'in_escrow', 'partial', 'disputed')
  AND NOT EXISTS (
      SELECT 1 FROM transactions t
      WHERE t.user_id = e.buyer_id
        AND t.type = 'withdraw'
        AND t.status IN ('pending', 'processing')
        AND t.currency = e.currency
        AND ABS(t.amount - e.amount) < 0.00000001
  )
ORDER BY e.id;

-- 3. Wallets with locked funds but no pending hold.
SELECT w.user_id, w.balance_irt, w.locked_irt, w.balance_usdt, w.locked_usdt
FROM wallets w
WHERE (w.locked_irt > 0 OR w.locked_usdt > 0)
  AND NOT EXISTS (
      SELECT 1 FROM transactions t
      WHERE t.user_id = w.user_id AND t.type = 'withdraw'
        AND t.status IN ('pending', 'processing')
  )
ORDER BY w.user_id;

-- 4. Pending holds that do not map to an active escrow. These must be reviewed
-- before any migration because a hold can also represent an external withdrawal.
SELECT t.transaction_id, t.user_id, t.amount, t.currency, t.ref_id, t.ref_type,
       t.metadata, t.created_at
FROM transactions t
WHERE t.type = 'withdraw' AND t.status IN ('pending', 'processing')
  AND (t.metadata LIKE '%escrow%' OR t.metadata LIKE '%budget%' OR t.metadata LIKE '%lottery%')
ORDER BY t.created_at;

-- 5. Ledger transaction imbalance.
SELECT transaction_id, currency, SUM(debit) AS debit_total, SUM(credit) AS credit_total,
       SUM(debit) - SUM(credit) AS delta
FROM ledger_entries
GROUP BY transaction_id, currency
HAVING ABS(SUM(debit) - SUM(credit)) > 0.00000001
ORDER BY transaction_id;

-- 6. Count legacy campaigns with a remaining budget but no active escrow.
SELECT a.id, a.type, a.user_id, a.currency, a.remaining_budget, a.status
FROM ads a
WHERE COALESCE(a.remaining_budget, 0) > 0
  AND a.status IN ('active', 'approved', 'paused', 'exhausted')
  AND NOT EXISTS (
      SELECT 1 FROM escrow_transactions e
      WHERE e.order_id = CAST(a.id AS CHAR)
        AND e.status IN ('pending', 'in_escrow', 'partial', 'disputed')
  )
ORDER BY a.id;
