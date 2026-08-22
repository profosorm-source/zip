#!/usr/bin/env python3
"""
Chortke HTTP Test Harness — زیرساخت جامع تست‌های سازمانی ۱۰ لایه‌ای (Enterprise 10-Tier QA Harness)

امکانات کلیدی معماری جدید (v3.0):
- DB snapshot/restore جهت ایزوله‌سازی کامل اجرای سناریوها
- مدیریت یکپارچه سشن‌ها (Login, CSRF, Cookie Jars)
- شبیه‌ساز قدرتمند درخواست‌های همزمان (Race Condition Injector) جهت بررسی قفل‌ها و Idempotency (لایه ۶)
- رانرهای اختصاصی پردازش‌های ناهمگام: Cron, Outbox, DLQ و Queue Worker (لایه ۹)
- ابزارهای بازرسی مستقیم آبزروابیلیتی و مانیتورینگ: Sentry Issues/Events و Audit Trails (لایه ۱۰)
- سیستم گزارش‌دهی ساختاریافته بر مبنای ماتریس ۱۰ لایه‌ای (L1 تا L10)

استفاده:
    python3 tests/scenario_test.py
"""

import subprocess
import re
import json
import sys
import time
import os
import shutil
import concurrent.futures
from dataclasses import dataclass, field
from typing import Optional, Callable, Dict, List, Tuple
from pathlib import Path

def _project_root() -> Path:
    """ریشه پروژه را نسبت به محل همین فایل پیدا می‌کند (tests/ -> chortke/)."""
    return Path(__file__).resolve().parent.parent


def _load_dotenv() -> Dict[str, str]:
    """
    خواندن پیکربندی واقعی پروژه از فایل .env

    منبع حقیقت برای آدرس سرویس و اتصال دیتابیس همان .env است که خود اپلیکیشن
    از طریق config/config.php از آن استفاده می‌کند؛ بنابراین هارنس تست نیز باید
    از همان مقادیر بخواند و آن‌ها را تکرار/هاردکد نکند.
    """
    values: Dict[str, str] = {}
    # همان ترتیب bootstrap/app.php: ابتدا .env و در صورت نبود آن .env.local
    # (این دو با هم ادغام نمی‌شوند؛ هر کدام که موجود بود همان مبنا است).
    root = _project_root()
    env_path = root / ".env"
    if not env_path.is_file():
        env_path = root / ".env.local"
    if not env_path.is_file():
        return values
    for raw_line in env_path.read_text(encoding="utf-8", errors="replace").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        value = value.strip().strip('"').strip("'")
        values[key.strip()] = value
    return values


_ENV = _load_dotenv()


def env_value(key: str, default: str = "") -> str:
    """اولویت با متغیر محیطی واقعی، سپس .env پروژه، سپس مقدار پیش‌فرض."""
    return os.environ.get(key) or _ENV.get(key) or default


BASE_URL = env_value("CHORTKE_E2E_BASE_URL", env_value("APP_URL", "http://127.0.0.1:8080")).rstrip("/")
DB_HOST = env_value("DB_HOST", "127.0.0.1")
DB_PORT = env_value("DB_PORT", "3306")
DB_NAME = env_value("DB_NAME", "chortk")
DB_USER = env_value("DB_USER", "root")
DB_PASS = env_value("DB_PASS", "")
SNAPSHOT_FILE = os.environ.get("CHORTKE_TEST_SNAPSHOT", "/tmp/chortke_test_snapshot.sql")
ADMIN_EMAIL = env_value("E2E_ADMIN_EMAIL", "admin@chortke.ir")
ADMIN_PASS = env_value("E2E_ADMIN_PASSWORD", "123456")
DEFAULT_PASSWORD = env_value("E2E_PASSWORD", "123456")


def db_conn_args() -> List[str]:
    """
    آرگومان‌های اتصال دیتابیس بر اساس پیکربندی واقعی پروژه.

    نکته: پیش از این گذرواژه هرگز به کلاینت پاس داده نمی‌شد و کاربر به صورت
    ثابت root فرض شده بود؛ در نتیجه هارنس روی هر محیطی که .env آن کاربر/گذرواژه
    اختصاصی داشت شکست می‌خورد.
    """
    args = ["-h", DB_HOST, "-P", str(DB_PORT), "-u", DB_USER]
    if DB_PASS:
        args.append(f"-p{DB_PASS}")
    return args

