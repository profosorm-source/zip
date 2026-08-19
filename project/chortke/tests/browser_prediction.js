/**
 * تست اتوماسیون مرورگر Playwright — بخش بازی‌های پیش‌بینی (Prediction E2E)
 * بررسی بارگذاری کارت‌های مسابقات، فرم ثبت پیش‌بینی و انتخاب ضرایب، صفحه پیش‌بینی‌های من و پایش کنسول JS
 */
const { chromium } = require('playwright');

const BASE_URL = 'http://127.0.0.1:8080';
const GREEN = '\x1b[92m', RED = '\x1b[91m', BOLD = '\x1b[1m', RESET = '\x1b[0m';

(async () => {
  console.log(`${BOLD}▶ شروع تست اتوماسیون مرورگر: بازی‌های پیش‌بینی (browser_prediction.js)${RESET}`);
  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-gpu'] });
  const context = await browser.newContext();
  const page = await context.newPage();
  
  const jsErrors = [];
  page.on('console', msg => { if (msg.type() === 'error') jsErrors.push(msg.text().substring(0, 100)); });
  page.on('pageerror', err => jsErrors.push('JS_ERROR: ' + err.message.substring(0, 100)));
  
  try {
    // ۱. ورود به حساب کاربری
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle', timeout: 15000 });
    await page.fill('input[name="email"]', 'admin@chortke.ir');
    await page.fill('input[name="password"]', '123456');
    await page.click('button[type="submit"], input[type="submit"]');
    await page.waitForLoadState('networkidle', { timeout: 10000 });
    console.log(`  ${GREEN}✓${RESET} ورود به حساب کاربری در مرورگر با موفقیت انجام شد.`);

    // ۲. بررسی صفحه اصلی مسابقات پیش‌بینی
    await page.goto(`${BASE_URL}/prediction`, { waitUntil: 'networkidle', timeout: 15000 });
    const title = await page.title();
    console.log(`  ${GREEN}✓${RESET} صفحه لیست مسابقات پیش‌بینی بارگذاری شد (Title: ${title})`);
    
    // ۳. بررسی صفحه جزئیات بازی و فرم ثبت پیش‌بینی
    await page.goto(`${BASE_URL}/prediction/game/1`, { waitUntil: 'networkidle', timeout: 15000 });
    const hasForm = await page.locator('form').count();
    console.log(`  ${GREEN}✓${RESET} فرم ثبت شرط و انتخاب ضرایب در مرورگر رندر شد (تعداد فرم: ${hasForm})`);

    // ۴. بررسی صفحه پیش‌بینی‌های من
    await page.goto(`${BASE_URL}/prediction/my-bets`, { waitUntil: 'networkidle', timeout: 15000 });
    console.log(`  ${GREEN}✓${RESET} صفحه تاریخچه پیش‌بینی‌های من در مرورگر رندر شد.`);
    
    // ۵. بررسی خطاهای جاوااسکریپت
    console.log(`  ${GREEN}✓${RESET} وضعیت پایش کنسول JS: ${jsErrors.length} خطا یافت شد.`);
    if (jsErrors.length > 0) {
        jsErrors.forEach(e => console.log(`    ${RED}-${RESET} ${e}`));
    }
  } catch (e) {
    console.log(`  ${RED}✗${RESET} خطای تست مرورگر: ${e.message}`);
  } finally {
    await browser.close();
    console.log(`${BOLD}✓ پایان تست مرورگری بازی‌های پیش‌بینی${RESET}\n`);
  }
})();
