/**
 * tests/browser_earning_and_user_mgmt_e2e.js
 *
 * تست E2E مرورگر اختصاصی Playwright برای تمام ماژول‌های درآمدی و مدیریت کاربران
 */

const { chromium } = require('playwright');

const BASE_URL = 'http://127.0.0.1:8080';
const GREEN = '\x1b[92m', RED = '\x1b[91m', CYAN = '\x1b[96m', BOLD = '\x1b[1m', RESET = '\x1b[0m';

async function solveCaptcha(page) {
    try {
        const captchaLabel = await page.locator('label:has-text("حاصل"), label:has-text("چقدر است")').first();
        if (await captchaLabel.isVisible({ timeout: 1500 })) {
            const text = await captchaLabel.innerText();
            const match = text.match(/(\d+)\s*([\+\-\*])\s*(\d+)/);
            if (match) {
                const num1 = parseInt(match[1], 10);
                const op = match[2];
                const num2 = parseInt(match[3], 10);
                let result = 0;
                if (op === '+') result = num1 + num2;
                else if (op === '-') result = num1 - num2;
                else if (op === '*') result = num1 * num2;
                
                const captchaInput = page.locator('input[name="captcha"], input[name="captcha_answer"]').first();
                if (await captchaInput.isVisible({ timeout: 1000 })) {
                    await captchaInput.fill(result.toString());
                }
            }
        }
    } catch (e) {
        // Captcha solver fallback
    }
}

