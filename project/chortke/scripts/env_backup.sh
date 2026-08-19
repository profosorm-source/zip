#!/usr/bin/env bash
#
# env_backup.sh — آماده‌سازی سریع محیط «چورته» پس از reset + بکاپ/بازگردانی.
#
# این اسکریپت idempotent است (اجرای چندباره خطا نمی‌دهد) و در دایرکتوری
# workspace-ماندگار scripts/ نگهداری می‌شود.
#
# کاربرد:
#   scripts/env_backup.sh          # آماده‌سازی کامل (نصب + migrate + seed) + ساخت snapshot
#   scripts/env_backup.sh --restore # بازگردانی سریع از snapshot (بدون migrate/seed)
#
set -euo pipefail

ROOT="/home/user/chortke"
SCRIPTS="$ROOT/scripts"
DB_BACKUP="$SCRIPTS/db_backup.sql.gz"
PKG_LIST="$SCRIPTS/apt-packages.txt"
DB_NAME="chortk"

PKGS=(
  php-cli php-bcmath php-mbstring php-xml php-curl php-zip php-sqlite3 php-mysql php-redis
  composer mariadb-server mariadb-client redis-server
)

log()  { echo "[env] $*"; }
sudo_n() { if command -v sudo >/dev/null 2>&1; then sudo -n "$@"; else "$@"; fi; }

ensure_hosts() {
  grep -q "127.0.0.1 redis" /etc/hosts || echo "127.0.0.1 redis" | sudo_n tee -a /etc/hosts >/dev/null
}

install_packages() {
  if command -v php >/dev/null 2>&1 && command -v composer >/dev/null 2>&1; then
    log "PHP و Composer موجودند؛ نصب رد می‌شود."
  else
    log "نصب بسته‌های سیستم..."
    sudo_n apt-get install -y --no-install-recommends "${PKGS[@]}" >/dev/null 2>&1
  fi
  sudo_n apt-get install -y --no-install-recommends mariadb-server mariadb-client redis-server >/dev/null 2>&1 || true
}

start_services() {
  log "بالا آوردن سرویس‌ها..."
  sudo_n service mariadb start >/dev/null 2>&1 || true
  sudo_n service redis-server start >/dev/null 2>&1 || true
  sleep 2
}

setup_db_root() {
  log "تنظیم دسترسی root دیتابیس..."
  sudo_n mysql <<'EOF' >/dev/null 2>&1 || true
ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('');
CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED BY '';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;
FLUSH PRIVILEGES;
EOF
}

ensure_db() {
  mysql -uroot -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" >/dev/null 2>&1 || true
}

write_phpstan_config() {
  cat > /tmp/phpstan-noignore.neon <<'EOF'
parameters:
    level: 9
    paths:
        - /home/user/chortke/app/
        - /home/user/chortke/core/
    excludePaths:
        - /home/user/chortke/app/Views/*
        - /home/user/chortke/app/Cache/*
        - /home/user/chortke/core/Cache/*
    bootstrapFiles:
        - /home/user/chortke/phpstan-bootstrap.php
    stubFiles:
        - /home/user/chortke/phpstan-stubs.php
    reportUnmatchedIgnoredErrors: false
    treatPhpDocTypesAsCertain: false
    universalObjectCratesClasses:
        - stdClass
    parallel:
        maximumNumberOfProcesses: 1
        processTimeout: 300.0
EOF
  log "کانفیگ PHPStan بدون-ignore نوشته شد (/tmp/phpstan-noignore.neon)."
}

migrate_seed() {
  log "اجرای migrate..."
  (cd "$ROOT" && php migrate.php >/dev/null 2>&1)
  log "اجرای seed..."
  (cd "$ROOT" && php database/migrations/2026_06_16_0005_seed_initial_data.php >/dev/null 2>&1)
}

make_backup() {
  log "ساخت snapshot دیتابیس و لیست بسته‌ها..."
  mkdir -p "$SCRIPTS"
  mysqldump -uroot --single-transaction --routines --triggers "$DB_NAME" 2>/dev/null | gzip > "$DB_BACKUP"
  dpkg-query -W -f='${binary:Package}\n' "${PKGS[@]}" 2>/dev/null | grep -v '^$' > "$PKG_LIST" || true
  log "بکاپ آماده: $DB_BACKUP و $PKG_LIST"
}

restore_backup() {
  if [[ ! -f "$DB_BACKUP" ]]; then
    log "بکاپ یافت نشد؛ انجام آماده‌سازی کامل..."
    full_setup
    return
  fi
  install_packages
  start_services
  ensure_hosts
  setup_db_root
  ensure_db
  write_phpstan_config
  log "بازگردانی snapshot دیتابیس..."
  gunzip -c "$DB_BACKUP" | mysql -uroot "$DB_NAME" 2>/dev/null || true
  log "بازگردانی کامل شد."
}

full_setup() {
  install_packages
  start_services
  ensure_hosts
  setup_db_root
  ensure_db
  migrate_seed
  write_phpstan_config
  make_backup
}

main() {
  if [[ "${1:-}" == "--restore" ]]; then
    restore_backup
  else
    full_setup
  fi
  log "پایان."
}

main "$@"
