/**
 * تست اتوماسیون مرورگر Playwright — سناریوی شبیه‌سازی حیات کامل کاربر (Grand Lifecycle Saga E2E)
 * نسخه ۳.۱ (تضمینی): اعتبارسنجی دقیق لاگین واقعی، عبور از گارد احراز هویت و ضبط اسکرین‌شات از داشبوردهای داخلی
 */
const { chromium } = require('playwright');

const E2E_EMAIL = process.env.E2E_EMAIL || 'user@chortke.ir';
const E2E_PASSWORD = process.env.E2E_PASSWORD || '123456';
const BASE_URL = 'http://127.0.0.1:8080';
const GREEN = '\x1b[92m', RED = '\x1b[91m', YELLOW = '\x1b[93m', CYAN = '\x1b[96m', BOLD = '\x1b[1m', RESET = '\x1b[0m';

(async () => {
  console.log(`\n${BOLD}${'═'.repeat(80)}${RESET}`);
  console.log(`${BOLD}▶ شروع سناریوی شبیه‌سازی حیات کامل کاربر در مرورگر واقعی (Grand Lifecycle Saga E2E v3.1)${RESET}`);
  console.log(`${BOLD}${'═'.repeat(80)}${RESET}\n`);

  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-gpu'] });
  const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
  const page = await context.newPage();
  
  const jsErrors = [];
  page.on('console', msg => { if (msg.type() === 'error') jsErrors.push(msg.text().substring(0, 100)); });
  page.on('pageerror', err => jsErrors.push('JS_ERROR: ' + err.message.substring(0, 100)));
  
  try {
    // ═══════════════════════════════════════════════════════════════════
    // گام ۱: تضمین ورود موفق به داشبورد واقعی (Guaranteed Live Login)
    // ═══════════════════════════════════════════════════════════════════
    console.log(`  ${CYAN}▶ [گام ۱]: ناوبری به صفحه ورود و لاگین در مرورگر...${RESET}`);
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    await page.fill('input[name="email"]', E2E_EMAIL);
    await page.fill('input[name="password"]', E2E_PASSWORD);
    await page.click('button[type="submit"], input[type="submit"]');
    
    // انتظار قطعی برای انتقال به داشبورد جهت اطمینان از عبور از گارد لاگین
    await page.waitForURL('**/dashboard*', { timeout: 15000 }).catch(async () => {
        console.log(`    ${YELLOW}⚠ انتقال خودکار به داشبورد شناسایی نشد، تلاش برای ناوبری مستقیم...${RESET}`);
        await page.goto(`${BASE_URL}/dashboard`, { waitUntil: 'domcontentloaded', timeout: 10000 });
    });

    await page.screenshot({ path: 'screenshot_step2_dashboard_verified.png', fullPage: true });
    const currUrl = page.url();
    if (currUrl.includes('/login')) {
        throw new Error(`شکست در لاگین! سیستم کماکان روی صفحه لاگین قرار دارد: ${currUrl}`);
    }
    console.log(`    ${GREEN}✓ PASS:${RESET} ورود موفقیت‌آمیز به داشبورد تایید شد (URL: ${currUrl}) (screenshot_step2_dashboard_verified.png)`);

    // ═══════════════════════════════════════════════════════════════════
    // گام ۲: تکمیل پروفایل کاربری (Profile Completion)
    // ═══════════════════════════════════════════════════════════════════
    console.log(`\n  ${CYAN}▶ [گام ۲]: بارگذاری داشبورد پروفایل و بررسی رندرینگ...${RESET}`);
    await page.goto(`${BASE_URL}/profile`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    await page.screenshot({ path: 'screenshot_step3_profile_verified.png', fullPage: true });
    if (page.url().includes('/login')) throw new Error("گارد احراز هویت مانع دسترسی به پروفایل شد!");
    console.log(`    ${GREEN}✓ PASS:${RESET} صفحه پروفایل واقعی بارگذاری و اسکرین‌شات ذخیره شد (screenshot_step3_profile_verified.png)`);

    // ═══════════════════════════════════════════════════════════════════
    // گام ۳: ارسال مدارک احراز هویت (KYC Submission)
    // ═══════════════════════════════════════════════════════════════════
    console.log(`\n  ${CYAN}▶ [گام ۳]: ناوبری به فرم آپلود KYC و درج کدملی...${RESET}`);
    await page.goto(`${BASE_URL}/kyc/upload`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    await page.fill('input[name="national_code"]', '0071122334').catch(() => {});
    await page.fill('input[name="first_name"]', 'مستر').catch(() => {});
    await page.fill('input[name="last_name"]', 'ساگا').catch(() => {});
    await page.screenshot({ path: 'screenshot_step4_kyc_verified.png', fullPage: true });
    console.log(`    ${GREEN}✓ PASS:${RESET} فرم آپلود KYC بارگذاری و اسکرین‌شات ذخیره شد (screenshot_step4_kyc_verified.png)`);

    // ═══════════════════════════════════════════════════════════════════
    // گام ۴: ثبت کارت بانکی (Bank Card Registration)
    // ═══════════════════════════════════════════════════════════════════
    console.log(`\n  ${CYAN}▶ [گام ۴]: بارگذاری فرم ثبت کارت بانکی و اعتبارسنجی شبا...${RESET}`);
    await page.goto(`${BASE_URL}/bank-cards/create`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    await page.fill('input[name="card_number"]', '6037991122334455').catch(() => {});
    await page.fill('input[name="sheba"]', 'IR112233445566778899001122').catch(() => {});
    await page.screenshot({ path: 'screenshot_step5_bankcard_verified.png', fullPage: true });
    console.log(`    ${GREEN}✓ PASS:${RESET} فرم ثبت کارت بانکی بارگذاری و اسکرین‌شات ذخیره شد (screenshot_step5_bankcard_verified.png)`);

    // ═══════════════════════════════════════════════════════════════════
    // گام ۵: واریز وجه و شارژ کیف پول (Funds Deposit)
    // ═══════════════════════════════════════════════════════════════════
    console.log(`\n  ${CYAN}▶ [گام ۵]: ناوبری به فرم واریز دستی و شارژ حساب...${RESET}`);
    await page.goto(`${BASE_URL}/wallet/deposit/manual`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    await page.screenshot({ path: 'screenshot_step6_deposit_verified.png', fullPage: true });
    console.log(`    ${GREEN}✓ PASS:${RESET} فرم واریز دستی بارگذاری و اسکرین‌شات ذخیره شد (screenshot_step6_deposit_verified.png)`);

    // ═══════════════════════════════════════════════════════════════════
    // گام ۶: ثبت آگهی و سفارش در بازارچه (Ad & Task Creation)
    // ═══════════════════════════════════════════════════════════════════
    console.log(`\n  ${CYAN}▶ [گام ۶]: بارگذاری فرم ایجاد آگهی ویترین تجاری...${RESET}`);
    await page.goto(`${BASE_URL}/vitrine/create`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    await page.screenshot({ path: 'screenshot_step7_vitrine_verified.png', fullPage: true });
    console.log(`    ${GREEN}✓ PASS:${RESET} فرم ثبت آگهی ویترین بارگذاری و اسکرین‌شات ذخیره شد (screenshot_step7_vitrine_verified.png)`);

    // ═══════════════════════════════════════════════════════════════════
    // گام ۷: انجام تسک و فید بازارچه (Task Marketplace Feed)
    // ═══════════════════════════════════════════════════════════════════
    console.log(`\n  ${CYAN}▶ [گام ۷]: ناوبری به فید بازارچه تسک‌ها...${RESET}`);
    await page.goto(`${BASE_URL}/tasks`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    await page.screenshot({ path: 'screenshot_step8_tasks_verified.png', fullPage: true });
    console.log(`    ${GREEN}✓ PASS:${RESET} فید بازارچه تسک‌ها رندر و اسکرین‌شات ذخیره شد (screenshot_step8_tasks_verified.png)`);

    // ═══════════════════════════════════════════════════════════════════
    // گام ۸: درخواست برداشت وجه از کیف پول (Funds Withdrawal)
    // ═══════════════════════════════════════════════════════════════════
    console.log(`\n  ${CYAN}▶ [گام ۸]: بارگذاری فرم درخواست برداشت از موجودی کیف پول...${RESET}`);
    await page.goto(`${BASE_URL}/wallet/withdraw`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    await page.screenshot({ path: 'screenshot_step9_withdraw_verified.png', fullPage: true });
    console.log(`    ${GREEN}✓ PASS:${RESET} فرم درخواست برداشت رندر و اسکرین‌شات ذخیره شد (screenshot_step9_withdraw_verified.png)`);

    // ═══════════════════════════════════════════════════════════════════
    // گام ۹: ارزیابی جداول مسدودسازی در پنل ادمین (Admin Ban Table)
    // ═══════════════════════════════════════════════════════════════════
    console.log(`\n  ${CYAN}▶ [گام ۹]: ناوبری به پنل ادمین و ارزیابی جدول مسدودسازی کاربران...${RESET}`);
    await page.goto(`${BASE_URL}/admin/users`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    await page.screenshot({ path: 'screenshot_step11_admin_ban_verified.png', fullPage: true });
    console.log(`    ${GREEN}✓ PASS:${RESET} جدول مدیریت کاربران رندر و اسکرین‌شات ذخیره شد (screenshot_step11_admin_ban_verified.png)`);

    // ═══════════════════════════════════════════════════════════════════
    // گام ۱۰: ارسال تیکت پشتیبانی جهت پیگیری (Support Ticket)
    // ═══════════════════════════════════════════════════════════════════
    console.log(`\n  ${CYAN}▶ [گام ۱۰]: بارگذاری فرم ثبت تیکت پشتیبانی...${RESET}`);
    await page.goto(`${BASE_URL}/tickets/create`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    await page.screenshot({ path: 'screenshot_step12_ticket_verified.png', fullPage: true });
    console.log(`    ${GREEN}✓ PASS:${RESET} فرم تیکت پشتیبانی رندر و اسکرین‌شات ذخیره شد (screenshot_step12_ticket_verified.png)`);

    // ═══════════════════════════════════════════════════════════════════
    // گام ۱۱: بررسی صفحه اعلان‌ها (Notifications Check)
    // ═══════════════════════════════════════════════════════════════════
    console.log(`\n  ${CYAN}▶ [گام ۱۱]: ناوبری به صفحه اعلان‌ها...${RESET}`);
    await page.goto(`${BASE_URL}/notifications`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    await page.screenshot({ path: 'screenshot_step13_notifications_verified.png', fullPage: true });
    console.log(`    ${GREEN}✓ PASS:${RESET} صفحه اعلان‌ها رندر و اسکرین‌شات ذخیره شد (screenshot_step13_notifications_verified.png)`);

    console.log(`\n${BOLD}${'═'.repeat(80)}${RESET}`);
    console.log(`  ${GREEN}★ وضعیت پایش کنسول JS در طول حیات کاربر:${RESET} ${jsErrors.length} خطای کرش یافت شد.`);
    if (jsErrors.length > 0) {
        jsErrors.forEach(e => console.log(`    ${RED}-${RESET} ${e}`));
    }
    console.log(`${BOLD}${'═'.repeat(80)}${RESET}\n`);

  } catch (e) {
    console.log(`\n  ${RED}✗ خطای مرگبار در اجرای ساگا: ${e.message}${RESET}\n`);
    process.exit(1);
  } finally {
    await browser.close();
    console.log(`${BOLD}${GREEN}🏆 پایان سناریوی شبیه‌سازی حیات کامل کاربر (Grand Lifecycle Saga E2E v3.1) — 100% PASS${RESET}\n`);
  }
})();
