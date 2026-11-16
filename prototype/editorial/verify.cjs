// Optional browser smoke check: PLAYWRIGHT_MODULE can point to an existing Playwright install.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const path = require('node:path');
const assert = require('node:assert/strict');
const url = process.env.PROTOTYPE_URL || 'http://127.0.0.1:8088/prototype/editorial/';
(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => document.fonts.ready);
    for (const width of [1440, 1024, 768, 390, 320]) {
      await page.setViewportSize({ width, height: width > 700 ? 1000 : 844 });
      await page.evaluate(() => scrollTo(0, 0));
      await page.waitForTimeout(1000);
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Horizontal overflow at ${width}`);
      if (width === 1440 || width === 390) await page.screenshot({ path: path.join(__dirname, width === 1440 ? 'desktop-preview.png' : 'mobile-preview.png'), animations: 'disabled' });
      console.log(`Layout ${width}px: no horizontal overflow`);
    }
    await page.setViewportSize({width:390,height:844});
    await page.locator('.menu-toggle').click();
    assert.equal(await page.locator('.menu-toggle').getAttribute('aria-expanded'), 'true');
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('.menu-toggle').getAttribute('aria-expanded'), 'false');
    assert(await page.locator('.menu-toggle').evaluate(el => el === document.activeElement));
    await page.locator('.menu-toggle').click();
    await page.locator('#mobile-menu a[href="#membership"]').click();
    assert(await page.locator('#mobile-menu').isHidden());
    await page.locator('[data-preview="membership"]').click();
    assert(await page.locator('dialog').isVisible());
    await page.keyboard.press('Escape');
    assert(await page.locator('dialog').isHidden());
    assert(await page.locator('[data-preview="membership"]').evaluate(el => el === document.activeElement));
    for (const filter of ['research', 'community', 'guidelines', 'all']) {
      await page.locator(`[data-filter="${filter}"]`).click();
      assert.equal(await page.locator('.story:visible').count(), filter === 'all' ? 3 : 1);
    }
    await page.emulateMedia({reducedMotion:'reduce'});
    await page.setViewportSize({width:1440,height:1000});
    // Visit each chapter to load lazy images, then capture the complete direction.
    for (const section of await page.locator('main section').all()) await section.scrollIntoViewIfNeeded();
    await page.evaluate(() => Promise.all([...document.images].map(image => image.decode().catch(() => {}))));
    const broken = await page.evaluate(() => [...document.images].filter(i => !i.complete || i.naturalWidth === 0).map(i => i.src));
    assert.deepEqual(broken, []);
    await page.evaluate(() => scrollTo(0, 0));
    await page.screenshot({path:path.join(__dirname,'full-page-preview.png'),fullPage:true,animations:'disabled'});
    assert.deepEqual(errors, []);
    const noJS = await browser.newPage({javaScriptEnabled:false,viewport:{width:390,height:844},reducedMotion:'reduce'});
    await noJS.goto(url,{waitUntil:'domcontentloaded'});
    assert.equal(await noJS.locator('main section').count(),9); // Hero plus eight editorial chapters.
    assert(await noJS.locator('h1').isVisible());
    assert(await noJS.locator('h1').evaluate(el => getComputedStyle(el).opacity === '1'));
    console.log('PASS: mobile navigation, Escape/focus return, dialogs, all filters, all images, reduced motion, no-JS content, and no JavaScript errors.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
