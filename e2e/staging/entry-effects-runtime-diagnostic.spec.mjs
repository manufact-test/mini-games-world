import { test, expect } from '@playwright/test';

const ORIGIN = process.env.MGW_STAGING_ORIGIN || 'https://seashell-okapi-889488.hostingersite.com';

const EFFECTS = [
  {
    id: 'entry-01',
    modulePath: '/app/assets/js/entry-effects/mgw-entry-effects-v8-legendary-strike.js?v=2',
    scene: '.mgw-entry-v8-legendary-strike',
    ownerAttr: 'data-entry-v8-legendary-strike',
    art: '.mgw-entry-v8-arena',
    artFile: 'entry-03-legendary-strike-arena.webp',
    artToken: 'asset=entry-v8-ls-1',
  },
  {
    id: 'entry-02',
    modulePath: '/app/assets/js/entry-effects/mgw-entry-effects-v8-royal-ascension.js?v=4',
    scene: '.mgw-entry-v8-royal-ascension',
    ownerAttr: 'data-entry-v8-royal-ascension',
    art: '.mgw-entry-v8-ra-guardian',
    artFile: 'entry-02-royal-ascension-guardian.webp',
    artToken: 'asset=royal-ascension-guardian-v2',
  },
  {
    id: 'entry-03',
    modulePath: '/app/assets/js/entry-effects/mgw-entry-effects-v8-lord-entrance.js?v=6',
    scene: '.mgw-entry-v8-lord-entrance',
    ownerAttr: 'data-entry-v8-lord-entrance',
    art: '.mgw-entry-v8-le-body',
    artFile: 'entry-03-lord-entrance-body.webp',
    artToken: 'asset=lord-entrance-open-face-raster-v5',
  },
];

for (const effect of EFFECTS) {
  test(`ENTRY EFFECT DIAGNOSTIC: deployed ${effect.id} mounts accepted V8 owner`, async ({ browser }, testInfo) => {
    const context = await browser.newContext({
      locale: 'ru-RU',
      timezoneId: 'Europe/Vilnius',
      viewport: { width: 390, height: 844 },
      isMobile: true,
      hasTouch: true,
    });

    try {
      const moduleResponse = await context.request.get(`${ORIGIN}${effect.modulePath}`, { timeout: 35_000 });
      expect(moduleResponse.status()).toBe(200);
      expect((await moduleResponse.text()).length).toBeGreaterThan(1000);

      const page = await context.newPage();
      await page.goto(`${ORIGIN}/app/__entry_effect_runtime_diagnostic_origin__.html`, {
        waitUntil: 'domcontentloaded',
        timeout: 35_000,
      });
      await page.setContent(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;background:#050609">
        <div class="mgw-entry-effect-layer" id="entryDiagnosticLayer" style="position:fixed;inset:0;display:grid;place-items:center;overflow:hidden">
          <img class="mgw-entry-effect-live-art" data-entry-effect-variant="${effect.id}" alt="" style="display:block;width:240px;height:240px">
          <div class="mgw-entry-effect-live-fx" data-entry-effect-variant="${effect.id}"></div>
          <div class="mgw-entry-effect-live-grid">
            <div class="mgw-entry-effect-live-card" data-entry-effect-variant="${effect.id}" data-player-index="0">
              <strong>Runtime diagnostic</strong><small>вступает в игру</small>
            </div>
          </div>
        </div>
      </body></html>`, { waitUntil: 'load' });

      await page.evaluate(async ({ modulePath }) => {
        await import(modulePath);
      }, { modulePath: effect.modulePath });

      const layer = page.locator('#entryDiagnosticLayer');
      await expect(layer).toHaveAttribute(effect.ownerAttr, '1', { timeout: 20_000 });
      const scene = page.locator(`#entryDiagnosticLayer ${effect.scene}`);
      await expect(scene).toHaveCount(1);

      const genericArt = page.locator('#entryDiagnosticLayer > .mgw-entry-effect-live-art');
      await expect(genericArt).toHaveCSS('display', 'none');

      const art = page.locator(`#entryDiagnosticLayer ${effect.art}`);
      await expect(art).toHaveCount(1);
      await page.waitForFunction(({ selector }) => {
        const image = document.querySelector(selector);
        return image instanceof HTMLImageElement && image.complete && image.naturalWidth > 0 && image.naturalHeight > 0;
      }, { selector: `#entryDiagnosticLayer ${effect.art}` }, { timeout: 20_000 });

      const diagnostic = await page.evaluate(({ sceneSelector, artSelector }) => {
        const scene = document.querySelector(sceneSelector);
        const art = document.querySelector(artSelector);
        if (!(scene instanceof HTMLElement) || !(art instanceof HTMLImageElement)) return null;
        const sceneRect = scene.getBoundingClientRect();
        const style = getComputedStyle(scene);
        return {
          sceneRect: { width: sceneRect.width, height: sceneRect.height },
          sceneDisplay: style.display,
          sceneVisibility: style.visibility,
          src: art.currentSrc || art.src,
          complete: art.complete,
          naturalWidth: art.naturalWidth,
          naturalHeight: art.naturalHeight,
          genericDisplay: getComputedStyle(document.querySelector('#entryDiagnosticLayer > .mgw-entry-effect-live-art')).display,
        };
      }, {
        sceneSelector: `#entryDiagnosticLayer ${effect.scene}`,
        artSelector: `#entryDiagnosticLayer ${effect.art}`,
      });

      console.log(`ENTRY_EFFECT_RUNTIME_DIAGNOSTIC_${effect.id}=` + JSON.stringify(diagnostic));
      await testInfo.attach(`entry-effect-runtime-diagnostic-${effect.id}.json`, {
        body: Buffer.from(JSON.stringify(diagnostic, null, 2)),
        contentType: 'application/json',
      });
      await testInfo.attach(`entry-effect-runtime-diagnostic-${effect.id}.png`, {
        body: await page.screenshot({ fullPage: true }),
        contentType: 'image/png',
      });

      expect(diagnostic).not.toBeNull();
      expect(diagnostic.src).toContain(effect.artFile);
      expect(diagnostic.src).toContain(effect.artToken);
      expect(diagnostic.complete).toBe(true);
      expect(diagnostic.naturalWidth).toBeGreaterThanOrEqual(320);
      expect(diagnostic.naturalHeight).toBeGreaterThanOrEqual(320);
      expect(diagnostic.sceneRect.width).toBeGreaterThan(100);
      expect(diagnostic.sceneRect.height).toBeGreaterThan(100);
      expect(diagnostic.sceneDisplay).not.toBe('none');
      expect(diagnostic.sceneVisibility).not.toBe('hidden');
      expect(diagnostic.genericDisplay).toBe('none');
    } finally {
      await context.close().catch(() => null);
    }
  });
}
