#!/usr/bin/env sh
set -eu

ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
cd "$ROOT"

if command -v gitleaks >/dev/null 2>&1; then
  exec gitleaks detect --source . --redact --verbose
fi

# Lightweight fallback for environments without gitleaks.
# It intentionally skips examples, vendor, tests, storage and documentation noise.
PATTERN='(APP_KEY|APP_SECRET|SECURITY_API_TOKEN_SECRET|SECRET_KEY|RECAPTCHA_SECRET_KEY|API_KEY|API_TOKEN|ACCESS_TOKEN|PRIVATE_KEY|PASSWORD|DB_PASS)[[:space:]]*=[[:space:]]*[^[:space:]#]{12,}'

FOUND=0
while IFS= read -r file; do
  case "$file" in
    ./.git/*|./vendor/*|./tests/*|./storage/*|./docs/*|*.example|*.example.*|*README*|*.md) continue ;;
  esac
  if grep -InE "$PATTERN" "$file" 2>/dev/null       | grep -Ev '(your-|example|changeme|dummy|test|\$\{[^}]+\}|=null|=false|=true)'       >/tmp/chortke_secret_scan_match; then
    cat /tmp/chortke_secret_scan_match
    FOUND=1
  fi
done <<EOF
$(find . -type f \
  ! -path './vendor/*' \
  ! -path './.git/*' \
  ! -path './storage/*' \
  ! -path './tests/*' \
  ! -path './public/uploads/*')
EOF

rm -f /tmp/chortke_secret_scan_match

if [ "$FOUND" -ne 0 ]; then
  echo "Secret scan failed. Install gitleaks for stronger scanning." >&2
  exit 1
fi

echo "Secret scan passed (fallback scanner). Install gitleaks for stronger scanning."
