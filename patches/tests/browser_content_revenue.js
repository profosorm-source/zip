/**
 * تست اتوماسیون مرورگر Playwright — بخش بازاریابی محتوا و جایگاه‌های تبلیغاتی (Content E2E)
 * بررسی بارگذاری جدول مطالب کاربر، داشبورد مدیریت محتوای ادمین، صفحه جایگاه‌های تبلیغاتی و پایش کنسول JS
 */
const { chromium } = require('playwright');

// ── سخت‌سازی هارنس: شکست باید واقعاً شکست باشد ──
// پیش از این، خطا در catch فقط چاپ می‌شد و اسکریپت با کد خروج ۰ تمام
// می‌شد؛ در نتیجه run_all.py که به returncode نگاه می‌کند همیشه «سبز»
// می‌دید. شمارنده‌های زیر و خروج غیرصفر این نقص را برطرف می‌کنند.
let __pass = 0, __fail = 0;
const __failures = [];
function check(name, condition, detail = '') {
    const ok = !!condition;
    const mark = ok ? '\x1b[92m\u2713\x1b[0m' : '\x1b[91m\u2717\x1b[0m';
    console.log('  ' + mark + ' ' + String(name) + (detail ? ' \u2014 ' + detail : ''));
    if (ok) { __pass++; } else { __fail++; __failures.push(String(name)); }
    return ok;
}
function recordFailure(name, err) {
    const msg = (err && err.message) ? err.message : String(err);
    return check(name, false, msg.substring(0, 160));
}
function __summary() {
    console.log('\n' + '\u2550'.repeat(60));
    console.log('  \x1b[92mPassed: ' + __pass + '\x1b[0m  \x1b[91mFailed: ' + __fail
                + '\x1b[0m  Total: ' + (__pass + __fail));
    if (__fail > 0) {
        console.log('  \x1b[91m\x1b[1m\u0645\u0648\u0627\u0631\u062f \u0646\u0627\u0645\u0648\u0641\u0642:\x1b[0m');
        __failures.forEach(function (f) { console.log('    \x1b[91m\u2717\x1b[0m ' + f); });
    }
    console.log('\u2550'.repeat(60) + '\n');
    process.exit(__fail > 0 ? 1 : 0);
}


const E2E_EMAIL = process.env.E2E_EMAIL || 'user@chortke.ir';
const E2E_PASSWORD = process.env.E2E_PASSWORD || '123456';
const BASE_URL = process.env.CHORTKE_E2E_BASE_URL || 'http://127.0.0.1:8080';
const GREEN = '\x1b[92m', RED = '\x1b[91m', BOLD = '\x1b[1m', RESET = '\x1b[0m';

(async () => {
  console.log(`${BOLD}▶ شروع تست اتوماسیون مرورگر: بازاریابی محتوا و جایگاه‌های تبلیغاتی (browser_content_revenue.js)${RESET}`);
  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-gpu'] });
  const context = await browser.newContext();
  const page = await context.newPage();
  
  const jsErrors = [];
  page.on('console', msg => { if (msg.type() === 'error') jsErrors.push(msg.text().substring(0, 100)); });
  page.on('pageerror', err => jsErrors.push('JS_ERROR: ' + err.message.substring(0, 100)));
  
  try {
    // ۱. ورود به حساب کاربری
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle', timeout: 15000 });
    await page.fill('input[name="email"]', E2E_EMAIL);
    await page.fill('input[name="password"]', E2E_PASSWORD);
    await page.click('button[type="submit"], input[type="submit"]');
    await page.waitForLoadState('networkidle', { timeout: 10000 });
    console.log(`  ${GREEN}✓${RESET} ورود به حساب کاربری در مرورگر با موفقیت انجام شد.`);

    // ۲. بررسی صفحه مدیریت محتوای کاربر
    await page.goto(`${BASE_URL}/content`, { waitUntil: 'networkidle', timeout: 15000 });
    const title = await page.title();
    console.log(`  ${GREEN}✓${RESET} صفحه مدیریت محتوای کاربر در مرورگر بارگذاری شد (Title: ${title})`);
    
    // ۳. بررسی داشبورد مدیریت محتوای ادمین
    await page.goto(`${BASE_URL}/admin/content`, { waitUntil: 'networkidle', timeout: 15000 });
    console.log(`  ${GREEN}✓${RESET} داشبورد مدیریت محتوا در پنل ادمین رندر شد.`);

    // ۴. بررسی صفحه مدیریت جایگاه‌های تبلیغاتی (Placements)
    await page.goto(`${BASE_URL}/admin/placements`, { waitUntil: 'networkidle', timeout: 15000 });
    console.log(`  ${GREEN}✓${RESET} صفحه مدیریت جایگاه‌های تبلیغاتی در مرورگر بارگذاری شد.`);
    
    // ۵. بررسی خطاهای جاوااسکریپت
    console.log(`  ${GREEN}✓${RESET} وضعیت پایش کنسول JS: ${jsErrors.length} خطا یافت شد.`);
    if (jsErrors.length > 0) {
        jsErrors.forEach(e => console.log(`    ${RED}-${RESET} ${e}`));
    }
  } catch (e) {
    recordFailure('\u0627\u062c\u0631\u0627\u06cc \u0633\u0646\u0627\u0631\u06cc\u0648 \u0628\u062f\u0648\u0646 \u0627\u0633\u062a\u062b\u0646\u0627\u06cc \u0645\u0631\u06af\u0628\u0627\u0631', e);
    console.log(`  ${RED}✗${RESET} خطای تست مرورگر: ${e.message}`);
  } finally {
    await browser.close();
    console.log(`${BOLD}✓ پایان تست مرورگری بازاریابی محتوا${RESET}\n`);
  }

  check('\u0628\u062f\u0648\u0646 \u062e\u0637\u0627\u06cc JS \u062f\u0631 \u06a9\u0646\u0633\u0648\u0644 \u0645\u0631\u0648\u0631\u06af\u0631', typeof jsErrors === 'undefined' || jsErrors.length === 0, typeof jsErrors !== 'undefined' ? jsErrors.slice(0, 3).join('; ') : '');
  __summary();
})();
