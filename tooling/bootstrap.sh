#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
SOURCE_REF="${CHORTKE_SOURCE_REF:-origin/arena/01a01b16-zip}"

echo "[1/7] Fetching the latest completed Chortke session..."
git fetch origin arena/01a01b16-zip --depth=1
rm -rf runtime
mkdir -p runtime
git archive "$SOURCE_REF" project/chortke | tar -x -C runtime --strip-components=2
git show "$SOURCE_REF":CHORTKE_TEST_PHPSTAN_CHECKPOINT_FA.md > runtime/CHORTKE_TEST_PHPSTAN_CHECKPOINT_FA.md

echo "[2/7] Restoring Composer vendor snapshot from chortke.zip..."
rm -rf /tmp/chortke_vendor
mkdir -p /tmp/chortke_vendor
unzip -q chortke.zip 'chortke/vendor/*' -d /tmp/chortke_vendor
rm -rf runtime/vendor
mv /tmp/chortke_vendor/chortke/vendor runtime/vendor
rm -rf /tmp/chortke_vendor

echo "[3/7] Installing PHP 8.4 WASM and browser-test packages..."
npm ci --prefix tooling
npm ci --prefix runtime

echo "[4/7] Preparing disposable SQLite preview adapter..."
python3 tooling/patch-runtime.py
mkdir -p runtime/storage/{logs,cache,sessions,uploads,exports,backups,email_fallback}
chmod -R u+rwX runtime/storage

PREVIEW_HOST="localhost:8000"
PREVIEW_SCHEME="http"
if [[ -n "${E2B_SANDBOX_ID:-}" ]]; then
  PREVIEW_HOST="8000-${E2B_SANDBOX_ID}.e2b.app"
  PREVIEW_SCHEME="https"
fi
APP_KEY="$(openssl rand -hex 32)"
API_KEY="$(openssl rand -hex 32)"
cat > runtime/.env <<EOF
APP_NAME=چرتکه
APP_ENV=local
APP_DEBUG=true
APP_URL=${PREVIEW_SCHEME}://${PREVIEW_HOST}
APP_BASE_PATH=
APP_KEY=${APP_KEY}
SECURITY_API_TOKEN_SECRET=${API_KEY}
DB_DRIVER=sqlite
DB_DATABASE=/workspace/runtime/storage/chortke.sqlite
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=chortke
DB_USER=root
DB_PASS=
SESSION_DRIVER=file
SESSION_FALLBACK_STORAGE=file
SESSION_LIFETIME=7200
SESSION_SECURE=false
REDIS_ENABLED=false
CACHE_DRIVER=file
DB_TRACK_QUERIES=false
APP_TIMEZONE=Asia/Tehran
FEATURE_LOTTERY_ENABLED=true
FEATURE_INVESTMENT_ENABLED=true
FEATURE_TASKS_ENABLED=true
FEATURE_REFERRAL_ENABLED=true
FEATURE_COUPONS_ENABLED=true
FEATURE_CRYPTO_ENABLED=true
FEATURE_VITRINE_ENABLED=true
EOF

echo "[5/7] Initializing the real SQLite preview database..."
node tooling/php-cli.mjs tooling/initdb.php

echo "[6/7] Verifying PHP runtime..."
node tooling/php.mjs -v | head -1

echo "[7/7] Bootstrap complete."
echo "Start with: PORT=8000 node tooling/php-server.mjs"
echo "Health URL: ${PREVIEW_SCHEME}://${PREVIEW_HOST}/api/health"
