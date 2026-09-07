import { test, expect } from '@playwright/test';

const ORIGIN = process.env.MGW_STAGING_ORIGIN || 'https://seashell-okapi-889488.hostingersite.com';
const ASSETS = Object.freeze([
  ['entry-01', '/app/assets/media/cosmetics/entry-effects/entry-effect-01-celestial-gate.webp?asset=live-img-v1'],
  ['entry-02', '/app/assets/media/cosmetics/entry-effects/entry-effect-02-portal-knight.webp?asset=live-img-v1'],
  ['entry-03', '/app/assets/media/cosmetics/entry-effects/entry-effect-03-knight-strike.webp?asset=live-img-v1'],
]);

async function inspectAsset(context, browser, label, path, testInfo) {
  const url = `${ORIGIN}${path}`;
  const response = await context.request.get(url, { timeout: 35_000 });
  const body = await response.body();
  const headers = response.headers();
  const header = body.subarray(0, 12);

  const network = {
    label,
    url,
    status: response.status(),
    contentType: headers['content-type'] || '',
    contentLengthHeader: headers['content-length'] || '',
    cacheControl: headers['cache-control'] || '',
    byteLength: body.length,
    riff: header.subarray(0, 4).toString('ascii'),
    webp: header.subarray(8, 12).toString('ascii'),
  };

  const imageContext = await browser.newContext({
    viewport: { width: 390, height: 844 },
    isMobile: true,
    hasTouch: true,
  });
  const page = await imageContext.newPage();

  try {
    const navigation = await page.goto(url, { waitUntil: 'load', timeout: 35_000 });
    const browserStatus = navigation?.status() || 0;
    await page.waitForFunction(() => {
      const img = document.querySelector('img');
      return img instanceof HTMLImageElement && img.complete;
    }, null, { timeout: 10_000 });

    const browserImage = await page.evaluate(async () => {
      const img = document.querySelector('img');
      if (!(img instanceof HTMLImageElement)) return { exists:false };
      try { await img.decode(); } catch {}
      const style = getComputedStyle(img);
      const rect = img.getBoundingClientRect();

      const canvas = document.createElement('canvas');
      canvas.width = 96;
      canvas.height = 72;
      const ctx = canvas.getContext('2d', { willReadFrequently:true });
      let pixels = null;
      if (ctx && img.naturalWidth > 0 && img.naturalHeight > 0) {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        const data = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
        let alphaSum = 0;
        let nonTransparent = 0;
        let opaque = 0;
        let rgbSum = 0;
        let rgbSqSum = 0;
        let coloredSamples = 0;
        for (let i = 0; i < data.length; i += 4) {
          const a = data[i + 3];
          alphaSum += a;
          if (a > 8) nonTransparent += 1;
          if (a > 240) opaque += 1;
          if (a > 8) {
            const lum = (data[i] + data[i + 1] + data[i + 2]) / 3;
            rgbSum += lum;
            rgbSqSum += lum * lum;
            coloredSamples += 1;
          }
        }
        const total = canvas.width * canvas.height;
        const mean = coloredSamples ? rgbSum / coloredSamples : 0;
        pixels = {
          meanAlpha: alphaSum / total,
          nonTransparentFraction: nonTransparent / total,
          opaqueFraction: opaque / total,
          meanRgb: mean,
          rgbStdDev: coloredSamples
            ? Math.sqrt(Math.max(0, rgbSqSum / coloredSamples - mean * mean))
            : 0,
        };
      }

      return {
        exists:true,
        complete:img.complete,
        src:img.src,
        currentSrc:img.currentSrc,
        naturalWidth:img.naturalWidth,
        naturalHeight:img.naturalHeight,
        rect:{ x:rect.x, y:rect.y, width:rect.width, height:rect.height },
        style:{
          display:style.display,
          visibility:style.visibility,
          opacity:style.opacity,
          objectFit:style.objectFit,
        },
        pixels,
      };
    });

    const screenshot = await page.screenshot({ fullPage:true });
    await testInfo.attach(`${label}.png`, { body:screenshot, contentType:'image/png' });

    return { network, browserStatus, browserImage };
  } finally {
    await imageContext.close().catch(() => null);
  }
}

test('ENTRY EFFECT ASSET DIAGNOSTIC: Hostinger serves visible WebP pixels', async ({ browser }, testInfo) => {
  const context = await browser.newContext();
  try {
    const diagnostics = [];
    for (const [label, path] of ASSETS) {
      diagnostics.push(await inspectAsset(context, browser, label, path, testInfo));
    }

    console.log('ENTRY_EFFECT_ASSET_DIAGNOSTIC=' + JSON.stringify(diagnostics));
    await testInfo.attach('entry-effect-asset-diagnostic.json', {
      body:Buffer.from(JSON.stringify(diagnostics, null, 2)),
      contentType:'application/json',
    });

    for (const diagnostic of diagnostics) {
      expect(diagnostic.network.status, `${diagnostic.network.label} HTTP`).toBe(200);
      expect(diagnostic.network.contentType, `${diagnostic.network.label} content-type`).toContain('image/webp');
      expect(diagnostic.network.riff, `${diagnostic.network.label} RIFF`).toBe('RIFF');
      expect(diagnostic.network.webp, `${diagnostic.network.label} WEBP`).toBe('WEBP');
      expect(diagnostic.browserStatus, `${diagnostic.network.label} browser HTTP`).toBe(200);
      expect(diagnostic.browserImage.exists, `${diagnostic.network.label} browser img`).toBe(true);
      expect(diagnostic.browserImage.complete, `${diagnostic.network.label} complete`).toBe(true);
      expect(diagnostic.browserImage.naturalWidth, `${diagnostic.network.label} naturalWidth`).toBeGreaterThan(0);
      expect(diagnostic.browserImage.naturalHeight, `${diagnostic.network.label} naturalHeight`).toBeGreaterThan(0);
      expect(diagnostic.browserImage.pixels?.nonTransparentFraction || 0, `${diagnostic.network.label} visible alpha`).toBeGreaterThan(0.05);
      expect(diagnostic.browserImage.pixels?.rgbStdDev || 0, `${diagnostic.network.label} visual detail`).toBeGreaterThan(4);
    }
  } finally {
    await context.close().catch(() => null);
  }
});
