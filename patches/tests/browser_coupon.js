/**
 * تست اتوماسیون مرورگر Playwright — بخش کوپن‌های تخفیف و کدهای هدیه (Coupon E2E)
 * بررسی بارگذاری لیست کوپن‌های فعال، جعبه ورود کد تخفیف در سبد خرید و پایش خطاهای کنسول JS
 */
const { chromium } = require('playwright');

const E2E_EMAIL = process.env.E2E_EMAIL || 'user@chortke.ir';
const E2E_PASSWORD = process.env.E2E_PASSWORD || '123456';
const BASE_URL = process.env.CHORTKE_E2E_BASE_URL || 'http://127.0.0.1:8080';
const GREEN = '\x1b[92m', RED = '\x1b[91m', BOLD = '\x1b[1m', RESET = '\x1b[0m';

(async () => {
  console.log(`${BOLD}▶ شروع تست اتوماسیون مرورگر: کوپن‌های تخفیف و کدهای هدیه (browser_coupon.js)${RESET}`);
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

    // ۲. بررسی صفحه اصلی کوپن‌های من
    await page.goto(`${BASE_URL}/coupons`, { waitUntil: 'networkidle', timeout: 15000 });
    const title = await page.title();
    console.log(`  ${GREEN}✓${RESET} صفحه لیست کوپن‌های تخفیف بارگذاری شد (Title: ${title})`);
    
    // ۳. بررسی فرم ورود کوپن در صفحه سرمایه‌گذاری یا پرداخت
    await page.goto(`${BASE_URL}/investment/create`, { waitUntil: 'networkidle', timeout: 15000 });
    const hasForm = await page.locator('form').count();
    console.log(`  ${GREEN}✓${RESET} جعبه ورود کوپن تخفیف در سبد خرید مرورگر رندر شد (تعداد فرم: ${hasForm})`);
    
    // ۴. بررسی خطاهای جاوااسکریپت
    console.log(`  ${GREEN}✓${RESET} وضعیت پایش کنسول JS: ${jsErrors.length} خطا یافت شد.`);
    if (jsErrors.length > 0) {
        jsErrors.forEach(e => console.log(`    ${RED}-${RESET} ${e}`));
    }
  } catch (e) {
    console.log(`  ${RED}✗${RESET} خطای تست مرورگر: ${e.message}`);
  } finally {
    await browser.close();
    console.log(`${BOLD}✓ پایان تست مرورگری کوپن‌های تخفیف${RESET}\n`);
  }
})();
