# Legacy Financial Migration Plan — No Automatic Execution

## Preconditions

1. Run `legacy_financial_audit.sql` on a production read replica.
2. Export all result sets with execution timestamp and database snapshot ID.
3. Take encrypted backup and rehearse restore.
4. Do not run any UPDATE before each row is classified.

## Classification and action

| Class | Criteria | Action |
|---|---|---|
| A: canonical healthy | Active escrow + matching pending hold + matching currency/amount | No data migration; new code handles it |
| B: legacy Vitrine pay | `order_type=vitrine_purchase`, balance was paid directly, no locked hold | Manual financial review; never invoke `refundEscrowToBuyer` automatically |
| C: orphan locked wallet | locked amount without matching pending hold | Reconciliation case; require ledger/transaction evidence and approved compensating transaction |
| D: pending hold without escrow | pending withdraw with financial metadata but no active escrow | Determine whether external withdrawal or failed escrow creation; cancel only after evidence |
| E: active ad without escrow | remaining budget but no active escrow | Pause/financial-review; reconstruct only with source transaction evidence |
| F: ledger imbalance | debit != credit | Block automated settlement and create correcting ledger entries only after approval |

## Migration rules

- No direct `UPDATE wallets`.
- No direct `UPDATE escrow_transactions` as a substitute for settlement.
- Corrections use WalletService / FinancialEscrowService where possible.
- Every correction has a deterministic idempotency key and a linked audit record.
- Rows in B–F require human approval; no bulk automatic refund.

## Rollout

1. Dry-run report only.
2. Review and approval per classified row/batch.
3. Canary batch of one row in staging clone.
4. Reconciliation checks.
5. Production batch with monitoring and rollback point.
