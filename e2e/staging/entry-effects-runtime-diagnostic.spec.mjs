import { test, expect } from '@playwright/test';

const ORIGIN = process.env.MGW_STAGING_ORIGIN || 'https://seashell-okapi-889488.hostingersite.com';
const ART_PATH = '/app/assets/media/cosmetics/entry-effects/entry-effect-03-knight-strike.webp?asset=reference-raster-v6';
const LIVE_JS_PATH = '/app/assets/js/production-clean-entry-v110-mvp19-3-final-polish.js?v=1137&entry_live_img=reference-raster-v6';
const LIVE_CSS_PATH = '/app/assets/css/production-v106-store-avatar-frame-density.css?v=9&live_entry=reference-art-v3';

async function bodyText(response) {
  return (await response.body()).toString('utf8');
}

test('ENTRY EFFECT DIAGNOSTIC: deployed decoder-valid WebP real-img owner paints pixels', async ({ browser }, testInfo) => {
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

    const artBody = await artResponse.body();
    const jsText = await bodyText(jsResponse);
    const cssText = await bodyText(cssResponse);

    expect(artResponse.headers()['content-type'] || '').toContain('image/webp');
    expect(artBody.length).toBeGreaterThan(1000);
    expect(artBody.subarray(0, 4).toString('ascii')).toBe('RIFF');
    expect(artBody.subarray(8, 12).toString('ascii')).toBe('WEBP');
    const declaredRiffTotal = artBody.readUInt32LE(4) + 8;
    expect(declaredRiffTotal).toBe(artBody.length);

    expect(jsText).toContain('store-entry-01-celestial-gate.svg?asset=reference-art-v3');
    expect(jsText).toContain('entry-effect-02-portal-knight.webp?asset=reference-raster-v6');
    expect(jsText).toContain('entry-effect-03-knight-strike.webp?asset=reference-raster-v6');
    expect(jsText).toContain("image.className = 'mgw-entry-effect-live-art'");

    expect(cssText).toContain('background-color:transparent!important;background-image:none!important;');
    expect(cssText).toContain('.mgw-entry-effect-live-art{');
    expect(cssText).toContain('display:block!important;');
    expect(cssText).not.toContain('.mgw-entry-effect-live-art{display:none!important}');

    const page = await context.newPage();
    // Establish a same-origin document so canvas pixel reads are meaningful and not CORS-tainted.
    await page.goto(`${ORIGIN}/app/v110.php?v=1127`, { waitUntil:'domcontentloaded', timeout:35_000 });
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
    </body></html>`, { waitUntil:'load' });

    const image = page.locator('#stagingEntryEffectDiagnosticLayer .mgw-entry-effect-live-art');
    await expect(image).toHaveCount(1);
    await page.waitForFunction(() => {
      const img = document.querySelector('#stagingEntryEffectDiagnosticLayer .mgw-entry-effect-live-art');
      return img instanceof HTMLImageElement && img.complete && img.naturalWidth > 0 && img.naturalHeight > 0;
    }, null, { timeout:15_000 });

    const diagnostic = await page.evaluate(() => {
      const img = document.querySelector('#stagingEntryEffectDiagnosticLayer .mgw-entry-effect-live-art');
      const card = document.querySelector('#stagingEntryEffectDiagnosticLayer .mgw-entry-effect-live-card');
      const emblem = card?.querySelector('.mgw-entry-effect-live-emblem');
      if (!(img instanceof HTMLImageElement) || !(card instanceof HTMLElement)) return null;

      const imageStyle = getComputedStyle(img);
      const cardStyle = getComputedStyle(card);
      const rect = img.getBoundingClientRect();
      let paintedPixelCount = 0;
      let sampledPixelCount = 0;
      let paintError = null;

      try {
        const canvas = document.createElement('canvas');
        canvas.width = img.naturalWidth;
        canvas.height = img.naturalHeight;
        const ctx = canvas.getContext('2d', { willReadFrequently:true });
        if (!ctx) throw new Error('2d canvas unavailable');
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0);
        const pixels = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
        // Sample every 16th source pixel in both axes. We only need proof of real painted content.
        for (let y = 0; y < canvas.height; y += 16) {
          for (let x = 0; x < canvas.width; x += 16) {
            const i = (y * canvas.width + x) * 4;
            sampledPixelCount += 1;
            if (pixels[i + 3] > 8 && (pixels[i] + pixels[i + 1] + pixels[i + 2]) > 12) {
              paintedPixelCount += 1;
            }
          }
        }
      } catch (error) {
        paintError = String(error?.message || error);
      }

      return {
        src:img.currentSrc || img.src,
        complete:img.complete,
        naturalWidth:img.naturalWidth,
        naturalHeight:img.naturalHeight,
        rect:{ width:rect.width, height:rect.height, x:rect.x, y:rect.y },
        imageStyle:{
          display:imageStyle.display,
          visibility:imageStyle.visibility,
          opacity:imageStyle.opacity,
          position:imageStyle.position,
          zIndex:imageStyle.zIndex,
        },
        paintedPixelCount,
        sampledPixelCount,
        paintError,
        cardBackgroundImage:cardStyle.backgroundImage,
        legacyMgDisplay:emblem instanceof HTMLElement ? getComputedStyle(emblem).display : null,
      };
    });

    console.log('ENTRY_EFFECT_RUNTIME_DIAGNOSTIC=' + JSON.stringify(diagnostic));
    await testInfo.attach('entry-effect-runtime-diagnostic.json', {
      body:Buffer.from(JSON.stringify(diagnostic, null, 2)),
      contentType:'application/json',
    });
    await testInfo.attach('entry-effect-runtime-diagnostic.png', {
      body:await page.screenshot({ fullPage:true }),
      contentType:'image/png',
    });

    expect(diagnostic).not.toBeNull();
    expect(diagnostic.src).toContain('entry-effect-03-knight-strike.webp');
    expect(diagnostic.src).toContain('asset=reference-raster-v6');
    expect(diagnostic.complete).toBe(true);
    expect(diagnostic.naturalWidth).toBe(640);
    expect(diagnostic.naturalHeight).toBe(480);
    expect(diagnostic.rect.width).toBeGreaterThan(100);
    expect(diagnostic.rect.height).toBeGreaterThan(100);
    expect(diagnostic.imageStyle.display).toBe('block');
    expect(diagnostic.imageStyle.visibility).not.toBe('hidden');
    expect(Number(diagnostic.imageStyle.opacity || 0)).toBeGreaterThan(0);
    expect(diagnostic.paintError).toBeNull();
    expect(diagnostic.sampledPixelCount).toBeGreaterThan(100);
    expect(diagnostic.paintedPixelCount).toBeGreaterThan(100);
    expect(diagnostic.cardBackgroundImage).toBe('none');
    expect(diagnostic.legacyMgDisplay).toBe('none');
  } finally {
    await context.close().catch(() => null);
  }
});
