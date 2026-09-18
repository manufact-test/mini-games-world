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

test('DOMINO — stable hand + owner-gated v38 viewport particles with measured displacement', async ({ page }) => {
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

    const oneFrame = () => new Promise(resolve => requestAnimationFrame(resolve));
    const nextFrame = () => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
    const waitForSheet = async selector => {
      const link = document.querySelector(selector);
      if (!(link instanceof HTMLLinkElement)) return false;
      if (link.sheet) return true;
      return await new Promise(resolve => {
        let settled = false;
        const finish = value => {
          if (settled) return;
          settled = true;
          resolve(value);
        };
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
    const gameFor = ({ id, handCount, chainCount, action, players, stockCount = 12 }) => {
      const hand = makeHand(handCount);
      return {
        id,
        game_type:'domino', status:'active', turn:'diag-me',
        players:players || [{ id:'diag-me', tile_count:handCount }, { id:'diag-opponent', tile_count:4 }],
        viewer_hand:hand,
        playable_sides:hand.length ? { [hand[0].id]:['right'] } : {},
        chain:makeChain(chainCount),
        open_left:5, open_right:6, stock_count:stockCount, opponent_tile_count:4,
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
    const centerOf = node => {
      const rect = node.getBoundingClientRect();
      return { x:rect.left + rect.width / 2, y:rect.top + rect.height / 2 };
    };
    const areaHeight = () => container.querySelector('.domino-chain-area')?.getBoundingClientRect().height || 0;

    state.profileInventory = { equipped:{ game_domino_effect:'base' }, catalog:[], owned:[] };
    renderDominoSurface({ game:gameFor({ id:'diag-v38-seven', handCount:7, chainCount:1 }), me:{ id:'diag-me' }, container, onAction:() => {} });
    const stabilitySheetLoaded = await waitForSheet('link[data-mgw-domino-mobile-stability]');
    const effectsSheetLoaded = await waitForSheet('link[data-mgw-domino-live-effects-v38]');
    await nextFrame();
    const seven = measureHand();
    const shortHeight = areaHeight();

    const twelveGame = gameFor({ id:'diag-v38-twelve', handCount:12, chainCount:18 });
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
    const myPrecisionGame = gameFor({
      id:'diag-v38-precision-mine', handCount:7, chainCount:4,
      action:{ type:'play', player_id:'diag-me', tile:'6-5', side:'right' },
    });
    renderDominoSurface({ game:myPrecisionGame, me:{ id:'diag-me' }, container, onAction:() => {} });
    await oneFrame();
    const precisionRoot = document.querySelector('.mgw-domino-precision-burst-v38[data-domino-native-game="diag-v38-precision-mine"]');
    const myPrecisionInitial = {
      exists:precisionRoot instanceof HTMLElement,
      owner:precisionRoot instanceof HTMLElement ? precisionRoot.dataset.dominoPrecisionOwner || '' : '',
      visual:precisionRoot instanceof HTMLElement ? precisionRoot.dataset.dominoPrecisionVisual || '' : '',
      declaredShards:Number(precisionRoot instanceof HTMLElement ? precisionRoot.dataset.dominoPrecisionShardCount || 0 : 0),
      actualShards:precisionRoot?.querySelectorAll('.precision-shard-v38').length || 0,
      launches:Number(container.dataset.dominoPrecisionLaunches || 0),
      oldLocal:container.querySelectorAll('.mgw-domino-precision-local-v33').length,
      oldNative:document.querySelectorAll('.domino-native-fx-accent.is-precision').length,
    };

    await wait(720);
    let precisionMotion = null;
    if (precisionRoot instanceof HTMLElement) {
      const origin = {
        x:Number.parseFloat(precisionRoot.style.left || '0'),
        y:Number.parseFloat(precisionRoot.style.top || '0'),
      };
      const samples = [...precisionRoot.querySelectorAll('.precision-shard-v38')]
        .filter(node => node instanceof HTMLElement)
        .map(node => {
          const point = centerOf(node);
          const opacity = Number.parseFloat(getComputedStyle(node).opacity || '0');
          return { distance:Math.hypot(point.x - origin.x, point.y - origin.y), opacity };
        });
      precisionMotion = {
        maxDistance:Math.max(0, ...samples.map(sample => sample.distance)),
        visibleAway:samples.filter(sample => sample.opacity > .2 && sample.distance >= 8).length,
      };
    }

    renderDominoSurface({ game:myPrecisionGame, me:{ id:'diag-me' }, container, onAction:() => {} });
    await oneFrame();
    const myPrecisionLaunchesAfterRepeat = Number(container.dataset.dominoPrecisionLaunches || 0);

    const opponentWithoutEffect = gameFor({
      id:'diag-v38-precision-opponent-base', handCount:7, chainCount:5,
      action:{ type:'play', player_id:'diag-opponent', tile:'6-5', side:'right' },
      players:[{ id:'diag-me', tile_count:7 }, { id:'diag-opponent', tile_count:4 }],
    });
    renderDominoSurface({ game:opponentWithoutEffect, me:{ id:'diag-me' }, container, onAction:() => {} });
    await oneFrame();
    const opponentWithoutEffectCount = document.querySelectorAll('.mgw-domino-precision-burst-v38[data-domino-native-game="diag-v38-precision-opponent-base"]').length;

    const opponentWithEffect = gameFor({
      id:'diag-v38-precision-opponent-owned', handCount:7, chainCount:6,
      action:{ type:'play', player_id:'diag-opponent', tile:'6-5', side:'right' },
      players:[
        { id:'diag-me', tile_count:7 },
        { id:'diag-opponent', tile_count:4, game_cosmetics:{ slots:{ game_domino_effect:'game-domino-effect-precision-drop' } } },
      ],
    });
    renderDominoSurface({ game:opponentWithEffect, me:{ id:'diag-me' }, container, onAction:() => {} });
    await oneFrame();
    const opponentOwnedRoot = document.querySelector('.mgw-domino-precision-burst-v38[data-domino-native-game="diag-v38-precision-opponent-owned"]');
    const opponentOwnedPrecision = {
      exists:opponentOwnedRoot instanceof HTMLElement,
      owner:opponentOwnedRoot instanceof HTMLElement ? opponentOwnedRoot.dataset.dominoPrecisionOwner || '' : '',
    };

    state.profileInventory.equipped.game_domino_effect = 'game-domino-effect-stock-pulse';
    const stockBase = gameFor({ id:'diag-v38-stock', handCount:7, chainCount:4, stockCount:12 });
    renderDominoSurface({ game:stockBase, me:{ id:'diag-me' }, container, onAction:() => {} });
    await oneFrame();

    const stockInterim = gameFor({
      id:'diag-v38-stock', handCount:7, chainCount:4, stockCount:11,
      action:{ type:'draw', player_id:'diag-me', drawn_count:1 },
    });
    renderDominoSurface({ game:stockInterim, me:{ id:'diag-me' }, container, onAction:() => {} });
    await oneFrame();
    const interimBeamCount = document.querySelectorAll('.mgw-domino-stock-burst-v38[data-domino-native-game="diag-v38-stock"]').length;

    const stockFinal = gameFor({
      id:'diag-v38-stock', handCount:8, chainCount:4, stockCount:11,
      action:{ type:'draw', player_id:'diag-me', drawn_count:1 },
    });
    renderDominoSurface({ game:stockFinal, me:{ id:'diag-me' }, container, onAction:() => {} });
    await oneFrame();
    const expectedTargetId = makeHand(8)[7].id;
    const stock = container.querySelector('.domino-stock-count');
    const targetButton = [...container.querySelectorAll('.domino-hand-tile')]
      .find(node => node instanceof HTMLElement && node.dataset.dominoTile === expectedTargetId) || null;
    const target = targetButton?.querySelector('.domino-tile');
    const stockRoot = document.querySelector('.mgw-domino-stock-burst-v38[data-domino-native-game="diag-v38-stock"]');
    let stockBeam = null;
    if (stock instanceof HTMLElement && targetButton instanceof HTMLElement && target instanceof HTMLElement && stockRoot instanceof HTMLElement) {
      const expectedStart = centerOf(stock);
      const expectedEnd = centerOf(targetButton);
      const line = stockRoot.querySelector('.stock-line-v38');
      if (line instanceof HTMLElement) {
        const startX = Number.parseFloat(line.style.left || '0');
        const startY = Number.parseFloat(line.style.top || '0');
        const distance = Number.parseFloat(line.style.width || '0');
        const angle = Number.parseFloat(line.style.getPropertyValue('--mgw-domino-stock-angle-v38') || '0') * Math.PI / 180;
        stockBeam = {
          source:stockRoot.dataset.dominoStockSource || '',
          target:stockRoot.dataset.dominoStockTarget || '',
          geometry:stockRoot.dataset.dominoStockGeometry || '',
          targetTile:stockRoot.dataset.dominoStockTargetTile || '',
          declaredSparks:Number(stockRoot.dataset.dominoStockSparkCount || 0),
          actualSparks:stockRoot.querySelectorAll('.stock-spark-v38').length,
          startError:Math.hypot(startX - expectedStart.x, startY - expectedStart.y),
          endError:Math.hypot(startX + Math.cos(angle) * distance - expectedEnd.x, startY + Math.sin(angle) * distance - expectedEnd.y),
          lineHeight:getComputedStyle(line).height,
          launches:Number(container.dataset.dominoStockLaunches || 0),
          oldBeams:document.querySelectorAll('.domino-native-fx-accent.is-stock,.domino-native-fx-accent.is-stock-v33').length,
          oldTargets:container.querySelectorAll('.mgw-domino-native-stock-target,.mgw-domino-stock-target-v33').length,
        };
      }
    }

    await wait(760);
    let stockMotion = null;
    if (stock instanceof HTMLElement && targetButton instanceof HTMLElement && stockRoot instanceof HTMLElement) {
      const start = centerOf(stock);
      const end = centerOf(targetButton);
      const dx = end.x - start.x;
      const dy = end.y - start.y;
      const distance = Math.max(1, Math.hypot(dx, dy));
      const ux = dx / distance;
      const uy = dy / distance;
      const samples = [...stockRoot.querySelectorAll('.stock-spark-v38')]
        .filter(node => node instanceof HTMLElement)
        .map(node => {
          const point = centerOf(node);
          const relX = point.x - start.x;
          const relY = point.y - start.y;
          const offAxis = Math.abs(relX * (-uy) + relY * ux);
          const opacity = Number.parseFloat(getComputedStyle(node).opacity || '0');
          return { offAxis, opacity };
        });
      stockMotion = {
        maxOffAxis:Math.max(0, ...samples.map(sample => sample.offAxis)),
        visibleOffAxis:samples.filter(sample => sample.opacity > .2 && sample.offAxis >= 14).length,
      };
    }

    renderDominoSurface({ game:stockFinal, me:{ id:'diag-me' }, container, onAction:() => {} });
    await oneFrame();
    const stockLaunchesAfterRepeat = Number(container.dataset.dominoStockLaunches || 0);

    state.profileInventory.equipped.game_domino_effect = 'game-domino-effect-chain-finale';
    renderDominoSurface({ game:gameFor({ id:'diag-v38-finale-active', handCount:7, chainCount:4 }), me:{ id:'diag-me' }, container, onAction:() => {} });
    await oneFrame();
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
      myPrecisionInitial,
      precisionMotion,
      myPrecisionLaunchesAfterRepeat,
      opponentWithoutEffectCount,
      opponentOwnedPrecision,
      interimBeamCount,
      stockBeam,
      stockMotion,
      stockLaunchesAfterRepeat,
      finaleQaCount,
    };
  });

  console.log(`DOMINO_V38_STABILITY=${JSON.stringify(setup)}`);
  expect(setup.entry).toContain('/app/v110.php');
  expect(setup.stabilitySheetLoaded).toBe(true);
  expect(setup.effectsSheetLoaded).toBe(true);
  expect(setup.marker).toBe('v30');
  expect(setup.liveEffects).toBe('v38');

  expect(setup.seven).toMatchObject({ count:7, layout:'single-row', rows:1, columns:7, allVisible:true });
  expect(setup.twelve).toMatchObject({ count:12, layout:'two-row', rows:2, columns:6, allVisible:true });
  expect(setup.afterInternalRerender).toMatchObject({ layout:'two-row', rows:2, columns:6, allVisible:true });
  expect(setup.heights.short).toBeCloseTo(232, 0);
  expect(setup.heights.long).toBeCloseTo(setup.heights.short, 0);
  expect(setup.heights.afterInternal).toBeCloseTo(setup.heights.short, 0);

  expect(setup.myPrecisionInitial).toEqual({
    exists:true,
    owner:'diag-me',
    visual:'eight-visible-shards-v38',
    declaredShards:8,
    actualShards:8,
    launches:1,
    oldLocal:0,
    oldNative:0,
  });
  expect(setup.precisionMotion?.maxDistance).toBeGreaterThanOrEqual(30);
  expect(setup.precisionMotion?.maxDistance).toBeLessThanOrEqual(60);
  expect(setup.precisionMotion?.visibleAway).toBeGreaterThanOrEqual(4);
  expect(setup.myPrecisionLaunchesAfterRepeat).toBe(1);
  expect(setup.opponentWithoutEffectCount).toBe(0);
  expect(setup.opponentOwnedPrecision).toEqual({ exists:true, owner:'diag-opponent' });

  expect(setup.interimBeamCount).toBe(0);
  expect(setup.stockBeam?.source).toBe('boneyard-v38');
  expect(setup.stockBeam?.target).toBe('exact-new-tile-v38');
  expect(setup.stockBeam?.geometry).toBe('viewport-portal-v38');
  expect(setup.stockBeam?.targetTile).toBe('0-2-7');
  expect(setup.stockBeam?.declaredSparks).toBe(12);
  expect(setup.stockBeam?.actualSparks).toBe(12);
  expect(setup.stockBeam?.startError).toBeLessThanOrEqual(1);
  expect(setup.stockBeam?.endError).toBeLessThanOrEqual(1);
  expect(Number.parseFloat(setup.stockBeam?.lineHeight || '99')).toBeLessThan(1);
  expect(setup.stockBeam?.launches).toBe(1);
  expect(setup.stockBeam?.oldBeams).toBe(0);
  expect(setup.stockBeam?.oldTargets).toBe(0);
  expect(setup.stockMotion?.maxOffAxis).toBeGreaterThanOrEqual(24);
  expect(setup.stockMotion?.visibleOffAxis).toBeGreaterThanOrEqual(4);
  expect(setup.stockLaunchesAfterRepeat).toBe(1);
  expect(setup.finaleQaCount).toBe(0);
});
