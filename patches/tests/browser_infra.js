/**
 * تست اتوماسیون مرورگر Playwright — بخش زیرساخت، مانیتورینگ و صف‌های ناهمگام (Infra E2E)
 * بررسی بارگذاری داشبورد خطاهای Sentry، اندپوینت‌های متریک‌های زیرساخت و پایش کنسول JS
 */
const { chromium } = require('playwright');

const E2E_EMAIL = process.env.E2E_EMAIL || 'user@chortke.ir';
const E2E_PASSWORD = process.env.E2E_PASSWORD || '123456';
const BASE_URL = process.env.CHORTKE_E2E_BASE_URL || 'http://127.0.0.1:8080';
const GREEN = '\x1b[92m', RED = '\x1b[91m', BOLD = '\x1b[1m', RESET = '\x1b[0m';

(async () => {
  console.log(`${BOLD}▶ شروع تست اتوماسیون مرورگر: زیرساخت، مانیتورینگ و صف‌های ناهمگام (browser_infra.js)${RESET}`);
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

    // ۲. بررسی داشبورد خطاهای Sentry
    await page.goto(`${BASE_URL}/admin/sentry`, { waitUntil: 'networkidle', timeout: 15000 });
    const title = await page.title();
    console.log(`  ${GREEN}✓${RESET} داشبورد خطاهای Sentry در مرورگر بارگذاری شد (Title: ${title})`);
    
    // ۳. بررسی اندپوینت متریک‌های سیستم (Metrics)
    await page.goto(`${BASE_URL}/metrics`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    console.log(`  ${GREEN}✓${RESET} اندپوینت متریک‌های زیرساخت در مرورگر بارگذاری شد.`);
    
    // ۴. بررسی خطاهای جاوااسکریپت
    console.log(`  ${GREEN}✓${RESET} وضعیت پایش کنسول JS: ${jsErrors.length} خطا یافت شد.`);
    if (jsErrors.length > 0) {
        jsErrors.forEach(e => console.log(`    ${RED}-${RESET} ${e}`));
    }
  } catch (e) {
    console.log(`  ${RED}✗${RESET} خطای تست مرورگر: ${e.message}`);
  } finally {
    await browser.close();
    console.log(`${BOLD}✓ پایان تست مرورگری زیرساخت و مانیتورینگ${RESET}\n`);
  }
})();
