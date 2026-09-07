import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';

const ORIGIN = process.env.MGW_STAGING_ORIGIN || 'https://seashell-okapi-889488.hostingersite.com';
const AUTH_URL = `${ORIGIN}/bot/staging-test-auth.php`;
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const launchSource = readFileSync(resolve(repoRoot, 'bot/helpers/WebAppLaunchUrl.php'), 'utf8');
const entryMatch = launchSource.match(/^\s*private const ENTRY_PATH = '([^']+)';/m);
if (!entryMatch) throw new Error('Canonical WebAppLaunchUrl ENTRY_PATH is unavailable.');
const ENTRY_URL = `${ORIGIN}${entryMatch[1]}`;
const ART_PATH = '/app/assets/media/cosmetics/entry-effects/entry-effect-03-knight-strike.webp?asset=live-img-v1';

async function requestOidcToken() {
  const source = process.env.ACTIONS_ID_TOKEN_REQUEST_URL || '';
  const bearer = process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN || '';
  if (!source || !bearer) throw new Error('GitHub Actions OIDC environment is unavailable.');
  const url = new URL(source);
  url.searchParams.set('audience', OIDC_AUDIENCE);
  const response = await fetch(url, {
    headers: { Authorization: `bearer ${bearer}`, Accept: 'application/json' },
  });
  if (!response.ok) throw new Error(`OIDC request failed: ${response.status}`);
  const payload = await response.json();
  if (typeof payload?.value !== 'string') throw new Error('OIDC JWT is unavailable.');
  return payload.value;
}

