const { chromium } = require('playwright');

(async () => {
  const base = process.env.BASE_URL || 'http://localhost/chortke';
  const userId = process.env.USER_ID || '10';
  const unique = Date.now();
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1366, height: 950 } });
  const errors = [];
  const responses = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('response', async res => {
    if (res.url().includes('/content/store')) {
      let body = '';
      try { body = await res.text(); } catch (_) {}
      responses.push({ status: res.status(), body: body.slice(0, 800) });
    }
  });
  await page.goto(`${base}/content/create?test_user_id=${userId}`, { waitUntil: 'networkidle' });
  await page.selectOption('#platform', 'youtube');
  await page.fill('#video_url', `https://www.youtube.com/watch?v=abc${unique}`);
  await page.fill('#title', `ویدیوی تست محتوا ${unique}`);
  await page.fill('#description', 'توضیح تستی برای بررسی ثبت محتوا');
  await page.check('#agreement_accepted');
  await page.click('#submitBtn');
  await page.waitForResponse(res => res.url().includes('/content/store'), { timeout: 30000 });
  await page.waitForTimeout(500);
  await browser.close();
  const parsed = responses.map(r => { try { return Object.assign({http:r.status}, JSON.parse(r.body)); } catch(e) { return {http:r.status, raw:r.body}; } });
  const ok = errors.length === 0 && parsed.some(r => r.http >= 200 && r.http < 300 && r.success === true);
  console.log(JSON.stringify({ ok, errors, responses: parsed }, null, 2));
  if (!ok) process.exit(1);
})().catch(e => { console.error(e); process.exit(1); });
