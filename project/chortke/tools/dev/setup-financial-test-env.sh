#!/usr/bin/env bash
# Rebuild an isolated local financial-test runtime after a sandbox/package reset.
# Never points at production. Requires only the local workspace .env and secret file.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

export DEBIAN_FRONTEND=noninteractive

if ! command -v php >/dev/null 2>&1 || ! command -v mariadb >/dev/null 2>&1 || ! command -v redis-server >/dev/null 2>&1; then
  sudo apt-get update -qq
  sudo apt-get install -y -qq \
    php8.4-cli php8.4-common php8.4-mbstring php8.4-xml php8.4-mysql \
    php8.4-redis php8.4-bcmath php8.4-curl php8.4-zip \
    mariadb-server mariadb-client redis-server curl
fi

SECRETS_FILE="${ROOT%/app}/.local-runtime-secrets"
if [[ ! -r "$SECRETS_FILE" ]]; then
  echo "Missing local runtime secret file: $SECRETS_FILE" >&2
  echo "Restore the isolated .env/secrets first; do not use production credentials." >&2
  exit 2
fi
# shellcheck disable=SC1090
source "$SECRETS_FILE"
: "${DB_PASSWORD:?DB_PASSWORD missing}"
: "${REDIS_PASSWORD:?REDIS_PASSWORD missing}"

sudo install -d -o mysql -g mysql -m 755 /run/mysqld
if ! sudo mariadb-admin --protocol=socket ping --silent >/dev/null 2>&1; then
  sudo /usr/sbin/mariadbd \
    --user=mysql --datadir=/var/lib/mysql \
    --socket=/run/mysqld/mysqld.sock --pid-file=/run/mysqld/mysqld.pid \
    --bind-address=127.0.0.1 --port=3306 \
    --log-error=/tmp/chortke-mariadb.log >/dev/null 2>&1 &
fi
for _ in $(seq 1 30); do
  sudo mariadb-admin --protocol=socket ping --silent >/dev/null 2>&1 && break
  sleep 1
done
sudo mariadb-admin --protocol=socket ping --silent >/dev/null

sudo mariadb --protocol=socket <<SQL
CREATE DATABASE IF NOT EXISTS chortke_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'chortke_local'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
CREATE USER IF NOT EXISTS 'chortke_local'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON chortke_local.* TO 'chortke_local'@'localhost';
GRANT ALL PRIVILEGES ON chortke_local.* TO 'chortke_local'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

if ! redis-cli -h 127.0.0.1 -p 6379 -a "$REDIS_PASSWORD" ping 2>/dev/null | grep -qx PONG; then
  redis-server --port 6379 --bind 127.0.0.1 --protected-mode yes \
    --requirepass "$REDIS_PASSWORD" --daemonize yes \
    --dir "${ROOT%/app}" --dbfilename chortke-local.rdb \
    --logfile "${ROOT%/app}/redis.local.log"
fi
redis-cli -h 127.0.0.1 -p 6379 -a "$REDIS_PASSWORD" CONFIG SET stop-writes-on-bgsave-error no >/dev/null

mkdir -p storage/cache/app storage/logs storage/sessions storage/uploads test-results/audit
php cli.php migration > test-results/audit/runtime-migration.log 2>&1
php vendor/bin/phpunit --colors=never tests/Integration/Financial \
  > test-results/audit/runtime-financial-tests.log 2>&1
php vendor/bin/phpstan analyse --no-progress --memory-limit=2G \
  > test-results/audit/runtime-phpstan.log 2>&1

echo "Local financial runtime ready."
echo "Migration log: test-results/audit/runtime-migration.log"
echo "Financial tests: test-results/audit/runtime-financial-tests.log"
echo "PHPStan: test-results/audit/runtime-phpstan.log"
