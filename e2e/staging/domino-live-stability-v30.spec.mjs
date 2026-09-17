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

test('DOMINO v30 — stable table, reachable hand and deterministic precision seam', async ({ page }) => {
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

    state.profileInventory = {
      equipped:{
        game_domino_theme:'game-domino-table-walnut',
        game_domino_elements:'game-domino-tiles-neon',
        game_domino_effect:'game-domino-effect-precision-drop',
      },
      catalog:[], owned:[],
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
    const gameFor = ({ id, handCount, chainCount, precision=false }) => {
      const hand = makeHand(handCount);
      const playable = hand.length ? { [hand[0].id]:['right'] } : {};
      return {
        id,
        game_type:'domino', status:'active', turn:'diag-me',
        players:[{ id:'diag-me', tile_count:handCount }, { id:'diag-opponent', tile_count:4 }],
        viewer_hand:hand,
        playable_sides:playable,
        chain:makeChain(chainCount),
        open_left:5, open_right:6, stock_count:12, opponent_tile_count:4,
        can_draw:false,
        move_count:chainCount,
        last_action:precision
          ? { type:'play', player_id:'diag-me', tile:chainCount % 2 ? '5-6' : '6-5', side:'right' }
          : { type:'start', player_id:'diag-opponent', tile:'5-6' },
      };
    };

    renderDominoSurface({ game:gameFor({ id:'diag-v30-seven', handCount:7, chainCount:1 }), me:{ id:'diag-me' }, container, onAction:() => {} });
    const stabilitySheetLoaded = await waitForSheet('link[data-mgw-domino-mobile-stability]');
    await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));

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
      return {
        left:rect.left - offset.x,
        top:rect.top - offset.y,
        right:rect.right - offset.x,
        bottom:rect.bottom - offset.y,
      };
    };
    const uniqueAxisCount = values => {
      const ordered = [...values].sort((a, b) => a - b);
      const groups = [];
      for (const value of ordered) {
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
        overflowX:getComputedStyle(hand).overflowX,
      };
    };
    const areaHeight = () => container.querySelector('.domino-chain-area')?.getBoundingClientRect().height || 0;

    const seven = measureHand();
    const shortHeight = areaHeight();

    const twelveGame = gameFor({ id:'diag-v30-twelve', handCount:12, chainCount:18 });
    renderDominoSurface({ game:twelveGame, me:{ id:'diag-me' }, container, onAction:() => {} });
    await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    const twelve = measureHand();
    const longHeight = areaHeight();

    const firstPlayable = container.querySelector('.domino-hand-tile.playable');
    if (!(firstPlayable instanceof HTMLButtonElement)) throw new Error('Playable hand tile unavailable.');
    firstPlayable.click();
    await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    const afterInternalRerender = measureHand();
    const afterInternalHeight = areaHeight();

    const precisionGame = gameFor({ id:'diag-v30-precision', handCount:7, chainCount:2, precision:true });
    renderDominoSurface({ game:precisionGame, me:{ id:'diag-me' }, container, onAction:() => {} });
    await new Promise(resolve => requestAnimationFrame(resolve));

    const latest = container.querySelector('.domino-chain-slot.latest .domino-tile');
    const slots = [...container.querySelectorAll('.domino-chain-slot')].filter(node => node instanceof HTMLElement);
    const latestSlot = container.querySelector('.domino-chain-slot.latest');
    const latestIndex = slots.indexOf(latestSlot);
    const neighborSlot = latestIndex === 0 ? slots[1] : slots[latestIndex - 1];
    const neighbor = neighborSlot?.querySelector('.domino-tile');
    const accent = [...document.querySelectorAll('.domino-native-fx-accent.is-precision')]
      .find(node => node instanceof HTMLElement && node.dataset.dominoNativeGame === 'diag-v30-precision');

    const stableSlotTileRect = (slot, tile) => {
      const slotRect = slot.getBoundingClientRect();
      const center = { x:slotRect.left + slotRect.width / 2, y:slotRect.top + slotRect.height / 2 };
      const baseWidth = Number(tile.offsetWidth || 0) || Number(tile.getBoundingClientRect().width || 0);
      const baseHeight = Number(tile.offsetHeight || 0) || Number(tile.getBoundingClientRect().height || 0);
      const vertical = slot.classList.contains('vertical');
      const isDouble = slot.classList.contains('is-double');
      const quarterTurn = (vertical && !isDouble) || (!vertical && isDouble);
      const width = quarterTurn ? baseHeight : baseWidth;
      const height = quarterTurn ? baseWidth : baseHeight;
      return {
        left:center.x - width / 2,
        right:center.x + width / 2,
        top:center.y - height / 2,
        bottom:center.y + height / 2,
        width,
        height,
      };
    };

    let precision = null;
    if (latestSlot instanceof HTMLElement && latest instanceof HTMLElement
      && neighborSlot instanceof HTMLElement && neighbor instanceof HTMLElement
      && accent instanceof HTMLElement) {
      const a = stableSlotTileRect(latestSlot, latest);
      const b = stableSlotTileRect(neighborSlot, neighbor);
      const latestCenter = { x:a.left + a.width / 2, y:a.top + a.height / 2 };
      const neighborCenter = { x:b.left + b.width / 2, y:b.top + b.height / 2 };
      const horizontal = Math.abs(latestCenter.x - neighborCenter.x) >= Math.abs(latestCenter.y - neighborCenter.y);
      const expected = horizontal
        ? {
            x:(latestCenter.x > neighborCenter.x ? a.left + b.right : a.right + b.left) / 2,
            y:(latestCenter.y + neighborCenter.y) / 2,
          }
        : {
            x:(latestCenter.x + neighborCenter.x) / 2,
            y:(latestCenter.y > neighborCenter.y ? a.top + b.bottom : a.bottom + b.top) / 2,
          };
      precision = {
        anchor:accent.dataset.dominoPrecisionAnchor || '',
        geometry:accent.dataset.dominoPrecisionGeometry || '',
        x:Number.parseFloat(accent.style.left || '0'),
        y:Number.parseFloat(accent.style.top || '0'),
        expectedX:expected.x,
        expectedY:expected.y,
      };
    }

    return {
      entry:String(location.pathname + location.search),
      stabilitySheetLoaded,
      marker:container.dataset.mgwDominoManualStability || '',
      seven,
      twelve,
      afterInternalRerender,
      heights:{ short:shortHeight, long:longHeight, afterInternal:afterInternalHeight },
      precision,
    };
  });

  console.log(`DOMINO_V30_STABILITY=${JSON.stringify(setup)}`);
  expect(setup.entry).toContain('/app/v110.php');
  expect(setup.entry).toContain('v=1192');
  expect(setup.entry).toContain('domino_stability=30');
  expect(setup.entry).toContain('runtime_fix=1');
  expect(setup.stabilitySheetLoaded).toBe(true);
  expect(setup.marker).toBe('v30');

  expect(setup.seven?.count).toBe(7);
  expect(setup.seven?.layout).toBe('single-row');
  expect(setup.seven?.rows).toBe(1);
  expect(setup.seven?.columns).toBe(7);
  expect(setup.seven?.allVisible).toBe(true);

  expect(setup.twelve?.count).toBe(12);
  expect(setup.twelve?.layout).toBe('two-row');
  expect(setup.twelve?.rows).toBe(2);
  expect(setup.twelve?.columns).toBe(6);
  expect(setup.twelve?.allVisible).toBe(true);

  expect(setup.afterInternalRerender?.layout).toBe('two-row');
  expect(setup.afterInternalRerender?.rows).toBe(2);
  expect(setup.afterInternalRerender?.columns).toBe(6);
  expect(setup.afterInternalRerender?.allVisible).toBe(true);

  expect(setup.heights.short).toBeCloseTo(232, 0);
  expect(setup.heights.long).toBeCloseTo(setup.heights.short, 0);
  expect(setup.heights.afterInternal).toBeCloseTo(setup.heights.short, 0);

  expect(setup.precision?.anchor).toBe('seam-v30');
  expect(setup.precision?.geometry).toBe('static-v2');
  expect(Math.abs(setup.precision.x - setup.precision.expectedX)).toBeLessThanOrEqual(0.5);
  expect(Math.abs(setup.precision.y - setup.precision.expectedY)).toBeLessThanOrEqual(0.5);
});
