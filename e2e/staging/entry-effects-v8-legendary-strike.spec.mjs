import { test, expect } from '@playwright/test';

const MODULE = '/app/assets/js/entry-effects/mgw-entry-effects-v8-legendary-strike.js?v=1';

async function mountFixture(page) {
  await page.goto('/app/index.html', { waitUntil: 'domcontentloaded' });
  await page.setContent(`<!doctype html><html><head></head><body style="margin:0;background:#050508">
    <div class="mgw-entry-effect-layer" id="fixture" style="position:fixed;inset:0;overflow:hidden;background:#050508">
      <button class="mgw-entry-effect-skip" id="skip" type="button">Пропустить</button>
      <img class="mgw-entry-effect-live-art" data-entry-effect-variant="entry-03" alt="" src="/app/assets/media/cosmetics/entry-effects/entry-effect-03-knight-strike.webp?asset=premium-art-v7">
      <div class="mgw-entry-effect-live-fx" data-entry-effect-variant="entry-03"></div>
      <div class="mgw-entry-effect-live-grid">
        <div class="mgw-entry-effect-live-card" data-entry-effect-variant="entry-03" data-player-index="0">
          <strong>Царь у дворца</strong><small>вступает в игру</small>
        </div>
      </div>
    </div>
  </body></html>`, { waitUntil: 'load' });
  await page.evaluate(async modulePath => { await import(modulePath); }, MODULE);
  await page.waitForSelector('.mgw-entry-v8-legendary-strike', { state: 'attached' });
  await page.waitForFunction(() => document.getElementById('mgw-entry-v8-legendary-strike-style')?.sheet, null, { timeout: 10_000 });
}

test('Entry 03 V8 mounts a real multi-part animated strike and replaces only the old visual owner', async ({ page }, testInfo) => {
  await mountFixture(page);

  const layer = page.locator('#fixture');
  await expect(layer).toHaveAttribute('data-entry-v8-legendary-strike', '1');
  await expect(page.locator('.mgw-entry-v8-legendary-strike')).toHaveCount(1);
  await expect(page.locator('.mgw-entry-v8-arena')).toHaveCount(1);
  await expect(page.locator('.mgw-entry-v8-sword')).toHaveCount(1);
  await expect(page.locator('.mgw-entry-v8-cracks')).toHaveCount(1);
  await expect(page.locator('.mgw-entry-v8-shockwave')).toHaveCount(1);
  await expect(page.locator('.mgw-entry-v8-dust')).toHaveCount(1);
  expect(await page.locator('.mgw-entry-v8-debris').count()).toBeGreaterThanOrEqual(10);
  expect(await page.locator('.mgw-entry-v8-spark').count()).toBeGreaterThanOrEqual(10);

  const state = await page.evaluate(() => {
    const oldArt = document.querySelector('.mgw-entry-effect-live-art[data-entry-effect-variant="entry-03"]');
    const oldFx = document.querySelector('.mgw-entry-effect-live-fx[data-entry-effect-variant="entry-03"]');
    const sword = document.querySelector('.mgw-entry-v8-sword');
    const cracks = document.querySelector('.mgw-entry-v8-cracks');
    const wave = document.querySelector('.mgw-entry-v8-shockwave');
    const skip = document.getElementById('skip');
    return {
      oldArtDisplay: oldArt ? getComputedStyle(oldArt).display : null,
      oldFxDisplay: oldFx ? getComputedStyle(oldFx).display : null,
      swordAnimation: sword ? getComputedStyle(sword).animationName : null,
      cracksAnimation: cracks ? getComputedStyle(cracks).animationName : null,
      waveAnimation: wave ? getComputedStyle(wave).animationName : null,
      skipStillPresent: skip instanceof HTMLButtonElement && skip.textContent === 'Пропустить',
    };
  });

  expect(state.oldArtDisplay).toBe('none');
  expect(state.oldFxDisplay).toBe('none');
  expect(state.swordAnimation).toContain('mgwEntryV8Sword');
  expect(state.cracksAnimation).toContain('mgwEntryV8Cracks');
  expect(state.waveAnimation).toContain('mgwEntryV8Shockwave');
  expect(state.skipStillPresent).toBe(true);

  await page.waitForTimeout(1_180);
  await testInfo.attach('entry-v8-legendary-strike-impact.png', {
    body: await page.screenshot({ fullPage: true }),
    contentType: 'image/png',
  });
});

test('Entry 03 V8 has a coherent reduced-motion final composition', async ({ browser }) => {
  const context = await browser.newContext({
    viewport: { width: 390, height: 844 },
    reducedMotion: 'reduce',
  });
  try {
    const page = await context.newPage();
    await mountFixture(page);
    const reduced = await page.evaluate(() => {
      const sword = document.querySelector('.mgw-entry-v8-sword');
      const cracks = document.querySelector('.mgw-entry-v8-cracks');
      const impact = document.querySelector('.mgw-entry-v8-impact');
      const debris = document.querySelector('.mgw-entry-v8-debris');
      return {
        swordOpacity: sword ? getComputedStyle(sword).opacity : null,
        swordAnimation: sword ? getComputedStyle(sword).animationName : null,
        cracksOpacity: cracks ? getComputedStyle(cracks).opacity : null,
        impactDisplay: impact ? getComputedStyle(impact).display : null,
        debrisDisplay: debris ? getComputedStyle(debris).display : null,
      };
    });
    expect(reduced.swordOpacity).toBe('1');
    expect(reduced.swordAnimation).toBe('none');
    expect(Number(reduced.cracksOpacity)).toBeGreaterThan(0);
    expect(reduced.impactDisplay).toBe('none');
    expect(reduced.debrisDisplay).toBe('none');
  } finally {
    await context.close();
  }
});
