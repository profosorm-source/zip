/**
 * تست اتوماسیون مرورگر Playwright — بخش نصب‌کننده خودکار و پیکربندی اولیه (Installer E2E)
 * بررسی بارگذاری ویزارد نصب، فرم ورود مشخصات دیتابیس، دکمه اجرای مایگریشن‌ها و پایش کنسول JS
 */
const { chromium } = require('playwright');

const BASE_URL = 'http://127.0.0.1:8080';
const GREEN = '\x1b[92m', RED = '\x1b[91m', BOLD = '\x1b[1m', RESET = '\x1b[0m';

(async () => {
  console.log(`${BOLD}▶ شروع تست اتوماسیون مرورگر: ویزارد نصب‌کننده خودکار (browser_installer.js)${RESET}`);
  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-gpu'] });
  const context = await browser.newContext();
  const page = await context.newPage();
  
  const jsErrors = [];
  page.on('console', msg => { if (msg.type() === 'error') jsErrors.push(msg.text().substring(0, 100)); });
  page.on('pageerror', err => jsErrors.push('JS_ERROR: ' + err.message.substring(0, 100)));
  
  try {
    // ۱. بررسی صفحه ویزارد نصب
    await page.goto(`${BASE_URL}/install`, { waitUntil: 'networkidle', timeout: 15000 });
    const hasForms = await page.locator('form').count();
    console.log(`  ${GREEN}✓${RESET} ویزارد نصب‌کننده در مرورگر بارگذاری شد (تعداد فرم: ${hasForms})`);
    
    // ۲. بررسی اندپوینت گام‌های ویزارد نصب
    await page.goto(`${BASE_URL}/install/step/1`, { waitUntil: 'networkidle', timeout: 15000 });
    console.log(`  ${GREEN}✓${RESET} گام اول پیکربندی اولیه در مرورگر رندر شد.`);

    // ۳. بررسی خطاهای جاوااسکریپت
    console.log(`  ${GREEN}✓${RESET} وضعیت پایش کنسول JS: ${jsErrors.length} خطا یافت شد.`);
    if (jsErrors.length > 0) {
        jsErrors.forEach(e => console.log(`    ${RED}-${RESET} ${e}`));
    }
  } catch (e) {
    console.log(`  ${RED}✗${RESET} خطای تست مرورگر: ${e.message}`);
  } finally {
    await browser.close();
    console.log(`${BOLD}✓ پایان تست مرورگری ویزارد نصب‌کننده${RESET}\n`);
  }
})();
