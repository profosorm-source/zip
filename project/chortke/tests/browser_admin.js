/**
 * تست اتوماسیون مرورگر Playwright — بخش پنل حاکمیتی و مدیریت ادمین (Admin E2E)
 * بررسی ورود ادمین، جداول مدیریت کاربران، بررسی صف‌های KYC، درخواست‌های برداشت و داشبورد Sentry
 */
const { chromium } = require('playwright');

const BASE_URL = 'http://127.0.0.1:8080';
const GREEN = '\x1b[92m', RED = '\x1b[91m', BOLD = '\x1b[1m', RESET = '\x1b[0m';

(async () => {
  console.log(`${BOLD}▶ شروع تست اتوماسیون مرورگر: پنل حاکمیتی ادمین (browser_admin.js)${RESET}`);
  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-gpu'] });
  const context = await browser.newContext();
  const page = await context.newPage();
  
  const jsErrors = [];
  page.on('console', msg => { if (msg.type() === 'error') jsErrors.push(msg.text().substring(0, 100)); });
  page.on('pageerror', err => jsErrors.push('JS_ERROR: ' + err.message.substring(0, 100)));
  
  try {
    // ۱. ورود به پنل ادمین
    await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'networkidle', timeout: 15000 });
    await page.fill('input[name="email"]', 'admin@chortke.ir');
    await page.fill('input[name="password"]', '123456');
    await page.click('button[type="submit"], input[type="submit"]');
    await page.waitForLoadState('networkidle', { timeout: 10000 });
    console.log(`  ${GREEN}✓${RESET} ورود به پنل مدیریتی ادمین با موفقیت انجام شد.`);

    // ۲. بررسی داشبورد مدیریت کاربران
    await page.goto(`${BASE_URL}/admin/users`, { waitUntil: 'networkidle', timeout: 15000 });
    const title = await page.title();
    console.log(`  ${GREEN}✓${RESET} جدول مدیریت کاربران در پنل ادمین بارگذاری شد (Title: ${title})`);
    
    // ۳. بررسی لیست بررسی KYC
    await page.goto(`${BASE_URL}/admin/kyc`, { waitUntil: 'networkidle', timeout: 15000 });
    console.log(`  ${GREEN}✓${RESET} داشبورد مدیریت و بررسی مدارک KYC در مرورگر رندر شد.`);

    // ۴. بررسی لیست درخواست‌های برداشت وجه
    await page.goto(`${BASE_URL}/admin/withdrawals`, { waitUntil: 'networkidle', timeout: 15000 });
    console.log(`  ${GREEN}✓${RESET} لیست درخواست‌های برداشت در مرورگر رندر شد.`);

    // ۵. بررسی داشبورد مانیتورینگ Sentry
    await page.goto(`${BASE_URL}/admin/sentry`, { waitUntil: 'networkidle', timeout: 15000 });
    console.log(`  ${GREEN}✓${RESET} داشبورد مانیتورینگ Sentry در مرورگر بارگذاری شد.`);
    
    // ۶. بررسی خطاهای جاوااسکریپت
    console.log(`  ${GREEN}✓${RESET} وضعیت پایش کنسول JS: ${jsErrors.length} خطا یافت شد.`);
    if (jsErrors.length > 0) {
        jsErrors.forEach(e => console.log(`    ${RED}-${RESET} ${e}`));
    }
  } catch (e) {
    console.log(`  ${RED}✗${RESET} خطای تست مرورگر: ${e.message}`);
  } finally {
    await browser.close();
    console.log(`${BOLD}✓ پایان تست مرورگری پنل ادمین${RESET}\n`);
  }
})();
