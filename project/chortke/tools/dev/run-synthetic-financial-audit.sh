#!/usr/bin/env bash
# Creates representative LEGACY financial fixtures in a rolled-back MariaDB
# transaction, executes the production read-only audit scripts against them,
# and proves the audit detects every unsafe category. No fixture persists.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
bash "$ROOT/tools/dev/setup-financial-test-env.sh" >/dev/null

# shellcheck disable=SC1091
source "${ROOT%/app}/.local-runtime-secrets"
FIXTURE="test-results/audit/synthetic_legacy_audit.out"
TX_LEGACY="audit_legacy_hold_$(openssl rand -hex 8)"
TX_IMBALANCED="audit_imbalanced_$(openssl rand -hex 8)"
ORDER="audit_vitrine_$(openssl rand -hex 6)"

mysql -h127.0.0.1 -P3306 -uchortke_local -p"$DB_PASSWORD" chortke_local --batch --raw > "$FIXTURE" <<SQL
START TRANSACTION;

-- The seeded users 1..4 are used only inside this transaction.
UPDATE wallets SET locked_irt = 123.0000 WHERE user_id = 3;

-- Legacy Vitrine: direct pay model, no corresponding locked hold.
INSERT INTO escrow_transactions
(order_id, order_type, buyer_id, seller_id, amount, currency, status, held_at, expires_at)
VALUES ('$ORDER', 'vitrine_purchase', 1, 2, 25.0000, 'usdt', 'in_escrow', NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY));

-- Pending financial hold with no escrow.
INSERT INTO transactions
(transaction_id, user_id, type, currency, amount, balance_before, balance_after, status, description, ref_id, ref_type, ip_address, device_fingerprint, metadata, created_at, updated_at)
VALUES ('$TX_LEGACY', 4, 'withdraw', 'irt', 77.0000, 1000.0000, 923.0000, 'pending', 'synthetic legacy hold', '999999', 'legacy_test', '127.0.0.1', 'synthetic', '{"type":"legacy_budget_hold"}', NOW(), NOW());

-- Deliberately imbalanced ledger transaction, linked to a real transaction.
INSERT INTO transactions
(transaction_id, user_id, type, currency, amount, balance_before, balance_after, status, description, ip_address, device_fingerprint, metadata, created_at, updated_at)
VALUES ('$TX_IMBALANCED', 4, 'audit_fixture', 'irt', 5.0000, 0, 0, 'completed', 'synthetic imbalance', '127.0.0.1', 'synthetic', '{}', NOW(), NOW());
INSERT INTO ledger_entries
(transaction_id, account, debit, credit, currency, description, metadata, created_at, updated_at)
VALUES ('$TX_IMBALANCED', 'audit_fixture', 5.0000, 0.0000, 'irt', 'synthetic imbalance', '{}', NOW(), NOW());

SOURCE test-results/audit/legacy_financial_audit.sql;
SOURCE test-results/audit/production_financial_reconciliation.sql;
ROLLBACK;
SQL

for required in legacy_vitrine_purchase orphan_locked_balance pending_hold_review ledger_imbalance; do
  if ! grep -q "$required" "$FIXTURE"; then
    echo "Synthetic audit failed to detect: $required" >&2
    exit 1
  fi
done

echo "Synthetic legacy audit passed; fixtures rolled back."
echo "Output: $FIXTURE"
