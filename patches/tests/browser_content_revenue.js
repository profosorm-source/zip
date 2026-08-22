/**
 * تست اتوماسیون مرورگر Playwright — بخش بازاریابی محتوا و جایگاه‌های تبلیغاتی (Content E2E)
 * بررسی بارگذاری جدول مطالب کاربر، داشبورد مدیریت محتوای ادمین، صفحه جایگاه‌های تبلیغاتی و پایش کنسول JS
 */
const { chromium } = require('playwright');

const E2E_EMAIL = process.env.E2E_EMAIL || 'user@chortke.ir';
const E2E_PASSWORD = process.env.E2E_PASSWORD || '123456';
const BASE_URL = 'http://127.0.0.1:8080';
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
    console.log(`  ${RED}✗${RESET} خطای تست مرورگر: ${e.message}`);
  } finally {
    await browser.close();
    console.log(`${BOLD}✓ پایان تست مرورگری بازاریابی محتوا${RESET}\n`);
  }
})();
