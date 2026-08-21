#!/usr/bin/env bash
# بازاعمال رفع‌های واقعی PHPStan L9 روی درخت استخراج‌شده پس از ریست sandbox.
set -euo pipefail
TARGET="${1:-/home/user/extract/workspace1e/chortke}"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[[ -d "$TARGET" ]] || { echo "درخت پروژه یافت نشد: $TARGET" >&2; exit 1; }
for rel in core/Session.php core/Request.php core/EventDispatcher.php \
           core/ExceptionHandler.php core/QueryBuilder.php core/Event.php \
           core/GenericEvent.php core/Scheduler.php core/IdempotencyKey.php \
           core/Sql/SafeExpression.php app/Traits/ClientInfoTrait.php \
           app/Services/MigrationService.php \
           app/Events/NotificationChannelRequestedEvent.php \
           app/Events/NotificationRequestedEvent.php app/Events/WithdrawalEvent.php; do
    install -D -m 644 "$SRC/$rel" "$TARGET/$rel"
    echo "  ✓ $rel"
done
for cfg in phpstan.neon phpstan_core.neon phpstan_full.neon phpstan-core-honest.neon; do
    install -m 644 "$SRC/configs/$cfg" "$TARGET/$cfg"
    echo "  ✓ $cfg"
done
echo
echo "تأیید:"
cd "$TARGET"
php -d memory_limit=3G vendor/bin/phpstan analyse -c phpstan.neon --no-progress 2>/dev/null | grep -E "Found|No errors"
php -d memory_limit=3G vendor/bin/phpstan analyse -c phpstan-core-honest.neon --no-progress 2>/dev/null | grep -E "Found|No errors"
