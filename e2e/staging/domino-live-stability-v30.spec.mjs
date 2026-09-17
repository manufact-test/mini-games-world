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

test.use({ viewport:{ width:320, height:640 }, isMobile:true, hasTouch:true, reducedMotion:'no-preference' });

test('DOMINO — stable 2-row hand + tile-local Precision + exact boneyard Stock v32', async ({ page }) => {
  const response = await page.goto(ENTRY_URL, { waitUntil:'domcontentloaded' });
  expect(response?.ok()).toBe(true);

  const setup = await page.evaluate(async () => {
    const [{ renderDominoSurface }, { state }] = await Promise.all([
      import('./assets/js/games/domino/renderer.js?v=74'),
      import('./assets/js/state.js?v=27'),
    ]);

    const screen = document.getElementById('screen-game');
    const container = document.getElementById('gameBoard');
    if (!(screen instanceof HTMLElement) || !(container instanceof HTMLElement)) {
      throw new Error('Domino diagnostic surface is unavailable.');
    }
    document.querySelectorAll('.screen.active').forEach(node => node.classList.remove('active'));
    screen.classList.add('active');
    screen.dataset.gameType = 'domino';

    const nextFrame = () => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    const waitForSheet = async selector => {
      const link = document.querySelector(selector);
      if (!(link instanceof HTMLLinkElement)) return false;
      if (link.sheet) return true;
      return await new Promise(resolve => {
        const finish = value => resolve(value);
        link.addEventListener('load', () => finish(true), { once:true });
        link.addEventListener('error', () => finish(false), { once:true });
        setTimeout(() => finish(Boolean(link.sheet)), 4000);
      });
    };

    const makeHand = count => Array.from({ length:count }, (_, index) => ({
      id:`${index % 7}-${(index + 2) % 7}-${index}`,
      a:index % 7,
      b:(index + 2) % 7,
      double:false,
    }));
    const makeChain = count => Array.from({ length:count }, (_, index) => ({
      tile:index % 2 ? '6-5' : '5-6',
      left:index % 2 ? 6 : 5,
      right:index % 2 ? 5 : 6,
      player_id:index === count - 1 ? 'diag-me' : 'diag-opponent',
      side:index === 0 ? 'start' : 'right',
      move_number:index + 1,
      is_start:index === 0,
    }));
    const gameFor = ({ id, handCount, chainCount, action }) => {
      const hand = makeHand(handCount);
      return {
        id,
        game_type:'domino', status:'active', turn:'diag-me',
        players:[{ id:'diag-me', tile_count:handCount }, { id:'diag-opponent', tile_count:4 }],
        viewer_hand:hand,
        playable_sides:hand.length ? { [hand[0].id]:['right'] } : {},
        chain:makeChain(chainCount),
        open_left:5, open_right:6, stock_count:12, opponent_tile_count:4,
        can_draw:false,
        move_count:chainCount,
        last_action:action || { type:'start', player_id:'diag-opponent', tile:'5-6' },
      };
    };

    const transformOffset = tile => {
      const transform = getComputedStyle(tile).transform;
      if (!transform || transform === 'none') return { x:0, y:0 };
      try {
        const matrix = new DOMMatrixReadOnly(transform);
        return { x:Number(matrix.m41 || 0), y:Number(matrix.m42 || 0) };
      } catch (_) {
        return { x:0, y:0 };
      }
    };
    const normalizedTileRect = tile => {
      const rect = tile.getBoundingClientRect();
      const offset = transformOffset(tile);
      return { left:rect.left - offset.x, top:rect.top - offset.y, right:rect.right - offset.x, bottom:rect.bottom - offset.y };
    };
    const uniqueAxisCount = values => {
      const groups = [];
      for (const value of [...values].sort((a, b) => a - b)) {
        if (!groups.some(existing => Math.abs(existing - value) <= 1)) groups.push(value);
      }
      return groups.length;
    };
    const measureHand = () => {
      const hand = container.querySelector('.domino-hand');
      if (!(hand instanceof HTMLElement)) return null;
      const handRect = hand.getBoundingClientRect();
      const tiles = [...hand.querySelectorAll(':scope > .domino-hand-tile')].filter(node => node instanceof HTMLElement);
      const actualRects = tiles.map(tile => tile.getBoundingClientRect());
      const layoutRects = tiles.map(normalizedTileRect);
      return {
        layout:hand.dataset.dominoHandLayout || '',
        count:tiles.length,
        columns:uniqueAxisCount(layoutRects.map(rect => rect.left)),
        rows:uniqueAxisCount(layoutRects.map(rect => rect.top)),
        allVisible:actualRects.every(rect => rect.left >= handRect.left - 1 && rect.right <= handRect.right + 1 && rect.left >= -1 && rect.right <= innerWidth + 1),
      };
    };
    const areaHeight = () => container.querySelector('.domino-chain-area')?.getBoundingClientRect().height || 0;

    state.profileInventory = { equipped:{ game_domino_effect:'base' }, catalog:[], owned:[] };
    renderDominoSurface({ game:gameFor({ id:'diag-v32-seven', handCount:7, chainCount:1 }), me:{ id:'diag-me' }, container, onAction:() => {} });
    const stabilitySheetLoaded = await waitForSheet('link[data-mgw-domino-mobile-stability]');
    const effectsSheetLoaded = await waitForSheet('link[data-mgw-domino-live-effects-v32]');
    await nextFrame();
    const seven = measureHand();
    const shortHeight = areaHeight();

    const twelveGame = gameFor({ id:'diag-v32-twelve', handCount:12, chainCount:18 });
    renderDominoSurface({ game:twelveGame, me:{ id:'diag-me' }, container, onAction:() => {} });
    await nextFrame();
    const twelve = measureHand();
    const longHeight = areaHeight();
    const firstPlayable = container.querySelector('.domino-hand-tile.playable');
    if (firstPlayable instanceof HTMLButtonElement) firstPlayable.click();
    await nextFrame();
    const afterInternalRerender = measureHand();
    const afterInternalHeight = areaHeight();

    state.profileInventory.equipped.game_domino_effect = 'game-domino-effect-precision-drop';
    renderDominoSurface({
      game:gameFor({
        id:'diag-v32-precision', handCount:7, chainCount:4,
        action:{ type:'play', player_id:'diag-me', tile:'6-5', side:'right' },
      }),
      me:{ id:'diag-me' }, container, onAction:() => {},
    });
    await new Promise(resolve => requestAnimationFrame(resolve));
    const latestSlot = container.querySelector('.domino-chain-slot.latest');
    const precisionLocal = latestSlot?.querySelector(':scope > .mgw-domino-precision-local-v32');
    const precision = {
      exists:precisionLocal instanceof HTMLElement,
      parentIsLatest:precisionLocal?.parentElement === latestSlot,
      anchor:precisionLocal instanceof HTMLElement ? precisionLocal.dataset.dominoPrecisionAnchor || '' : '',
      globalCount:document.querySelectorAll('.domino-native-fx-accent.is-precision').length,
    };

    state.profileInventory.equipped.game_domino_effect = 'game-domino-effect-stock-pulse';
    renderDominoSurface({
      game:{
        ...gameFor({
          id:'diag-v32-stock', handCount:8, chainCount:4,
          action:{ type:'draw', player_id:'diag-me', drawn_count:1 },
        }),
        stock_count:11,
      },
      me:{ id:'diag-me' }, container, onAction:() => {},
    });
    await new Promise(resolve => requestAnimationFrame(resolve));
    const stock = container.querySelector('.domino-stock-count');
    const target = container.querySelector('.domino-hand-tile.mgw-domino-native-stock-target .domino-tile');
    const beam = document.querySelector('.domino-native-fx-accent.is-stock.is-stock-v32');
    let stockBeam = null;
    if (stock instanceof HTMLElement && target instanceof HTMLElement && beam instanceof HTMLElement) {
      const startRect = stock.getBoundingClientRect();
      const targetRect = target.getBoundingClientRect();
      const expectedStart = { x:startRect.left + startRect.width / 2, y:startRect.top + startRect.height / 2 };
      const expectedEnd = { x:targetRect.left + targetRect.width / 2, y:targetRect.top + targetRect.height / 2 };
      const startX = Number.parseFloat(beam.style.left || '0');
      const startY = Number.parseFloat(beam.style.top || '0');
      const distance = Number.parseFloat(beam.style.width || '0');
      const angle = Number.parseFloat(beam.style.getPropertyValue('--mgw-domino-native-angle') || '0') * Math.PI / 180;
      stockBeam = {
        source:beam.dataset.dominoStockSource || '',
        target:beam.dataset.dominoStockTarget || '',
        startError:Math.hypot(startX - expectedStart.x, startY - expectedStart.y),
        endError:Math.hypot(startX + Math.cos(angle) * distance - expectedEnd.x, startY + Math.sin(angle) * distance - expectedEnd.y),
        lineHeight:getComputedStyle(beam.querySelector('.stock-line')).height,
      };
    }

    state.profileInventory.equipped.game_domino_effect = 'game-domino-effect-chain-finale';
    renderDominoSurface({ game:gameFor({ id:'diag-v32-finale-active', handCount:7, chainCount:4 }), me:{ id:'diag-me' }, container, onAction:() => {} });
    await new Promise(resolve => requestAnimationFrame(resolve));
    const finaleQaCount = container.querySelectorAll('.domino-finale-qa-row,.domino-finale-qa-button').length;

    return {
      entry:String(location.pathname + location.search),
      marker:container.dataset.mgwDominoManualStability || '',
      liveEffects:container.dataset.mgwDominoLiveEffects || '',
      stabilitySheetLoaded,
      effectsSheetLoaded,
      seven,
      twelve,
      afterInternalRerender,
      heights:{ short:shortHeight, long:longHeight, afterInternal:afterInternalHeight },
      precision,
      stockBeam,
      finaleQaCount,
    };
  });

  console.log(`DOMINO_V32_STABILITY=${JSON.stringify(setup)}`);
  expect(setup.entry).toContain('/app/v110.php');
  expect(setup.stabilitySheetLoaded).toBe(true);
  expect(setup.effectsSheetLoaded).toBe(true);
  expect(setup.marker).toBe('v30');
  expect(setup.liveEffects).toBe('v32');

  expect(setup.seven).toMatchObject({ count:7, layout:'single-row', rows:1, columns:7, allVisible:true });
  expect(setup.twelve).toMatchObject({ count:12, layout:'two-row', rows:2, columns:6, allVisible:true });
  expect(setup.afterInternalRerender).toMatchObject({ layout:'two-row', rows:2, columns:6, allVisible:true });
  expect(setup.heights.short).toBeCloseTo(232, 0);
  expect(setup.heights.long).toBeCloseTo(setup.heights.short, 0);
  expect(setup.heights.afterInternal).toBeCloseTo(setup.heights.short, 0);

  expect(setup.precision).toEqual({ exists:true, parentIsLatest:true, anchor:'latest-slot-local-v32', globalCount:0 });
  expect(setup.stockBeam?.source).toBe('boneyard-v32');
  expect(setup.stockBeam?.target).toBe('exact-drawn-tile-v32');
  expect(setup.stockBeam?.startError).toBeLessThanOrEqual(1);
  expect(setup.stockBeam?.endError).toBeLessThanOrEqual(1);
  expect(Number.parseFloat(setup.stockBeam?.lineHeight || '99')).toBeLessThanOrEqual(1.5);
  expect(setup.finaleQaCount).toBe(0);
});
