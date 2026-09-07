import { test, expect } from '@playwright/test';

const ORIGIN = process.env.MGW_STAGING_ORIGIN || 'https://seashell-okapi-889488.hostingersite.com';
const ARTS = Object.freeze([
  { variant:'entry-01', file:'store-entry-01-celestial-gate.svg' },
  { variant:'entry-02', file:'store-entry-02-king-ascension.svg' },
  { variant:'entry-03', file:'store-entry-03-lord-blade.svg' },
]);

const artPath = file => `/app/assets/media/cosmetics/entry-effects/${file}?asset=canonical-svg-v1`;

test('ENTRY EFFECT DIAGNOSTIC: deployed canonical SVG art owns Store/live presentation', async ({ browser }, testInfo) => {
  const context = await browser.newContext({
    locale: 'ru-RU',
    timezoneId: 'Europe/Vilnius',
    viewport: { width: 390, height: 844 },
    isMobile: true,
    hasTouch: true,
  });

  try {
    const assetDiagnostics = [];
    for (const art of ARTS) {
      const response = await context.request.get(`${ORIGIN}${artPath(art.file)}`, { timeout: 35_000 });
      const body = await response.body();
      const text = body.toString('utf8');
      const diagnostic = {
        variant:art.variant,
        file:art.file,
        status:response.status(),
        contentType:response.headers()['content-type'] || '',
        byteLength:body.length,
        hasSvgRoot:/<svg\b/i.test(text),
        hasReadableTextNode:/<text\b/i.test(text),
      };
      assetDiagnostics.push(diagnostic);
      expect(diagnostic.status).toBe(200);
      expect(diagnostic.contentType).toContain('image/svg+xml');
      expect(diagnostic.byteLength).toBeGreaterThan(1000);
      expect(diagnostic.hasSvgRoot).toBe(true);
      expect(diagnostic.hasReadableTextNode).toBe(false);
    }

    const page = await context.newPage();
    const anchor = await page.goto(`${ORIGIN}${artPath(ARTS[2].file)}`, { waitUntil:'domcontentloaded', timeout:35_000 });
    expect(anchor?.ok()).toBe(true);

    const cssResponse = page.waitForResponse(response => (
      response.url().includes('/app/assets/css/production-v106-store-avatar-frame-density.css')
      && response.request().method() === 'GET'
    ), { timeout:20_000 });

    await page.setContent(`<!doctype html><html><head>
      <meta charset="utf-8">
      <link rel="stylesheet" href="/app/assets/css/production-v106-store-avatar-frame-density.css?diag=canonical-svg-v1">
      <style>body{margin:0;min-height:844px;background:#090b11}.diag-previews{position:fixed;left:8px;bottom:8px;display:flex;gap:8px;z-index:2147483400}.diag-preview{position:relative;width:112px;height:84px}</style>
      </head><body>
      <div id="stagingEntryEffectDiagnosticLayer" class="mgw-entry-effect-layer">
        <button class="mgw-entry-effect-skip" type="button">Пропустить</button>
        <div class="mgw-entry-effect-live-grid">
          ${ARTS.map((art, index) => `<div class="mgw-entry-effect-live-card" data-entry-effect-variant="${art.variant}" data-player-index="${index}"><div class="mgw-entry-effect-live-emblem"><i></i><b>MG</b><i></i></div><strong>${art.variant}</strong><small>вступает в игру</small></div>`).join('')}
        </div>
        <img id="obsoleteEntryWebpSibling" class="mgw-entry-effect-live-art" alt="" src="/app/assets/media/cosmetics/entry-effects/entry-effect-03-knight-strike.webp?asset=live-img-v1">
      </div>
      <div class="diag-previews">
        ${ARTS.map(art => `<div class="diag-preview mgw-entry-effect-preview" data-entry-effect-variant="${art.variant}"><span class="mgw-entry-effect-preview-core"><i></i><b>MG</b><i></i></span></div>`).join('')}
      </div>
      </body></html>`, { waitUntil:'domcontentloaded' });

    expect((await cssResponse).status()).toBe(200);

    await page.waitForFunction((arts) => arts.every(({variant,file}) => {
      const live = document.querySelector(`.mgw-entry-effect-live-card[data-entry-effect-variant="${variant}"]`);
      const preview = document.querySelector(`.mgw-entry-effect-preview[data-entry-effect-variant="${variant}"]`);
      return live instanceof HTMLElement
        && preview instanceof HTMLElement
        && getComputedStyle(live).backgroundImage.includes(file)
        && getComputedStyle(preview).backgroundImage.includes(file);
    }), ARTS, { timeout:10_000 });

    const presentation = await page.evaluate(async (arts) => {
      async function pixelStats(url) {
        const image = new Image();
        image.decoding = 'async';
        image.src = url;
        try { await image.decode(); } catch {}
        if (!image.complete || image.naturalWidth <= 0 || image.naturalHeight <= 0) {
          return { complete:image.complete, naturalWidth:image.naturalWidth, naturalHeight:image.naturalHeight, pixels:null };
        }
        const canvas = document.createElement('canvas');
        canvas.width = 64;
        canvas.height = 64;
        const ctx = canvas.getContext('2d', { willReadFrequently:true });
        if (!ctx) return { complete:true, naturalWidth:image.naturalWidth, naturalHeight:image.naturalHeight, pixels:null };
        ctx.clearRect(0, 0, 64, 64);
        ctx.drawImage(image, 0, 0, 64, 64);
        const data = ctx.getImageData(0, 0, 64, 64).data;
        let alphaSum = 0;
        let nonTransparent = 0;
        let rgbSum = 0;
        let rgbSqSum = 0;
        let samples = 0;
        for (let i = 0; i < data.length; i += 4) {
          const a = data[i + 3];
          alphaSum += a;
          if (a > 8) {
            nonTransparent += 1;
            const lum = (data[i] + data[i + 1] + data[i + 2]) / 3;
            rgbSum += lum;
            rgbSqSum += lum * lum;
            samples += 1;
          }
        }
        const mean = samples ? rgbSum / samples : 0;
        return {
          complete:true,
          naturalWidth:image.naturalWidth,
          naturalHeight:image.naturalHeight,
          pixels:{
            meanAlpha:alphaSum / (64 * 64),
            nonTransparentFraction:nonTransparent / (64 * 64),
            meanRgb:mean,
            rgbStdDev:samples ? Math.sqrt(Math.max(0, rgbSqSum / samples - mean * mean)) : 0,
          },
        };
      }

      const rows = [];
      for (const art of arts) {
        const live = document.querySelector(`.mgw-entry-effect-live-card[data-entry-effect-variant="${art.variant}"]`);
        const preview = document.querySelector(`.mgw-entry-effect-preview[data-entry-effect-variant="${art.variant}"]`);
        const emblem = live?.querySelector('.mgw-entry-effect-live-emblem');
        const previewMg = preview?.querySelector('.mgw-entry-effect-preview-core b');
        const liveStyle = live instanceof HTMLElement ? getComputedStyle(live) : null;
        const previewStyle = preview instanceof HTMLElement ? getComputedStyle(preview) : null;
        rows.push({
          variant:art.variant,
          file:art.file,
          art:await pixelStats(`${location.origin}/app/assets/media/cosmetics/entry-effects/${art.file}?asset=canonical-svg-v1`),
          live:liveStyle ? { backgroundImage:liveStyle.backgroundImage, display:liveStyle.display, visibility:liveStyle.visibility, opacity:liveStyle.opacity } : null,
          preview:previewStyle ? { backgroundImage:previewStyle.backgroundImage, display:previewStyle.display, visibility:previewStyle.visibility, opacity:previewStyle.opacity } : null,
          legacyMgDisplay:emblem instanceof HTMLElement ? getComputedStyle(emblem).display : null,
          previewMgOpacity:previewMg instanceof HTMLElement ? getComputedStyle(previewMg).opacity : null,
        });
      }
      const obsolete = document.getElementById('obsoleteEntryWebpSibling');
      return {
        rows,
        obsoleteWebpDisplay:obsolete instanceof HTMLElement ? getComputedStyle(obsolete).display : 'absent',
      };
    }, ARTS);

    for (const row of presentation.rows) {
      expect(row.art.complete).toBe(true);
      expect(row.art.naturalWidth).toBeGreaterThan(0);
      expect(row.art.naturalHeight).toBeGreaterThan(0);
      expect(row.art.pixels?.nonTransparentFraction || 0).toBeGreaterThan(0.05);
      expect(row.art.pixels?.rgbStdDev || 0).toBeGreaterThan(5);
      expect(row.live?.backgroundImage || '').toContain(row.file);
      expect(row.live?.display).not.toBe('none');
      expect(row.live?.visibility).not.toBe('hidden');
      expect(Number(row.live?.opacity || 0)).toBeGreaterThan(0);
      expect(row.preview?.backgroundImage || '').toContain(row.file);
      expect(row.preview?.display).not.toBe('none');
      expect(row.preview?.visibility).not.toBe('hidden');
      expect(Number(row.preview?.opacity || 0)).toBeGreaterThan(0);
      expect(row.legacyMgDisplay).toBe('none');
      expect(Number(row.previewMgOpacity || 1)).toBe(0);
    }
    expect(presentation.obsoleteWebpDisplay).toBe('none');

    const evidence = { assets:assetDiagnostics, presentation };
    console.log('ENTRY_EFFECT_RUNTIME_DIAGNOSTIC=' + JSON.stringify(evidence));
    await testInfo.attach('entry-effect-runtime-diagnostic.json', {
      body:Buffer.from(JSON.stringify(evidence, null, 2)),
      contentType:'application/json',
    });
    await testInfo.attach('entry-effect-runtime-diagnostic.png', {
      body:await page.screenshot({ fullPage:true }),
      contentType:'image/png',
    });
  } finally {
    await context.close().catch(() => null);
  }
});
