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
  $user=(int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())", ["pred_hub_$n","pred_hub_$n@example.test","کاربر تست هاب پیش‌بینی","active","user","verified"]);
  $db->insert("INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?,0,750,0,0,NOW(),NOW())", [$user]);
  $open=(int)$db->insert("INSERT INTO prediction_games (title,team_home,team_away,sport_type,description,match_date,bet_deadline,min_bet_usdt,max_bet_usdt,commission_percent,bonus_pool_usdt,status,created_by,created_at,updated_at) VALUES (?,?,?,?,?,DATE_ADD(NOW(), INTERVAL 2 DAY),DATE_ADD(NOW(), INTERVAL 1 DAY),1,1000,10,15,'open',1,NOW(),NOW())", ["PRED-HUB: بازی باز","سپهر","آذرخش","football","شرح تستی قوانین شفاف"]);
  $finished=(int)$db->insert("INSERT INTO prediction_games (title,team_home,team_away,sport_type,match_date,bet_deadline,min_bet_usdt,max_bet_usdt,commission_percent,status,result,winners_paid,site_fee_usdt,rollover_amount_usdt,settlement_summary,finished_at,created_by,created_at,updated_at) VALUES (?,?,?,?,DATE_SUB(NOW(), INTERVAL 1 DAY),DATE_SUB(NOW(), INTERVAL 2 DAY),1,1000,10,'finished','draw',1,100,100,?,NOW(),1,NOW(),NOW())", ["PRED-HUB: بدون برنده","خانه","مهمان","football", json_encode(['no_winners'=>true], JSON_UNESCAPED_UNICODE)]);
  echo json_encode(['ok'=>true,'user_id'=>$user,'open_game'=>$open,'finished_game'=>$finished], JSON_UNESCAPED_UNICODE);
 `],{cwd:project,encoding:'utf8'}));
 const base=process.env.BASE_URL||'http://127.0.0.1:8099/chortke';
 const outDir=path.join(project,'tools/browser-preview/screenshots'); fs.mkdirSync(outDir,{recursive:true});
 const browser=await chromium.launch({headless:true});
 const page=await browser.newPage({viewport:{width:1440,height:1050}});
 const errors=[]; const failedRequests=[]; const responses=[];
 page.on('pageerror',e=>errors.push(e.message));
 page.on('requestfailed',req=>{const u=req.url(); const err=req.failure()?.errorText||'failed'; if(/assets\/(css|js|vendor)\//.test(u)&&err!=='net::ERR_ABORTED') failedRequests.push(u+' :: '+err);});
 page.on('response', async res=>{ if(res.url().includes(`/prediction/${seed.open_game}/bet`)){let b='';try{b=await res.text()}catch{}; try{responses.push({http:res.status(),...JSON.parse(b)})}catch{responses.push({http:res.status(),raw:b.slice(0,300)})}} });
 await page.goto(`${base}/prediction?test_user_id=${seed.user_id}`,{waitUntil:'networkidle'});
 await page.screenshot({path:path.join(outDir,'prediction-phase2-user-hub-open.png'),fullPage:true});
 await page.click('[data-pred-tab="rules"]');
 await page.waitForTimeout(150);
 const rulesText = await page.locator('[data-pred-panel="rules"]').innerText();
 await page.screenshot({path:path.join(outDir,'prediction-phase2-user-hub-rules.png'),fullPage:true});
 await page.click('[data-pred-tab="open"]');
 await page.click(`[data-game-card="${seed.open_game}"] .pred-choice input[value="home"]`, {force:true});
 await page.fill(`[data-game-card="${seed.open_game}"] .pred-amount`,'25');
 const previewText=await page.locator(`[data-game-card="${seed.open_game}"] .pred-preview`).innerText();
 await page.click(`[data-game-card="${seed.open_game}"] .pred-bet-form button[type="submit"]`);
 await page.waitForResponse(res=>res.url().includes(`/prediction/${seed.open_game}/bet`),{timeout:30000});
 await page.waitForTimeout(1200);
 const check=await page.evaluate(()=>{
   const text=document.body.innerText;
   const icon=document.querySelector('.material-icons');
   return {
    hasHub:text.includes('مرکز پیش‌بینی شفاف')&&text.includes('بازی‌های باز')&&text.includes('قوانین شفاف'),
    hasRules:text.includes('کمیسیون فقط از پول بازنده‌ها')&&text.includes('اگر هیچ برنده‌ای نباشد')&&text.includes('۵۰٪'),
    hasMyBets: text.includes('پیش‌بینی‌های من'),
    hasPhpError:/Fatal error|Parse error|Warning:|Undefined|SQLSTATE|GlobalException|خطای سیستمی/.test(text),
    iconsFontLoaded:icon?/Material Icons/i.test(getComputedStyle(icon).fontFamily):true,
    activeMyBets: !!document.querySelector('[data-pred-panel="my-bets"].active')
   };
 });
 await browser.close();
 const hasRulesBefore = rulesText.includes('کمیسیون فقط از بازنده‌ها')&&rulesText.includes('اگر هیچ برنده‌ای نباشد')&&rulesText.includes('۵۰٪');
 const ok=errors.length===0&&failedRequests.length===0&&responses.some(r=>r.http>=200&&r.http<300&&r.success===true)&&check.hasHub&&hasRulesBefore&&check.hasMyBets&&!check.hasPhpError&&check.iconsFontLoaded&&previewText.includes('دریافتی تقریبی');
 console.log(JSON.stringify({ok,seed,errors,failedRequests,responses,previewText,hasRulesBefore,check},null,2));
 if(!ok) process.exit(1);
})().catch(e=>{console.error(e);process.exit(1);});
