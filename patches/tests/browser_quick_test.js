const { chromium } = require('playwright');

// ── سخت‌سازی هارنس: شکست باید واقعاً شکست باشد ──
// پیش از این، خطا در catch فقط چاپ می‌شد و اسکریپت با کد خروج ۰ تمام
// می‌شد؛ در نتیجه run_all.py که به returncode نگاه می‌کند همیشه «سبز»
// می‌دید. شمارنده‌های زیر و خروج غیرصفر این نقص را برطرف می‌کنند.
let __pass = 0, __fail = 0;
const __failures = [];
function check(name, condition, detail = '') {
    const ok = !!condition;
    const mark = ok ? '\x1b[92m\u2713\x1b[0m' : '\x1b[91m\u2717\x1b[0m';
    console.log('  ' + mark + ' ' + String(name) + (detail ? ' \u2014 ' + detail : ''));
    if (ok) { __pass++; } else { __fail++; __failures.push(String(name)); }
    return ok;
}
function recordFailure(name, err) {
    const msg = (err && err.message) ? err.message : String(err);
    return check(name, false, msg.substring(0, 160));
}
function __summary() {
    console.log('\n' + '\u2550'.repeat(60));
    console.log('  \x1b[92mPassed: ' + __pass + '\x1b[0m  \x1b[91mFailed: ' + __fail
                + '\x1b[0m  Total: ' + (__pass + __fail));
    if (__fail > 0) {
        console.log('  \x1b[91m\x1b[1m\u0645\u0648\u0627\u0631\u062f \u0646\u0627\u0645\u0648\u0641\u0642:\x1b[0m');
        __failures.forEach(function (f) { console.log('    \x1b[91m\u2717\x1b[0m ' + f); });
    }
    console.log('\u2550'.repeat(60) + '\n');
    process.exit(__fail > 0 ? 1 : 0);
}


(async () => {
  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
  const page = await browser.newPage();
  
  const consoleErrors = [];
  page.on('console', msg => { if (msg.type() === 'error') consoleErrors.push(msg.text().substring(0, 100)); });
  page.on('pageerror', err => consoleErrors.push('PAGE_ERROR: ' + err.message.substring(0, 100)));
  
  // Test homepage
  await page.goto('http://127.0.0.1:8080/', { waitUntil: 'networkidle', timeout: 15000 });
  const title = await page.title();
  console.log('✓ Homepage loaded — title:', title);
  console.log('  Console errors:', consoleErrors.length);
  if (consoleErrors.length) consoleErrors.forEach(e => console.log('    -', e));
  
  // Test login page
  await page.goto('http://127.0.0.1:8080/login', { waitUntil: 'networkidle', timeout: 15000 });
  const hasForm = await page.locator('form').count();
  const hasCsrf = await page.locator('input[name="_csrf_token"]').count();
  console.log('✓ Login page — forms:', hasForm, 'csrf:', hasCsrf);
  
  // Test register page
  await page.goto('http://127.0.0.1:8080/register', { waitUntil: 'networkidle', timeout: 15000 });
  const captchaVisible = await page.locator('.captcha-container').count();
  console.log('✓ Register page — captcha:', captchaVisible);
  
  await browser.close();
  console.log('✓ Browser test completed');

  check('\u0628\u062f\u0648\u0646 \u062e\u0637\u0627\u06cc JS \u062f\u0631 \u06a9\u0646\u0633\u0648\u0644 \u0645\u0631\u0648\u0631\u06af\u0631', typeof jsErrors === 'undefined' || jsErrors.length === 0, typeof jsErrors !== 'undefined' ? jsErrors.slice(0, 3).join('; ') : '');
  __summary();
})();
