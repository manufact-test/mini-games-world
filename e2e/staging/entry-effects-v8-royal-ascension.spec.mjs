import { test, expect } from '@playwright/test';

const MODULE = '/app/assets/js/entry-effects/mgw-entry-effects-v8-royal-ascension.js?v=3';

async function mountFixture(page, variant = 'entry-02') {
  await page.goto('/app/index.html', { waitUntil: 'domcontentloaded' });
  await page.setContent(`<!doctype html><html><head></head><body style="margin:0;background:#050508">
    <div class="mgw-entry-effect-layer" id="fixture" style="position:fixed;inset:0;overflow:hidden;background:#050508">
      <button class="mgw-entry-effect-skip" id="skip" type="button">Пропустить</button>
      <img class="mgw-entry-effect-live-art" data-entry-effect-variant="${variant}" alt="" src="/app/assets/media/cosmetics/entry-effects/entry-effect-02-portal-knight.webp?asset=premium-art-v7">
      <div class="mgw-entry-effect-live-fx" data-entry-effect-variant="${variant}"></div>
      <div class="mgw-entry-effect-live-grid"><div class="mgw-entry-effect-live-card" data-entry-effect-variant="${variant}" data-player-index="0"><strong>Илья</strong><small>вступает в игру</small></div></div>
    </div>
  </body></html>`, { waitUntil: 'load' });
  await page.evaluate(async modulePath => { await import(modulePath); }, MODULE);
}

test('Entry 02 Royal Ascension mounts only on medium tier with real illustrated guardian and independent ceremony layers', async ({ page }, testInfo) => {
  await mountFixture(page, 'entry-02');
  await page.waitForSelector('.mgw-entry-v8-royal-ascension', { state: 'attached' });
  await page.waitForFunction(() => document.getElementById('mgw-entry-v8-royal-ascension-style')?.sheet, null, { timeout: 10_000 });
  await page.waitForFunction(() => {
    const img = document.querySelector('.mgw-entry-v8-ra-guardian');
    return img instanceof HTMLImageElement && img.complete && img.naturalWidth >= 320 && img.naturalHeight >= 320;
  }, null, { timeout: 10_000 });

  const layer = page.locator('#fixture');
  await expect(layer).toHaveAttribute('data-entry-v8-royal-ascension', '1');
  await expect(page.locator('.mgw-entry-v8-ra-sigil')).toHaveCount(1);
  await expect(page.locator('.mgw-entry-v8-ra-guardian')).toHaveCount(1);
  await expect(page.locator('.mgw-entry-v8-ra-banner')).toHaveCount(2);
  await expect(page.locator('.mgw-entry-v8-ra-beam')).toHaveCount(1);
  await expect(page.locator('.mgw-entry-v8-ra-halo')).toHaveCount(1);
  await expect(page.locator('.mgw-entry-v8-ra-crown-flare')).toHaveCount(1);
  await expect(page.locator('.mgw-entry-v8-ra-pulse')).toHaveCount(1);
  expect(await page.locator('.mgw-entry-v8-ra-ray').count()).toBeGreaterThanOrEqual(8);
  expect(await page.locator('.mgw-entry-v8-ra-particle').count()).toBeGreaterThanOrEqual(12);

  const state = await page.evaluate(() => {
    const oldArt = document.querySelector('.mgw-entry-effect-live-art[data-entry-effect-variant="entry-02"]');
    const oldFx = document.querySelector('.mgw-entry-effect-live-fx[data-entry-effect-variant="entry-02"]');
    const figure = document.querySelector('.mgw-entry-v8-ra-figure');
    const sigil = document.querySelector('.mgw-entry-v8-ra-sigil');
    const guardian = document.querySelector('.mgw-entry-v8-ra-guardian');
    const skip = document.getElementById('skip');
    return {
      oldArtDisplay: oldArt ? getComputedStyle(oldArt).display : null,
      oldFxDisplay: oldFx ? getComputedStyle(oldFx).display : null,
      figureAnimation: figure ? getComputedStyle(figure).animationName : null,
      sigilAnimation: sigil ? getComputedStyle(sigil).animationName : null,
      guardianAnimation: guardian ? getComputedStyle(guardian).animationName : null,
      guardianWidth: guardian instanceof HTMLImageElement ? guardian.naturalWidth : 0,
      guardianHeight: guardian instanceof HTMLImageElement ? guardian.naturalHeight : 0,
      guardianSrc: guardian instanceof HTMLImageElement ? guardian.currentSrc : '',
      skipStillPresent: skip instanceof HTMLButtonElement && skip.textContent === 'Пропустить',
    };
  });
  expect(state.oldArtDisplay).toBe('none');
  expect(state.oldFxDisplay).toBe('none');
  expect(state.figureAnimation).toContain('mgwRaFigure');
  expect(state.sigilAnimation).toContain('mgwRaSigil');
  expect(state.guardianAnimation).toContain('mgwRaGuardianLight');
  expect(state.guardianWidth).toBeGreaterThanOrEqual(320);
  expect(state.guardianHeight).toBeGreaterThanOrEqual(320);
  expect(state.guardianSrc).toContain('entry-02-royal-ascension-guardian.webp');
  expect(state.skipStillPresent).toBe(true);

  await page.waitForTimeout(1_950);
  const screenshotPath = testInfo.outputPath('entry-02-royal-ascension-peak.png');
  await page.screenshot({ path: screenshotPath, fullPage:true });
  await testInfo.attach('entry-02-royal-ascension-peak.png', { path:screenshotPath, contentType:'image/png' });
});

test('Royal Ascension does not mount on Entry 01 or Entry 03', async ({ page }) => {
  for (const variant of ['entry-01', 'entry-03']) {
    await mountFixture(page, variant);
    await page.waitForTimeout(250);
    await expect(page.locator('.mgw-entry-v8-royal-ascension')).toHaveCount(0);
    await expect(page.locator('#fixture')).not.toHaveAttribute('data-entry-v8-royal-ascension', '1');
  }
});

test('Royal Ascension reduced motion preserves the illustrated ceremonial composition', async ({ browser }) => {
  const context = await browser.newContext({ viewport:{ width:390, height:844 }, reducedMotion:'reduce' });
  try {
    const page = await context.newPage();
    await mountFixture(page, 'entry-02');
    await page.waitForSelector('.mgw-entry-v8-royal-ascension');
    await page.waitForFunction(() => {
      const img = document.querySelector('.mgw-entry-v8-ra-guardian');
      return img instanceof HTMLImageElement && img.complete && img.naturalWidth >= 320;
    });
    const reduced = await page.evaluate(() => ({
      figureOpacity:getComputedStyle(document.querySelector('.mgw-entry-v8-ra-figure')).opacity,
      guardianAnimation:getComputedStyle(document.querySelector('.mgw-entry-v8-ra-guardian')).animationName,
      banners:getComputedStyle(document.querySelector('.mgw-entry-v8-ra-banner')).opacity,
      pulseDisplay:getComputedStyle(document.querySelector('.mgw-entry-v8-ra-pulse')).display,
      figureAnimation:getComputedStyle(document.querySelector('.mgw-entry-v8-ra-figure')).animationName,
    }));
    expect(reduced.figureOpacity).toBe('1');
    expect(reduced.guardianAnimation).toBe('none');
    expect(Number(reduced.banners)).toBeGreaterThan(0);
    expect(reduced.pulseDisplay).toBe('none');
    expect(reduced.figureAnimation).toBe('none');
  } finally { await context.close(); }
});