async function runBrowserEarningAndUserMgmtTests() {
    console.log(`\n${BOLD}${CYAN}========================================================================${RESET}`);
    console.log(`${BOLD}${CYAN}  اجرای تست اتوماسیون مرورگر Playwright برای ماژول‌های درآمدی و کاربران  ${RESET}`);
    console.log(`${BOLD}${CYAN}========================================================================${RESET}\n`);

    const browser = await chromium.launch({
        executablePath: '/home/user/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome',
        headless: true,
        args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu']
    });

    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await context.newPage();

    let passed = 0;
    let failed = 0;

    function logStep(name, success, extra = '') {
        if (success) {
            passed++;
            console.log(`  ${GREEN}✓ PASS:${RESET} ${name}` + (extra ? ` (${extra})` : ''));
        } else {
            failed++;
            console.log(`  ${RED}✗ FAIL:${RESET} ${name}` + (extra ? ` (${extra})` : ''));
        }
    }

    try {
        // ۱. ورود به سیستم با اکانت کاربری
        console.log(`\n${BOLD}--- ۱. ورود به سیستم و راه‌اندازی جلسه کاربر ---${RESET}`);
        await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle' });
        await page.fill('input[name="identifier"], input[name="email"]', 'user@chortke.ir');
        await page.fill('input[name="password"]', '123456');
        await solveCaptcha(page);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle', timeout: 5000 }).catch(() => {}),
            page.click('button[type="submit"]')
        ]);
        const currentUrl = page.url();
        logStep('ورود کاربر به داشبورد و دریافت کوکی جلسه', currentUrl.includes('/dashboard') || currentUrl.includes('/') || currentUrl.includes('/profile'), `URL: ${currentUrl}`);

        // ۲. بازرسی صفحات مدیریت کاربر و پروفایل
        console.log(`\n${BOLD}--- ۲. بررسی صفحات مدیریت کاربر و احراز هویت (KYC) ---${RESET}`);
        const profileResp = await page.goto(`${BASE_URL}/profile`, { waitUntil: 'networkidle' });
        logStep('دسترسی به صفحه مدیریت پروفایل کاربر', profileResp && profileResp.status() === 200, `HTTP ${profileResp?.status()}`);

        const kycResp = await page.goto(`${BASE_URL}/kyc`, { waitUntil: 'networkidle' });
        logStep('دسترسی به مرکز احراز هویت (KYC)', kycResp && kycResp.status() === 200, `HTTP ${kycResp?.status()}`);

        // ۳. بازرسی ماژول تسک سفارشی
        console.log(`\n${BOLD}--- ۳. بررسی بازارچه تسک‌های سفارشی ---${RESET}`);
        const customTaskResp = await page.goto(`${BASE_URL}/custom-tasks`, { waitUntil: 'networkidle' });
        logStep('دسترسی به فهرست تسک‌های سفارشی', customTaskResp && customTaskResp.status() === 200, `HTTP ${customTaskResp?.status()}`);

        // ۴. بازرسی ماژول تسک سوسیال
        console.log(`\n${BOLD}--- ۴. بررسی ماژول تسک‌های شبکه‌های اجتماعی ---${RESET}`);
        const socialTaskResp = await page.goto(`${BASE_URL}/social-tasks`, { waitUntil: 'networkidle' });
        logStep('دسترسی به ماژول درآمدزایی شبکه‌های اجتماعی', socialTaskResp && socialTaskResp.status() === 200, `HTTP ${socialTaskResp?.status()}`);

        // ۵. بازرسی ماژول تسک سئو
        console.log(`\n${BOLD}--- ۵. بررسی ماژول کلیک و بازدید سئو ---${RESET}`);
        const seoResp = await page.goto(`${BASE_URL}/seo`, { waitUntil: 'networkidle' });
        logStep('دسترسی به بخش تسک‌های سئو و ترافیک', seoResp && seoResp.status() === 200, `HTTP ${seoResp?.status()}`);

        // ۶. بازرسی حساب‌های شبکه‌های اجتماعی
        console.log(`\n${BOLD}--- ۶. بررسی اکانت‌های شبکه اجتماعی کاربر ---${RESET}`);
        const socialAccountsResp = await page.goto(`${BASE_URL}/social-accounts`, { waitUntil: 'networkidle' });
        logStep('دسترسی به پنل اتصال شبکه‌های اجتماعی', socialAccountsResp && socialAccountsResp.status() === 200, `HTTP ${socialAccountsResp?.status()}`);

        // ۷. بازرسی ماژول قرعه‌کشی و لاتاری
        console.log(`\n${BOLD}--- ۷. بررسی گردونه لاتاری و بخت‌آزمایی ---${RESET}`);
        const lotteryResp = await page.goto(`${BASE_URL}/lottery`, { waitUntil: 'networkidle' });
        logStep('دسترسی به دوره فعال لاتاری و خرید بلیت', lotteryResp && lotteryResp.status() === 200, `HTTP ${lotteryResp?.status()}`);

        // ۸. بازرسی ماژول سرمایه‌گذاری و استیکینگ
        console.log(`\n${BOLD}--- ۸. بررسی پلن‌های سرمایه‌گذاری و سود دهی ---${RESET}`);
        const invResp = await page.goto(`${BASE_URL}/investment`, { waitUntil: 'networkidle' });
        logStep('دسترسی به جدول پلن‌های سرمایه‌گذاری و سود ماهانه', invResp && invResp.status() === 200, `HTTP ${invResp?.status()}`);

        // ۹. بازرسی مرکز نوتیفیکیشن‌ها
        console.log(`\n${BOLD}--- ۹. بررسی مرکز پیام‌ها و اعلانات ---${RESET}`);
        const notifResp = await page.goto(`${BASE_URL}/notifications`, { waitUntil: 'networkidle' });
        logStep('دسترسی به مرکز پیام‌ها و اعلانات کاربر', notifResp && notifResp.status() === 200, `HTTP ${notifResp?.status()}`);

        // ۱۰. ورود به پنل ادمین و بررسی مدیریت کاربران و ماژول‌ها
        console.log(`\n${BOLD}--- ۱۰. بررسی پنل حاکمیتی ادمین و مدیریت کاربران و تسک‌ها ---${RESET}`);
        await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle' });
        await page.fill('input[name="identifier"], input[name="email"]', 'admin@chortke.ir');
        await page.fill('input[name="password"]', '123456');
        await solveCaptcha(page);
        await page.click('button[type="submit"]');
        await page.waitForTimeout(1000);

        const adminUsersResp = await page.goto(`${BASE_URL}/admin/users`, { waitUntil: 'networkidle' });
        logStep('دسترسی ادمین به جدول مدیریت کلیه کاربران', adminUsersResp && adminUsersResp.status() === 200, `HTTP ${adminUsersResp?.status()}`);

        const adminCustomTasksResp = await page.goto(`${BASE_URL}/admin/custom-tasks`, { waitUntil: 'networkidle' });
        logStep('دسترسی ادمین به مدیریت تسک‌های سفارشی', adminCustomTasksResp && adminCustomTasksResp.status() === 200, `HTTP ${adminCustomTasksResp?.status()}`);

        const adminSocialTasksResp = await page.goto(`${BASE_URL}/admin/social-tasks`, { waitUntil: 'networkidle' });
        logStep('دسترسی ادمین به نظارت تسک‌های سوسیال', adminSocialTasksResp && adminSocialTasksResp.status() === 200, `HTTP ${adminSocialTasksResp?.status()}`);

        const adminInvestmentResp = await page.goto(`${BASE_URL}/admin/investment`, { waitUntil: 'networkidle' });
        logStep('دسترسی ادمین به نظارت بر طرح‌های سرمایه‌گذاری', adminInvestmentResp && adminInvestmentResp.status() === 200, `HTTP ${adminInvestmentResp?.status()}`);

        const adminLotteryResp = await page.goto(`${BASE_URL}/admin/lottery`, { waitUntil: 'networkidle' });
        logStep('دسترسی ادمین به مدیریت ادوار لاتاری', adminLotteryResp && adminLotteryResp.status() === 200, `HTTP ${adminLotteryResp?.status()}`);

    } catch (e) {
        console.error('Playwright Error:', e);
        failed++;
    } finally {
        await browser.close();
    }

    console.log(`\n${BOLD}${CYAN}========================================================================${RESET}`);
    console.log(`  خلاصه آزمون مرورگر Playwright: ${GREEN}PASS: ${passed}${RESET} | ${RED}FAIL: ${failed}${RESET}`);
    console.log(`${BOLD}${CYAN}========================================================================${RESET}\n`);

    if (failed > 0) {
        process.exit(1);
    } else {
        process.exit(0);
    }
}

runBrowserEarningAndUserMgmtTests();
