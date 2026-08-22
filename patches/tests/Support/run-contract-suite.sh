#!/usr/bin/env bash
#
# run-contract-suite.sh — اجرای بازتولیدپذیر سوئیت Contract
#
# چرا این اسکریپت لازم است:
#   تست‌های contract عمداً بررسی می‌کنند که گارد SSRF، آدرس‌های loopback و
#   خصوصی را مسدود کند. بنابراین سرور جعلی نمی‌تواند روی 127.0.0.1 گوش دهد —
#   وگرنه تست‌های SSRF بی‌معنا می‌شوند. راه‌حل، ساخت یک alias از یک IP عمومی
#   (8.8.8.8) روی رابط lo، داخل یک network namespace خصوصی است. این کار
#   نه به root نیاز دارد و نه شبکهٔ میزبان را لمس می‌کند.
#
# استفاده:
#   tests/Support/run-contract-suite.sh [آرگومان‌های اضافی phpunit]
#
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$PROJECT_ROOT"

FAKE_IP="${PROVIDER_FAKE_IP:-8.8.8.8}"
FAKE_PORT="${PROVIDER_FAKE_PORT:-8092}"
# مسیر state نسبی به ریشهٔ پروژه است، نه یک مسیر مطلقِ سخت‌کدشده.
STATE_DIR="${PROVIDER_FAKE_STATE_DIR:-$PROJECT_ROOT/build/provider-fake-state}"
# داخل network namespace، سرورِ Redis میزبان دیده نمی‌شود و لایهٔ کش بی‌سروصدا
# به فایل fallback می‌کند. اگر باینری Redis موجود باشد، یک نمونهٔ جداگانه هم
# داخل همان namespace بالا می‌آید تا مسیر واقعیِ کش آزموده شود.
REDIS_BIN="${PROVIDER_FAKE_REDIS_BIN:-/home/user/tools/redis/bin/redis-server}"
REDIS_PORT="${PROVIDER_FAKE_REDIS_PORT:-6379}"

mkdir -p "$STATE_DIR"
rm -f "$STATE_DIR"/* 2>/dev/null || true

if ! unshare -rn true 2>/dev/null; then
    echo "خطا: unshare در دسترس نیست؛ نمی‌توان alias حلقهٔ محلی را ساخت." >&2
    echo "      سوئیت contract بدون آن قابل اجرا نیست." >&2
    exit 1
fi

export PROVIDER_FAKE_STATE_DIR="$STATE_DIR"
export PROVIDER_CONTRACT_BASE_URL="http://${FAKE_IP}:${FAKE_PORT}"
export NO_PROXY="${FAKE_IP},127.0.0.1,localhost"
export no_proxy="$NO_PROXY"

echo "── سرور جعلی: ${PROVIDER_CONTRACT_BASE_URL}"
echo "── دایرکتوری state: ${STATE_DIR}"
echo

# همه چیز داخل یک network namespace واحد اجرا می‌شود تا سرور و phpunit
# بتوانند از طریق alias یکدیگر را ببینند.
exec unshare -rn bash -euo pipefail -c '
    ip link set lo up
    ip addr add '"$FAKE_IP"'/32 dev lo

    if [ -x '"$REDIS_BIN"' ]; then
        '"$REDIS_BIN"' --bind 127.0.0.1 --port '"$REDIS_PORT"' \
            --save "" --appendonly no --daemonize no \
            >'"$STATE_DIR"'/redis.log 2>&1 &
        REDIS_PID=$!
        trap "kill $REDIS_PID 2>/dev/null || true" EXIT
    fi

    php -S '"$FAKE_IP"':'"$FAKE_PORT"' \
        -t '"$PROJECT_ROOT"' \
        '"$PROJECT_ROOT"'/tests/Support/fake-provider-server.php \
        >'"$STATE_DIR"'/server.log 2>&1 &
    SERVER_PID=$!
    trap "kill $SERVER_PID ${REDIS_PID:-} 2>/dev/null || true" EXIT

    for _ in $(seq 1 50); do
        if php -r "exit(@fsockopen(\"'"$FAKE_IP"'\", '"$FAKE_PORT"', \$e, \$s, 0.2) ? 0 : 1);" 2>/dev/null; then
            break
        fi
        sleep 0.1
    done

    php -d memory_limit=2G vendor/bin/phpunit -c phpunit.contract.xml "$@"
' _ "$@"
