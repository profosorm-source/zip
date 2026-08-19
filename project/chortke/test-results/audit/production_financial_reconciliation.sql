-- Production financial reconciliation (READ ONLY)
-- Run on a read replica or a transaction-consistent snapshot after the legacy
-- audit and after every canary/batch. This script deliberately contains no DML.

-- A. Ledger transactions whose debit/credit legs do not balance.
SELECT 'ledger_imbalance' AS finding,
       transaction_id,
       currency,
       SUM(debit) AS debit_total,
       SUM(credit) AS credit_total,
       SUM(debit) - SUM(credit) AS delta,
       COUNT(*) AS leg_count
FROM ledger_entries
GROUP BY transaction_id, currency
HAVING ABS(SUM(debit) - SUM(credit)) > 0.00000001
ORDER BY transaction_id, currency;

-- B. Locked wallet balances with no pending withdrawal hold.
SELECT 'orphan_locked_balance' AS finding,
       w.user_id,
       w.balance_irt,
       w.locked_irt,
       w.balance_usdt,
       w.locked_usdt
FROM wallets w
WHERE (w.locked_irt > 0 OR w.locked_usdt > 0)
  AND NOT EXISTS (
      SELECT 1
      FROM transactions t
      WHERE t.user_id = w.user_id
        AND t.type = 'withdraw'
        AND t.status IN ('pending', 'processing')
  )
ORDER BY w.user_id;

-- C. Active escrow whose buyer no longer has a matching pending hold.
SELECT 'escrow_without_pending_hold' AS finding,
       e.id AS escrow_id,
       e.order_id,
       e.order_type,
       e.buyer_id,
       e.seller_id,
       e.amount,
       e.currency,
       e.status
FROM escrow_transactions e
WHERE e.status IN ('pending', 'in_escrow', 'partial', 'disputed')
  AND NOT EXISTS (
      SELECT 1
      FROM transactions t
      WHERE t.user_id = e.buyer_id
        AND t.type = 'withdraw'
        AND t.status IN ('pending', 'processing')
        AND t.currency = e.currency
        AND ABS(t.amount - e.amount) < 0.00000001
  )
ORDER BY e.id;

-- D. Pending financial holds whose metadata indicates escrow/budget/lottery but
-- whose relationship cannot be automatically classified. Review manually.
SELECT 'pending_hold_review' AS finding,
       t.transaction_id,
       t.user_id,
       t.amount,
       t.currency,
       t.ref_id,
       t.ref_type,
       t.metadata,
       t.created_at
FROM transactions t
WHERE t.type = 'withdraw'
  AND t.status IN ('pending', 'processing')
  AND (t.metadata LIKE '%escrow%' OR t.metadata LIKE '%budget%' OR t.metadata LIKE '%lottery%')
ORDER BY t.created_at;

-- E. Retired Vitrine escrow contract. Never migrate these automatically.
SELECT 'legacy_vitrine_purchase' AS finding,
       e.id AS escrow_id,
       e.order_id,
       e.buyer_id,
       e.seller_id,
       e.amount,
       e.currency,
       e.status,
       e.created_at
FROM escrow_transactions e
WHERE e.order_type = 'vitrine_purchase'
ORDER BY e.id;

-- F. Campaign budget that is not backed by an active escrow. These are legacy
-- financial-review candidates, not automatic refunds.
SELECT 'ad_budget_without_escrow' AS finding,
       a.id AS ad_id,
       a.type,
       a.user_id,
       a.currency,
       a.remaining_budget,
       a.status
FROM ads a
WHERE COALESCE(a.remaining_budget, 0) > 0
  AND a.status IN ('active', 'approved', 'paused', 'exhausted')
  AND NOT EXISTS (
      SELECT 1 FROM escrow_transactions e
      WHERE e.order_id = CAST(a.id AS CHAR)
        AND e.status IN ('pending', 'in_escrow', 'partial', 'disputed')
  )
ORDER BY a.id;

-- G. Summary suitable for a go/no-go decision. Every count must be reviewed;
-- no automatic migration is authorized by this output.
SELECT 'summary_legacy_vitrine' AS metric, COUNT(*) AS finding_count
FROM escrow_transactions WHERE order_type = 'vitrine_purchase'
UNION ALL
SELECT 'summary_active_escrow', COUNT(*)
FROM escrow_transactions WHERE status IN ('pending', 'in_escrow', 'partial', 'disputed')
UNION ALL
SELECT 'summary_pending_withdrawal', COUNT(*)
FROM transactions WHERE type = 'withdraw' AND status IN ('pending', 'processing')
UNION ALL
SELECT 'summary_ledger_imbalance', COUNT(*)
FROM (
    SELECT transaction_id, currency
    FROM ledger_entries
    GROUP BY transaction_id, currency
    HAVING ABS(SUM(debit) - SUM(credit)) > 0.00000001
) AS imbalanced;
