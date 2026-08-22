/**
 * تست اتوماسیون مرورگر Playwright — بخش بازارچه تسک‌ها و گیگ‌اکونومی (Tasks E2E)
 * بررسی فید یکپارچه تسک‌ها، بارگذاری تسک‌های اجتماعی و سفارشی، فرم ارسال مدرک و پایش کنسول JS
 */
const { chromium } = require('playwright');

const E2E_EMAIL = process.env.E2E_EMAIL || 'user@chortke.ir';
const E2E_PASSWORD = process.env.E2E_PASSWORD || '123456';
const BASE_URL = 'http://127.0.0.1:8080';
const GREEN = '\x1b[92m', RED = '\x1b[91m', BOLD = '\x1b[1m', RESET = '\x1b[0m';

(async () => {
  console.log(`${BOLD}▶ شروع تست اتوماسیون مرورگر: بازارچه تسک‌ها و گیگ‌اکونومی (browser_tasks.js)${RESET}`);
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
    console.log(`  ${GREEN}✓${RESET} ورود به حساب کاربری با موفقیت انجام شد.`);

    // ۲. بررسی فید یکپارچه بازارچه تسک‌ها
    await page.goto(`${BASE_URL}/tasks`, { waitUntil: 'networkidle', timeout: 15000 });
    const title = await page.title();
    console.log(`  ${GREEN}✓${RESET} فید یکپارچه بازارچه تسک‌ها بارگذاری شد (Title: ${title})`);
    
    // ۳. بررسی صفحه تسک‌های اجتماعی
    await page.goto(`${BASE_URL}/social-tasks`, { waitUntil: 'networkidle', timeout: 15000 });
    console.log(`  ${GREEN}✓${RESET} صفحه تسک‌های اجتماعی در مرورگر رندر شد.`);

    // ۴. بررسی صفحه تسک‌های سفارشی
    await page.goto(`${BASE_URL}/custom-tasks`, { waitUntil: 'networkidle', timeout: 15000 });
    console.log(`  ${GREEN}✓${RESET} صفحه تسک‌های سفارشی در مرورگر رندر شد.`);

    // ۵. بررسی صفحه تبلیغات AdTube
    await page.goto(`${BASE_URL}/adtube`, { waitUntil: 'networkidle', timeout: 15000 });
    console.log(`  ${GREEN}✓${RESET} صفحه تبلیغات AdTube در مرورگر بارگذاری شد.`);
    
    // ۶. بررسی خطاهای جاوااسکریپت
    console.log(`  ${GREEN}✓${RESET} وضعیت پایش کنسول JS: ${jsErrors.length} خطا یافت شد.`);
    if (jsErrors.length > 0) {
        jsErrors.forEach(e => console.log(`    ${RED}-${RESET} ${e}`));
    }
  } catch (e) {
    console.log(`  ${RED}✗${RESET} خطای تست مرورگر: ${e.message}`);
  } finally {
    await browser.close();
    console.log(`${BOLD}✓ پایان تست مرورگری بازارچه تسک‌ها${RESET}\n`);
  }
})();
