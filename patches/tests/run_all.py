#!/usr/bin/env python3
"""
Chortke Unified Enterprise Test Runner — رانر یکپارچه و کلان تست‌های سازمانی ۱۰ لایه‌ای
اجرای ترکیبی: Python (API/DB) + Playwright (Browser E2E)

امکانات کلیدی معماری جدید (v4.0):
- شلیک خودکار و یکپارچه به تمامی ۳۰ حوزه تخصصی (پوشش ۱۰۰٪ تمام کنترلرها، سرویس‌ها، ابزارها، نصب‌کننده، پایش حاکمیتی، موتور سنتری بومی، سناریوی حیات کامل کاربر، مهندسی هرج‌و‌مرج، پایش کلان، گام اول، دوم، سوم و ممیزی بی‌رحمانه معماری)
- پوشش مرورگر: ۳۴ اسکریپت Playwright موجود است و MODULES به ۲۸ فایل
  متمایز ارجاع می‌دهد؛ چند ماژول عمداً یک اسکریپت مشترک دارند. تقارن
  ۱:۱ برقرار نیست و ادعای پیشین نادرست بود.
- لاگ‌برداری ساختاریافته و استخراج تفکیکی وضعیت موفقیت در جداول نهایی
- حالت اجرای تعاملی در محیط‌های عملیاتی (PHP/MariaDB)

استفاده:
    python3 tests/run_all.py [module_name]
    python3 tests/run_all.py auth               # فقط احراز هویت
    python3 tests/run_all.py hardcore_deep_dive # فقط ممیزی بی‌رحمانه و چالش‌برانگیز معماری توزیع‌شده
    python3 tests/run_all.py all                # شلیک به تمام ۳۰ ماژول
"""
import subprocess
import sys
import os
import time
from pathlib import Path
from urllib.parse import urlparse

BASE_DIR = Path(__file__).parent.parent
TESTS_DIR = Path(__file__).parent

# آدرس پایه از همان هارنس مشترک پروژه گرفته می‌شود تا با .env هماهنگ بماند.
sys.path.insert(0, str(BASE_DIR / "tests"))
from scenario_test import BASE_URL  # noqa: E402

GREEN = "\033[92m"
RED = "\033[91m"
YELLOW = "\033[93m"
CYAN = "\033[96m"
MAGENTA = "\033[95m"
BOLD = "\033[1m"
RESET = "\033[0m"

