const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

function parseJson(output) {
  const text = String(output).trim();
  const idx = text.lastIndexOf('\n{');
  return JSON.parse(idx >= 0 ? text.slice(idx + 1) : text);
}

(async () => {
  const project = '/home/user/projects/zip/extracted/chortke';
  const seed = parseJson(execFileSync('php', ['-r', `
    require 'bootstrap/app.php';
    $db=Core\\Container::getInstance()->make(Core\\Database::class);
    $n=time();
    $buyer=(int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())", ["inf_hub_buyer_$n", "inf_hub_buyer_$n@example.test", "Influencer Hub Buyer", "active", "user", "verified"]);
    $creator=(int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())", ["inf_hub_creator_$n", "inf_hub_creator_$n@example.test", "Influencer Hub Creator", "active", "user", "verified"]);
    $db->insert("INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?,250000,0,0,0,NOW(),NOW())", [$buyer]);
    $db->insert("INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?,0,0,0,0,NOW(),NOW())", [$creator]);
    $profile=(int)$db->insert("INSERT INTO influencer_profiles (user_id,username,platform,follower_count,followers_count,status,is_active,priority,story_price_24h,post_price_24h,post_price_48h,post_price_72h,currency,created_at,updated_at) VALUES (?,?,?,?,?,'verified',1,999999,90000,120000,170000,220000,'irt',NOW(),NOW())", [$creator, "inf_hub_page_$n", "instagram", 33000, 33000]);
    echo json_encode(['ok'=>true,'buyer_id'=>$buyer,'creator_id'=>$creator,'profile_id'=>$profile], JSON_UNESCAPED_UNICODE);
  `], { cwd: project, encoding: 'utf8' }));
  if (!seed.ok) throw new Error('seed failed');

  const base = process.env.BASE_URL || 'http://127.0.0.1:8099/chortke';
  const outDir = '/home/user/projects/zip/extracted/chortke/tools/browser-preview/screenshots';
  fs.mkdirSync(outDir, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1366, height: 950 } });
  const errors = [];
  const failedRequests = [];
  const storeResponses = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('requestfailed', req => {
    const u = req.url();
    const err = req.failure()?.errorText || 'failed';
    if (/assets\/(css|js|vendor)\//.test(u) && err !== 'net::ERR_ABORTED') failedRequests.push(u + ' :: ' + err);
  });
  page.on('response', async res => {
    if (res.url().includes('/influencer/ads/store')) {
      let body = '';
      try { body = await res.text(); } catch (_) {}
      try { storeResponses.push({ http: res.status(), ...JSON.parse(body) }); } catch (_) { storeResponses.push({ http: res.status(), raw: body.slice(0, 400) }); }
    }
  });

  await page.goto(`${base}/influencer?test_user_id=${seed.buyer_id}`, { waitUntil: 'networkidle' });
  await page.click('[data-inf-tab="market"]');
  await page.click(`[data-select-influencer][data-id="${seed.profile_id}"]`);
  await page.fill('#hubOrderForm textarea[name="caption"]', 'سفارش تستی از هاب اینفلوئنسر');
  await page.fill('#hubOrderForm input[name="link"]', 'https://example.test/product');
  await page.screenshot({ path: path.join(outDir, 'influencer-phase2-hub-market.png'), fullPage: true });
  await page.click('#hubOrderForm button[type="submit"]');
  await page.waitForResponse(res => res.url().includes('/influencer/ads/store'), { timeout: 30000 });
  await page.waitForTimeout(800);
  await page.goto(`${base}/influencer?test_user_id=${seed.buyer_id}&section=placed`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: path.join(outDir, 'influencer-phase2-hub-placed.png'), fullPage: true });

  const check = await page.evaluate(() => {
    const body = document.body.innerText;
    const icon = document.querySelector('.material-icons');
    return {
      hasHub: body.includes('مرکز اینفلوئنسر') && body.includes('سفارش‌های من'),
      hasOrderText: body.includes('سفارش تستی از هاب اینفلوئنسر') || body.includes('inf_hub_page'),
      activePlaced: !!document.querySelector('[data-inf-panel="placed"].active'),
      hasPhpError: /Fatal error|Parse error|Warning:|Undefined|SQLSTATE|GlobalException|خطای سیستمی/.test(body),
      doctypeCount: document.doctype ? 1 : 0,
      iconsFontLoaded: icon ? /Material Icons/i.test(getComputedStyle(icon).fontFamily) : true,
    };
  });

  await browser.close();
  const ok = errors.length === 0 && failedRequests.length === 0 && storeResponses.some(r => r.http >= 200 && r.http < 300 && r.success === true) && check.hasHub && check.activePlaced && !check.hasPhpError && check.iconsFontLoaded;
  console.log(JSON.stringify({ ok, seed, errors, failedRequests, storeResponses, check }, null, 2));
  if (!ok) process.exit(1);
})().catch(e => { console.error(e); process.exit(1); });
