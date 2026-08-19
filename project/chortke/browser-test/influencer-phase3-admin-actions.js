const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
function parseJson(output){const t=String(output).trim(); const i=t.lastIndexOf('\n{'); return JSON.parse(i>=0?t.slice(i+1):t);}
(async()=>{
 const project='/home/user/projects/zip/extracted/chortke';
 const seed=parseJson(execFileSync('php',['-r',`
  require 'bootstrap/app.php';
  $db=Core\\Container::getInstance()->make(Core\\Database::class);
  $svc=Core\\Container::getInstance()->make(App\\Services\\InfluencerService::class);
  $n=time();
  $admin=(int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())", ["inf_admin_action_super_$n", "inf_admin_action_super_$n@example.test", "Influencer Admin Action Super", "active", "super_admin", "verified"]);
  function userx($db,$s){return (int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())", ["inf_admin_action_{$s}", "inf_admin_action_{$s}@example.test", "Inf Admin Action {$s}", "active", "user", "verified"]);} 
  function profilex($db,$u,$name,$status){return (int)$db->insert("INSERT INTO influencer_profiles (user_id,username,platform,follower_count,followers_count,status,is_active,story_price_24h,currency,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?, 'irt',NOW(),NOW())", [$u,$name,'instagram',10000,10000,$status,1,70000]);}
  $uApprove=userx($db,'profile_approve_'.$n); $pApprove=profilex($db,$uApprove,'inf_action_approve_'.$n,'pending');
  $uReject=userx($db,'profile_reject_'.$n); $pReject=profilex($db,$uReject,'inf_action_reject_'.$n,'pending');
  $uSuspend=userx($db,'profile_suspend_'.$n); $pSuspend=profilex($db,$uSuspend,'inf_action_suspend_'.$n,'verified');
  $uVerA=userx($db,'ver_approve_'.$n); $pVerA=profilex($db,$uVerA,'inf_action_verapprove_'.$n,'pending_admin_review'); $vApprove=(int)$db->insert("INSERT INTO influencer_verifications (influencer_id,profile_id,code,verification_type,proof_data,status,post_url,proof_url,submitted_at,created_at) VALUES (?,?,?,'post',?, 'submitted', ?, ?, NOW(), NOW())", [$pVerA,$pVerA,'CK-A', '{}','https://www.instagram.com/p/a/','uploads/a.png']);
  $uVerR=userx($db,'ver_reject_'.$n); $pVerR=profilex($db,$uVerR,'inf_action_verreject_'.$n,'pending_admin_review'); $vReject=(int)$db->insert("INSERT INTO influencer_verifications (influencer_id,profile_id,code,verification_type,proof_data,status,post_url,proof_url,submitted_at,created_at) VALUES (?,?,?,'post',?, 'submitted', ?, ?, NOW(), NOW())", [$pVerR,$pVerR,'CK-R', '{}','https://www.instagram.com/p/r/','uploads/r.png']);
  $buyer=userx($db,'buyer_'.$n); $creator=userx($db,'creator_'.$n); $db->insert("INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?,100000,0,0,0,NOW(),NOW())", [$buyer]); $db->insert("INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?,0,0,0,0,NOW(),NOW())", [$creator]); $pOrder=profilex($db,$creator,'inf_action_order_'.$n,'verified');
  $create=$svc->createOrder($buyer,$pOrder,['order_type'=>'story','duration_hours'=>24,'caption'=>'INF-ACTION-DISPUTE']); $orderId=(int)($create['order']->id??0); $svc->respondToOrder($orderId,$creator,'accept'); $svc->submitProof($orderId,$creator,['proof_link'=>'https://instagram.example/proof']); $svc->buyerDispute($orderId,$buyer,'مدرک ناکافی است'); $dis=$db->fetch("SELECT id FROM disputes WHERE ref_type='influencer_order' AND ref_id=? ORDER BY id DESC LIMIT 1",[$orderId]);
  echo json_encode(['ok'=>true,'admin_id'=>$admin,'profile_approve'=>$pApprove,'profile_reject'=>$pReject,'profile_suspend'=>$pSuspend,'verification_approve'=>$vApprove,'verification_reject'=>$vReject,'dispute_id'=>(int)$dis->id,'order_id'=>$orderId,'buyer_id'=>$buyer,'creator_id'=>$creator], JSON_UNESCAPED_UNICODE);
 `],{cwd:project,encoding:'utf8'}));
 if(!seed.ok) throw new Error('seed failed');
 const base=process.env.BASE_URL||'http://127.0.0.1:8099/chortke';
 const browser=await chromium.launch({headless:true}); const page=await browser.newPage({viewport:{width:1366,height:900}}); const errors=[]; page.on('pageerror',e=>errors.push(e.message));
 await page.goto(`${base}/admin/influencer/profiles?test_user_id=${seed.admin_id}`,{waitUntil:'networkidle'}); const csrf=await page.locator('meta[name="csrf-token"]').getAttribute('content');
 async function post(path,body){return await page.evaluate(async({url,body,csrf})=>{const r=await fetch(url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},body:JSON.stringify(body||{})});const t=await r.text();let j;try{j=JSON.parse(t)}catch(e){j={raw:t.slice(0,300)}};return {http:r.status,...j};},{url:base+path,body,csrf});}
 const results={
  approveProfile: await post('/admin/influencer/profiles/approve',{profile_id:seed.profile_approve,decision:'approve'}),
  rejectProfile: await post('/admin/influencer/profiles/approve',{profile_id:seed.profile_reject,decision:'reject',reason:'کیفیت پیج کافی نیست'}),
  suspendProfile: await post('/admin/influencer/profiles/approve',{profile_id:seed.profile_suspend,decision:'suspend',reason:'تخلف تستی در سفارش‌ها'}),
  approveVerification: await post('/admin/influencer/verifications/approve',{verification_id:seed.verification_approve}),
  rejectVerification: await post('/admin/influencer/verifications/reject',{verification_id:seed.verification_reject,reason:'کد در تصویر واضح نیست'}),
  resolveDispute: await post(`/admin/influencer/disputes/${seed.dispute_id}/resolve`,{dispute_id:seed.dispute_id,verdict:'favor_customer',note:'مدرک اینفلوئنسر برای سفارش کافی نبود.',refund_percent:100})
 };
 await browser.close();
 const final=parseJson(execFileSync('php',['-r',`
  require 'bootstrap/app.php'; $db=Core\\Container::getInstance()->make(Core\\Database::class);
  $profiles=$db->fetchAll("SELECT id,status,is_active FROM influencer_profiles WHERE id IN (${seed.profile_approve},${seed.profile_reject},${seed.profile_suspend}) ORDER BY id");
  $verA=$db->fetch("SELECT status FROM influencer_verifications WHERE id=?",[${seed.verification_approve}]);
  $verR=$db->fetch("SELECT status FROM influencer_verifications WHERE id=?",[${seed.verification_reject}]);
  $order=$db->fetch("SELECT status FROM story_orders WHERE id=?",[${seed.order_id}]);
  $wallet=$db->fetch("SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?",[${seed.buyer_id}]);
  echo json_encode(['profiles'=>$profiles,'verA'=>$verA,'verR'=>$verR,'order'=>$order,'wallet'=>$wallet], JSON_UNESCAPED_UNICODE);
 `],{cwd:project,encoding:'utf8'}));
 const ok=errors.length===0 && Object.values(results).every(r=>r.http>=200&&r.http<300&&r.success===true) && final.verA.status==='approved' && final.verR.status==='rejected' && final.order.status==='refunded' && Number(final.wallet.locked_irt)===0;
 console.log(JSON.stringify({ok,seed,errors,results,final},null,2)); if(!ok) process.exit(1);
})().catch(e=>{console.error(e);process.exit(1);});