# ═══════════════════════════════════════════════════════════════════
# Enterprise 10-Tier Architecture Definitions
# ═══════════════════════════════════════════════════════════════════
TIERS = {
    "L1": "Smoke & Sanity",
    "L2": "Happy Path",
    "L3": "Failure Paths",
    "L4": "Security & Auth",
    "L5": "Edge Cases",
    "L6": "Concurrency & Idempotency",
    "L7": "Browser E2E",
    "L8": "Data Integrity",
    "L9": "Async & Queues",
    "L10": "Infra & Observability"
}

# ═══════════════════════════════════════════════════════════════════
# Colors
# ═══════════════════════════════════════════════════════════════════
GREEN = "\033[92m"
RED = "\033[91m"
YELLOW = "\033[93m"
CYAN = "\033[96m"
MAGENTA = "\033[95m"
RESET = "\033[0m"
BOLD = "\033[1m"


# ═══════════════════════════════════════════════════════════════════
# DB Helpers
# ═══════════════════════════════════════════════════════════════════
def db_snapshot():
    """گرفتن snapshot از دیتابیس برای restore بعد از تست"""
    import shutil
    dump_cmd = 'mariadb-dump' if shutil.which('mariadb-dump') else ('mysqldump' if shutil.which('mysqldump') else None)
    if not dump_cmd:
        return False
    try:
        subprocess.run(
            [dump_cmd, *db_conn_args(), DB_NAME, "--no-tablespaces",
             "--single-transaction", "--routines", "--triggers",
             "-r", SNAPSHOT_FILE],
            capture_output=True
        )
        return os.path.exists(SNAPSHOT_FILE) and os.path.getsize(SNAPSHOT_FILE) > 0
    except Exception:
        return False


def db_restore():
    """بازگردانی دیتابیس به وضعیت قبل از تست"""
    import shutil
    if not os.path.exists(SNAPSHOT_FILE):
        return False
    cli_cmd = 'mariadb' if shutil.which('mariadb') else ('mysql' if shutil.which('mysql') else 'mariadb')
    try:
        subprocess.run(
            [cli_cmd, *db_conn_args(), DB_NAME],
            stdin=open(SNAPSHOT_FILE, 'r'),
            capture_output=True
        )
        return True
    except Exception:
        return False


def get_mysql_cli() -> str:
    return shutil.which('mariadb') or shutil.which('mysql') or 'mariadb'

def reset_rate_limits():
    """Reset rate limits in database and cache"""
    cli = get_mysql_cli()
    subprocess.run([cli, *db_conn_args(), DB_NAME, "-e", "TRUNCATE rate_limit_requests; TRUNCATE rate_limits;"], capture_output=True)
    try:
        subprocess.run(["redis-cli", "FLUSHALL"], capture_output=True)
    except Exception:
        pass


def db_query(sql: str) -> list:
    """اجرای کوئری و برگرداندن نتایج"""
    cli = get_mysql_cli()
    result = subprocess.run(
        [cli, *db_conn_args(), DB_NAME, "-N", "-B", "-e", sql],
        capture_output=True, text=True
    )
    return [line.strip() for line in result.stdout.strip().split('\n') if line.strip()] if result.stdout.strip() else []


def db_scalar(sql: str) -> str:
    """اجرای کوئری و برگرداندن یک مقدار"""
    cli = get_mysql_cli()
    result = subprocess.run(
        [cli, *db_conn_args(), DB_NAME, "-N", "-B", "-e", sql],
        capture_output=True, text=True
    )
    return result.stdout.strip()


def db_insert(sql: str):
    """اجرای INSERT/UPDATE/DELETE"""
    cli = get_mysql_cli()
    subprocess.run(
        [cli, *db_conn_args(), DB_NAME, "-e", sql],
        capture_output=True, text=True
    )