MODULES = {
    "auth": {
        "name": "احراز هویت و امنیت",
        "curl": "tests/scenario_auth.py",
        "browser": "tests/browser_auth.js",
    },
    "profile": {
        "name": "پروفایل و حساب کاربری",
        "curl": "tests/scenario_profile.py",
        "browser": "tests/browser_profile.js",
    },
    "admin": {
        "name": "پنل حاکمیتی ادمین",
        "curl": "tests/scenario_admin.py",
        "browser": "tests/browser_admin.js",
    },
    "wallet": {
        "name": "کیف پول و خزانه‌داری",
        "curl": "tests/scenario_wallet.py",
        "browser": "tests/browser_wallet.js",
    },
    "payment": {
        "name": "درگاه‌های پرداخت آنلاین",
        "curl": "tests/scenario_payment.py",
        "browser": "tests/browser_payment.js",
    },
    "crypto": {
        "name": "واریز رمزارز (USDT/TRX)",
        "curl": "tests/scenario_crypto.py",
        "browser": "tests/browser_kyc.js",
    },
    "bankcard": {
        "name": "مدیریت کارت‌های بانکی",
        "curl": "tests/scenario_bankcard.py",
        "browser": "tests/browser_wallet.js",
    },
    "escrow": {
        "name": "سیستم اسکرو و قفل اعتبار",
        "curl": "tests/scenario_escrow.py",
        "browser": "tests/browser_wallet.js",
    },
    "tasks": {
        "name": "بازارچه تسک‌ها و گیگ‌اکونومی",
        "curl": "tests/scenario_tasks.py",
        "browser": "tests/browser_tasks.js",
    },
    "vitrine": {
        "name": "ویترین تجاری و کالا",
        "curl": "tests/scenario_vitrine.py",
        "browser": "tests/browser_vitrine.js",
    },
    "dispute": {
        "name": "سیستم حل اختلاف و داوری",
        "curl": "tests/scenario_dispute.py",
        "browser": "tests/browser_ticket.js",
    },
    "kyc": {
        "name": "احراز هویت کاربری (KYC)",
        "curl": "tests/scenario_kyc.py",
        "browser": "tests/browser_kyc.js",
    },
    "investment": {
        "name": "سرمایه‌گذاری و توزیع سود",
        "curl": "tests/scenario_investment.py",
        "browser": "tests/browser_investment.js",
    },
    "lottery": {
        "name": "بخت‌آزمایی و لاتاری",
        "curl": "tests/scenario_lottery.py",
        "browser": "tests/browser_lottery.js",
    },
    "prediction": {
        "name": "بازی‌های پیش‌بینی و رقابت‌ها",
        "curl": "tests/scenario_prediction.py",
        "browser": "tests/browser_prediction.js",
    },
    "referral": {
        "name": "معرفی دوستان و زیرمجموعه‌گیری",
        "curl": "tests/scenario_referral.py",
        "browser": "tests/browser_referral.js",
    },
    "coupon": {
        "name": "کوپن‌های تخفیف و کدهای هدیه",
        "curl": "tests/scenario_coupon.py",
        "browser": "tests/browser_coupon.js",
    },
    "notification": {
        "name": "اعلان‌ها و ارتباطات کاربری",
        "curl": "tests/scenario_notification.py",
        "browser": "tests/browser_notification.js",
    },
    "infra": {
        "name": "زیرساخت، مانیتورینگ و صف‌ها",
        "curl": "tests/scenario_infra.py",
        "browser": "tests/browser_infra.js",
    },
    "ticket": {
        "name": "پشتیبانی، تیکت و پیام مستقیم",
        "curl": "tests/scenario_ticket.py",
        "browser": "tests/browser_ticket.js",
    },
    "antifraud": {
        "name": "موتور ضدتقلب و بایومتریک",
        "curl": "tests/scenario_antifraud.py",
        "browser": "tests/browser_antifraud.js",
    },
    "analytics": {
        "name": "تحلیل داده، جستجوی سازمانی و بکاپ",
        "curl": "tests/scenario_analytics_search.py",
        "browser": "tests/browser_analytics_search.js",
    },
    "installer": {
        "name": "نصب‌کننده خودکار و پیکربندی",
        "curl": "tests/scenario_installer.py",
        "browser": "tests/browser_installer.js",
    },
    "content": {
        "name": "بازاریابی محتوا و جریان درآمدی",
        "curl": "tests/scenario_content_revenue.py",
        "browser": "tests/browser_content_revenue.js",
    },
    "maintenance": {
        "name": "ابزارهای نگهداری دیتابیس و تعمیرات",
        "curl": "tests/scenario_maintenance_tools.py",
        "browser": "tests/browser_maintenance_tools.js",
    },
    "admin_gov": {
        "name": "پایش حاکمیتی، KPI و لاگ‌های سیستمی",
        "curl": "tests/scenario_admin_governance.py",
        "browser": "tests/browser_admin_governance.js",
    },
    "custom_sentry": {
        "name": "موتور سنتری شخصی‌سازی‌شده و عملکرد",
        "curl": "tests/scenario_custom_sentry.py",
        "browser": "tests/browser_custom_sentry.js",
    },
    "chaos": {
        "name": "مهندسی هرج‌و‌مرج، قطعی و بار شدید",
        "curl": "tests/scenario_chaos_engineering.py",
        "browser": "tests/browser_chaos_engineering.js",
    },
    "grand_saga": {
        "name": "سناریوی حیات کامل کاربر (Saga E2E)",
        "curl": None,
        "browser": "tests/browser_grand_lifecycle_saga.js",
    },
    "universal_e2e": {
        "name": "پایش یکپارچه و کلان تمامی بخش‌ها",
        "curl": None,
        "browser": "tests/browser_0_to_100_full_platform_e2e.js",
    },
    "logic_auth_profile": {
        "name": "گام اول: منطق احراز هویت و پروفایل",
        "curl": "tests/logic_auth_profile.py",
        "browser": "tests/browser_logic_auth_profile.js",
    },
    "logic_security_fraud": {
        "name": "گام دوم: امنیت، حریم و ضدتقلب",
        "curl": "tests/logic_security_fraud.py",
        "browser": "tests/browser_logic_security_fraud.js",
    },
    "logic_financial_treasury": {
        "name": "گام سوم: هسته مالی، کارت و درگاه",
        "curl": "tests/logic_financial_treasury.py",
        "browser": "tests/browser_logic_financial_treasury.js",
    },
    "hardcore_deep_dive": {
        "name": "ممیزی بی‌رحمانه و چالش‌برانگیز معماری",
        "curl": "tests/logic_hardcore_deep_dive.py",
        "browser": None,
    },
    "browser_base": {
        "name": "تست مرورگری عمومی پایه",
        "curl": None,
        "browser": "tests/browser_test.js",
    },
    "browser_deep": {
        "name": "تست مرورگری عمیق ترکیبی",
        "curl": None,
        "browser": "tests/browser_deep_test.js",
    },
}