async function authorize(context) {
  const response = await context.request.post(AUTH_URL, {
    headers: {
      Authorization: `Bearer ${await requestOidcToken()}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    data: { action: 'issue', slot: 'A' },
    timeout: 35_000,
  });
  expect(response.status()).toBe(200);
  const payload = await response.json();
  expect(payload?.ok).toBe(true);
}

function requestAction(request) {
  try { return String(request.postDataJSON()?.action || ''); } catch { return ''; }
}

test('ENTRY EFFECT DIAGNOSTIC: real img load, pixels and computed geometry', async ({ browser }, testInfo) => {
  const context = await browser.newContext({
    locale: 'ru-RU',
    timezoneId: 'Europe/Vilnius',
    viewport: { width: 390, height: 844 },
    isMobile: true,
    hasTouch: true,
  });

  try {
    await authorize(context);

    const assetResponse = await context.request.get(`${ORIGIN}${ART_PATH}`, { timeout: 35_000 });
    const assetBody = await assetResponse.body();
    const assetHeaders = assetResponse.headers();
    const header = assetBody.subarray(0, 12);
    const assetDiagnostic = {
      status: assetResponse.status(),
      contentType: assetHeaders['content-type'] || '',
      contentLengthHeader: assetHeaders['content-length'] || '',
      byteLength: assetBody.length,
      riff: header.subarray(0, 4).toString('ascii'),
      webp: header.subarray(8, 12).toString('ascii'),
    };

    const page = await context.newPage();
    const bootstrapPromise = page.waitForResponse(response => (
      response.url() === `${ORIGIN}/bot/api.php`
      && response.request().method() === 'POST'
      && requestAction(response.request()) === 'bootstrap'
    ), { timeout: 35_000 });

    const entry = await page.goto(ENTRY_URL, { waitUntil: 'domcontentloaded' });
    expect(entry?.ok()).toBe(true);
    expect((await bootstrapPromise).status()).toBe(200);
    await page.waitForFunction(() => window.__MGW_APP_BOOTSTRAP_V2__?.ready === true, null, { timeout: 20_000 });

    await page.evaluate(() => {
      document.querySelectorAll('.mgw-entry-effect-layer').forEach(node => node.remove());
      const layer = document.createElement('div');
      layer.className = 'mgw-entry-effect-layer';
      layer.id = 'stagingEntryEffectDiagnosticLayer';
      layer.innerHTML = `<button class="mgw-entry-effect-skip" type="button">Пропустить</button><div class="mgw-entry-effect-live-grid">
        <div class="mgw-entry-effect-live-card" data-entry-effect-variant="entry-03" data-player-index="0">
          <div class="mgw-entry-effect-live-emblem"><i></i><b>MG</b><i></i></div>
          <strong>Runtime diagnostic</strong><small>вступает в игру</small>
        </div>
      </div>`;
      document.body.append(layer);
    });

    const image = page.locator('#stagingEntryEffectDiagnosticLayer .mgw-entry-effect-live-art');
    await expect(image).toHaveCount(1, { timeout: 5_000 });
    await page.waitForFunction(() => {
      const img = document.querySelector('#stagingEntryEffectDiagnosticLayer .mgw-entry-effect-live-art');
      return img instanceof HTMLImageElement && img.complete;
    }, null, { timeout: 10_000 });

    const domDiagnostic = await page.evaluate(async () => {
      const img = document.querySelector('#stagingEntryEffectDiagnosticLayer .mgw-entry-effect-live-art');
      if (!(img instanceof HTMLImageElement)) return { exists:false };
      try { await img.decode(); } catch {}
      const style = getComputedStyle(img);
      const rect = img.getBoundingClientRect();
      const cx = Math.max(0, Math.min(innerWidth - 1, rect.left + rect.width / 2));
      const cy = Math.max(0, Math.min(innerHeight - 1, rect.top + rect.height / 2));
      const top = document.elementFromPoint(cx, cy);

      let pixels = null;
      if (img.naturalWidth > 0 && img.naturalHeight > 0) {
        const canvas = document.createElement('canvas');
        canvas.width = 64;
        canvas.height = 64;
        const ctx = canvas.getContext('2d', { willReadFrequently:true });
        if (ctx) {
          ctx.clearRect(0, 0, 64, 64);
          ctx.drawImage(img, 0, 0, 64, 64);
          const data = ctx.getImageData(0, 0, 64, 64).data;
          let alphaSum = 0;
          let nonTransparent = 0;
          let rgbSum = 0;
          let rgbSqSum = 0;
          let samples = 0;
          for (let i = 0; i < data.length; i += 4) {
            const a = data[i + 3];
            alphaSum += a;
            if (a > 8) nonTransparent += 1;
            if (a > 8) {
              const lum = (data[i] + data[i + 1] + data[i + 2]) / 3;
              rgbSum += lum;
              rgbSqSum += lum * lum;
              samples += 1;
            }
          }
          const mean = samples ? rgbSum / samples : 0;
          pixels = {
            meanAlpha: alphaSum / (64 * 64),
            nonTransparentFraction: nonTransparent / (64 * 64),
            meanRgb: mean,
            rgbStdDev: samples ? Math.sqrt(Math.max(0, rgbSqSum / samples - mean * mean)) : 0,
          };
        }
      }

      return {
        exists:true,
        connected:img.isConnected,
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
          position:style.position,
          zIndex:style.zIndex,
          transform:style.transform,
          filter:style.filter,
          objectFit:style.objectFit,
        },
        topElementAtCenter:top ? `${top.tagName}.${top.className || ''}` : '',
        parentClass:img.parentElement?.className || '',
        pixels,
      };
    });

    const diagnostic = { asset:assetDiagnostic, dom:domDiagnostic };
    console.log('ENTRY_EFFECT_RUNTIME_DIAGNOSTIC=' + JSON.stringify(diagnostic));
    await testInfo.attach('entry-effect-runtime-diagnostic.json', {
      body: Buffer.from(JSON.stringify(diagnostic, null, 2)),
      contentType: 'application/json',
    });
    await testInfo.attach('entry-effect-runtime-diagnostic.png', {
      body: await page.screenshot({ fullPage:true }),
      contentType: 'image/png',
    });

    expect(assetDiagnostic.status).toBe(200);
    expect(assetDiagnostic.contentType).toContain('image/webp');
    expect(assetDiagnostic.riff).toBe('RIFF');
    expect(assetDiagnostic.webp).toBe('WEBP');
    expect(domDiagnostic.exists).toBe(true);
    expect(domDiagnostic.complete).toBe(true);
    expect(domDiagnostic.naturalWidth).toBeGreaterThan(0);
    expect(domDiagnostic.naturalHeight).toBeGreaterThan(0);
    expect(domDiagnostic.rect?.width || 0).toBeGreaterThan(100);
    expect(domDiagnostic.rect?.height || 0).toBeGreaterThan(100);
    expect(Number(domDiagnostic.style?.opacity || 0)).toBeGreaterThan(0);
    expect(domDiagnostic.style?.display).not.toBe('none');
    expect(domDiagnostic.style?.visibility).not.toBe('hidden');
    expect(domDiagnostic.pixels?.nonTransparentFraction || 0).toBeGreaterThan(0.05);
  } finally {
    await context.close().catch(() => null);
  }
});
