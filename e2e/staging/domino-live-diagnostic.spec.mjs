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

test('DOMINO LIVE-native — real DOM effects, hand scroll and exit reachability', async ({ page }) => {
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
      game:{ ...baseGame('diag-native-precision'), last_action:{ type:'play', player_id:'diag-me', tile:'6-5', side:'right' } },
      me:{ id:'diag-me' }, container, onAction:() => {},
    });

    const cosmeticsSheetLoaded = await waitForSheet('link[data-mgw-domino-live-cosmetics]');
    const nativeSheetLoaded = await waitForSheet('link[data-mgw-domino-live-native-effects]');
    await new Promise(resolve => setTimeout(resolve, 90));

    const precisionTile = container.querySelector('.domino-chain-slot.latest .domino-tile');
    const precisionAccent = document.querySelector('.domino-native-fx-accent.is-precision');
    const precisionStyle = precisionTile instanceof HTMLElement ? getComputedStyle(precisionTile) : null;
    const precision = {
      realTileClass:precisionTile?.classList.contains('mgw-domino-native-precision-tile') || false,
      animationName:precisionStyle?.animationName || '',
      accentExists:precisionAccent instanceof HTMLElement,
      previewInsideAccent:Boolean(precisionAccent?.querySelector('.store-v2-game-preview,.mgw-domino-preview')),
      legacyHostExists:Boolean(document.querySelector('.domino-live-fx-host')),
    };

    const hand = container.querySelector('.domino-hand');
    const content = screen.querySelector(':scope > .content');
    const leave = document.getElementById('leaveGame');
    if (!(hand instanceof HTMLElement) || !(content instanceof HTMLElement) || !(leave instanceof HTMLElement)) {
      throw new Error('Domino diagnostic layout nodes are unavailable.');
    }
    hand.scrollLeft = hand.scrollWidth;
    content.scrollTop = content.scrollHeight;
    await new Promise(resolve => requestAnimationFrame(resolve));
    const leaveRect = leave.getBoundingClientRect();
    const screenRect = screen.getBoundingClientRect();
    const layout = {
      handOverflowX:getComputedStyle(hand).overflowX,
      handClientWidth:hand.clientWidth,
      handScrollWidth:hand.scrollWidth,
      handScrollLeft:hand.scrollLeft,
      contentOverflowY:getComputedStyle(content).overflowY,
      contentClientHeight:content.clientHeight,
      contentScrollHeight:content.scrollHeight,
      contentScrollTop:content.scrollTop,
      leaveTop:leaveRect.top,
      leaveBottom:leaveRect.bottom,
      viewportHeight:innerHeight,
      screenHeight:screenRect.height,
      screenBottom:screenRect.bottom,
    };

    equip('game-domino-effect-stock-pulse');
    renderDominoSurface({
      game:{ ...baseGame('diag-native-stock'), stock_count:7, last_action:{ type:'draw', player_id:'diag-me', drawn_count:2 } },
      me:{ id:'diag-me' }, container, onAction:() => {},
    });
    await new Promise(resolve => setTimeout(resolve, 90));
    const stockSource = container.querySelector('.domino-stock-count');
    const stockTargets = [...container.querySelectorAll('.domino-hand-tile.mgw-domino-native-stock-target')];
    const stockAccent = document.querySelector('.domino-native-fx-accent.is-stock');
    const stock = {
      sourceClass:stockSource?.classList.contains('mgw-domino-native-stock-source') || false,
      sourceAnimation:stockSource instanceof HTMLElement ? getComputedStyle(stockSource).animationName : '',
      targetCount:stockTargets.length,
      targetAnimation:stockTargets[0]?.querySelector('.domino-tile') instanceof HTMLElement
        ? getComputedStyle(stockTargets[0].querySelector('.domino-tile')).animationName : '',
      accentExists:stockAccent instanceof HTMLElement,
      previewInsideAccent:Boolean(stockAccent?.querySelector('.store-v2-game-preview,.mgw-domino-preview')),
    };

    equip('game-domino-effect-chain-finale');
    renderDominoSurface({
      game:{
        ...baseGame('diag-native-finale'), status:'finished', turn:'', winner_id:'diag-me',
        my_points:0, opponent_points:17, end_reason:'empty_hand',
        last_action:{ type:'play', player_id:'diag-me', tile:'6-5', side:'right' },
      },
      me:{ id:'diag-me' }, container, onAction:() => {},
    });
    await new Promise(resolve => setTimeout(resolve, 120));
    const finaleTiles = [...container.querySelectorAll('.domino-chain-slot .domino-tile.mgw-domino-native-finale-tile')];
    const finaleAccent = document.querySelector('.domino-native-fx-accent.is-finale');
    const finale = {
      realChainCount:container.querySelectorAll('.domino-chain-slot .domino-tile').length,
      animatedRealChainCount:finaleTiles.length,
      firstAnimation:finaleTiles[0] instanceof HTMLElement ? getComputedStyle(finaleTiles[0]).animationName : '',
      accentExists:finaleAccent instanceof HTMLElement,
      gateActive:String(document.body.dataset.mgwDominoFinale || '') === 'diag-native-finale',
      previewInsideAccent:Boolean(finaleAccent?.querySelector('.store-v2-game-preview,.mgw-domino-preview')),
    };

    await new Promise(resolve => setTimeout(resolve, 2300));
    finale.gateReleased = !document.body.dataset.mgwDominoFinale;
    finale.accentRemoved = !document.querySelector('.domino-native-fx-accent.is-finale');

    return {
      entry:String(location.pathname + location.search),
      importMapHasNative:String(document.querySelector('script[type="importmap"]')?.textContent || '').includes('live-native-effects-v1'),
      cosmeticsSheetLoaded,
      nativeSheetLoaded,
      cosmeticsHref:String(document.querySelector('link[data-mgw-domino-live-cosmetics]')?.href || ''),
      nativeHref:String(document.querySelector('link[data-mgw-domino-live-native-effects]')?.href || ''),
      marker:String(container.dataset.mgwDominoLiveCosmetics || ''),
      nativeMarker:String(container.dataset.mgwDominoNativeEffects || ''),
      theme:String(container.dataset.dominoTheme || ''),
      elements:String(container.dataset.dominoElements || ''),
      precision, stock, finale, layout,
    };
  });

  console.log(`DOMINO_LIVE_NATIVE_DIAGNOSTIC=${JSON.stringify(diagnostic)}`);
  expect(diagnostic.entry).toContain('v=1187');
  expect(diagnostic.importMapHasNative).toBe(true);
  expect(diagnostic.cosmeticsSheetLoaded).toBe(true);
  expect(diagnostic.nativeSheetLoaded).toBe(true);
  expect(diagnostic.nativeHref).toContain('live-native-effects-v1.css');
  expect(diagnostic.marker).toBe('full-v4');
  expect(diagnostic.nativeMarker).toBe('v1');
  expect(diagnostic.theme).toBe('walnut');
  expect(diagnostic.elements).toBe('neon');

  expect(diagnostic.precision.realTileClass).toBe(true);
  expect(diagnostic.precision.animationName).toContain('mgw-domino-native-precision-tile');
  expect(diagnostic.precision.accentExists).toBe(true);
  expect(diagnostic.precision.previewInsideAccent).toBe(false);
  expect(diagnostic.precision.legacyHostExists).toBe(false);

  expect(diagnostic.stock.sourceClass).toBe(true);
  expect(diagnostic.stock.sourceAnimation).toContain('mgw-domino-native-stock-source');
  expect(diagnostic.stock.targetCount).toBe(2);
  expect(diagnostic.stock.targetAnimation).toContain('mgw-domino-native-stock-target');
  expect(diagnostic.stock.accentExists).toBe(true);
  expect(diagnostic.stock.previewInsideAccent).toBe(false);

  expect(diagnostic.finale.realChainCount).toBeGreaterThan(5);
  expect(diagnostic.finale.animatedRealChainCount).toBe(diagnostic.finale.realChainCount);
  expect(diagnostic.finale.firstAnimation).toContain('mgw-domino-native-finale-tile');
  expect(diagnostic.finale.accentExists).toBe(true);
  expect(diagnostic.finale.gateActive).toBe(true);
  expect(diagnostic.finale.previewInsideAccent).toBe(false);
  expect(diagnostic.finale.gateReleased).toBe(true);
  expect(diagnostic.finale.accentRemoved).toBe(true);

  expect(diagnostic.layout.screenHeight).toBeLessThanOrEqual(diagnostic.layout.viewportHeight + 1);
  expect(diagnostic.layout.screenBottom).toBeLessThanOrEqual(diagnostic.layout.viewportHeight + 1);
  expect(diagnostic.layout.handOverflowX).toBe('auto');
  expect(diagnostic.layout.handScrollWidth).toBeGreaterThan(diagnostic.layout.handClientWidth);
  expect(diagnostic.layout.handScrollLeft).toBeGreaterThan(0);
  expect(diagnostic.layout.contentOverflowY).toBe('auto');
  expect(diagnostic.layout.contentScrollHeight).toBeGreaterThan(diagnostic.layout.contentClientHeight);
  expect(diagnostic.layout.contentScrollTop).toBeGreaterThan(0);
  expect(diagnostic.layout.leaveBottom).toBeLessThanOrEqual(diagnostic.layout.viewportHeight + 1);
  expect(diagnostic.layout.leaveTop).toBeGreaterThanOrEqual(-1);
});
