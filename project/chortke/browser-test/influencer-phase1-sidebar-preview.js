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
    $inf=(int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())", ["inf_sidebar_$n", "inf_sidebar_$n@example.test", "Influencer Sidebar User", "active", "user", "verified"]);
    $buyer=(int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())", ["inf_sidebar_buyer_$n", "inf_sidebar_buyer_$n@example.test", "Influencer Sidebar Buyer", "active", "user", "verified"]);
    $profile=(int)$db->insert("INSERT INTO influencer_profiles (user_id,username,platform,follower_count,followers_count,status,is_active,story_price_24h,currency,created_at,updated_at) VALUES (?,?,?,?,?,'verified',1,100000,'irt',NOW(),NOW())", [$inf, "inf_sidebar_page_$n", "instagram", 5000, 5000]);
    $order=(int)$db->insert("INSERT INTO story_orders (customer_id,influencer_id,influencer_user_id,status,price,currency,verification_code,idempotency_key,order_type,duration_hours,caption,site_fee_percent,site_fee_amount,influencer_earning,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())", [$buyer,$profile,$inf,"pending_acceptance",100000,"irt","CK-SIDEBAR","inf_sidebar_order_$n","story",24,"Sidebar badge fixture",15,15000,85000]);
    echo json_encode(['ok'=>true,'influencer_user_id'=>$inf,'order_id'=>$order], JSON_UNESCAPED_UNICODE);
  `], { cwd: project, encoding: 'utf8' }));
  if (!seed.ok) throw new Error('seed failed');

  const base = process.env.BASE_URL || 'http://127.0.0.1:8099/chortke';
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

  await page.goto(`${base}/influencer?test_user_id=${seed.influencer_user_id}`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: path.join(outDir, 'influencer-phase1-sidebar.png'), fullPage: true });
  const check = await page.evaluate(() => {
    const body = document.body.innerText;
    const links = Array.from(document.querySelectorAll('.sidebar-menu a')).map(a => a.innerText.trim());
    const influencerLink = Array.from(document.querySelectorAll('.sidebar-menu a')).find(a => a.innerText.includes('اینفلوئنسر'));
    const icon = document.querySelector('.material-icons');
    return {
      hasInfluencerPersian: body.includes('اینفلوئنسر'),
      hasEnglishInfluencerMarketing: body.includes('Influencer Marketing'),
      influencerLinkText: influencerLink ? influencerLink.innerText.trim() : '',
      badgeFound: influencerLink ? /\d+/.test(influencerLink.innerText) : false,
      hasPhpError: /Fatal error|Parse error|Warning:|Undefined|SQLSTATE|GlobalException|خطای سیستمی/.test(body),
      doctypeCount: document.doctype ? 1 : 0,
      iconsFontLoaded: icon ? /Material Icons/i.test(getComputedStyle(icon).fontFamily) : true,
      sidebarLinks: links,
    };
  });
  await browser.close();
  const ok = errors.length === 0 && failedRequests.length === 0 && check.hasInfluencerPersian && !check.hasEnglishInfluencerMarketing && check.badgeFound && !check.hasPhpError && check.iconsFontLoaded;
  console.log(JSON.stringify({ ok, seed, errors, failedRequests, check }, null, 2));
  if (!ok) process.exit(1);
})().catch(e => { console.error(e); process.exit(1); });
