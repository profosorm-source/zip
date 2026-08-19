#!/usr/bin/env bash
# Synthetic legacy crypto audit in a rolled-back local MariaDB transaction.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
bash "$ROOT/tools/dev/setup-financial-test-env.sh" >/dev/null
source "${ROOT%/app}/.local-runtime-secrets"
OUT="test-results/audit/synthetic_crypto_audit.out"
TX="audit_crypto_$(openssl rand -hex 8)"

mysql -h127.0.0.1 -P3306 -uchortke_local -p"$DB_PASSWORD" chortke_local --batch --raw > "$OUT" <<SQL
START TRANSACTION;
INSERT INTO crypto_deposit_intents (user_id,currency,network,requested_amount,expected_amount,to_wallet,expires_at,status,created_at,updated_at)
VALUES (1,'USDT','TRC20',10,10.12345678,'TQ9e4mGmA1s8QmBz4d2mTtUX4WQvi6WzMr',DATE_ADD(NOW(),INTERVAL 30 MINUTE),'claimed',NOW(),NOW());
SET @intent_id = LAST_INSERT_ID();
INSERT INTO crypto_deposits (user_id,intent_id,amount,currency,tx_hash,network,wallet_address,verification_status,auto_check_deadline,created_at,updated_at)
VALUES (1,@intent_id,9.99,'usdt','$TX','BNB20','0x0000000000000000000000000000000000000000','pending',DATE_SUB(NOW(),INTERVAL 1 MINUTE),NOW(),NOW());
INSERT INTO crypto_deposits (user_id,amount,currency,tx_hash,network,wallet_address,verification_status,created_at,updated_at)
VALUES (2,5,'usdt','legacy_$TX','TRC20','TLegacyWalletAddress1111111111111111','pending',NOW(),NOW());
SOURCE test-results/audit/legacy_crypto_deposit_audit.sql;
ROLLBACK;
SQL
for f in deposit_without_intent intent_snapshot_mismatch expired_pending_deposit; do
  grep -q "$f" "$OUT" || { echo "Audit missed $f" >&2; exit 1; }
done
echo "Synthetic crypto audit passed; fixtures rolled back."