# ═══════════════════════════════════════════════════════════════════
# HTTP Client & Race Condition Injector
# ═══════════════════════════════════════════════════════════════════
class HttpClient:
    def __init__(self, jar_path: str):
        self.jar = jar_path
        if os.path.exists(self.jar):
            os.remove(self.jar)

    def get(self, path: str, expect_code: int = None) -> tuple:
        url = f"{BASE_URL}{path}"
        r = subprocess.run(
            ['curl', '-sS', '-b', self.jar, '-c', self.jar,
             '-o', '/tmp/ht_body.html', '-w', '%{http_code}',
             '--max-time', '15', url],
            capture_output=True, text=True, timeout=20
        )
        code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
        body = Path('/tmp/ht_body.html').read_text(encoding='utf-8', errors='replace') if Path('/tmp/ht_body.html').exists() else ''
        if expect_code and code != expect_code:
            print(f"    {YELLOW}⚠ Expected {expect_code}, got {code}{RESET}")
        return code, body

    def extract_csrf_from_html(self, html: str) -> str:
        if not html:
            return ''
        m = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', html)
        if m:
            return m.group(1)
        m = re.search(r'value="([^"]+)"[^>]*name="_csrf_token"', html)
        if m:
            return m.group(1)
        m = re.search(r'csrf-token"\s+content="([^"]+)"', html)
        if m:
            return m.group(1)
        return ''

    def get_csrf(self, path: str = None) -> str:
        if path is None:
            path = '/admin/dashboard' if getattr(self, 'is_admin', False) else '/dashboard'
        code, body = self.get(path)
        return self.extract_csrf_from_html(body)

    def post(self, path: str, data: dict = None, expect_code: int = None,
             csrf_token: str = None, page_body: str = None) -> tuple:
        url = f"{BASE_URL}{path}"
        # CSRF token: از body موجود یا از صفحه فعلی استخراج کن
        if csrf_token:
            token = csrf_token
        elif page_body:
            token = self.extract_csrf_from_html(page_body)
        else:
            token = self.get_csrf()
        cmd = ['curl', '-sS', '-b', self.jar, '-c', self.jar, '-X', 'POST', url,
               '-o', '/tmp/ht_body.html', '-w', '%{http_code}', '--max-time', '15']
        if data:
            cmd.extend(['--data-urlencode', f'_csrf_token={token}'])
            for k, v in data.items():
                cmd.extend(['--data-urlencode', f'{k}={v}'])
        elif token:
            cmd.extend(['--data-urlencode', f'_csrf_token={token}'])
        r = subprocess.run(cmd, capture_output=True, text=True, timeout=20)
        code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
        body = Path('/tmp/ht_body.html').read_text(encoding='utf-8', errors='replace') if Path('/tmp/ht_body.html').exists() else ''
        # Try parse JSON
        json_body = None
        try:
            json_body = json.loads(body)
        except:
            pass
        if expect_code and code != expect_code:
            print(f"    {YELLOW}⚠ Expected {expect_code}, got {code}{RESET}")
        return code, body, json_body

    def post_json(self, path: str, data: dict, expect_code: int = None) -> tuple:
        """POST با JSON body و headerهای API"""
        url = f"{BASE_URL}{path}"
        token = self.get_csrf()
        cmd = ['curl', '-sS', '-b', self.jar, '-c', self.jar, '-X', 'POST', url,
               '-H', 'Content-Type: application/json',
               '-H', 'Accept: application/json',
               '-H', f'X-CSRF-TOKEN: {token}',
               '-d', json.dumps(data),
               '-o', '/tmp/ht_body.html', '-w', '%{http_code}', '--max-time', '15']
        r = subprocess.run(cmd, capture_output=True, text=True, timeout=20)
        code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
        body = Path('/tmp/ht_body.html').read_text(encoding='utf-8', errors='replace') if Path('/tmp/ht_body.html').exists() else ''
        json_body = None
        try:
            json_body = json.loads(body)
        except:
            pass
        if expect_code and code != expect_code:
            print(f"    {YELLOW}⚠ Expected {expect_code}, got {code}{RESET}")
        return code, body, json_body

    def post_concurrent(self, path: str, data: dict, count: int = 5, csrf_token: str = None) -> list:
        """
        [لایه ۶] شبیه‌سازی درخواست‌های همزمان (Race Condition Injector)
        ارسال چندین درخواست موازی با سشن و توکن یکسان جهت تست قفل‌ها و Idempotency
        """
        url = f"{BASE_URL}{path}"
        token = csrf_token or self.get_csrf()
        
        def single_request(idx: int):
            tmp_body = f'/tmp/ht_body_race_{idx}.html'
            cmd = ['curl', '-sS', '-b', self.jar, '-c', self.jar, '-X', 'POST', url,
                   '-o', tmp_body, '-w', '%{http_code}', '--max-time', '15']
            cmd.extend(['--data-urlencode', f'_csrf_token={token}'])
            for k, v in data.items():
                cmd.extend(['--data-urlencode', f'{k}={v}'])
            r = subprocess.run(cmd, capture_output=True, text=True, timeout=20)
            code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
            body = Path(tmp_body).read_text(encoding='utf-8', errors='replace') if Path(tmp_body).exists() else ''
            json_body = None
            try:
                json_body = json.loads(body)
            except:
                pass
            return code, body, json_body

        with concurrent.futures.ThreadPoolExecutor(max_workers=count) as executor:
            futures = [executor.submit(single_request, i) for i in range(count)]
            results = [f.result() for f in concurrent.futures.as_completed(futures)]
        return results

    def login(self, email: str, password: str, admin: bool = False) -> bool:
        self.is_admin = admin
        login_path = '/admin/login' if admin else '/login'
        code, body = self.get(login_path)
        token = self.extract_csrf_from_html(body)
        if not token:
            return False

        # جمع‌آوری captcha اگر در صفحه وجود دارد (math captcha قابل حل)
        captcha_token = ''
        captcha_response = ''
        # math captcha question: e.g. "23 + 7 = ?"
        q = re.search(r'captcha-question[^>]*>\s*(\d+)\s*([+\-*])\s*(\d+)', body)
        ct = re.search(r'name="captcha_token"\s+value="([^"]+)"', body)
        if q and ct:
            a, op, b = int(q.group(1)), q.group(2), int(q.group(3))
            answer = {'+': a+b, '-': a-b, '*': a*b}[op]
            captcha_token = ct.group(1)
            captcha_response = str(answer)

        fields = [
            f'_csrf_token={token}',
            f'email={email}',
            f'password={password}',
        ]
        if captcha_token:
            fields.append(f'captcha_token={captcha_token}')
            fields.append(f'captcha_response={captcha_response}')

        r = subprocess.run(
            ['curl', '-sS', '-b', self.jar, '-c', self.jar, '-X', 'POST',
             f'{BASE_URL}{login_path}']
            + sum([['--data-urlencode', f] for f in fields], [])
            + ['-o', '/dev/null', '-w', '%{http_code}', '--max-time', '15'],
            capture_output=True, text=True, timeout=20
        )
        code = r.stdout.strip()
        return code == '302'


