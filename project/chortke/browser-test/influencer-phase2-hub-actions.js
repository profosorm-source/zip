const { chromium } = require('playwright');
const { execFileSync } = require('child_process');

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
    $svc=Core\\Container::getInstance()->make(App\\Services\\InfluencerService::class);
    $n=time();
    $buyer=(int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())", ["inf_hub_action_buyer_$n", "inf_hub_action_buyer_$n@example.test", "Influencer Hub Action Buyer", "active", "user", "verified"]);
    $creator=(int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())", ["inf_hub_action_creator_$n", "inf_hub_action_creator_$n@example.test", "Influencer Hub Action Creator", "active", "user", "verified"]);
    $db->insert("INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?,200000,0,0,0,NOW(),NOW())", [$buyer]);
    $db->insert("INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?,0,0,0,0,NOW(),NOW())", [$creator]);
    $profile=(int)$db->insert("INSERT INTO influencer_profiles (user_id,username,platform,follower_count,followers_count,status,is_active,story_price_24h,currency,created_at,updated_at) VALUES (?,?,?,?,?,'verified',1,100000,'irt',NOW(),NOW())", [$creator, "inf_hub_action_page_$n", "instagram", 20000, 20000]);
    $create=$svc->createOrder($buyer,$profile,['order_type'=>'story','duration_hours'=>24,'caption'=>'INF-HUB-ACTION: order','link'=>'https://example.test/action']);
    echo json_encode(['ok'=>!empty($create['success']),'buyer_id'=>$buyer,'creator_id'=>$creator,'profile_id'=>$profile,'order_id'=>(int)($create['order']->id ?? 0)], JSON_UNESCAPED_UNICODE);
  `], { cwd: project, encoding: 'utf8' }));
  if (!seed.ok || !seed.order_id) throw new Error('seed failed ' + JSON.stringify(seed));

  const base = process.env.BASE_URL || 'http://127.0.0.1:8099/chortke';
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1366, height: 950 } });
  const errors = [];
  const actionResponses = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('response', async res => {
    if (/\/influencer\/orders\/\d+\/(respond|proof)|\/influencer\/ads\/orders\/\d+\/confirm/.test(res.url())) {
      let body = ''; try { body = await res.text(); } catch (_) {}
      try { actionResponses.push({ url: res.url(), http: res.status(), ...JSON.parse(body) }); } catch (_) { actionResponses.push({ url: res.url(), http: res.status(), raw: body.slice(0, 300) }); }
    }
  });

  await page.goto(`${base}/influencer?test_user_id=${seed.creator_id}&section=incoming`, { waitUntil: 'networkidle' });
  await page.click(`[data-action="respond-order"][data-order-id="${seed.order_id}"]`);
  await page.waitForResponse(res => res.url().includes(`/influencer/orders/${seed.order_id}/respond`), { timeout: 30000 });
  await page.waitForTimeout(900);
  await page.goto(`${base}/influencer?test_user_id=${seed.creator_id}&section=incoming`, { waitUntil: 'networkidle' });
  await page.click(`[data-action="open-proof-modal"][data-order-id="${seed.order_id}"]`);
  await page.fill('#proofForm input[name="proof_link"]', 'https://www.instagram.com/stories/proof');
  await page.fill('#proofForm textarea[name="proof_notes"]', 'مدرک تستی از هاب اینفلوئنسر');
  await page.click('#proofSubmitBtn');
  await page.waitForResponse(res => res.url().includes(`/influencer/orders/${seed.order_id}/proof`), { timeout: 30000 });
  await page.waitForTimeout(900);

  await page.goto(`${base}/influencer?test_user_id=${seed.buyer_id}&section=placed`, { waitUntil: 'networkidle' });
  page.once('dialog', d => d.accept());
  await page.click(`[data-action="confirm-order"][data-order-id="${seed.order_id}"]`);
  await page.waitForResponse(res => res.url().includes(`/influencer/ads/orders/${seed.order_id}/confirm`), { timeout: 30000 });
  await page.waitForTimeout(800);
  await browser.close();

  const final = parseJson(execFileSync('php', ['-r', `
    require 'bootstrap/app.php';
    $db=Core\\Container::getInstance()->make(Core\\Database::class);
    $order=$db->fetch('SELECT id,status,payout_transaction_id FROM story_orders WHERE id=?', [${seed.order_id}]);
    $buyer=$db->fetch('SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?', [${seed.buyer_id}]);
    $seller=$db->fetch('SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?', [${seed.creator_id}]);
    $escrow=$db->fetch("SELECT status FROM escrow_transactions WHERE order_id=? AND order_type='influencer_order'", [${seed.order_id}]);
    echo json_encode(['order'=>$order,'buyer'=>$buyer,'seller'=>$seller,'escrow'=>$escrow], JSON_UNESCAPED_UNICODE);
  `], { cwd: project, encoding: 'utf8' }));

  const ok = errors.length === 0
    && actionResponses.filter(r => r.http >= 200 && r.http < 300 && r.success === true).length >= 3
    && final.order.status === 'completed'
    && final.escrow.status === 'released'
    && Number(final.buyer.locked_irt) === 0
    && Number(final.seller.balance_irt) === 85000;
  console.log(JSON.stringify({ ok, seed, errors, actionResponses, final }, null, 2));
  if (!ok) process.exit(1);
})().catch(e => { console.error(e); process.exit(1); });
