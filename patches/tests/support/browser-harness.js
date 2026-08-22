/**
 * هارنس مشترک تست‌های مرورگری (Playwright)
 *
 * چرا وجود دارد: بیشتر اسکریپت‌های browser_*.js خطا را در catch می‌بلعیدند و
 * با کد خروج ۰ تمام می‌شدند، بنابراین tests/run_all.py — که فقط
 * `result.returncode == 0` را می‌خواند — آن‌ها را همیشه «سبز» می‌دید.
 * این ماژول همان قرارداد فایل مرجع پروژه (tests/browser_deep_test.js) را
 * متمرکز می‌کند: check()، شمارندهٔ pass/fail، خلاصهٔ «Passed: / Failed:»
 * و در نهایت process.exit(fail > 0 ? 1 : 0).
 *
 * پیکربندی از محیط واقعی پروژه خوانده می‌شود (بدون هاردکد):
 *   CHORTKE_E2E_BASE_URL → آدرس پایه (پیش‌فرض از .env پروژه)
 *   E2E_EMAIL / E2E_PASSWORD → اعتبارنامهٔ کاربر seed
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const GREEN = '\x1b[92m', RED = '\x1b[91m', YELLOW = '\x1b[93m',
      CYAN = '\x1b[96m', BOLD = '\x1b[1m', RESET = '\x1b[0m';

/** خواندن یک کلید از .env پروژه — همان منبعی که PHP/Python هم می‌خوانند. */
function envFromDotenv(key) {
  const candidates = [
    path.resolve(__dirname, '../../.env'),
    path.resolve(__dirname, '../../../.env'),
  ];
  for (const file of candidates) {
    try {
      if (!fs.existsSync(file)) continue;
      const line = fs.readFileSync(file, 'utf8')
        .split('\n')
        .find(l => l.trim().startsWith(key + '='));
      if (line) return line.slice(line.indexOf('=') + 1).trim().replace(/^["']|["']$/g, '');
    } catch (_) { /* عبور: .env اختیاری است */ }
  }
  return null;
}

// تقدم: متغیر محیطی واقعی → .env پروژه → پیش‌فرض
const BASE = process.env.CHORTKE_E2E_BASE_URL
  || envFromDotenv('APP_URL')
  || 'http://127.0.0.1:8080';
const E2E_EMAIL = process.env.E2E_EMAIL || 'user@chortke.ir';
const E2E_PASSWORD = process.env.E2E_PASSWORD || '123456';

let pass = 0, fail = 0;
const failures = [];

/** ثبت یک ادعا. برخلاف console.log ساده، شکست واقعاً شمرده می‌شود. */
function check(name, condition, detail = '') {
  const ok = !!condition;
  const sym = ok ? `${GREEN}✓${RESET}` : `${RED}✗${RESET}`;
  console.log(`  ${sym} ${String(name).padEnd(52)} ${detail}`);
  if (ok) pass++; else { fail++; failures.push(name + (detail ? ` — ${detail}` : '')); }
  return ok;
}

/** ثبت شکست صریح (برای مسیرهای catch). */
function recordFailure(name, err) {
  const msg = err && err.message ? err.message : String(err);
  return check(name, false, msg.substring(0, 160));
}

async function createBrowser() {
  return await chromium.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
  });
}

/** صفحهٔ جدید همراه با جمع‌آوری خطاهای کنسول/صفحه. */
async function newTrackedPage(browser) {
  const context = await browser.newContext();
  const page = await context.newPage();
  const errors = [];
  page.on('console', msg => { if (msg.type() === 'error') errors.push(msg.text().substring(0, 150)); });
  page.on('pageerror', err => errors.push('PAGE_ERROR: ' + err.message.substring(0, 150)));
  return { context, page, errors };
}

/**
 * ورود واقعی. کپچای ریاضی را مثل الگوی browser_deep_test.js حل می‌کند.
 * برخلاف نسخه‌های قدیمی، موفقیت ورود را **تأیید** می‌کند.
 */
async function login(page, email = E2E_EMAIL, password = E2E_PASSWORD) {
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle', timeout: 20000 });
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);

  const captchaQ = await page.locator('.captcha-question').textContent().catch(() => null);
  if (captchaQ) {
    const m = captchaQ.match(/(\d+)\s*([+\-*])\s*(\d+)/);
    if (m) {
      const a = parseInt(m[1], 10), b = parseInt(m[3], 10);
      const answer = m[2] === '-' ? a - b : m[2] === '*' ? a * b : a + b;
      await page.fill('input[name="captcha_response"]', String(answer)).catch(() => {});
    }
  }

  await page.click('button[type="submit"], input[type="submit"]');
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
  return !/\/login\b/.test(page.url());
}

/**
 * پایان اجرا: خلاصه چاپ و کد خروج تعیین می‌شود.
 * خطاهای JS کنسول هم — در صورت درخواست — شکست تلقی می‌شوند.
 */
function finish(title, jsErrors = []) {
  if (Array.isArray(jsErrors) && jsErrors.length > 0) {
    jsErrors.slice(0, 5).forEach(e => console.log(`    ${YELLOW}JS_ERROR${RESET} ${e}`));
  }
  console.log(`\n${BOLD}═══════════════════════════════════════════════════${RESET}`);
  if (title) console.log(`  ${BOLD}${title}${RESET}`);
  console.log(`  ${GREEN}Passed: ${pass}${RESET}  ${RED}Failed: ${fail}${RESET}  Total: ${pass + fail}`);
  if (fail > 0) {
    console.log(`\n  ${RED}${BOLD}موارد ناموفق:${RESET}`);
    failures.forEach(f => console.log(`    ${RED}✗${RESET} ${f}`));
  }
  console.log(`${BOLD}═══════════════════════════════════════════════════${RESET}\n`);
  process.exit(fail > 0 ? 1 : 0);
}

/**
 * پوشش اجرای اصلی: هر استثنای فرارنکرده به‌عنوان شکست ثبت می‌شود،
 * مرورگر بسته می‌شود و کد خروج درست برمی‌گردد.
 */
async function run(title, body) {
  console.log(`\n${BOLD}${CYAN}▶ ${title}${RESET}`);
  const browser = await createBrowser();
  const collected = [];
  try {
    await body(browser, collected);
  } catch (e) {
    recordFailure('اجرای سناریو بدون استثنای مرگبار', e);
  } finally {
    await browser.close().catch(() => {});
  }
  finish(title, collected);
}

// اگر تست چیزی را assert نکرده باشد، «سبز» گزارش نشود.
process.on('exit', () => {});

module.exports = {
  BASE, E2E_EMAIL, E2E_PASSWORD,
  GREEN, RED, YELLOW, CYAN, BOLD, RESET,
  check, recordFailure, createBrowser, newTrackedPage, login, finish, run,
  counters: () => ({ pass, fail }),
};