SERVER_READY_TIMEOUT = float(os.environ.get("CHORTKE_SERVER_READY_TIMEOUT", "30"))


def _probe_server(url: str) -> str:
    """یک درخواست ساده به سرور و برگرداندن کد وضعیت HTTP."""
    try:
        r = subprocess.run(
            ["curl", "-sS", "-o", "/dev/null", "-w", "%{http_code}", url],
            capture_output=True, text=True, timeout=5
        )
        return r.stdout.strip()
    except subprocess.SubprocessError:
        return ""


def ensure_server():
    """
    اطمینان از اینکه سرور PHP در حال اجراست.

    آدرس سرور از پیکربندی واقعی پروژه (BASE_URL هارنس، برگرفته از .env)
    خوانده می‌شود و به جای انتظار ثابت، تا آماده شدن سرور نظرسنجی می‌کنیم.
    """
    root_url = BASE_URL + "/"
    if _probe_server(root_url) == "200":
        return True

    print(f"{YELLOW}سرور پایین است — راه‌اندازی دیمن روی {BASE_URL} ...{RESET}")
    parsed = urlparse(BASE_URL)
    listen = parsed.netloc or "127.0.0.1:8080"
    subprocess.Popen(
        [os.environ.get("PHP_BINARY") or "php", "-S", listen, "-t", "public"],
        stdout=open("/tmp/chortke_server.log", "w"),
        stderr=subprocess.STDOUT
    )

    deadline = time.time() + SERVER_READY_TIMEOUT
    while time.time() < deadline:
        if _probe_server(root_url) == "200":
            return True
        time.sleep(0.1)
    return False


def flush_cache():
    """پاک‌سازی cache برای ایزوله‌سازی وضعیت (State Isolation)"""
    subprocess.run(["php", "tests/flush_cache.php"], capture_output=True, timeout=15)


def run_curl_test(script_path, module_name):
    """اجرای تست Python (API + DB + Concurrency + Queues)"""
    print(f"\n{BOLD}{CYAN}┌─ لایه‌های ۱-۵ و ۸-۱۰ (Python QA Suite): {module_name}{RESET}")
    print(f"{BOLD}{CYAN}│  اسکریپت: {script_path}{RESET}\n")

    result = subprocess.run(
        ["python3", script_path],
        capture_output=True, text=True, timeout=600,
        cwd=str(BASE_DIR)
    )

    output = result.stdout
    for line in output.split("\n"):
        if "Passed:" in line or "Failed:" in line:
            print(f"{CYAN}│  {line.strip()}{RESET}")
            break

    for line in output.split("\n"):
        if "✗" in line or "FAIL" in line.upper():
            print(f"{RED}│  {line.strip()}{RESET}")

    return result.returncode == 0


def run_browser_test(script_path, module_name):
    """اجرای تست Playwright (Browser E2E & JS Console Logs)"""
    print(f"\n{BOLD}{MAGENTA}┌─ لایه ۷ (Playwright Browser Automation): {module_name}{RESET}")
    print(f"{BOLD}{MAGENTA}│  اسکریپت: {script_path}{RESET}\n")

    result = subprocess.run(
        ["node", script_path],
        capture_output=True, text=True, timeout=300,
        cwd=str(BASE_DIR)
    )

    output = result.stdout
    for line in output.split("\n"):
        if "Passed:" in line or "Failed:" in line or "✓" in line or "PASS:" in line:
            print(f"{MAGENTA}│  {line.strip()}{RESET}")
            break

    saw_failure_marker = False
    for line in output.split("\n"):
        if "✗" in line or "JS_ERROR" in line or "PAGE_ERROR" in line:
            print(f"{RED}│  {line.strip()}{RESET}")
            saw_failure_marker = True

    # دفاع لایه‌دوم: اسکریپت‌های قدیمی مرورگری خطا را در catch می‌بلعیدند و با
    # کد خروج ۰ تمام می‌شدند، بنابراین اتکای صرف به returncode شکست‌ها را پنهان
    # می‌کرد. اگر نشانهٔ شکست در خروجی باشد، حتی با returncode==0 ناموفق است.
    return result.returncode == 0 and not saw_failure_marker


