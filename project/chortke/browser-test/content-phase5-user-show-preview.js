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
    $userId=(int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())", ["content_phase5_show_$n", "content_phase5_show_$n@example.test", "Content Phase5 Show User", "active", "user", "verified"]);
    $submissionId=(int)$db->insert("INSERT INTO content_submissions (user_id,title,url,video_url,platform,status,description,category,agreement_accepted,agreement_accepted_at,approved_at,approved_by,published_at,published_url,published_by,channel_name,is_deleted,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,1,NOW(),DATE_SUB(NOW(), INTERVAL 3 MONTH),1,DATE_SUB(NOW(), INTERVAL 2 MONTH),?,1,?,0,NOW(),NOW())", [$userId, "CP5SHOW: محتوای نمایشی", "https://www.youtube.com/watch?v=CP5SHOW", "https://www.youtube.com/watch?v=CP5SHOW", "youtube", "published", "توضیح نمایشی برای صفحه جزئیات کاربر", "education", "https://www.youtube.com/watch?v=CP5SHOW_PUBLISHED", "کانال نمایشی محتوا"]);
    echo json_encode(['ok'=>true,'user_id'=>$userId,'submission_id'=>$submissionId], JSON_UNESCAPED_UNICODE);
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

  await page.goto(`${base}/content/${seed.submission_id}?test_user_id=${seed.user_id}`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: path.join(outDir, 'content-phase5-user-show.png'), fullPage: true });

  const check = await page.evaluate(() => {
    const body = document.body.innerText;
    const icon = document.querySelector('.material-icons');
    return {
      hasExpectedText: body.includes('جزئیات محتوا') && body.includes('درآمدهای این محتوا') && body.includes('کانال نمایشی محتوا'),
      hasPhpError: /Fatal error|Parse error|Warning:|Undefined|SQLSTATE|GlobalException|خطای سیستمی/.test(body),
      doctypeCount: document.doctype ? 1 : 0,
      iconsFontLoaded: icon ? /Material Icons/i.test(getComputedStyle(icon).fontFamily) : true,
    };
  });

  await browser.close();
  const ok = errors.length === 0 && failedRequests.length === 0 && check.hasExpectedText && !check.hasPhpError && check.doctypeCount === 1 && check.iconsFontLoaded;
  console.log(JSON.stringify({ ok, seed, errors, failedRequests, check }, null, 2));
  if (!ok) process.exit(1);
})().catch(e => { console.error(e); process.exit(1); });
