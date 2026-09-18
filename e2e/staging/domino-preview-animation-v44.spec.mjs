import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';

const ORIGIN = process.env.MGW_STAGING_ORIGIN || 'https://seashell-okapi-889488.hostingersite.com';
const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const launchSource = readFileSync(resolve(repoRoot, 'bot/helpers/WebAppLaunchUrl.php'), 'utf8');
const entryMatch = launchSource.match(/^\s*private const ENTRY_PATH = '([^']+)';/m);
if (!entryMatch) throw new Error('Canonical WebAppLaunchUrl ENTRY_PATH is unavailable.');
const ENTRY_URL = `${ORIGIN}${entryMatch[1]}`;

async function probePreviewMotion(page) {
  const response = await page.goto(ENTRY_URL, { waitUntil:'domcontentloaded' });
  expect(response?.ok()).toBe(true);

  return await page.evaluate(async () => {
    const { dominoPreviewMarkup } = await import('./assets/js/screens/store-screen-domino-store-v1.js?v=14&mvp19_9=domino-svg-pips-v48');

    const waitForSheet = async selector => {
      const link = document.querySelector(selector);
      if (!(link instanceof HTMLLinkElement)) return false;
      if (link.sheet) return true;
      return await new Promise(resolve => {
        const timer = setTimeout(() => resolve(Boolean(link.sheet)), 5000);
        link.addEventListener('load', () => { clearTimeout(timer); resolve(true); }, { once:true });
        link.addEventListener('error', () => { clearTimeout(timer); resolve(false); }, { once:true });
      });
    };

    let profileCss = document.querySelector('link[data-mgw-preview-v44-profile-test]');
    if (!(profileCss instanceof HTMLLinkElement)) {
      profileCss = document.createElement('link');
      profileCss.rel = 'stylesheet';
      profileCss.dataset.mgwPreviewV44ProfileTest = '1';
      profileCss.href = './assets/css/screens/profile-domino-store-parity-v1.css?v=4&mvp19_9=domino-profile-animation-parity-v44';
      document.head.appendChild(profileCss);
    }

    const componentLoaded = await waitForSheet('link[data-mgw-domino-preview-component-v44]');
    if (!componentLoaded) throw new Error('v44 preview component stylesheet did not load.');
    if (!profileCss.sheet) {
      await new Promise(resolve => {
        const timer = setTimeout(resolve, 5000);
        profileCss.addEventListener('load', () => { clearTimeout(timer); resolve(); }, { once:true });
        profileCss.addEventListener('error', () => { clearTimeout(timer); resolve(); }, { once:true });
      });
    }

    const root = document.createElement('div');
    root.id = 'mgw-domino-preview-v44-motion-probe';
    root.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:#07110e;overflow:auto;padding:12px;display:grid;gap:12px;';
    document.body.appendChild(root);

    const surfaceDefs = [
      { id:'store', width:116, height:122, wrapStart:'<div class="store-v2-game-product" data-store-game-product="domino">', wrapEnd:'</div>' },
      { id:'profile', width:220, height:138, wrapStart:'<div id="screen-profile"><div class="profile-v2-game-panel" data-profile-game-panel="domino"><div class="profile-v2-game-card">', wrapEnd:'</div></div></div>' },
      { id:'detail', width:320, height:200, wrapStart:'<div class="profile-v2-game-preview-wrap">', wrapEnd:'</div>' },
    ];
    const variants = ['precision-drop','stock-pulse','chain-finale'];
    const selectors = {
      'precision-drop':'.mgw-domino-v44-precision-sparks .s3',
      'stock-pulse':'.mgw-domino-v44-stock-orb',
      'chain-finale':'.mgw-domino-v44-finale-sweep',
    };

    const entries = [];
    surfaceDefs.forEach(surface => {
      variants.forEach(variant => {
        const host = document.createElement('div');
        host.dataset.surface = surface.id;
        host.dataset.variant = variant;
        host.style.cssText = `position:relative;width:${surface.width}px;height:${surface.height}px;`;
        host.innerHTML = `${surface.wrapStart}<div class="store-v2-game-preview" data-game-type="domino" data-cosmetic-layer="effect" data-cosmetic-variant="${variant}" style="position:relative;width:100%;height:100%;overflow:hidden">${dominoPreviewMarkup('effect', variant)}</div>${surface.wrapEnd}`;
        root.appendChild(host);
        const preview = host.querySelector('.store-v2-game-preview');
        const stage = host.querySelector('.mgw-domino-live-v44-stage');
        const mover = host.querySelector(selectors[variant]);
        const faces = [...host.querySelectorAll('.mgw-domino-v48-face[data-mgw-domino-face="svg-v1"]')];
        const legacyHalves = host.querySelectorAll('.mgw-domino-live-v44-stage .mgw-domino-v44-tile .mgw-domino-preview-half');
        if (!(preview instanceof HTMLElement) || !(stage instanceof HTMLElement) || !(mover instanceof HTMLElement)) {
          throw new Error(`Missing v44 preview nodes for ${surface.id}/${variant}`);
        }
        entries.push({ surface:surface.id, variant, preview, stage, mover, faceCount:faces.length, legacyHalfCount:legacyHalves.length });
      });
    });

    const snapshot = () => entries.map(entry => {
      const stageRect = entry.stage.getBoundingClientRect();
      const moverStyle = getComputedStyle(entry.mover);
      return {
        surface:entry.surface,
        variant:entry.variant,
        component:String(entry.stage.dataset.mgwDominoPreviewComponent || ''),
        stageRatio:stageRect.height > 0 ? stageRect.width / stageRect.height : 0,
        animationName:moverStyle.animationName,
        animationPlayState:moverStyle.animationPlayState,
        left:moverStyle.left,
        top:moverStyle.top,
        opacity:Number.parseFloat(moverStyle.opacity || '0'),
        transform:moverStyle.transform,
      };
    });

    const frames = [];
    for (let index = 0; index < 13; index += 1) {
      await new Promise(resolve => setTimeout(resolve, 350));
      frames.push(snapshot());
    }

    return entries.map((entry, entryIndex) => {
      const samples = frames.map(frame => frame[entryIndex]);
      const states = samples.map(sample => [
        sample.left,
        sample.top,
        sample.opacity.toFixed(3),
        sample.transform,
      ].join('|'));
      const visibleSamples = samples.filter(sample => sample.opacity >= .12).length;
      const maxOpacity = Math.max(...samples.map(sample => sample.opacity));
      return {
        surface:entry.surface,
        variant:entry.variant,
        component:samples[0]?.component || '',
        faceCount:entry.faceCount,
        legacyHalfCount:entry.legacyHalfCount,
        stageRatio:samples[0]?.stageRatio || 0,
        animationName:samples[0]?.animationName || '',
        animationPlayState:samples[0]?.animationPlayState || '',
        distinctStates:new Set(states).size,
        visibleSamples,
        maxOpacity,
        samples:samples.map(sample => ({
          left:sample.left,
          top:sample.top,
          opacity:sample.opacity,
          transform:sample.transform,
        })),
      };
    });
  });
}

