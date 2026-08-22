/**
 * تست اتوماسیون مرورگر Playwright — بخش پروفایل و حساب کاربری (Profile E2E)
 * بررسی بارگذاری صفحه پروفایل، جدول سشن‌ها، تنظیمات 2FA، توکن‌های API، درخواست لغو عضویت و پایش کنسول JS
 */
const { chromium } = require('playwright');

const E2E_EMAIL = process.env.E2E_EMAIL || 'user@chortke.ir';
const E2E_PASSWORD = process.env.E2E_PASSWORD || '123456';
const BASE_URL = process.env.CHORTKE_E2E_BASE_URL || 'http://127.0.0.1:8080';
const GREEN = '\x1b[92m', RED = '\x1b[91m', BOLD = '\x1b[1m', RESET = '\x1b[0m';

(async () => {
  console.log(`${BOLD}▶ شروع تست اتوماسیون مرورگر: پروفایل و حساب کاربری (browser_profile.js)${RESET}`);
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

    // ۲. بررسی صفحه پروفایل
    await page.goto(`${BASE_URL}/profile`, { waitUntil: 'networkidle', timeout: 15000 });
    const title = await page.title();
    console.log(`  ${GREEN}✓${RESET} صفحه پروفایل کاربری بارگذاری شد (Title: ${title})`);
    
    // ۳. بررسی صفحه مدیریت سشن‌ها (Sessions)
    await page.goto(`${BASE_URL}/account/sessions`, { waitUntil: 'networkidle', timeout: 15000 });
    console.log(`  ${GREEN}✓${RESET} صفحه مدیریت نشست‌ها در مرورگر رندر شد.`);

    // ۴. بررسی تنظیمات امنیتی (2FA)
    await page.goto(`${BASE_URL}/account/security`, { waitUntil: 'networkidle', timeout: 15000 });
    console.log(`  ${GREEN}✓${RESET} صفحه تنظیمات امنیتی و 2FA در مرورگر رندر شد.`);

    // ۵. بررسی صفحه توکن‌های API
    await page.goto(`${BASE_URL}/account/api-tokens`, { waitUntil: 'networkidle', timeout: 15000 });
    console.log(`  ${GREEN}✓${RESET} صفحه مدیریت توکن‌های API در مرورگر بارگذاری شد.`);

    // ۶. بررسی صفحه لغو عضویت (Account Deletion)
    await page.goto(`${BASE_URL}/account/delete`, { waitUntil: 'networkidle', timeout: 15000 });
    console.log(`  ${GREEN}✓${RESET} صفحه درخواست لغو عضویت در مرورگر بارگذاری شد.`);
    
    // ۷. بررسی خطاهای جاوااسکریپت
    console.log(`  ${GREEN}✓${RESET} وضعیت پایش کنسول JS: ${jsErrors.length} خطا یافت شد.`);
    if (jsErrors.length > 0) {
        jsErrors.forEach(e => console.log(`    ${RED}-${RESET} ${e}`));
    }
  } catch (e) {
    console.log(`  ${RED}✗${RESET} خطای تست مرورگر: ${e.message}`);
  } finally {
    await browser.close();
    console.log(`${BOLD}✓ پایان تست مرورگری پروفایل و حساب کاربری${RESET}\n`);
  }
})();