# ═══════════════════════════════════════════════════════════════════
# Async Background Jobs & Observability Helpers (L9 & L10)
# ═══════════════════════════════════════════════════════════════════
def get_php_bin() -> str:
    return shutil.which('php') or '/usr/bin/php'

def run_cron():
    """[لایه ۹] اجرای دستی زمان‌بندی وظایف (Cron Dispatcher)"""
    return subprocess.run([get_php_bin(), 'cron.php'], capture_output=True, text=True, timeout=30)

def run_queue_work(limit: int = 10):
    """[لایه ۹] پردازش صف‌های سیستم"""
    return subprocess.run([get_php_bin(), 'cli.php', 'queue:work', f'--limit={limit}'], capture_output=True, text=True, timeout=30)

def run_outbox_publish(limit: int = 100):
    """[لایه ۹] انتشار رویدادهای ثبت‌شده در جدول Outbox به سیستم پیام‌رسان"""
    return subprocess.run([get_php_bin(), 'cli.php', 'outbox:publish', f'--limit={limit}'], capture_output=True, text=True, timeout=30)

def run_dlq_retry():
    """[لایه ۹] تلاش مجدد برای جاب‌های شکست‌خورده در صف مرده (DLQ)"""
    return subprocess.run([get_php_bin(), 'cli.php', 'dlq:retry'], capture_output=True, text=True, timeout=30)

def get_failed_jobs() -> list:
    """[لایه ۹] دریافت لیست پیام‌های سمی و شکست‌خورده در دیتابیس"""
    return db_query("SELECT id, queue, payload, failed_at FROM failed_jobs ORDER BY failed_at DESC")

def get_outbox_events() -> list:
    """[لایه ۹] دریافت وضعیت رکوردهای Outbox"""
    return db_query("SELECT id, event_type, status, created_at FROM outbox_events ORDER BY created_at DESC")

