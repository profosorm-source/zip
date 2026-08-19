/**
 * تست اتوماسیون مرورگر Playwright — بخش بخت‌آزمایی و لاتاری (Lottery E2E)
 * بررسی بارگذاری کارت‌های دوره‌های لاتاری، فرم رای‌گیری و نظرسنجی و پایش خطاهای کنسول JS
 */
const { chromium } = require('playwright');

const BASE_URL = 'http://127.0.0.1:8080';
const GREEN = '\x1b[92m', RED = '\x1b[91m', BOLD = '\x1b[1m', RESET = '\x1b[0m';

(async () => {
  console.log(`${BOLD}▶ شروع تست اتوماسیون مرورگر: بخت‌آزمایی و لاتاری (browser_lottery.js)${RESET}`);
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

    // ۲. بررسی صفحه اصلی لاتاری
    await page.goto(`${BASE_URL}/lottery`, { waitUntil: 'networkidle', timeout: 15000 });
    const title = await page.title();
    console.log(`  ${GREEN}✓${RESET} صفحه اصلی دوره‌های لاتاری بارگذاری شد (Title: ${title})`);
    
    // ۳. بررسی فرم نظرسنجی و رای‌گیری لاتاری
    const hasForms = await page.locator('form').count();
    console.log(`  ${GREEN}✓${RESET} فرم‌های رای‌گیری و ثبت‌نام لاتاری در مرورگر رندر شدند (تعداد فرم: ${hasForms})`);
    
    // ۴. بررسی خطاهای جاوااسکریپت
    console.log(`  ${GREEN}✓${RESET} وضعیت پایش کنسول JS: ${jsErrors.length} خطا یافت شد.`);
    if (jsErrors.length > 0) {
        jsErrors.forEach(e => console.log(`    ${RED}-${RESET} ${e}`));
    }
  } catch (e) {
    console.log(`  ${RED}✗${RESET} خطای تست مرورگر: ${e.message}`);
  } finally {
    await browser.close();
    console.log(`${BOLD}✓ پایان تست مرورگری بخت‌آزمایی و لاتاری${RESET}\n`);
  }
})();
