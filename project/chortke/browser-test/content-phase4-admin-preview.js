const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

(async () => {
  const base = process.env.BASE_URL || 'http://127.0.0.1:8099/chortke';
  const outDir = '/home/user/projects/zip/extracted/chortke/tools/browser-preview/screenshots';
  fs.mkdirSync(outDir, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1440, height: 980 } });
  const errors = [];
  const failedRequests = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('requestfailed', req => {
    const u = req.url();
    if (/assets\/(css|js|vendor)\//.test(u)) failedRequests.push(u + ' :: ' + (req.failure()?.errorText || 'failed'));
  });
  async function inspect(expected) {
    return await page.evaluate((expected) => {
      const body = document.body.innerText;
      const icon = document.querySelector('.material-icons');
      return {
        url: location.href,
        hasExpectedText: body.includes(expected),
        hasPhpError: /Fatal error|Parse error|Warning:|Undefined variable|Undefined property|GlobalException|SQLSTATE|خطای سیستمی/.test(body),
        doctypeCount: document.doctype ? 1 : 0,
        iconsFontLoaded: icon ? /Material Icons/i.test(getComputedStyle(icon).fontFamily) : true,
        hasAdminContentCss: !!document.querySelector('link[href*="admincontent.css"]'),
        hasAdminContentJs: !!document.querySelector('script[src*="admin/content.js"]'),
      };
    }, expected);
  }
  const checks = {};
  function localUrl(href) {
    if (!href) return null;
    try {
      const b = new URL(base);
      const u = new URL(href, base);
      if ((u.hostname === 'localhost' || u.hostname === '127.0.0.1') && u.pathname.startsWith('/chortke')) {
        u.protocol = b.protocol;
        u.hostname = b.hostname;
        u.port = b.port;
      }
      return u.toString();
    } catch (_) {
      return href;
    }
  }

  await page.goto(`${base}/admin/content?test_user_id=1`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: path.join(outDir, 'content-phase4-admin-index.png'), fullPage: true });
  checks.index = await inspect('بررسی محتواهای ارسالی کاربران');

  const firstContentHref = await page.locator('.ac-table tbody .ac-title-cell a').first().getAttribute('href').catch(() => null);
  let firstRevenueCreateHref = await page.locator('a[href*="/revenue/create"]').first().getAttribute('href').catch(() => null);
  if (!firstRevenueCreateHref) {
    await page.goto(`${base}/admin/content?test_user_id=1&status=published`, { waitUntil: 'networkidle' });
    firstRevenueCreateHref = await page.locator('a[href*="/revenue/create"]').first().getAttribute('href').catch(() => null);
    await page.goto(`${base}/admin/content?test_user_id=1`, { waitUntil: 'networkidle' });
  }
  if (firstContentHref) {
    await page.goto(localUrl(firstContentHref), { waitUntil: 'networkidle' });
    await page.screenshot({ path: path.join(outDir, 'content-phase4-admin-show.png'), fullPage: true });
    checks.show = await inspect('تاریخچه درآمد');

    const revenueCreateHref = await page.locator('a[href*="/revenue/create"]').first().getAttribute('href').catch(() => null) || firstRevenueCreateHref;
    if (revenueCreateHref) {
      await page.goto(localUrl(revenueCreateHref), { waitUntil: 'networkidle' });
      await page.screenshot({ path: path.join(outDir, 'content-phase4-admin-revenue-create.png'), fullPage: true });
      checks.revenueCreate = await inspect('ثبت درآمد برای محتوا');
    } else {
      checks.revenueCreate = { skipped: true, reason: 'no published content link on first show page' };
    }
  } else {
    checks.show = { skipped: true, reason: 'no content rows' };
    checks.revenueCreate = { skipped: true, reason: 'no content rows' };
  }

  await page.goto(`${base}/admin/content/revenues?test_user_id=1`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: path.join(outDir, 'content-phase4-admin-revenues.png'), fullPage: true });
  checks.revenues = await inspect('مدیریت درآمدها و پرداخت‌ها');

  await browser.close();
  const inspected = Object.values(checks).filter(c => !c.skipped);
  const ok = errors.length === 0 && failedRequests.length === 0 && inspected.length >= 3 && inspected.every(c => c.hasExpectedText && !c.hasPhpError && c.doctypeCount === 1 && c.iconsFontLoaded && c.hasAdminContentCss && c.hasAdminContentJs);
  console.log(JSON.stringify({ ok, errors, failedRequests, checks }, null, 2));
  if (!ok) process.exit(1);
})().catch(e => { console.error(e); process.exit(1); });