def get_sentry_issues() -> list:
    """[لایه ۱۰] دریافت لیست مشکلات ثبت‌شده در Sentry"""
    return db_query("SELECT id, status, level, last_seen, count FROM sentry_issues ORDER BY last_seen DESC")

def get_sentry_events() -> list:
    """[لایه ۱۰] دریافت رخدادهای خطای ثبت‌شده در Sentry"""
    return db_query("SELECT id, issue_id, level, created_at FROM sentry_events ORDER BY created_at DESC")

def get_audit_trails(user_id: int = None) -> list:
    """[لایه ۱۰] دریافت لاگ‌های حسابرسی و دسترسی‌ها"""
    where = f"WHERE user_id = {user_id}" if user_id else ""
    return db_query(f"SELECT id, user_id, action, ip_address, created_at FROM audit_trails {where} ORDER BY created_at DESC")


# ═══════════════════════════════════════════════════════════════════
# Test Runner & 10-Tier Tracking
# ═══════════════════════════════════════════════════════════════════
def _mark(passed):
    """نشانهٔ وضعیت: None یعنی یادداشت/skip، نه ادعای موفق."""
    if passed is None:
        return f"{YELLOW}⚠{RESET}"
    return f"{GREEN}✓{RESET}" if passed else f"{RED}✗{RESET}"


@dataclass
class TestResult:
    name: str
    passed: bool
    details: str = ""
    assertions: list = field(default_factory=list)
    tier: str = "L1"
    skipped: bool = False


