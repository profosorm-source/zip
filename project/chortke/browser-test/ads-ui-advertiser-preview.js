const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

(async () => {
  const base = process.env.BASE_URL || 'http://localhost/chortke';
  const userId = process.env.USER_ID || '10';
  const outDir = '/home/user/projects/zip/extracted/chortke/tools/browser-preview/screenshots';
  fs.mkdirSync(outDir, { recursive: true });

  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1440, height: 1100 }, deviceScaleFactor: 1 });
  const errors = [];
  const failedRequests = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('requestfailed', req => {
    const u = req.url();
    if (/assets\/(css|js|vendor)\//.test(u)) failedRequests.push(u + ' :: ' + (req.failure()?.errorText || 'failed'));
  });
  page.on('console', msg => {
    if (msg.type() === 'error') {
      const text = msg.text();
      if (!/Content Security Policy|favicon|Failed to load resource/.test(text)) errors.push(text);
    }
  });

  await page.goto(`${base}/ads?test_user_id=${userId}`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: path.join(outDir, 'ads-ui-management.png'), fullPage: true });
  const management = await page.evaluate(() => {
    const h1 = document.querySelector('.ads-hero h1');
    const icon = document.querySelector('.material-icons');
    const sidebarText = document.body.innerText;
    return {
      hasHero: !!document.querySelector('.ads-hero'),
      hasSubnav: !!document.querySelector('.ads-subnav'),
      statCards: document.querySelectorAll('.ads-stat-card').length,
      campaignCards: document.querySelectorAll('.campaign-card').length,
      h1Font: h1 ? parseFloat(getComputedStyle(h1).fontSize) : 0,
      h1Weight: h1 ? getComputedStyle(h1).fontWeight : '',
      noSidebar: document.body.classList.contains('layout-no-sidebar'),
      themeFab: !!document.querySelector('#themeToggleBtn'),
      iconsFontLoaded: /Material Icons/i.test(getComputedStyle(icon || document.body).fontFamily),
      hasPhpError: /Fatal error|Parse error|Undefined|GlobalException|خطای سیستمی/.test(document.body.innerText),
      hasPersianAdsEntry: sidebarText.includes('تبلیغات'),
      hasOldProofWords: /proof schema|customtask|socialtask|delivery|escrow/i.test(sidebarText),
    };
  });

  const beforeUrl = page.url();
  await page.click('[data-ads-section="create"]');
  await page.waitForSelector('[data-ads-panel="create"].active', { timeout: 3000 });
  const afterSwitchUrl = page.url();
  await page.screenshot({ path: path.join(outDir, 'ads-ui-create-wizard.png'), fullPage: true });
  const create = await page.evaluate(() => {
    const h1 = document.querySelector('.ads-hero h1');
    const icon = document.querySelector('.material-icons');
    return {
      createPanelActive: !!document.querySelector('[data-ads-panel="create"].active'),
      managePanelHidden: !document.querySelector('[data-ads-panel="manage"]')?.classList.contains('active'),
      hasTips: !!document.querySelector('.ads-create-tips'),
      typeCards: document.querySelectorAll('[data-ads-panel="create"] .type-card').length,
      h1Font: h1 ? parseFloat(getComputedStyle(h1).fontSize) : 0,
      h1Weight: h1 ? getComputedStyle(h1).fontWeight : '',
      noSidebar: document.body.classList.contains('layout-no-sidebar'),
      themeFab: !!document.querySelector('#themeToggleBtn'),
      iconsFontLoaded: /Material Icons/i.test(getComputedStyle(icon || document.body).fontFamily),
      hasPhpError: /Fatal error|Parse error|Undefined|GlobalException|خطای سیستمی/.test(document.body.innerText),
      hasOldProofWords: /proof schema|customtask|socialtask|delivery|escrow/i.test(document.body.innerText),
    };
  });

  await page.click('[data-ads-panel="create"] [data-type="banner"]');
  await page.waitForSelector('#step2.active #dynamicForm input[name="title"]', { state: 'visible', timeout: 5000 });
  await page.screenshot({ path: path.join(outDir, 'ads-ui-create-details.png'), fullPage: true });
  const details = await page.evaluate(() => {
    const form = document.querySelector('#dynamicForm');
    const visibleFields = [...document.querySelectorAll('#dynamicForm input:not([type="hidden"]), #dynamicForm select, #dynamicForm textarea')]
      .filter(el => {
        const r = el.getBoundingClientRect();
        const cs = getComputedStyle(el);
        return r.width > 0 && r.height > 0 && cs.display !== 'none' && cs.visibility !== 'hidden';
      }).map(el => el.name);
    return {
      step2Active: !!document.querySelector('#step2.active'),
      formVisible: !!form && getComputedStyle(form).display !== 'none' && form.getBoundingClientRect().height > 0,
      visibleFieldCount: visibleFields.length,
      visibleFields,
      hasTitle: visibleFields.includes('title'),
      hasPlacement: visibleFields.includes('placement'),
      hasBudget: visibleFields.includes('budget'),
    };
  });

  await browser.close();
  const samePageNoReload = beforeUrl.split('?')[0] === afterSwitchUrl.split('?')[0] && afterSwitchUrl.includes('/ads');
  const ok = errors.length === 0 && failedRequests.length === 0
    && management.hasHero && management.hasSubnav && management.statCards === 4 && management.h1Font > 18 && management.h1Font <= 28
    && management.noSidebar && management.themeFab && management.iconsFontLoaded && !management.hasPhpError && management.hasPersianAdsEntry && !management.hasOldProofWords
    && samePageNoReload && create.createPanelActive && create.managePanelHidden && create.hasTips && create.typeCards >= 6
    && create.h1Font > 18 && create.h1Font <= 28 && !create.hasPhpError && create.iconsFontLoaded && create.themeFab && create.noSidebar && !create.hasOldProofWords
    && details.step2Active && details.formVisible && details.visibleFieldCount >= 5 && details.hasTitle && details.hasPlacement && details.hasBudget;
  console.log(JSON.stringify({ ok, errors, failedRequests, samePageNoReload, beforeUrl, afterSwitchUrl, management, create, details, screenshots: ['ads-ui-management.png', 'ads-ui-create-wizard.png', 'ads-ui-create-details.png'] }, null, 2));
  if (!ok) process.exit(1);
})().catch(e => { console.error(e); process.exit(1); });
