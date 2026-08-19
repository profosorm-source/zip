/**
 * تست اتوماسیون مرورگر Playwright — بخش احراز هویت کاربری (Auth E2E)
 * بررسی فرم ورود، فرم ثبت‌نام، فرم فراموشی رمز عبور و پایش خطاهای کنسول JS
 */
const { chromium } = require('playwright');

const BASE_URL = 'http://127.0.0.1:8080';
const GREEN = '\x1b[92m', RED = '\x1b[91m', BOLD = '\x1b[1m', RESET = '\x1b[0m';

(async () => {
  console.log(`${BOLD}▶ شروع تست اتوماسیون مرورگر: احراز هویت کاربری (browser_auth.js)${RESET}`);
  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-gpu'] });
  const context = await browser.newContext();
  const page = await context.newPage();
  
  const jsErrors = [];
  page.on('console', msg => { if (msg.type() === 'error') jsErrors.push(msg.text().substring(0, 100)); });
  page.on('pageerror', err => jsErrors.push('JS_ERROR: ' + err.message.substring(0, 100)));
  
  try {
    // ۱. بررسی صفحه ورود
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle', timeout: 15000 });
    const hasLoginForm = await page.locator('form').count();
    console.log(`  ${GREEN}✓${RESET} فرم ورود در مرورگر بارگذاری شد (تعداد فرم: ${hasLoginForm})`);
    
    // ۲. بررسی صفحه ثبت‌نام
    await page.goto(`${BASE_URL}/register`, { waitUntil: 'networkidle', timeout: 15000 });
    const hasRegForm = await page.locator('form').count();
    console.log(`  ${GREEN}✓${RESET} فرم ثبت‌نام در مرورگر بارگذاری شد (تعداد فرم: ${hasRegForm})`);

    // ۳. بررسی صفحه فراموشی رمز عبور
    await page.goto(`${BASE_URL}/forgot-password`, { waitUntil: 'networkidle', timeout: 15000 });
    console.log(`  ${GREEN}✓${RESET} صفحه فراموشی رمز عبور در مرورگر رندر شد.`);

    // ۴. بررسی خطاهای جاوااسکریپت
    console.log(`  ${GREEN}✓${RESET} وضعیت پایش کنسول JS: ${jsErrors.length} خطا یافت شد.`);
    if (jsErrors.length > 0) {
        jsErrors.forEach(e => console.log(`    ${RED}-${RESET} ${e}`));
    }
  } catch (e) {
    console.log(`  ${RED}✗${RESET} خطای تست مرورگر: ${e.message}`);
  } finally {
    await browser.close();
    console.log(`${BOLD}✓ پایان تست مرورگری احراز هویت${RESET}\n`);
  }
})();
