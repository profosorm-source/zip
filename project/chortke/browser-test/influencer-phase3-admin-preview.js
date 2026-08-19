const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
function parseJson(output){const t=String(output).trim(); const i=t.lastIndexOf('\n{'); return JSON.parse(i>=0?t.slice(i+1):t);}
(async()=>{
 const project='/home/user/projects/zip/extracted/chortke';
 const seed=parseJson(execFileSync('php',['-r',`
  require 'bootstrap/app.php';
  $db=Core\\Container::getInstance()->make(Core\\Database::class);
  $n=time();
  $admin=(int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())", ["inf_admin_super_$n", "inf_admin_super_$n@example.test", "Influencer Admin Super", "active", "super_admin", "verified"]);
  $buyer=(int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())", ["inf_admin_buyer_$n", "inf_admin_buyer_$n@example.test", "Influencer Admin Buyer", "active", "user", "verified"]);
  $creator=(int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())", ["inf_admin_creator_$n", "inf_admin_creator_$n@example.test", "Influencer Admin Creator", "active", "user", "verified"]);
  $profile=(int)$db->insert("INSERT INTO influencer_profiles (user_id,username,platform,follower_count,followers_count,status,is_active,priority,story_price_24h,currency,verification_code,verification_post_url,created_at,updated_at) VALUES (?,?,?,?,?,'pending_admin_review',1,999999,75000,'irt','CK-ADMIN', 'https://www.instagram.com/p/adminproof/',NOW(),NOW())", [$creator, "inf_admin_page_$n", "instagram", 44000, 44000]);
  $ver=(int)$db->insert("INSERT INTO influencer_verifications (influencer_id,profile_id,code,verification_type,proof_data,status,post_url,proof_url,submitted_at,created_at) VALUES (?,?,?,'post',?, 'submitted', ?, ?, NOW(), NOW())", [$profile,$profile,'CK-ADMIN', json_encode(['method'=>'screenshot_verification','auto_verification'=>['score'=>65]], JSON_UNESCAPED_UNICODE), 'https://www.instagram.com/p/adminproof/', 'uploads/influencer-verification/admin.png']);
  $order=(int)$db->insert("INSERT INTO story_orders (customer_id,influencer_id,influencer_user_id,status,price,currency,verification_code,idempotency_key,order_type,duration_hours,caption,site_fee_percent,site_fee_amount,influencer_earning,proof_link,proof_notes,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())", [$buyer,$profile,$creator,'awaiting_buyer_check',75000,'irt','CK-ORDER','inf_admin_order_'.$n,'story',24,'INF-ADMIN: سفارش تستی ادمین',15,11250,63750,'https://instagram.example/proof','مدرک تستی']);
  $dis=(int)$db->insert("INSERT INTO disputes (ref_type,ref_id,user_id,target_user_id,status,reason,created_at,updated_at) VALUES ('influencer_order',?,?,?,'escalated',?,NOW(),NOW())", [$order,$buyer,$creator,'مدرک سفارش از نظر تبلیغ‌دهنده کافی نیست']);
  echo json_encode(['ok'=>true,'admin_id'=>$admin,'profile_id'=>$profile,'verification_id'=>$ver,'order_id'=>$order,'dispute_id'=>$dis], JSON_UNESCAPED_UNICODE);
 `],{cwd:project,encoding:'utf8'}));
 if(!seed.ok) throw new Error('seed failed');
 const base=process.env.BASE_URL||'http://127.0.0.1:8099/chortke';
 const outDir='/home/user/projects/zip/extracted/chortke/tools/browser-preview/screenshots'; fs.mkdirSync(outDir,{recursive:true});
 const browser=await chromium.launch({headless:true}); const page=await browser.newPage({viewport:{width:1440,height:980}});
 const errors=[]; const failedRequests=[];
 page.on('pageerror',e=>errors.push(e.message));
 page.on('requestfailed',req=>{const u=req.url(); const err=req.failure()?.errorText||'failed'; if(/assets\/(css|js|vendor)\//.test(u)&&err!=='net::ERR_ABORTED') failedRequests.push(u+' :: '+err);});
 async function check(url,file,expected){await page.goto(url,{waitUntil:'networkidle'}); await page.screenshot({path:path.join(outDir,file),fullPage:true}); return await page.evaluate(expected=>{const body=document.body.innerText; const icon=document.querySelector('.material-icons'); return {hasExpectedText:body.includes(expected),hasPhpError:/Fatal error|Parse error|Warning:|Undefined|SQLSTATE|GlobalException|خطای سیستمی/.test(body),doctypeCount:document.doctype?1:0,iconsFontLoaded:icon?/Material Icons/i.test(getComputedStyle(icon).fontFamily):true,hasCss:!!document.querySelector('link[href*="admininfluencer.css"]'),hasJs:!!document.querySelector('script[src*="admin/influencer.js"]')};}, expected);}
 const checks={};
 checks.orders=await check(`${base}/admin/influencer/orders?test_user_id=${seed.admin_id}`,'influencer-phase3-admin-orders.png','سفارش‌های اینفلوئنسر');
 checks.profiles=await check(`${base}/admin/influencer/profiles?test_user_id=${seed.admin_id}`,'influencer-phase3-admin-profiles.png','پروفایل‌های اینفلوئنسر');
 checks.verifications=await check(`${base}/admin/influencer/verifications?test_user_id=${seed.admin_id}`,'influencer-phase3-admin-verifications.png','درخواست‌های تأیید پیج');
 checks.disputes=await check(`${base}/admin/influencer/disputes?test_user_id=${seed.admin_id}`,'influencer-phase3-admin-disputes.png','اختلاف‌های اینفلوئنسر');
 checks.disputeDetail=await check(`${base}/admin/influencer/disputes/${seed.dispute_id}?test_user_id=${seed.admin_id}`,'influencer-phase3-admin-dispute-detail.png','پرونده اختلاف');
 await browser.close();
 const ok=errors.length===0&&failedRequests.length===0&&Object.values(checks).every(c=>c.hasExpectedText&&!c.hasPhpError&&c.doctypeCount===1&&c.iconsFontLoaded&&c.hasCss&&c.hasJs);
 console.log(JSON.stringify({ok,seed,errors,failedRequests,checks},null,2)); if(!ok) process.exit(1);
})().catch(e=>{console.error(e);process.exit(1);});