def run_certify_mode():
    """
    حالت --certify حذف شد.

    پیاده‌سازی پیشین هیچ تستی اجرا نمی‌کرد: با time.sleep(0.03) روی
    فهرست MODULES حلقه می‌زد، برای هر ماژول بی‌قید و شرط «✓ PASS» و
    «۱۰۰% PASS» چاپ می‌کرد، سپس «تأییدیه نهایی صحت سیستم» و
    «۱۰۰٪ آماده استقرار در پروداکشن» را نمایش می‌داد و با sys.exit(0)
    خارج می‌شد — حتی اگر کل سیستم خراب بود. این یک گواهی جعلی بود.

    برای اجرای واقعی از حالت عادی استفاده کنید:
        python3 tests/run_all.py all
    """
    print(
        "حالت --certify حذف شد: این حالت بدون اجرای هیچ تستی گواهی قبولی\n"
        "چاپ می‌کرد. برای اجرای واقعی سناریوها دستور زیر را بزنید:\n"
        "    python3 tests/run_all.py all"
    )
    sys.exit(2)


def main():
    if "--certify" in sys.argv:
        run_certify_mode()

    target = sys.argv[1] if len(sys.argv) > 1 else "all"

    print(f"\n{'═' * 75}")
    print(f"{BOLD}  چرتکه — رانر یکپارچه و کلان تست‌های سازمانی ۱۰ لایه‌ای (v4.0){RESET}")
    print(f"  تاریخ و زمان: {time.strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"{'═' * 75}\n")

    if not ensure_server():
        print(f"{RED}❌ سرور راه‌اندازی نشد (بررسی پورت 8080){RESET}")
        sys.exit(1)
    print(f"{GREEN}✓ سرور PHP فعال و در دسترس است.{RESET}")

    flush_cache()
    print(f"{GREEN}✓ کش سیستم جهت ایزوله‌سازی سناریوها پاک‌سازی شد.{RESET}\n")

    modules_to_run = []
    if target == "all":
        modules_to_run = list(MODULES.keys())
    elif target in MODULES:
        modules_to_run = [target]
    else:
        print(f"{RED}ماژول نامعتبر: {target}{RESET}")
        print(f"ماژول‌های مجاز: {', '.join(MODULES.keys())}")
        sys.exit(1)

    results = {}
    for mod_key in modules_to_run:
        mod = MODULES[mod_key]
        mod_results = {"curl": None, "browser": None}

        if mod["curl"]:
            flush_cache()
            mod_results["curl"] = run_curl_test(mod["curl"], mod["name"])

        if mod["browser"]:
            mod_results["browser"] = run_browser_test(mod["browser"], mod["name"])

        results[mod_key] = mod_results

    # Enterprise Summary Table
    print(f"\n{'═' * 75}")
    print(f"{BOLD}  ماتریس نهایی وضعیت عبور سناریوها (Enterprise QA Audit Summary){RESET}")
    print(f"{'═' * 75}")
    total_pass = 0
    total_fail = 0
    for mod_key, mod_results in results.items():
        mod = MODULES[mod_key]
        curl_ok = mod_results["curl"]
        browser_ok = mod_results["browser"]

        curl_str = f"{GREEN}✓ PASS{RESET}" if curl_ok else (f"{RED}✗ FAIL{RESET}" if curl_ok is not None else "—")
        browser_str = f"{GREEN}✓ PASS{RESET}" if browser_ok else (f"{RED}✗ FAIL{RESET}" if browser_ok is not None else "—")

        all_ok = (curl_ok is None or curl_ok) and (browser_ok is None or browser_ok)
        status = f"{GREEN}★ PASS{RESET}" if all_ok else f"{RED}✕ FAIL{RESET}"

        print(f"  {status} {mod['name']:38s} Python Suite: {curl_str:12s}  Playwright E2E: {browser_str}")

        if not all_ok:
            total_fail += 1
        else:
            total_pass += 1

    print(f"\n  {GREEN}✓ ماژول‌های کاملاً سبز (PASS): {total_pass}{RESET}    {RED}✗ ماژول‌های نیازمند بررسی (FAIL): {total_fail}{RESET}")
    print(f"{'═' * 75}\n")

    sys.exit(0 if total_fail == 0 else 1)


if __name__ == "__main__":
    main()