class TestSuite:
    def __init__(self, name: str):
        self.name = name
        self.results: list[TestResult] = []

    def _extract_tier(self, test_name: str) -> str:
        """استخراج شناسه لایه (L1 تا L10) از نام تابع تست"""
        m = re.search(r'_(L10|L[1-9])_', test_name)
        return m.group(1) if m else "L1"

    def run_test(self, name: str, fn: Callable) -> TestResult:
        """اجرای یک تست با snapshot/restore + cache flush"""
        tier = self._extract_tier(name)
        tier_name = TIERS.get(tier, "Smoke & Sanity")
        print(f"\n  {CYAN}▶ {name} {MAGENTA}[{tier}: {tier_name}]{RESET}")
        assertions = []

        # Snapshot
        db_snapshot()
        # Flush cache to reset risk scores / captcha requirements
        php_bin = shutil.which('php') or '/usr/bin/php'
        try:
            subprocess.run([php_bin, 'tests/flush_cache.php'],
                           capture_output=True, timeout=15)
        except Exception:
            pass

        # Enable crypto_deposit feature flag (disabled by default after snapshot restore)
        db_insert("UPDATE feature_flags SET enabled=1 WHERE name IN ('crypto_deposit', 'crypto_wallet', 'crypto');")
        try:
            subprocess.run(["redis-cli", "FLUSHALL"], capture_output=True)
        except Exception:
            pass

        # Ensure site bank settings exist (needed for manual deposit page)
        db_insert("""INSERT INTO system_settings (`key`, value, `group`, type, is_public, created_at, updated_at) VALUES
                    ('site_irt_card_number', '6037-9975-1234-5678', 'general', 'string', 0, NOW(), NOW()),
                    ('site_irt_account_number', '1234567890', 'general', 'string', 0, NOW(), NOW()),
                    ('site_irt_sheba', 'IR820570022080012345678901', 'general', 'string', 0, NOW(), NOW()),
                    ('site_irt_bank_name', 'بانک ملی', 'general', 'string', 0, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE value=VALUES(value), updated_at=NOW()""")

        client = HttpClient(f"/tmp/test_{self.name}_{len(self.results)}.jar")
        try:
            fn(client, assertions)
            result = TestResult(name, True, "", assertions, tier=tier)
            for desc, passed in assertions:
                status = _mark(passed)
                print(f"    {status} {desc}")
        except ScenarioSkipped as e:
            result = TestResult(name, True, f"SKIPPED: {e}", assertions, tier=tier, skipped=True)
            print(f"    {YELLOW}⚠ SKIPPED: {e}{RESET}")
            for desc, passed in assertions:
                print(f"    {_mark(passed)} {desc}")
        except AssertionError as e:
            result = TestResult(name, False, str(e), assertions, tier=tier)
            print(f"    {RED}✗ ASSERT FAILED: {e}{RESET}")
            for desc, passed in assertions:
                print(f"    {_mark(passed)} {desc}")
        except Exception as e:
            result = TestResult(name, False, f"Exception: {e}", assertions, tier=tier)
            print(f"    {RED}✗ ERROR: {e}{RESET}")
        finally:
            db_restore()

        self.results.append(result)
        if result.skipped:
            status = f"{YELLOW}SKIP{RESET}"
        else:
            status = f"{GREEN}PASS{RESET}" if result.passed else f"{RED}FAIL{RESET}"
        print(f"  → {status}")
        return result

    def summary(self):
        skipped = sum(1 for r in self.results if r.skipped)
        passed = sum(1 for r in self.results if r.passed and not r.skipped)
        failed = len(self.results) - passed - skipped
        print(f"\n{'═' * 70}")
        print(f"{BOLD}گزارش تست سازمانی: {self.name}{RESET}")
        print(f"{'═' * 70}")
        print(f"  {GREEN}Passed: {passed}{RESET}  {RED}Failed: {failed}{RESET}  "
              f"{YELLOW}Skipped: {skipped}{RESET}  Total: {len(self.results)}\n")
        if skipped:
            print(f"  {YELLOW}توجه: {skipped} سناریو به دلیل نبود پیش‌نیاز داده اجرا نشد "
                  f"و در شمار موفق‌ها نیامده است.{RESET}\n")
        
        # 10-Tier Breakdown
        print(f"{BOLD}تحلیل پوشش ۱۰ لایه‌ای (10-Tier Coverage Breakdown):{RESET}")
        for tier_key, tier_name in TIERS.items():
            tier_results = [r for r in self.results if r.tier == tier_key]
            total_tier = len(tier_results)
            if total_tier == 0:
                print(f"  {YELLOW}⚠ {tier_key} ({tier_name}): 0 سناریو (نیازمند پوشش){RESET}")
            else:
                skipped_tier = sum(1 for r in tier_results if r.skipped)
                passed_tier = sum(1 for r in tier_results if r.passed and not r.skipped)
                failed_tier = total_tier - passed_tier - skipped_tier
                status_str = f"{GREEN}✓ PASS{RESET}" if failed_tier == 0 else f"{RED}✗ FAIL{RESET}"
                extra = f" ({YELLOW}{skipped_tier} skip{RESET})" if skipped_tier else ""
                print(f"  {status_str} {tier_key} ({tier_name}): "
                      f"{passed_tier}/{total_tier - skipped_tier}{extra}")

        print(f"\n{BOLD}جزئیات سناریوها:{RESET}")
        for r in self.results:
            if r.skipped:
                status = f"{YELLOW}⚠{RESET}"
            else:
                status = f"{GREEN}✓{RESET}" if r.passed else f"{RED}✗{RESET}"
            print(f"  {status} [{r.tier}] {r.name}")
            if not r.passed and r.details:
                print(f"      {RED}{r.details}{RESET}")
        return failed == 0


# ═══════════════════════════════════════════════════════════════════
# Assertion Helpers
# ═══════════════════════════════════════════════════════════════════
class ScenarioSkipped(Exception):
    """
    سناریو به دلیل نبودِ پیش‌نیاز داده اجرا نشد.

    پیش از این، نبودِ داده با assertions.append((desc, True)) ثبت می‌شد که
    تست را «سبز» نشان می‌داد. نتیجه: یک سناریوی هرگز-اجرانشده از یک سناریوی
    واقعاً موفق قابل تفکیک نبود. حالا صراحتاً SKIP گزارش می‌شود.
    """
    pass


def skip_scenario(assertions: list, reason: str):
    """سناریو را به‌صورت صریح SKIP کن (نه PASS ساختگی)."""
    assertions.append((f"SKIP: {reason}", None))
    raise ScenarioSkipped(reason)


def require_data(assertions: list, value, reason: str):
    """
    اگر پیش‌نیاز داده موجود نبود، سناریو را SKIP کن؛ در غیر این صورت مقدار را برگردان.
    جایگزین الگوی «if not x: assertions.append((..., True))».
    """
    if not value:
        skip_scenario(assertions, reason)
    return value


def note(assertions: list, desc: str):
    """
    ثبت یک یادداشتِ اطلاعاتی (نه ادعا). در شمارش ادعاها لحاظ نمی‌شود.
    جایگزین assertions.append((desc, True)) هنگامی که صرفاً اطلاعات ثبت می‌شود.
    """
    assertions.append((f"ℹ {desc}", None))


