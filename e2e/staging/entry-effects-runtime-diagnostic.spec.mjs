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
const ART_PATH = '/app/assets/media/cosmetics/entry-effects/store-entry-03-lord-blade.svg?asset=canonical-svg-v1';

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

test('ENTRY EFFECT DIAGNOSTIC: canonical SVG is visible in Store/live presentation', async ({ browser }, testInfo) => {
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
    const assetText = assetBody.toString('utf8');
    const assetDiagnostic = {
      status: assetResponse.status(),
      contentType: assetHeaders['content-type'] || '',
      contentLengthHeader: assetHeaders['content-length'] || '',
      byteLength: assetBody.length,
      hasSvgRoot: /<svg\b/i.test(assetText),
      hasReadableTextNode: /<text\b/i.test(assetText),
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
      document.querySelectorAll('#stagingEntryEffectDiagnosticLayer,#stagingEntryEffectPreviewDiagnostic').forEach(node => node.remove());

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

      const preview = document.createElement('div');
      preview.id = 'stagingEntryEffectPreviewDiagnostic';
      preview.className = 'mgw-entry-effect-preview';
      preview.dataset.entryEffectVariant = 'entry-03';
      preview.innerHTML = '<span class="mgw-entry-effect-preview-core"><i></i><b>MG</b><i></i></span>';
      Object.assign(preview.style, { position:'fixed', left:'8px', bottom:'8px', width:'120px', height:'90px', zIndex:'2147483300' });
      document.body.append(preview);
    });

    await page.waitForFunction(() => {
      const card = document.querySelector('#stagingEntryEffectDiagnosticLayer .mgw-entry-effect-live-card');
      const preview = document.querySelector('#stagingEntryEffectPreviewDiagnostic');
      return card instanceof HTMLElement
        && preview instanceof HTMLElement
        && getComputedStyle(card).backgroundImage.includes('store-entry-03-lord-blade.svg')
        && getComputedStyle(preview).backgroundImage.includes('store-entry-03-lord-blade.svg');
    }, null, { timeout: 10_000 });

    const diagnostic = await page.evaluate(async (artUrl) => {
      const card = document.querySelector('#stagingEntryEffectDiagnosticLayer .mgw-entry-effect-live-card');
      const preview = document.querySelector('#stagingEntryEffectPreviewDiagnostic');
      const emblem = card?.querySelector('.mgw-entry-effect-live-emblem');
      const oldImg = document.querySelector('#stagingEntryEffectDiagnosticLayer .mgw-entry-effect-live-art');
      const previewCore = preview?.querySelector('.mgw-entry-effect-preview-core b');

      const image = new Image();
      image.decoding = 'async';
      image.src = artUrl;
      try { await image.decode(); } catch {}

      let pixels = null;
      if (image.naturalWidth > 0 && image.naturalHeight > 0) {
        const canvas = document.createElement('canvas');
        canvas.width = 64;
        canvas.height = 64;
        const ctx = canvas.getContext('2d', { willReadFrequently:true });
        if (ctx) {
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
          pixels = {
            meanAlpha: alphaSum / (64 * 64),
            nonTransparentFraction: nonTransparent / (64 * 64),
            meanRgb: mean,
            rgbStdDev: samples ? Math.sqrt(Math.max(0, rgbSqSum / samples - mean * mean)) : 0,
          };
        }
      }

      const cardStyle = card instanceof HTMLElement ? getComputedStyle(card) : null;
      const previewStyle = preview instanceof HTMLElement ? getComputedStyle(preview) : null;
      const cardRect = card instanceof HTMLElement ? card.getBoundingClientRect() : null;
      const previewRect = preview instanceof HTMLElement ? preview.getBoundingClientRect() : null;

      return {
        art:{
          complete:image.complete,
          naturalWidth:image.naturalWidth,
          naturalHeight:image.naturalHeight,
          pixels,
        },
        card:cardStyle && cardRect ? {
          backgroundImage:cardStyle.backgroundImage,
          display:cardStyle.display,
          visibility:cardStyle.visibility,
          opacity:cardStyle.opacity,
          width:cardRect.width,
          height:cardRect.height,
        } : null,
        preview:previewStyle && previewRect ? {
          backgroundImage:previewStyle.backgroundImage,
          display:previewStyle.display,
          visibility:previewStyle.visibility,
          opacity:previewStyle.opacity,
          width:previewRect.width,
          height:previewRect.height,
        } : null,
        legacyMgDisplay:emblem instanceof HTMLElement ? getComputedStyle(emblem).display : null,
        previewMgOpacity:previewCore instanceof HTMLElement ? getComputedStyle(previewCore).opacity : null,
        oldWebpSiblingDisplay:oldImg instanceof HTMLElement ? getComputedStyle(oldImg).display : 'absent',
      };
    }, `${ORIGIN}${ART_PATH}`);

    const evidence = { asset:assetDiagnostic, presentation:diagnostic };
    console.log('ENTRY_EFFECT_RUNTIME_DIAGNOSTIC=' + JSON.stringify(evidence));
    await testInfo.attach('entry-effect-runtime-diagnostic.json', {
      body: Buffer.from(JSON.stringify(evidence, null, 2)),
      contentType: 'application/json',
    });
    await testInfo.attach('entry-effect-runtime-diagnostic.png', {
      body: await page.screenshot({ fullPage:true }),
      contentType: 'image/png',
    });

    expect(assetDiagnostic.status).toBe(200);
    expect(assetDiagnostic.contentType).toContain('image/svg+xml');
    expect(assetDiagnostic.byteLength).toBeGreaterThan(1000);
    expect(assetDiagnostic.hasSvgRoot).toBe(true);
    expect(assetDiagnostic.hasReadableTextNode).toBe(false);

    expect(diagnostic.art.complete).toBe(true);
    expect(diagnostic.art.naturalWidth).toBeGreaterThan(0);
    expect(diagnostic.art.naturalHeight).toBeGreaterThan(0);
    expect(diagnostic.art.pixels?.nonTransparentFraction || 0).toBeGreaterThan(0.05);
    expect(diagnostic.art.pixels?.rgbStdDev || 0).toBeGreaterThan(5);

    expect(diagnostic.card?.backgroundImage || '').toContain('store-entry-03-lord-blade.svg');
    expect(diagnostic.card?.width || 0).toBeGreaterThan(100);
    expect(diagnostic.card?.height || 0).toBeGreaterThan(100);
    expect(Number(diagnostic.card?.opacity || 0)).toBeGreaterThan(0);
    expect(diagnostic.card?.display).not.toBe('none');
    expect(diagnostic.card?.visibility).not.toBe('hidden');

    expect(diagnostic.preview?.backgroundImage || '').toContain('store-entry-03-lord-blade.svg');
    expect(diagnostic.preview?.width || 0).toBeGreaterThan(80);
    expect(diagnostic.preview?.height || 0).toBeGreaterThan(60);
    expect(diagnostic.legacyMgDisplay).toBe('none');
    expect(Number(diagnostic.previewMgOpacity || 1)).toBe(0);
    expect(['none','absent']).toContain(diagnostic.oldWebpSiblingDisplay);
  } finally {
    await context.close().catch(() => null);
  }
});