for (const reducedMotion of ['no-preference','reduce']) {
  test.describe(`DOMINO PREVIEW v48 motion — ${reducedMotion}`, () => {
    test.use({ viewport:{ width:390, height:700 }, reducedMotion });

    test('Store/Profile/detail use one moving component for all three effects', async ({ page }) => {
      const result = await probePreviewMotion(page);
      console.log(`DOMINO_PREVIEW_V48_${reducedMotion.replace('-', '_').toUpperCase()}=${JSON.stringify(result)}`);
      expect(result).toHaveLength(9);
      for (const entry of result) {
        expect(entry.component, `${entry.surface}/${entry.variant} component`).toBe('v44');
        expect(entry.faceCount, `${entry.surface}/${entry.variant} SVG face count`).toBe(entry.variant === 'precision-drop' ? 3 : (entry.variant === 'chain-finale' ? 5 : 1));
        expect(entry.legacyHalfCount, `${entry.surface}/${entry.variant} legacy half count`).toBe(0);
        expect(entry.stageRatio, `${entry.surface}/${entry.variant} canonical ratio`).toBeGreaterThan(1.57);
        expect(entry.stageRatio, `${entry.surface}/${entry.variant} canonical ratio`).toBeLessThan(1.63);
        expect(entry.animationName, `${entry.surface}/${entry.variant} animation name`).toContain('mgw-domino-v44-');
        expect(entry.animationPlayState, `${entry.surface}/${entry.variant} play state`).toBe('running');
        expect(entry.distinctStates, `${entry.surface}/${entry.variant} must change across a full animation cycle`).toBeGreaterThanOrEqual(3);
        expect(entry.visibleSamples, `${entry.surface}/${entry.variant} must become visibly non-transparent`).toBeGreaterThanOrEqual(1);
        expect(entry.maxOpacity, `${entry.surface}/${entry.variant} must reach readable opacity`).toBeGreaterThanOrEqual(.35);
      }
    });
  });
}