def assert_equal(assertions: list, desc: str, actual, expected):
    passed = actual == expected
    if not passed:
        desc = f"{desc} (got: {actual}, expected: {expected})"
    assertions.append((desc, passed))
    if not passed:
        raise AssertionError(f"{desc} — got: {actual}, expected: {expected}")


def assert_true(assertions: list, desc: str, value):
    assert_equal(assertions, desc, bool(value), True)


def assert_in_range(assertions: list, desc: str, value, low, high):
    passed = low <= value <= high
    assertions.append((f"{desc} ({value} in [{low}, {high}])", passed))
    if not passed:
        raise AssertionError(f"{desc} — {value} not in [{low}, {high}]")


def assert_db(assertions: list, desc: str, sql: str, expected_value):
    actual = db_scalar(sql)
    assert_equal(assertions, desc, actual, str(expected_value))


def assert_contains(assertions: list, desc: str, actual: str, expected_substring: str):
    passed = expected_substring in actual
    if not passed:
        desc = f"{desc} (substring '{expected_substring}' not found in actual)"
    assertions.append((desc, passed))
    if not passed:
        raise AssertionError(f"{desc} — substring '{expected_substring}' not found")


def assert_not_contains(assertions: list, desc: str, actual: str, unexpected_substring: str):
    passed = unexpected_substring not in actual
    if not passed:
        desc = f"{desc} (unexpected substring '{unexpected_substring}' found in actual)"
    assertions.append((desc, passed))
    if not passed:
        raise AssertionError(f"{desc} — unexpected substring '{unexpected_substring}' found")


def assert_match(assertions: list, desc: str, actual: str, regex_pattern: str):
    passed = bool(re.search(regex_pattern, actual))
    if not passed:
        desc = f"{desc} (regex '{regex_pattern}' did not match actual)"
    assertions.append((desc, passed))
    if not passed:
        raise AssertionError(f"{desc} — regex '{regex_pattern}' did not match")


