const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

(async () => {
  const base = process.env.BASE_URL || 'http://localhost/chortke';
  const userId = process.env.USER_ID || '10';
  const outDir = '/home/user/projects/zip/extracted/chortke/tools/browser-preview/screenshots';
  fs.mkdirSync(outDir, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1366, height: 950 } });
  const errors = [];
  const failedRequests = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('requestfailed', req => {
    const u = req.url();
    if (/assets\/(css|js|vendor)\//.test(u)) failedRequests.push(u + ' :: ' + (req.failure()?.errorText || 'failed'));
  });
  const pages = [
    ['index', `${base}/content?test_user_id=${userId}`, 'content-phase1-index.png', 'محتواهای من'],
    ['create', `${base}/content/create?test_user_id=${userId}`, 'content-phase1-create.png', 'ارسال محتوای جدید'],
    ['revenues', `${base}/content/revenues?test_user_id=${userId}`, 'content-phase1-revenues.png', 'درآمدهای محتوا'],
  ];
  const checks = {};
  for (const [key, url, file, expected] of pages) {
    await page.goto(url, { waitUntil: 'networkidle' });
    await page.screenshot({ path: path.join(outDir, file), fullPage: true });
    checks[key] = await page.evaluate((expected) => ({
      hasExpectedText: document.body.innerText.includes(expected),
      hasPhpError: /Fatal error|Parse error|Undefined|GlobalException|خطای سیستمی/.test(document.body.innerText),
      doctypeCount: document.doctype ? 1 : 0,
      iconsFontLoaded: /Material Icons/i.test(getComputedStyle(document.querySelector('.material-icons') || document.body).fontFamily),
      sidebarLink: document.body.innerText.includes('درآمد از محتوا')
    }), expected);
  }
  await browser.close();
  const ok = errors.length === 0 && failedRequests.length === 0 && Object.values(checks).every(c => c.hasExpectedText && !c.hasPhpError && c.iconsFontLoaded && c.sidebarLink);
  console.log(JSON.stringify({ ok, errors, failedRequests, checks }, null, 2));
  if (!ok) process.exit(1);
})().catch(e => { console.error(e); process.exit(1); });
