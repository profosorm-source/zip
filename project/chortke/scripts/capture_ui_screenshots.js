const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE_URL = 'http://127.0.0.1:8080';
const screenshotsDir = path.join(__dirname, '../screenshots');

if (!fs.existsSync(screenshotsDir)) {
    fs.mkdirSync(screenshotsDir, { recursive: true });
}

(async () => {
    console.log('Capturing real UI screenshots...');
    const browser = await chromium.launch({
        executablePath: '/home/user/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome',
        headless: true,
        args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu']
    });

    // --- 1. User Context ---
    const userContext = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const userPage = await userContext.newPage();

    await userPage.goto(`${BASE_URL}/login`);
    await userPage.screenshot({ path: path.join(screenshotsDir, '01_login_page.png') });

    await userPage.fill('input[name="email"]', 'user@chortke.ir');
    await userPage.fill('input[name="password"]', '123456');
    await Promise.all([
        userPage.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}),
        userPage.click('button[type="submit"]')
    ]);
    await userPage.waitForTimeout(1000);

    await userPage.goto(`${BASE_URL}/dashboard`);
    await userPage.screenshot({ path: path.join(screenshotsDir, '02_user_dashboard.png') });

    await userPage.goto(`${BASE_URL}/tasks`);
    await userPage.screenshot({ path: path.join(screenshotsDir, '03_user_tasks.png') });

    await userPage.goto(`${BASE_URL}/lottery`);
    await userPage.screenshot({ path: path.join(screenshotsDir, '04_user_lottery.png') });

    // --- 2. Admin Context ---
    const adminContext = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const adminPage = await adminContext.newPage();

    await adminPage.goto(`${BASE_URL}/admin/login`);
    await adminPage.fill('input[name="email"]', 'admin@chortke.ir');
    await adminPage.fill('input[name="password"]', '123456');
    await Promise.all([
        adminPage.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}),
        adminPage.click('button[type="submit"]')
    ]);
    await adminPage.waitForTimeout(1000);

    await adminPage.goto(`${BASE_URL}/admin/dashboard`);
    await adminPage.screenshot({ path: path.join(screenshotsDir, '05_admin_dashboard.png') });

    await adminPage.goto(`${BASE_URL}/admin/users`);
    await adminPage.screenshot({ path: path.join(screenshotsDir, '06_admin_users.png') });

    console.log('Successfully captured UI screenshots in screenshots/');
    await browser.close();
})();