# ═══════════════════════════════════════════════════════════════════
# Test Data Helpers
# ═══════════════════════════════════════════════════════════════════
def ensure_test_user(email: str, role: str = 'user', verified: bool = True,
                     balance_irt: str = '0', balance_usdt: str = '0') -> int:
    """ساخت یا به‌روزرسانی کاربر تستی با حالت دلخواه"""
    # سیستم از هش غیراستاندارد استفاده می‌کند: base64(sha384(password)) به‌جای password خام
    password_hash = subprocess.run(
        [get_php_bin(), '-r',
         "echo password_hash(base64_encode(hash('sha384', '" + DEFAULT_PASSWORD + "', true)), PASSWORD_DEFAULT);"],
        capture_output=True, text=True
    ).stdout.strip()

    existing = db_scalar(f"SELECT id FROM users WHERE email='{email}'")
    verified_at = "NOW()" if verified else "NULL"
    # سازگاری با Python < 3.12: بک‌اسلش داخل عبارتِ f-string تا PEP 701 خطای نحوی است.
    # مقدار از پیش ساخته می‌شود تا f-string عاری از بک‌اسلش بماند.
    kyc_sql_value = "'verified'" if verified else "'unverified'"
    username_value = email.split('@')[0]

    if existing:
        db_insert(f"""
            UPDATE users SET password='{password_hash}', role='{role}',
            status='active', email_verified_at={verified_at},
            kyc_status={kyc_sql_value}
            WHERE id={existing}
        """)
        user_id = existing
    else:
        db_insert(f"""
            INSERT INTO users (username, email, password, role, status, email_verified_at, kyc_status, created_at, updated_at)
            VALUES ('{username_value}', '{email}', '{password_hash}', '{role}', 'active', {verified_at}, {kyc_sql_value}, NOW(), NOW())
        """)
        user_id = db_scalar(f"SELECT id FROM users WHERE email='{email}'")

    if verified:
        kyc_exists = db_scalar(f"SELECT id FROM kyc_verifications WHERE user_id={user_id}")
        if not kyc_exists:
            db_insert(f"INSERT INTO kyc_verifications (user_id, status, verified_at, created_at) VALUES ({user_id}, 'verified', NOW(), NOW())")

    # Ensure wallet exists
    if balance_irt or balance_usdt:
        wallet_exists = db_scalar(f"SELECT id FROM wallets WHERE user_id={user_id}")
        if wallet_exists:
            db_insert(f"UPDATE wallets SET balance_irt={balance_irt}, balance_usdt={balance_usdt} WHERE user_id={user_id}")
        else:
            db_insert(f"""
                INSERT INTO wallets (user_id, balance_irt, balance_usdt, locked_irt, locked_usdt, is_frozen)
                VALUES ({user_id}, {balance_irt}, {balance_usdt}, 0, 0, 0)
            """)

    # Ensure a verified bank card exists (needed for withdrawal AND manual deposits)
    if role == 'user':
        card_exists = db_scalar(f"SELECT id FROM bank_cards WHERE user_id={user_id} AND status='verified' LIMIT 1")
        if not card_exists:
            # استفاده از شماره کارت یکتا برای هر کاربر (جلوگیری از duplicate)
            unique_card = f'621986{int(user_id):010d}'[-16:]
            base_dir = str(Path(__file__).parent.parent)
            php_code = f"define('BASE_PATH', '{base_dir}'); require '{base_dir}/vendor/autoload.php'; require '{base_dir}/bootstrap/app.php'; $e = new \\Core\\Encryption(); echo $e->encrypt('{unique_card}', 'bank.card_number');"
            res = subprocess.run([get_php_bin(), '-r', php_code], capture_output=True, text=True)
            enc_card = res.stdout.strip() if res.stdout.strip() else unique_card
            db_insert(f"""
                INSERT INTO bank_cards (user_id, card_number, bank_name, sheba, status, is_default, created_at, updated_at)
                VALUES ({user_id}, '{enc_card}', 'بانک ملی', 'IR820570022080012345678901', 'verified', 1, NOW(), NOW())
            """)
            # Get the card_id for return
            card_id = db_scalar(f"SELECT id FROM bank_cards WHERE user_id={user_id} AND status='verified' ORDER BY id DESC LIMIT 1")
            # Store as global for test access
            globals()['LAST_CARD_ID'] = int(card_id) if (card_id and card_id.isdigit()) else 1

    # Ensure KYC is verified (needed for withdrawal)
    if role == 'user' and verified:
        db_insert(f"""
            INSERT INTO kyc_verifications (user_id, status, national_code, submitted_at, reviewed_at)
            VALUES ({user_id}, 'verified', '1234567890', NOW(), NOW())
            ON DUPLICATE KEY UPDATE status='verified', reviewed_at=NOW()
        """)
        db_insert(f"UPDATE users SET kyc_status='verified' WHERE id={user_id}")

    # Link to role
    if role in ('admin', 'super_admin'):
        role_id = db_scalar(f"SELECT id FROM roles WHERE slug='{role}'")
        if role_id:
            db_insert(f"INSERT IGNORE INTO user_roles (user_id, role_id) VALUES ({user_id}, {role_id})")

    return int(user_id or 0)


if __name__ == '__main__':
    print(f"{BOLD}Chortke Enterprise 10-Tier Test Harness v3.0{RESET}")
    print(f"Checking infrastructure...")

    # Check server
    code = subprocess.run(['curl', '-sS', '-o', '/dev/null', '-w', '%{http_code}',
                           f'{BASE_URL}/'], capture_output=True, text=True).stdout.strip()
    print(f"  Server: {'✅' if code == '200' else '❌'} (HTTP {code})")

    # Check DB
    tables = db_scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()")
    print(f"  Database: {'✅' if int(tables) > 100 else '❌'} ({tables} tables)")

    # Check snapshot capability
    ok = db_snapshot()
    print(f"  Snapshot: {'✅' if ok else '❌'}")
    if ok:
        db_restore()
        print(f"  Restore: ✅")

    print(f"\n{BOLD}Available Enterprise Test Suites:{RESET}")
    print(f"  python3 tests/scenario_auth.py")
    print(f"  python3 tests/scenario_wallet.py")
    print(f"  python3 tests/scenario_tasks.py")
    print(f"  python3 tests/scenario_payment.py")
    print(f"  python3 tests/scenario_crypto.py")
    print(f"  python3 tests/scenario_escrow.py")
    print(f"  python3 tests/run_all.py all")
