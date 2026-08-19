# Production Financial Cutover Runbook

## Scope

This runbook governs production validation and any approved legacy correction after the root financial refactor. It does **not** authorize automatic data changes.

## Roles

| Role | Responsibility |
|---|---|
| Finance owner | Approves each legacy classification and correcting entry |
| DBA | Creates backup/snapshot, runs read-only audit and approved migration |
| Engineering owner | Validates application version, idempotency and reconciliation output |
| Operations | Monitors canary metrics and owns rollback decision |

## Phase 0 — Preconditions

- Deploy the tested application version to staging first.
- Run full PHPUnit and PHPStan in CI.
- Verify `FinancialIntegrityTest` architecture guards pass.
- Enable application maintenance/write freeze only for financial admin operations if required.
- Create encrypted database backup plus point-in-time restore reference.
- Rehearse restoring that backup in a non-production environment.

## Phase 1 — Read-only production audit

Run only with a DB account that has `SELECT` permission:

```bash
mysql -h <read-replica> -u <read-only-user> -p <database> \
  < production_financial_reconciliation.sql \
  > reconciliation_before_YYYYMMDD.out

mysql -h <read-replica> -u <read-only-user> -p <database> \
  < legacy_financial_audit.sql \
  > legacy_audit_YYYYMMDD.out
```

Attach both outputs to the change record after removing PII/secrets.

## Phase 2 — Classification gate

Every finding must receive one status before any mutation:

```text
APPROVED_CORRECTION
NO_ACTION_CANONICAL
MANUAL_FINANCE_REVIEW
EXTERNAL_WITHDRAWAL
BLOCKED_INSUFFICIENT_EVIDENCE
```

No row labelled `MANUAL_FINANCE_REVIEW`, `EXTERNAL_WITHDRAWAL`, or `BLOCKED_INSUFFICIENT_EVIDENCE` may be modified automatically.

## Phase 3 — Migration design review

For each `APPROVED_CORRECTION` row, the proposed operation must include:

- source wallet and target wallet/system account;
- amount and currency;
- linked escrow/transaction IDs;
- deterministic idempotency key;
- compensating/rollback strategy;
- before/after expected wallet, locked, escrow and ledger values.

Hard rules:

```text
No direct UPDATE wallets
No DELETE from transactions or ledger_entries
No bulk automatic refund
No state-only escrow update without proven wallet/ledger settlement
```

## Phase 4 — Staging rehearsal

- Restore a sanitized production clone.
- Execute migration in dry-run mode, then one approved canary row.
- Run `production_financial_reconciliation.sql`.
- Verify all amounts match the approved before/after worksheet.
- Verify no outbox/DLQ failures.

## Phase 5 — Production canary

1. Start monitoring dashboard and alerting.
2. Apply exactly one approved correction.
3. Run reconciliation query immediately.
4. Wait for outbox/queue drain.
5. Review audit, ledger, wallet and escrow values.
6. Only then approve the next small batch.

Stop immediately if any of these occur:

```text
ledger imbalance
negative wallet/locked value
orphan locked balance increase
unexpected idempotency conflict
outbox/DLQ failure
wallet/escrow amount mismatch
```

## Phase 6 — Completion

- Run final reconciliation.
- Archive before/after audit outputs.
- Record corrected IDs, idempotency keys and ledger transactions.
- Remove temporary maintenance restrictions.
- Keep production monitoring elevated for at least one settlement cycle.
