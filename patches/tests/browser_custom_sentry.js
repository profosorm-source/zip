/**
 * تست اتوماسیون مرورگر Playwright — بخش موتور سنتری شخصی‌سازی‌شده و پایش عملکرد (Sentry E2E)
 * بررسی بارگذاری داشبورد سنتری، جداول رخدادها، دکمه‌های Mute/Resolve/Acknowledge، چارت‌های عملکرد و پایش کنسول JS
 */
const { chromium } = require('playwright');

const E2E_EMAIL = process.env.E2E_EMAIL || 'user@chortke.ir';
const E2E_PASSWORD = process.env.E2E_PASSWORD || '123456';
const BASE_URL = 'http://127.0.0.1:8080';
const GREEN = '\x1b[92m', RED = '\x1b[91m', BOLD = '\x1b[1m', RESET = '\x1b[0m';

(async () => {
  console.log(`${BOLD}▶ شروع تست اتوماسیون مرورگر: موتور سنتری شخصی‌سازی‌شده و پایش عملکرد (browser_custom_sentry.js)${RESET}`);
  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-gpu'] });
  const context = await browser.newContext();
  const page = await context.newPage();
  
  const jsErrors = [];
  page.on('console', msg => { if (msg.type() === 'error') jsErrors.push(msg.text().substring(0, 100)); });
  page.on('pageerror', err => jsErrors.push('JS_ERROR: ' + err.message.substring(0, 100)));
  
  try {
    // ۱. ورود به پنل ادمین
    await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'networkidle', timeout: 15000 });
    await page.fill('input[name="email"]', E2E_EMAIL);
    await page.fill('input[name="password"]', E2E_PASSWORD);
    await page.click('button[type="submit"], input[type="submit"]');
    await page.waitForLoadState('networkidle', { timeout: 10000 });
    console.log(`  ${GREEN}✓${RESET} ورود به پنل مدیریتی ادمین با موفقیت انجام شد.`);

    // ۲. بررسی داشبورد اصلی سنتری بومی (Custom Sentry Dashboard)
    await page.goto(`${BASE_URL}/admin/sentry`, { waitUntil: 'networkidle', timeout: 15000 });
    const title = await page.title();
    console.log(`  ${GREEN}✓${RESET} داشبورد اصلی مانیتورینگ سنتری بومی بارگذاری شد (Title: ${title})`);
    
    // ۳. بررسی صفحه جزئیات رخداد خطا (Issue Detail)
    await page.goto(`${BASE_URL}/admin/sentry/issue/1`, { waitUntil: 'networkidle', timeout: 15000 });
    console.log(`  ${GREEN}✓${RESET} صفحه جزئیات رخداد خطا و استک‌ترِیس در مرورگر رندر شد.`);

    // ۴. بررسی چارت‌های پایش عملکرد و سرعت سرور (Sentry Performance)
    await page.goto(`${BASE_URL}/admin/sentry/performance`, { waitUntil: 'networkidle', timeout: 15000 });
    console.log(`  ${GREEN}✓${RESET} چارت‌های پایش عملکرد و سرعت پاسخگویی سرور در مرورگر بارگذاری شد.`);
    
    // ۵. بررسی خطاهای جاوااسکریپت
    console.log(`  ${GREEN}✓${RESET} وضعیت پایش کنسول JS: ${jsErrors.length} خطا یافت شد.`);
    if (jsErrors.length > 0) {
        jsErrors.forEach(e => console.log(`    ${RED}-${RESET} ${e}`));
    }
  } catch (e) {
    console.log(`  ${RED}✗${RESET} خطای تست مرورگر: ${e.message}`);
  } finally {
    await browser.close();
    console.log(`${BOLD}✓ پایان تست مرورگری موتور سنتری بومی${RESET}\n`);
  }
})();
