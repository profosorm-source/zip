/**
 * Chortke Browser Test Suite — تست ترکیبی مرورگر + سرور
 * 
 * پوشش:
 * - خطاهای کنسول JS در هر صفحه
 * - فرم‌ها: fill, submit, AJAX response
 * - asset failures (CSS/JS/Image)
 * - DOM rendering validation
 * - JavaScript execution errors
 * - Network request failures
 */
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:8080';
const ADMIN_EMAIL = 'admin@chortke.ir';
const ADMIN_PASS = '123456';

// Color helpers
const GREEN = '\x1b[92m', RED = '\x1b[91m', YELLOW = '\x1b[93m', CYAN = '\x1b[96m', RESET = '\x1b[0m', BOLD = '\x1b[1m';

let totalPass = 0, totalFail = 0;

async function login(page, email = ADMIN_EMAIL, password = ADMIN_PASS) {
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle', timeout: 15000 });
    // Fill login form
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    
    // Solve math captcha if present
    const captchaQ = await page.locator('.captcha-question').textContent().catch(() => null);
    if (captchaQ) {
        const m = captchaQ.match(/(\d+)\s*([+\-*])\s*(\d+)/);
        if (m) {
            const a = parseInt(m[1]), op = m[2], b = parseInt(m[3]);
            const answer = {'+': a+b, '-': a-b, '*': a*b}[op];
            await page.fill('input[name="captcha_response"]', String(answer));
        }
    }
    
    await page.click('button[type="submit"], button:not([type])');
    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
    return page.url().includes('dashboard') || page.url().includes('verify');
}

async function testPage(browser, name, url, options = {}) {
    const page = await browser.newPage();
    const errors = [];
    const failedAssets = [];
    
    page.on('console', msg => {
        if (msg.type() === 'error') errors.push(msg.text().substring(0, 120));
    });
    page.on('pageerror', err => errors.push('JS_ERROR: ' + err.message.substring(0, 120)));
    page.on('requestfailed', req => {
        const url = req.url();
        if (!url.includes('favicon') && !url.includes('recaptcha')) {
            failedAssets.push(url.substring(0, 80));
        }
    });
    
    let status = 'PASS';
    const details = [];
    
    try {
        await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle', timeout: 15000 });
        
        // Check for PHP errors in page
        const bodyText = await page.locator('body').textContent().catch(() => '');
        const phpErrors = ['Fatal error', 'Undefined variable', 'Call to undefined', 'SQLSTATE', 'Exception'];
        for (const pe of phpErrors) {
            if (bodyText.includes(pe)) {
                errors.push('PHP: ' + pe);
            }
        }
        
        // Check DOM
        const hasContent = bodyText.trim().length > 100;
        if (!hasContent) {
            errors.push('Empty page content');
        }
        
        // Check forms
        const formCount = await page.locator('form').count();
        if (options.expectForm && formCount === 0) {
            errors.push('Expected form but none found');
        }
        
        // Check tables
        const tableCount = await page.locator('table').count();
        if (options.expectTable && tableCount === 0) {
            errors.push('Expected table but none found');
        }
        
    } catch (e) {
        errors.push('NAVIGATION: ' + e.message.substring(0, 100));
    }
    
    if (errors.length > 0 || failedAssets.length > 0) {
        status = 'FAIL';
        totalFail++;
    } else {
        totalPass++;
    }
    
    const symbol = status === 'PASS' ? `${GREEN}✓${RESET}` : `${RED}✗${RESET}`;
    console.log(`  ${symbol} ${name.padEnd(40)} ${url}`);
    errors.forEach(e => console.log(`      ${RED}${e}${RESET}`));
    failedAssets.forEach(a => console.log(`      ${YELLOW}ASSET FAIL: ${a}${RESET}`));
    
    await page.close();
    return { status, errors, failedAssets };
}

