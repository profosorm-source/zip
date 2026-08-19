const { chromium } = require('playwright');
const { execFileSync } = require('child_process');

function parseJsonFromOutput(output) {
  const text = String(output).trim();
  const start = text.lastIndexOf('\n{');
  const jsonText = start >= 0 ? text.slice(start + 1) : text;
  return JSON.parse(jsonText);
}

(async () => {
  const project = '/home/user/projects/zip/extracted/chortke';
  const seed = parseJsonFromOutput(execFileSync('php', ['tools/content-phase4-admin-http-seed.php'], { cwd: project, encoding: 'utf8' }));
  if (!seed.ok) throw new Error('seed failed: ' + JSON.stringify(seed));
  const ids = seed.ids;
  const base = process.env.BASE_URL || 'http://127.0.0.1:8099/chortke';

  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1366, height: 900 } });
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));

  await page.goto(`${base}/admin/content?test_user_id=1`, { waitUntil: 'networkidle' });
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  if (!csrf) throw new Error('csrf token not found');

  async function post(path, body = {}) {
    return await page.evaluate(async ({ url, csrf, body }) => {
      const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': csrf
        },
        body: JSON.stringify(body)
      });
      const text = await res.text();
      let json;
      try { json = JSON.parse(text); } catch (_) { json = { raw: text.slice(0, 500) }; }
      return { http: res.status, ...json };
    }, { url: `${base}${path}`, csrf, body });
  }

  const period = '1404-' + String((Date.now() % 12) + 1).padStart(2, '0');
  const results = {
    approve: await post(`/admin/content/${ids.approve_submission}/approve`),
    reject: await post(`/admin/content/${ids.reject_submission}/reject`, { reason: 'لینک یا توضیحات محتوا برای انتشار قابل قبول نیست.' }),
    publish: await post(`/admin/content/${ids.publish_submission}/publish`, { published_url: 'https://www.youtube.com/watch?v=CP4HTTPpublishedByRoute', channel_name: 'کانال تست محتوا' }),
    suspend: await post(`/admin/content/${ids.suspend_submission}/suspend`, { reason: 'تعلیق تستی برای بررسی مسیر مدیریت محتوا.' }),
    createRevenue: await post(`/admin/content/${ids.create_revenue_submission}/revenue/store`, { submission_id: ids.create_revenue_submission, period, views: 12345, total_revenue: 100000, idempotency_key: 'CP4HTTP_CREATE_' + ids.create_revenue_submission + '_' + period }),
    approveRevenue: await post(`/admin/content/revenue/${ids.approve_revenue}/approve`),
    payRevenue: await post(`/admin/content/revenue/${ids.pay_revenue}/pay`),
    payRevenueAgain: null,
  };
  results.payRevenueAgain = await post(`/admin/content/revenue/${ids.pay_revenue}/pay`);

  await browser.close();

  const ok = errors.length === 0
    && Object.entries(results).every(([key, r]) => r && r.http >= 200 && r.http < 300 && r.success === true)
    && results.payRevenueAgain.data && results.payRevenueAgain.data.already_paid === true
    && results.createRevenue.data && Number(results.createRevenue.data.revenue_id) > 0;

  console.log(JSON.stringify({ ok, errors, ids, results }, null, 2));
  if (!ok) process.exit(1);
})().catch(e => { console.error(e); process.exit(1); });
