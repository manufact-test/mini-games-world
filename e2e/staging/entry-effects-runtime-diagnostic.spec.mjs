import { test, expect } from '@playwright/test';

const ORIGIN = process.env.MGW_STAGING_ORIGIN || 'https://seashell-okapi-889488.hostingersite.com';
const ART_PATH = '/app/assets/media/cosmetics/entry-effects/store-entry-03-lord-blade.svg?asset=live-node-svg-v2';
const LIVE_JS_PATH = '/app/assets/js/production-clean-entry-v110-mvp19-3-final-polish.js?v=1133&entry_live_img=canonical-svg-node-v2';
const LIVE_CSS_PATH = '/app/assets/css/production-v106-store-avatar-frame-density.css?v=7&live_entry=canonical-svg-node-v2';

async function bodyText(response) {
  return (await response.body()).toString('utf8');
}

test('ENTRY EFFECT DIAGNOSTIC: deployed canonical SVG real-img owner is visible', async ({ browser }, testInfo) => {
  const context = await browser.newContext({
    locale: 'ru-RU',
    timezoneId: 'Europe/Vilnius',
    viewport: { width: 390, height: 844 },
    isMobile: true,
    hasTouch: true,
  });

  try {
    const [artResponse, jsResponse, cssResponse] = await Promise.all([
      context.request.get(`${ORIGIN}${ART_PATH}`, { timeout: 35_000 }),
      context.request.get(`${ORIGIN}${LIVE_JS_PATH}`, { timeout: 35_000 }),
      context.request.get(`${ORIGIN}${LIVE_CSS_PATH}`, { timeout: 35_000 }),
    ]);

    expect(artResponse.status()).toBe(200);
    expect(jsResponse.status()).toBe(200);
    expect(cssResponse.status()).toBe(200);

    const artText = await bodyText(artResponse);
    const jsText = await bodyText(jsResponse);
    const cssText = await bodyText(cssResponse);

    expect(artResponse.headers()['content-type'] || '').toContain('image/svg+xml');
    expect(artText).toMatch(/<svg\b/i);
    expect(artText).not.toMatch(/<text\b/i);
    expect(artText.length).toBeGreaterThan(1000);

    expect(jsText).toContain('store-entry-01-celestial-gate.svg?asset=live-node-svg-v2');
    expect(jsText).toContain('store-entry-02-king-ascension.svg?asset=live-node-svg-v2');
    expect(jsText).toContain('store-entry-03-lord-blade.svg?asset=live-node-svg-v2');
    expect(jsText).toContain("image.className = 'mgw-entry-effect-live-art'");

    expect(cssText).toContain('background-color:transparent!important;background-image:none!important;');
    expect(cssText).toContain('.mgw-entry-effect-live-art{');
    expect(cssText).toContain('display:block!important;');
    expect(cssText).not.toContain('.mgw-entry-effect-live-art{display:none!important}');

    const page = await context.newPage();
    await page.setContent(`<!doctype html><html><head>
      <link rel="stylesheet" href="${ORIGIN}${LIVE_CSS_PATH}">
    </head><body>
      <div class="mgw-entry-effect-layer" id="stagingEntryEffectDiagnosticLayer">
        <button class="mgw-entry-effect-skip" type="button">Пропустить</button>
        <img class="mgw-entry-effect-live-art" data-entry-effect-variant="entry-03" alt="" aria-hidden="true" src="${ORIGIN}${ART_PATH}" style="position:absolute;left:50%;top:50%;z-index:1;display:block;width:min(144vw,720px);height:min(88vh,620px);max-width:none;object-fit:contain;object-position:center;opacity:1;visibility:visible;pointer-events:none;transform:translate(-50%,-50%) scale(1)">
        <div class="mgw-entry-effect-live-grid">
          <div class="mgw-entry-effect-live-card" data-entry-effect-variant="entry-03" data-player-index="0">
            <div class="mgw-entry-effect-live-emblem"><i></i><b>MG</b><i></i></div>
            <strong>Runtime diagnostic</strong><small>вступает в игру</small>
          </div>
        </div>
      </div>
    </body></html>`, { waitUntil: 'load' });

    const image = page.locator('#stagingEntryEffectDiagnosticLayer .mgw-entry-effect-live-art');
    await expect(image).toHaveCount(1);
    await page.waitForFunction(() => {
      const img = document.querySelector('#stagingEntryEffectDiagnosticLayer .mgw-entry-effect-live-art');
      return img instanceof HTMLImageElement && img.complete && img.naturalWidth > 0 && img.naturalHeight > 0;
    }, null, { timeout: 15_000 });

    const diagnostic = await page.evaluate(() => {
      const img = document.querySelector('#stagingEntryEffectDiagnosticLayer .mgw-entry-effect-live-art');
      const card = document.querySelector('#stagingEntryEffectDiagnosticLayer .mgw-entry-effect-live-card');
      const emblem = card?.querySelector('.mgw-entry-effect-live-emblem');
      if (!(img instanceof HTMLImageElement) || !(card instanceof HTMLElement)) return null;
      const imageStyle = getComputedStyle(img);
      const cardStyle = getComputedStyle(card);
      const rect = img.getBoundingClientRect();
      return {
        src: img.currentSrc || img.src,
        complete: img.complete,
        naturalWidth: img.naturalWidth,
        naturalHeight: img.naturalHeight,
        rect: { width:rect.width, height:rect.height, x:rect.x, y:rect.y },
        imageStyle: {
          display:imageStyle.display,
          visibility:imageStyle.visibility,
          opacity:imageStyle.opacity,
          position:imageStyle.position,
          zIndex:imageStyle.zIndex,
        },
        cardBackgroundImage:cardStyle.backgroundImage,
        legacyMgDisplay:emblem instanceof HTMLElement ? getComputedStyle(emblem).display : null,
      };
    });

    console.log('ENTRY_EFFECT_RUNTIME_DIAGNOSTIC=' + JSON.stringify(diagnostic));
    await testInfo.attach('entry-effect-runtime-diagnostic.json', {
      body: Buffer.from(JSON.stringify(diagnostic, null, 2)),
      contentType: 'application/json',
    });
    await testInfo.attach('entry-effect-runtime-diagnostic.png', {
      body: await page.screenshot({ fullPage:true }),
      contentType: 'image/png',
    });

    expect(diagnostic).not.toBeNull();
    expect(diagnostic.src).toContain('store-entry-03-lord-blade.svg');
    expect(diagnostic.complete).toBe(true);
    expect(diagnostic.naturalWidth).toBeGreaterThan(0);
    expect(diagnostic.naturalHeight).toBeGreaterThan(0);
    expect(diagnostic.rect.width).toBeGreaterThan(100);
    expect(diagnostic.rect.height).toBeGreaterThan(100);
    expect(diagnostic.imageStyle.display).toBe('block');
    expect(diagnostic.imageStyle.visibility).not.toBe('hidden');
    expect(Number(diagnostic.imageStyle.opacity || 0)).toBeGreaterThan(0);
    expect(diagnostic.cardBackgroundImage).toBe('none');
    expect(diagnostic.legacyMgDisplay).toBe('none');
  } finally {
    await context.close().catch(() => null);
  }
});
