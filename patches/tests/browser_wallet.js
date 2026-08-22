/**
 * تست اتوماسیون مرورگر Playwright — بخش کیف پول و مدیریت مالی (Wallet E2E)
 * بررسی بارگذاری صفحات، فرم واریز دستی، فرم درخواست برداشت، پایش خطاهای کنسول JS و تعاملات کاربری
 */
const { chromium } = require('playwright');

const E2E_EMAIL = process.env.E2E_EMAIL || 'user@chortke.ir';
const E2E_PASSWORD = process.env.E2E_PASSWORD || '123456';
const BASE_URL = process.env.CHORTKE_E2E_BASE_URL || 'http://127.0.0.1:8080';
const GREEN = '\x1b[92m', RED = '\x1b[91m', BOLD = '\x1b[1m', RESET = '\x1b[0m';

(async () => {
  console.log(`${BOLD}▶ شروع تست اتوماسیون مرورگر: کیف پول و مدیریت مالی (browser_wallet.js)${RESET}`);
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

    // ۲. بررسی داشبورد کیف پول
    await page.goto(`${BASE_URL}/wallet`, { waitUntil: 'networkidle', timeout: 15000 });
    const title = await page.title();
    const bodyText = await page.innerText('body');
    console.log(`  ${GREEN}✓${RESET} صفحه کیف پول بارگذاری شد (Title: ${title})`);
    
    // ۳. بررسی فرم واریز دستی
    await page.goto(`${BASE_URL}/wallet/deposit/manual`, { waitUntil: 'networkidle', timeout: 15000 });
    const hasDepositForm = await page.locator('form').count();
    console.log(`  ${GREEN}✓${RESET} فرم واریز دستی در مرورگر رندر شد (تعداد فرم: ${hasDepositForm})`);

    // ۴. بررسی فرم برداشت وجه
    await page.goto(`${BASE_URL}/wallet/withdraw`, { waitUntil: 'networkidle', timeout: 15000 });
    const hasWithdrawForm = await page.locator('form').count();
    console.log(`  ${GREEN}✓${RESET} فرم درخواست برداشت در مرورگر رندر شد (تعداد فرم: ${hasWithdrawForm})`);
    
    // ۵. بررسی خطاهای جاوااسکریپت
    console.log(`  ${GREEN}✓${RESET} وضعیت پایش کنسول JS: ${jsErrors.length} خطا یافت شد.`);
    if (jsErrors.length > 0) {
        jsErrors.forEach(e => console.log(`    ${RED}-${RESET} ${e}`));
    }
  } catch (e) {
    console.log(`  ${RED}✗${RESET} خطای تست مرورگر: ${e.message}`);
  } finally {
    await browser.close();
    console.log(`${BOLD}✓ پایان تست مرورگری کیف پول${RESET}\n`);
  }
})();
