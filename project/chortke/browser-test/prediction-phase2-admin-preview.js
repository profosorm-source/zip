const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
function parseJson(output){const t=String(output).trim(); const i=t.lastIndexOf('\n{'); return JSON.parse(i>=0?t.slice(i+1):t);}
(async()=>{
 const project='/home/user/projects/zip/extracted/chortke';
 const seed=parseJson(execFileSync('php',['-r',`
  require 'bootstrap/app.php';
  $db=Core\\Container::getInstance()->make(Core\\Database::class); $n=time();
  $db->execute("INSERT IGNORE INTO users (id,username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (1,'admin_preview','admin_preview@example.test','مدیر تست','active','admin','verified',NOW(),NOW())");
  $game=(int)$db->insert("INSERT INTO prediction_games (title,team_home,team_away,sport_type,description,match_date,bet_deadline,min_bet_usdt,max_bet_usdt,commission_percent,bonus_pool_usdt,status,created_by,created_at,updated_at) VALUES (?,?,?,?,?,DATE_ADD(NOW(), INTERVAL 2 DAY),DATE_ADD(NOW(), INTERVAL 1 DAY),1,1000,10,0,'open',1,NOW(),NOW())", ["PRED-ADMIN: بازی تست","مدیرخانه","مدیرمهمان","football","تست پنل ادمین"]);
  echo json_encode(['ok'=>true,'game_id'=>$game], JSON_UNESCAPED_UNICODE);
 `],{cwd:project,encoding:'utf8'}));
 const base=process.env.BASE_URL||'http://127.0.0.1:8099/chortke';
 const outDir=path.join(project,'tools/browser-preview/screenshots'); fs.mkdirSync(outDir,{recursive:true});
 const browser=await chromium.launch({headless:true});
 const page=await browser.newPage({viewport:{width:1440,height:1050}});
 const errors=[]; const failedRequests=[]; const closeResponses=[];
 page.on('pageerror',e=>errors.push(e.message));
 page.on('requestfailed',req=>{const u=req.url(); const err=req.failure()?.errorText||'failed'; if(/assets\/(css|js|vendor)\//.test(u)&&err!=='net::ERR_ABORTED') failedRequests.push(u+' :: '+err);});
 page.on('response', async res=>{ if(res.url().includes(`/admin/prediction/${seed.game_id}/close-betting`)){let b='';try{b=await res.text()}catch{}; try{closeResponses.push({http:res.status(),...JSON.parse(b)})}catch{closeResponses.push({http:res.status(),raw:b.slice(0,300)})}} });
 await page.goto(`${base}/admin/prediction?test_user_id=1`,{waitUntil:'networkidle'});
 await page.screenshot({path:path.join(outDir,'prediction-phase2-admin-index.png'),fullPage:true});
 const indexCheck = await page.evaluate(()=>{
   const text=document.body.innerText;
   return {
    hasIndex:text.includes('بازی‌ها، استخرها و تسویه‌ها')&&text.includes('لیست بازی‌ها')&&text.includes('تعریف بازی جدید'),
    hasPhpError:/Fatal error|Parse error|Warning:|Undefined|SQLSTATE|GlobalException|خطای سیستمی/.test(text)
   };
 });
 await page.goto(`${base}/admin/prediction/${seed.game_id}?test_user_id=1`,{waitUntil:'networkidle'});
 await page.screenshot({path:path.join(outDir,'prediction-phase2-admin-show.png'),fullPage:true});
 const closeWait = page.waitForResponse(res=>res.url().includes(`/admin/prediction/${seed.game_id}/close-betting`),{timeout:30000});
 page.once('dialog', d => d.accept());
 await page.click('[data-admin-action="close"]');
 await closeWait;
 await page.waitForTimeout(700);
 const check=await page.evaluate(()=>{
   const text=document.body.innerText;
   const icon=document.querySelector('.material-icons');
   return {
    hasAdmin:text.includes('جزئیات بازی')&&text.includes('توزیع پیش‌بینی‌ها')&&text.includes('کمیسیون')&&text.includes('فقط از پول بازنده‌ها'),
    hasPhpError:/Fatal error|Parse error|Warning:|Undefined|SQLSTATE|GlobalException|خطای سیستمی/.test(text),
    iconsFontLoaded:icon?/Material Icons/i.test(getComputedStyle(icon).fontFamily):true
   };
 });
 await browser.close();
 const ok=errors.length===0&&failedRequests.length===0&&indexCheck.hasIndex&&!indexCheck.hasPhpError&&closeResponses.some(r=>r.http>=200&&r.http<300&&r.success===true)&&check.hasAdmin&&!check.hasPhpError&&check.iconsFontLoaded;
 console.log(JSON.stringify({ok,seed,errors,failedRequests,closeResponses,indexCheck,check},null,2));
 if(!ok) process.exit(1);
})().catch(e=>{console.error(e);process.exit(1);});
