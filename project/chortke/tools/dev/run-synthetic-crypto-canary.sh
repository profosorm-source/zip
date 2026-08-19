#!/usr/bin/env bash
# Simulates a canonical crypto intent/deposit canary in MariaDB and rolls back.
# It validates the intent_id snapshot contract without touching real funds.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
bash "$ROOT/tools/dev/setup-financial-test-env.sh" >/dev/null
source "${ROOT%/app}/.local-runtime-secrets"
OUT="test-results/audit/synthetic_crypto_canary.out"
TX="canary_crypto_$(openssl rand -hex 10)"
WALLET="TJyts6uCHWeRExNefycbQGdgK8wEvkKtsJ"

mysql -h127.0.0.1 -P3306 -uchortke_local -p"$DB_PASSWORD" chortke_local --batch --raw > "$OUT" <<SQL
START TRANSACTION;
INSERT INTO crypto_deposit_intents
(user_id,currency,network,requested_amount,expected_amount,to_wallet,expires_at,status,created_at,updated_at)
VALUES (1,'USDT','TRC20',25.0,25.12345678,'$WALLET',DATE_ADD(NOW(),INTERVAL 30 MINUTE),'claimed',NOW(),NOW());
SET @intent_id = LAST_INSERT_ID();
INSERT INTO crypto_deposits
(user_id,intent_id,amount,currency,tx_hash,network,wallet_address,from_wallet,verification_status,auto_check_deadline,created_at,updated_at)
VALUES (1,@intent_id,25.12345678,'usdt','$TX','TRC20','$WALLET','TPNDtsqStzzGmqfxGaBZizduHZKTaTSaVL','pending',DATE_ADD(NOW(),INTERVAL 30 MINUTE),NOW(),NOW());

SELECT 'canary_snapshot' AS check_name,
       d.intent_id,
       (d.amount = i.expected_amount) AS amount_matches,
       (UPPER(d.network) = UPPER(i.network)) AS network_matches,
       (d.wallet_address = i.to_wallet) AS wallet_matches,
       (i.expires_at > NOW()) AS intent_live
FROM crypto_deposits d
JOIN crypto_deposit_intents i ON i.id = d.intent_id
WHERE d.tx_hash = '$TX';

SOURCE test-results/audit/legacy_crypto_deposit_audit.sql;
ROLLBACK;
SQL

awk -F '\t' '$1 == "canary_snapshot" && $3 == 1 && $4 == 1 && $5 == 1 && $6 == 1 { found=1 } END { exit(found ? 0 : 1) }' "$OUT" || { echo "Canonical crypto canary snapshot failed" >&2; exit 1; }
# The canary must not be reported as a legacy anomaly.
if grep -q "$TX" "$OUT" && grep -q 'intent_snapshot_mismatch' "$OUT"; then
  echo "Canary was incorrectly classified as legacy" >&2; exit 1
fi
echo "Synthetic crypto canary passed; fixtures rolled back."
echo "Output: $OUT"
