const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
function parseJson(output){const t=String(output).trim(); const i=t.lastIndexOf('\n{'); return JSON.parse(i>=0?t.slice(i+1):t);}
(async()=>{
 const project='/home/user/projects/zip/extracted/chortke';
 const seed=parseJson(execFileSync('php',['-r',`
  require 'bootstrap/app.php';
  $db=Core\\Container::getInstance()->make(Core\\Database::class);
  $n=time();
  $user=(int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())", ["inf_compat_user_$n", "inf_compat_user_$n@example.test", "Influencer Compat User", "active", "user", "verified"]);
  $creator=(int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())", ["inf_compat_creator_$n", "inf_compat_creator_$n@example.test", "Influencer Compat Creator", "active", "user", "verified"]);
  $profile=(int)$db->insert("INSERT INTO influencer_profiles (user_id,username,platform,follower_count,followers_count,status,is_active,priority,story_price_24h,currency,created_at,updated_at) VALUES (?,?,?,?,?,'verified',1,999999,88000,'irt',NOW(),NOW())", [$creator, "inf_compat_page_$n", "instagram", 12000, 12000]);
  echo json_encode(['ok'=>true,'user_id'=>$user,'profile_id'=>$profile], JSON_UNESCAPED_UNICODE);
 `],{cwd:project,encoding:'utf8'}));
 const base=process.env.BASE_URL||'http://127.0.0.1:8099/chortke';
 const browser=await chromium.launch({headless:true}); const page=await browser.newPage({viewport:{width:1366,height:900}}); const errors=[]; page.on('pageerror',e=>errors.push(e.message));
 const cases=[
  ['/influencer/register','profile'],
  ['/influencer/orders','incoming'],
  ['/influencer/ads','market'],
  ['/influencer/ads/my-orders','placed'],
  [`/influencer/ads/create?influencer_id=${seed.profile_id}`,'market'],
 ];
 const checks=[];
 for(const [url,section] of cases){
   const sep=url.includes('?')?'&':'?';
   await page.goto(`${base}${url}${sep}test_user_id=${seed.user_id}`,{waitUntil:'networkidle'});
   const check=await page.evaluate(section=>({
     href: location.href,
     hasHub: document.body.innerText.includes('مرکز اینفلوئنسر'),
     active: !!document.querySelector(`[data-inf-panel="${section}"].active`),
     selected: section==='market' ? (document.getElementById('hubInfluencerId')?.value || null) : null,
     hasPhpError:/Fatal error|Parse error|Warning:|Undefined|SQLSTATE|GlobalException|خطای سیستمی/.test(document.body.innerText)
   }),section);
   checks.push({url,section,check});
 }
 await browser.close();
 const ok=errors.length===0 && checks.every(c=>c.check.hasHub && c.check.active && !c.check.hasPhpError) && checks.find(c=>c.url.startsWith('/influencer/ads/create')).check.selected==String(seed.profile_id);
 console.log(JSON.stringify({ok,seed,errors,checks},null,2)); if(!ok) process.exit(1);
})().catch(e=>{console.error(e);process.exit(1);});
