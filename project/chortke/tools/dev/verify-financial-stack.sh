#!/usr/bin/env bash
# End-to-end local verification with real services and synthetic financial data.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

"$ROOT/tools/dev/setup-financial-test-env.sh"
"$ROOT/tools/dev/run-synthetic-financial-audit.sh"

nohup php -S 127.0.0.1:8090 -t public public/index.php \
  > test-results/audit/verify-web-8090.log 2>&1 < /dev/null &
WEB_PID=$!
cleanup() { kill "$WEB_PID" >/dev/null 2>&1 || true; }
trap cleanup EXIT
for _ in $(seq 1 15); do
  curl -fsS --max-time 2 http://127.0.0.1:8090/health/live >/dev/null && break
  sleep 1
done

php vendor/bin/phpunit --colors=never > test-results/audit/verify-full-phpunit.log 2>&1
php vendor/bin/phpstan analyse --no-progress --memory-limit=2G > test-results/audit/verify-phpstan.log 2>&1

echo "Financial stack verification passed."
