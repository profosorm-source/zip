const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
function parseJson(output){const t=String(output).trim(); const i=t.lastIndexOf('\n{'); return JSON.parse(i>=0?t.slice(i+1):t);}
(async()=>{
 const project='/home/user/projects/zip/extracted/chortke';
 const seed=parseJson(execFileSync('php',['-r',`
  require 'bootstrap/app.php'; $db=Core\\Container::getInstance()->make(Core\\Database::class); $n=time();
  $user=(int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())", ["pred_preview_$n","pred_preview_$n@example.test","Prediction Preview User","active","user","verified"]);
  $db->insert("INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?,0,500,0,0,NOW(),NOW())", [$user]);
  $game=(int)$db->insert("INSERT INTO prediction_games (title,team_home,team_away,sport_type,match_date,bet_deadline,min_bet_usdt,max_bet_usdt,commission_percent,status,created_by,created_at,updated_at) VALUES (?,?,?,?,DATE_ADD(NOW(), INTERVAL 2 DAY),DATE_ADD(NOW(), INTERVAL 1 DAY),1,1000,10,'open',1,NOW(),NOW())", ["PRED-PREVIEW: بازی تستی","تیم خانه","تیم مهمان","football"]);
  echo json_encode(['ok'=>true,'user_id'=>$user,'game_id'=>$game], JSON_UNESCAPED_UNICODE);
 `],{cwd:project,encoding:'utf8'}));
 const base=process.env.BASE_URL||'http://127.0.0.1:8099/chortke';
 const outDir='/home/user/projects/zip/extracted/chortke/tools/browser-preview/screenshots'; fs.mkdirSync(outDir,{recursive:true});
 const browser=await chromium.launch({headless:true}); const page=await browser.newPage({viewport:{width:1366,height:950}}); const errors=[]; const failedRequests=[]; const betResponses=[];
 page.on('pageerror',e=>errors.push(e.message));
 page.on('requestfailed',req=>{const u=req.url(); const err=req.failure()?.errorText||'failed'; if(/assets\/(css|js|vendor)\//.test(u)&&err!=='net::ERR_ABORTED') failedRequests.push(u+' :: '+err);});
 page.on('response', async res=>{ if(res.url().includes(`/prediction/${seed.game_id}/bet`)){let b='';try{b=await res.text()}catch{}; try{betResponses.push({http:res.status(),...JSON.parse(b)})}catch{betResponses.push({http:res.status(),raw:b.slice(0,300)})}} });
 await page.goto(`${base}/prediction/${seed.game_id}?test_user_id=${seed.user_id}`,{waitUntil:'networkidle'});
 await page.click('.prediction-card input[value="home"] + div, .prediction-card:has(input[value="home"])');
 await page.fill('#betAmount','25');
 await page.screenshot({path:path.join(outDir,'prediction-phase1-show-rules.png'),fullPage:true});
 await page.click('#submitBet');
 await page.waitForResponse(res=>res.url().includes(`/prediction/${seed.game_id}/bet`),{timeout:30000});
 await page.waitForTimeout(500);
 const check=await page.evaluate(()=>{const body=document.body.innerText; const icon=document.querySelector('.material-icons'); return {hasRules:body.includes('کمیسیون سایت فقط از پول بازنده‌ها')&&body.includes('چرخه بازی‌های بعدی'),hasBetResponseText:body.includes('شرط‌بندی شما')||body.includes('پیش‌بینی شما'),hasPhpError:/Fatal error|Parse error|Warning:|Undefined|SQLSTATE|GlobalException|خطای سیستمی/.test(body),doctypeCount:document.doctype?1:0,iconsFontLoaded:icon?/Material Icons/i.test(getComputedStyle(icon).fontFamily):true};});
 await browser.close();
 const ok=errors.length===0&&failedRequests.length===0&&betResponses.some(r=>r.http>=200&&r.http<300&&r.success===true)&&check.hasRules&&!check.hasPhpError&&check.iconsFontLoaded;
 console.log(JSON.stringify({ok,seed,errors,failedRequests,betResponses,check},null,2)); if(!ok) process.exit(1);
})().catch(e=>{console.error(e);process.exit(1);});