async function testLoginForm(browser) {
    const page = await browser.newPage();
    const errors = [];
    
    page.on('console', msg => { if (msg.type() === 'error') errors.push(msg.text().substring(0, 120)); });
    page.on('pageerror', err => errors.push('JS_ERROR: ' + err.message.substring(0, 120)));
    
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle', timeout: 15000 });
    
    // Check form fields exist
    const emailField = await page.locator('input[name="email"]').count();
    const passwordField = await page.locator('input[name="password"]').count();
    const csrfField = await page.locator('input[name="_csrf_token"]').count();
    const submitBtn = await page.locator('button[type="submit"]').count();
    
    const allFields = emailField > 0 && passwordField > 0 && csrfField > 0 && submitBtn > 0;
    
    // Try filling and submitting
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    
    // Solve captcha
    const captchaQ = await page.locator('.captcha-question').textContent().catch(() => null);
    if (captchaQ) {
        const m = captchaQ.match(/(\d+)\s*([+\-*])\s*(\d+)/);
        if (m) {
            const answer = {'+': +m[1]+ +m[3], '-': +m[1]- +m[3], '*': +m[1]* +m[3]}[m[2]];
            await page.fill('input[name="captcha_response"]', String(answer));
        }
    }
    
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
    
    const redirected = page.url().includes('dashboard') || page.url().includes('verify');
    
    const status = allFields && errors.length === 0 ? 'PASS' : 'FAIL';
    const symbol = status === 'PASS' ? `${GREEN}✓${RESET}` : `${RED}✗${RESET}`;
    
    if (status === 'PASS') { totalPass++; } else { totalFail++; }
    
    console.log(`  ${symbol} ${'B1: فرم ورود - فیلدها + AJAX submit'.padEnd(40)}`);
    console.log(`      Fields: email=${emailField} pass=${passwordField} csrf=${csrfField} submit=${submitBtn}`);
    if (errors.length) errors.forEach(e => console.log(`      ${RED}${e}${RESET}`));
    
    await page.close();
    return status;
}

async function testRegisterForm(browser) {
    const page = await browser.newPage();
    const errors = [];
    
    page.on('console', msg => { if (msg.type() === 'error') errors.push(msg.text().substring(0, 120)); });
    page.on('pageerror', err => errors.push('JS_ERROR: ' + err.message.substring(0, 120)));
    
    await page.goto(`${BASE}/register`, { waitUntil: 'networkidle', timeout: 15000 });
    
    // Check all required fields
    const fields = ['email', 'full_name', 'password', 'password_confirmation'];
    const results = {};
    for (const f of fields) {
        results[f] = await page.locator(`input[name="${f}"]`).count();
    }
    
    // Check captcha
    const captchaCount = await page.locator('.captcha-container, .captcha-wrapper').count();
    
    // Check JS files loaded
    const jsFiles = await page.locator('script[src]').count();
    
    // Try weak password
    await page.fill('input[name="email"]', 'browsertest@chortke.test');
    await page.fill('input[name="full_name"]', 'Browser Test');
    await page.fill('input[name="password"]', '123');
    await page.fill('input[name="password_confirmation"]', '123');
    
    // Solve captcha
    const captchaQ = await page.locator('.captcha-question').textContent().catch(() => null);
    if (captchaQ) {
        const m = captchaQ.match(/(\d+)\s*([+\-*])\s*(\d+)/);
        if (m) {
            const answer = {'+': +m[1]+ +m[3], '-': +m[1]- +m[3], '*': +m[1]* +m[3]}[m[2]];
            await page.fill('input[name="captcha_response"]', String(answer));
        }
    }
    
    // Check terms checkbox
    const termsCheckbox = await page.locator('input[name="terms"]').count();
    if (termsCheckbox > 0) await page.check('input[name="terms"]');
    
    // Submit weak password
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {});
    
    // Should still be on register page (rejected)
    const stillOnRegister = page.url().includes('register');
    
    const allFieldsOk = Object.values(results).every(v => v > 0);
    const status = allFieldsOk && captchaCount > 0 && errors.length === 0 ? 'PASS' : 'FAIL';
    const symbol = status === 'PASS' ? `${GREEN}✓${RESET}` : `${RED}✗${RESET}`;
    
    if (status === 'PASS') { totalPass++; } else { totalFail++; }
    
    console.log(`  ${symbol} ${'B2: فرم ثبت‌نام - فیلدها + captcha + validation'.padEnd(40)}`);
    console.log(`      Fields: ${JSON.stringify(results)} captcha=${captchaCount} jsFiles=${jsFiles}`);
    console.log(`      Weak password rejected: ${stillOnRegister ? 'YES' : 'NO'}`);
    if (errors.length) errors.forEach(e => console.log(`      ${RED}${e}${RESET}`));
    
    await page.close();
    return status;
}

