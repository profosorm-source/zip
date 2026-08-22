const { chromium } = require('playwright');

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
})();
