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

test.use({ viewport:{ width:390, height:560 }, isMobile:true, hasTouch:true, reducedMotion:'no-preference' });

test('DOMINO manual corrective v25 — visible paid effects, real touch swipe and finale QA', async ({ page }) => {
  const response = await page.goto(ENTRY_URL, { waitUntil:'domcontentloaded' });
  expect(response?.ok()).toBe(true);
  expect(response?.headers()['x-mgw-client-bootstrap']).toBe('v2-single-owner');

  const diagnostic = await page.evaluate(async () => {
    const [{ renderDominoSurface }, { state }] = await Promise.all([
      import('./assets/js/games/domino/renderer.js?v=74'),
      import('./assets/js/state.js?v=27'),
    ]);

    const waitForSheet = async selector => {
      const link = document.querySelector(selector);
      if (!(link instanceof HTMLLinkElement)) return false;
      if (link.sheet) return true;
      return await new Promise(resolve => {
        const done = value => resolve(value);
        link.addEventListener('load', () => done(true), { once:true });
        link.addEventListener('error', () => done(false), { once:true });
        setTimeout(() => done(Boolean(link.sheet)), 4000);
      });
    };

    const screen = document.getElementById('screen-game');
    const container = document.getElementById('gameBoard');
    if (!(screen instanceof HTMLElement) || !(container instanceof HTMLElement)) {
      throw new Error('Domino diagnostic surface is unavailable.');
    }
    document.querySelectorAll('.screen.active').forEach(node => node.classList.remove('active'));
    screen.classList.add('active');
    screen.dataset.gameType = 'domino';

    const chain = Array.from({ length:18 }, (_, index) => ({
      tile:index % 2 ? '6-5' : '5-6',
      left:index % 2 ? 6 : 5,
      right:index % 2 ? 5 : 6,
      player_id:index === 17 ? 'diag-me' : 'diag-opponent',
      side:index === 0 ? 'start' : 'right',
      move_number:index + 1,
      is_start:index === 0,
    }));
    const handPairs = [[0,0],[0,1],[1,2],[2,3],[3,4],[4,5],[5,6],[1,1],[2,2],[3,3],[4,4],[6,6]];
    const viewerHand = handPairs.map(([a,b], index) => ({ id:`${a}-${b}-${index}`, a, b, double:a === b }));
    const baseGame = id => ({
      id, game_type:'domino', status:'active', turn:'diag-me',
      players:[
        { id:'diag-me', name:'Diagnostic A', tile_count:viewerHand.length },
        { id:'diag-opponent', name:'Diagnostic B', tile_count:3 },
      ],
      viewer_hand:viewerHand,
      playable_sides:{}, chain,
      open_left:5, open_right:6, stock_count:9, opponent_tile_count:3,
      can_draw:false, move_count:18,
    });
    const equip = effect => {
      state.profileInventory = {
        equipped:{
          game_domino_theme:'game-domino-table-walnut',
          game_domino_elements:'game-domino-tiles-neon',
          game_domino_effect:effect,
        },
        catalog:[], owned:[],
      };
    };

    equip('game-domino-effect-precision-drop');
    renderDominoSurface({
      game:{ ...baseGame('diag-v25-precision'), last_action:{ type:'play', player_id:'diag-me', tile:'6-5', side:'right' } },
      me:{ id:'diag-me' }, container, onAction:() => {},
    });

    const cosmeticsSheetLoaded = await waitForSheet('link[data-mgw-domino-live-cosmetics]');
    const nativeSheetLoaded = await waitForSheet('link[data-mgw-domino-live-native-effects]');
    const correctiveSheetLoaded = await waitForSheet('link[data-mgw-domino-manual-corrective]');
    await new Promise(resolve => setTimeout(resolve, 100));

    const precisionTile = container.querySelector('.domino-chain-slot.latest .domino-tile');
    const precisionAccent = document.querySelector('.domino-native-fx-accent.is-precision');
    const precisionRing = precisionAccent?.querySelector('.ring');
    const precisionStyle = precisionTile instanceof HTMLElement ? getComputedStyle(precisionTile) : null;
    const precisionRingStyle = precisionRing instanceof HTMLElement ? getComputedStyle(precisionRing) : null;
    const precision = {
      realTileClass:precisionTile?.classList.contains('mgw-domino-native-precision-tile') || false,
      animationName:precisionStyle?.animationName || '',
      animationDuration:precisionStyle?.animationDuration || '',
      accentExists:precisionAccent instanceof HTMLElement,
      ringWidth:Number.parseFloat(precisionRingStyle?.width || '0'),
      previewInsideAccent:Boolean(precisionAccent?.querySelector('.store-v2-game-preview,.mgw-domino-preview')),
      legacyHostExists:Boolean(document.querySelector('.domino-live-fx-host')),
    };

    equip('game-domino-effect-stock-pulse');
    renderDominoSurface({
      game:{ ...baseGame('diag-v25-stock'), stock_count:7, last_action:{ type:'draw', player_id:'diag-me', drawn_count:2 } },
      me:{ id:'diag-me' }, container, onAction:() => {},
    });
    await new Promise(resolve => setTimeout(resolve, 100));
    const stockSource = container.querySelector('.domino-stock-count');
    const stockTargets = [...container.querySelectorAll('.domino-hand-tile.mgw-domino-native-stock-target')];
    const stockAccent = document.querySelector('.domino-native-fx-accent.is-stock');
    const stockOrb = stockAccent?.querySelector('.stock-orb');
    const stockLine = stockAccent?.querySelector('.stock-line');
    const stock = {
      sourceClass:stockSource?.classList.contains('mgw-domino-native-stock-source') || false,
      sourceAnimation:stockSource instanceof HTMLElement ? getComputedStyle(stockSource).animationName : '',
      targetCount:stockTargets.length,
      targetAnimation:stockTargets[0]?.querySelector('.domino-tile') instanceof HTMLElement
        ? getComputedStyle(stockTargets[0].querySelector('.domino-tile')).animationName : '',
      accentExists:stockAccent instanceof HTMLElement,
      orbWidth:stockOrb instanceof HTMLElement ? Number.parseFloat(getComputedStyle(stockOrb).width) : 0,
      lineHeight:stockLine instanceof HTMLElement ? Number.parseFloat(getComputedStyle(stockLine).height) : 0,
      previewInsideAccent:Boolean(stockAccent?.querySelector('.store-v2-game-preview,.mgw-domino-preview')),
    };

    equip('game-domino-effect-chain-finale');
    renderDominoSurface({
      game:{ ...baseGame('diag-v25-finale-qa'), last_action:{ type:'play', player_id:'diag-opponent', tile:'5-6', side:'right' } },
      me:{ id:'diag-me' }, container, onAction:() => {},
    });
    await new Promise(resolve => requestAnimationFrame(resolve));

    const hand = container.querySelector('.domino-hand');
    const content = screen.querySelector(':scope > .content');
    const leave = document.getElementById('leaveGame');
    const qaButton = container.querySelector('[data-domino-finale-qa="preview"]');
    if (!(hand instanceof HTMLElement) || !(content instanceof HTMLElement) || !(leave instanceof HTMLElement)) {
      throw new Error('Domino diagnostic layout nodes are unavailable.');
    }
    hand.scrollLeft = 0;
    content.scrollTop = 0;

    return {
      entry:String(location.pathname + location.search),
      importMapHasCorrective:String(document.querySelector('script[type="importmap"]')?.textContent || '').includes('manual-corrective-v25'),
      cosmeticsSheetLoaded,
      nativeSheetLoaded,
      correctiveSheetLoaded,
      correctiveHref:String(document.querySelector('link[data-mgw-domino-manual-corrective]')?.href || ''),
      marker:String(container.dataset.mgwDominoLiveCosmetics || ''),
      nativeMarker:String(container.dataset.mgwDominoNativeEffects || ''),
      correctiveMarker:String(container.dataset.mgwDominoManualCorrective || ''),
      theme:String(container.dataset.dominoTheme || ''),
      elements:String(container.dataset.dominoElements || ''),
      precision,
      stock,
      finaleQa:{ buttonExists:qaButton instanceof HTMLButtonElement, buttonText:String(qaButton?.textContent || '') },
      layout:{
        handOverflowX:getComputedStyle(hand).overflowX,
        handTouchAction:getComputedStyle(hand).touchAction,
        contentOverflowY:getComputedStyle(content).overflowY,
        contentTouchAction:getComputedStyle(content).touchAction,
        handClientWidth:hand.clientWidth,
        handScrollWidth:hand.scrollWidth,
      },
    };
  });

  expect(diagnostic.entry).toContain('v=1188');
  expect(diagnostic.importMapHasCorrective).toBe(true);
  expect(diagnostic.cosmeticsSheetLoaded).toBe(true);
  expect(diagnostic.nativeSheetLoaded).toBe(true);
  expect(diagnostic.correctiveSheetLoaded).toBe(true);
  expect(diagnostic.correctiveHref).toContain('live-native-manual-v25.css');
  expect(diagnostic.marker).toBe('full-v4');
  expect(diagnostic.nativeMarker).toBe('v1');
  expect(diagnostic.correctiveMarker).toBe('v25');
  expect(diagnostic.theme).toBe('walnut');
  expect(diagnostic.elements).toBe('neon');

  expect(diagnostic.precision.realTileClass).toBe(true);
  expect(diagnostic.precision.animationName).toContain('mgw-domino-native-precision-tile-v25');
  expect(diagnostic.precision.accentExists).toBe(true);
  expect(diagnostic.precision.ringWidth).toBeGreaterThanOrEqual(30);
  expect(diagnostic.precision.previewInsideAccent).toBe(false);
  expect(diagnostic.precision.legacyHostExists).toBe(false);

  expect(diagnostic.stock.sourceClass).toBe(true);
  expect(diagnostic.stock.sourceAnimation).toContain('mgw-domino-native-stock-source-v25');
  expect(diagnostic.stock.targetCount).toBe(2);
  expect(diagnostic.stock.targetAnimation).toContain('mgw-domino-native-stock-target-v25');
  expect(diagnostic.stock.accentExists).toBe(true);
  expect(diagnostic.stock.orbWidth).toBeGreaterThanOrEqual(15);
  expect(diagnostic.stock.lineHeight).toBeGreaterThanOrEqual(5);
  expect(diagnostic.stock.previewInsideAccent).toBe(false);

  expect(diagnostic.finaleQa.buttonExists).toBe(true);
  expect(diagnostic.finaleQa.buttonText).toContain('Финиш цепи');
  expect(diagnostic.layout.handOverflowX).toBe('auto');
  expect(diagnostic.layout.handScrollWidth).toBeGreaterThan(diagnostic.layout.handClientWidth);
  expect(diagnostic.layout.handTouchAction).toContain('pan-x');
  expect(diagnostic.layout.contentTouchAction).toContain('pan-x');
  expect(diagnostic.layout.contentOverflowY).toBe('auto');

  const handBox = await page.locator('.domino-hand').boundingBox();
  expect(handBox).not.toBeNull();
  const client = await page.context().newCDPSession(page);
  const startX = handBox.x + handBox.width - 28;
  const endX = handBox.x + 54;
  const y = handBox.y + handBox.height / 2;
  await client.send('Input.dispatchTouchEvent', { type:'touchStart', touchPoints:[{ x:startX, y, radiusX:4, radiusY:4, force:1 }] });
  for (const ratio of [0.18,0.36,0.54,0.72,0.9,1]) {
    const x = startX + (endX - startX) * ratio;
    await client.send('Input.dispatchTouchEvent', { type:'touchMove', touchPoints:[{ x, y, radiusX:4, radiusY:4, force:1 }] });
    await page.waitForTimeout(18);
  }
  await client.send('Input.dispatchTouchEvent', { type:'touchEnd', touchPoints:[] });
  await page.waitForTimeout(180);

  const swipe = await page.evaluate(() => {
    const hand = document.querySelector('.domino-hand');
    if (!(hand instanceof HTMLElement)) throw new Error('Domino hand disappeared during touch swipe.');
    return { scrollLeft:hand.scrollLeft, maxScroll:hand.scrollWidth - hand.clientWidth };
  });
  expect(swipe.maxScroll).toBeGreaterThan(120);
  expect(swipe.scrollLeft).toBeGreaterThan(45);

  await page.locator('[data-domino-finale-qa="preview"]').click();
  await page.waitForTimeout(120);
  const qaRunning = await page.evaluate(() => ({
    realChainCount:document.querySelectorAll('.domino-chain-slot .domino-tile').length,
    animatedRealChainCount:document.querySelectorAll('.domino-chain-slot .domino-tile.mgw-domino-native-finale-tile').length,
    accentExists:Boolean(document.querySelector('.domino-native-fx-accent.is-finale.is-qa')),
    previewInsideAccent:Boolean(document.querySelector('.domino-native-fx-accent.is-finale.is-qa .store-v2-game-preview,.domino-native-fx-accent.is-finale.is-qa .mgw-domino-preview')),
    buttonDisabled:Boolean(document.querySelector('[data-domino-finale-qa="preview"]')?.disabled),
  }));
  expect(qaRunning.realChainCount).toBeGreaterThan(5);
  expect(qaRunning.animatedRealChainCount).toBe(qaRunning.realChainCount);
  expect(qaRunning.accentExists).toBe(true);
  expect(qaRunning.previewInsideAccent).toBe(false);
  expect(qaRunning.buttonDisabled).toBe(true);

  await page.waitForTimeout(2300);
  const qaFinished = await page.evaluate(() => ({
    accentRemoved:!document.querySelector('.domino-native-fx-accent.is-finale.is-qa'),
    classesRemoved:document.querySelectorAll('.domino-chain-slot .domino-tile.mgw-domino-native-finale-tile').length === 0,
    buttonEnabled:document.querySelector('[data-domino-finale-qa="preview"]')?.disabled === false,
    buttonText:String(document.querySelector('[data-domino-finale-qa="preview"]')?.textContent || ''),
  }));
  expect(qaFinished.accentRemoved).toBe(true);
  expect(qaFinished.classesRemoved).toBe(true);
  expect(qaFinished.buttonEnabled).toBe(true);
  expect(qaFinished.buttonText).toContain('Повторить');

  const terminalFinale = await page.evaluate(async () => {
    const [{ renderDominoSurface }, { state }] = await Promise.all([
      import('./assets/js/games/domino/renderer.js?v=74'),
      import('./assets/js/state.js?v=27'),
    ]);
    const container = document.getElementById('gameBoard');
    const handPairs = [[0,0],[0,1],[1,2],[2,3],[3,4],[4,5],[5,6],[1,1],[2,2],[3,3],[4,4],[6,6]];
    const viewerHand = handPairs.map(([a,b], index) => ({ id:`${a}-${b}-${index}`, a, b, double:a === b }));
    const chain = Array.from({ length:18 }, (_, index) => ({
      tile:index % 2 ? '6-5' : '5-6', left:index % 2 ? 6 : 5, right:index % 2 ? 5 : 6,
      player_id:index === 17 ? 'diag-me' : 'diag-opponent', side:index === 0 ? 'start' : 'right', move_number:index + 1,
    }));
    state.profileInventory = { equipped:{ game_domino_effect:'game-domino-effect-chain-finale' }, catalog:[], owned:[] };
    const game = {
      id:'diag-v25-terminal', game_type:'domino', status:'finished', turn:'', winner_id:'diag-me',
      players:[{id:'diag-me',tile_count:0},{id:'diag-opponent',tile_count:3}], viewer_hand:viewerHand,
      playable_sides:{}, chain, open_left:5, open_right:6, stock_count:0, opponent_tile_count:3,
      can_draw:false, move_count:18, my_points:0, opponent_points:17, end_reason:'empty_hand',
      last_action:{ type:'play', player_id:'diag-me', tile:'6-5', side:'right' },
    };
    renderDominoSurface({ game, me:{id:'diag-me'}, container, onAction:() => {} });
    await new Promise(resolve => setTimeout(resolve, 120));
    return {
      animatedRealChainCount:container.querySelectorAll('.domino-chain-slot .domino-tile.mgw-domino-native-finale-tile').length,
      realChainCount:container.querySelectorAll('.domino-chain-slot .domino-tile').length,
      accentExists:Boolean(document.querySelector('.domino-native-fx-accent.is-finale:not(.is-qa)')),
      gateActive:String(document.body.dataset.mgwDominoFinale || '') === 'diag-v25-terminal',
      qaButtonAbsent:!container.querySelector('[data-domino-finale-qa="preview"]'),
    };
  });
  expect(terminalFinale.animatedRealChainCount).toBe(terminalFinale.realChainCount);
  expect(terminalFinale.accentExists).toBe(true);
  expect(terminalFinale.gateActive).toBe(true);
  expect(terminalFinale.qaButtonAbsent).toBe(true);

  await page.waitForTimeout(2300);
  expect(await page.evaluate(() => !document.body.dataset.mgwDominoFinale)).toBe(true);

  await page.evaluate(() => {
    const screen = document.getElementById('screen-game');
    const content = screen?.querySelector(':scope > .content');
    if (content instanceof HTMLElement) content.scrollTop = content.scrollHeight;
  });
  await page.waitForTimeout(40);
  const exitLayout = await page.evaluate(() => {
    const screen = document.getElementById('screen-game');
    const content = screen?.querySelector(':scope > .content');
    const leave = document.getElementById('leaveGame');
    if (!(screen instanceof HTMLElement) || !(content instanceof HTMLElement) || !(leave instanceof HTMLElement)) {
      throw new Error('Domino exit layout unavailable.');
    }
    const leaveRect = leave.getBoundingClientRect();
    const screenRect = screen.getBoundingClientRect();
    return {
      contentScrollTop:content.scrollTop,
      contentScrollHeight:content.scrollHeight,
      contentClientHeight:content.clientHeight,
      leaveTop:leaveRect.top,
      leaveBottom:leaveRect.bottom,
      viewportHeight:innerHeight,
      screenHeight:screenRect.height,
      screenBottom:screenRect.bottom,
    };
  });
  expect(exitLayout.screenHeight).toBeLessThanOrEqual(exitLayout.viewportHeight + 1);
  expect(exitLayout.screenBottom).toBeLessThanOrEqual(exitLayout.viewportHeight + 1);
  expect(exitLayout.contentScrollHeight).toBeGreaterThan(exitLayout.contentClientHeight);
  expect(exitLayout.contentScrollTop).toBeGreaterThan(0);
  expect(exitLayout.leaveBottom).toBeLessThanOrEqual(exitLayout.viewportHeight + 1);
  expect(exitLayout.leaveTop).toBeGreaterThanOrEqual(-1);

  console.log(`DOMINO_MANUAL_CORRECTIVE_V25=${JSON.stringify({ diagnostic, swipe, qaRunning, qaFinished, terminalFinale, exitLayout })}`);
});