async function testLoggedInPages(browser) {
    const context = await browser.newContext();
    const page = await context.newPage();
    
    // Login first
    await login(page);
    
    // Test pages with active session
    const pages = [
        ['/dashboard', 'داشبورد کاربر'],
        ['/wallet', 'کیف پول'],
        ['/wallet/history', 'تاریخچه کیف پول'],
        ['/tasks', 'فید تسک‌ها'],
        ['/social-tasks', 'تسک‌های اجتماعی'],
        ['/custom-tasks', 'تسک‌های سفارشی'],
        ['/profile', 'پروفایل'],
        ['/notifications', 'اعلان‌ها'],
    ];
    
    for (const [url, name] of pages) {
        await testPage(browser, `B-LG: ${name}`, url);
    }
    
    await context.close();
}

async function testAdminPages(browser) {
    const context = await browser.newContext();
    const page = await context.newPage();
    
    // Admin login
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle', timeout: 15000 });
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
    
    // Test admin pages
    const pages = [
        ['/admin/dashboard', 'داشبورد ادمین'],
        ['/admin/users', 'مدیریت کاربران'],
        ['/admin/withdrawals', 'برداشت‌ها'],
        ['/admin/kpi', 'KPI'],
        ['/admin/analytics', 'آنالیتیکس'],
        ['/admin/fraud/logs', 'لاگ تقلب'],
        ['/admin/notifications/stats', 'آمار اعلان‌ها'],
    ];
    
    for (const [url, name] of pages) {
        await testPage(browser, `B-AD: ${name}`, url);
    }
    
    await context.close();
}

// ═══ Main ═══
(async () => {
    console.log(`\n${BOLD}${CYAN}═══════════════════════════════════════════════════════${RESET}`);
    console.log(`${BOLD}${CYAN}  تست مرورگری چرتکه — Playwright + Chromium${RESET}`);
    console.log(`${BOLD}${CYAN}═══════════════════════════════════════════════════════${RESET}\n`);
    
    const browser = await chromium.launch({ 
        headless: true, 
        args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'] 
    });
    
    // Public pages
    console.log(`${BOLD}\n--- صفحات عمومی ---${RESET}`);
    await testPage(browser, 'صفحه اصلی', '/');
    await testPage(browser, 'صفحه ورود', '/login');
    await testPage(browser, 'صفحه ثبت‌نام', '/register');
    await testPage(browser, 'فراموشی رمز', '/forgot-password');
    
    // Form tests
    console.log(`${BOLD}\n--- فرم‌ها (تعاملی) ---${RESET}`);
    await testLoginForm(browser);
    await testRegisterForm(browser);
    
    // Logged-in pages
    console.log(`${BOLD}\n--- صفحات کاربر لاگین‌شده ---${RESET}`);
    await testLoggedInPages(browser);
    
    // Admin pages
    console.log(`${BOLD}\n--- صفحات ادمین ---${RESET}`);
    await testAdminPages(browser);
    
    await browser.close();
    
    console.log(`\n${BOLD}═══════════════════════════════════════════════════════${RESET}`);
    console.log(`  ${GREEN}Passed: ${totalPass}${RESET}  ${RED}Failed: ${totalFail}${RESET}  Total: ${totalPass + totalFail}`);
    console.log(`${BOLD}═══════════════════════════════════════════════════════${RESET}\n`);
    
    process.exit(totalFail > 0 ? 1 : 0);
})();
