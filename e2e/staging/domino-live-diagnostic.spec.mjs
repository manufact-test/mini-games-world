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

test('DOMINO LIVE DIAGNOSTIC — deployed wrapper, effect host, hand scroll and exit reachability', async ({ page }) => {
  const response = await page.goto(ENTRY_URL, { waitUntil:'domcontentloaded' });
  expect(response?.ok()).toBe(true);
  expect(response?.headers()['x-mgw-client-bootstrap']).toBe('v2-single-owner');

  const diagnostic = await page.evaluate(async () => {
    const [{ renderDominoSurface }, { state }] = await Promise.all([
      import('./assets/js/games/domino/renderer.js?v=74'),
      import('./assets/js/state.js?v=27'),
    ]);

    state.profileInventory = {
      equipped:{
        game_domino_theme:'game-domino-table-walnut',
        game_domino_elements:'game-domino-tiles-neon',
        game_domino_effect:'game-domino-effect-precision-drop',
      },
      catalog:[], owned:[],
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

    const game = {
      id:'domino-live-diagnostic-v4', game_type:'domino', status:'active', turn:'diag-me',
      players:[
        { id:'diag-me', name:'Diagnostic A', tile_count:viewerHand.length },
        { id:'diag-opponent', name:'Diagnostic B', tile_count:3 },
      ],
      viewer_hand:viewerHand,
      playable_sides:{},
      chain,
      open_left:5,
      open_right:6,
      stock_count:9,
      opponent_tile_count:3,
      can_draw:false,
      move_count:18,
      last_action:{ type:'play', player_id:'diag-me', tile:'6-5', side:'right' },
    };

    renderDominoSurface({ game, me:{ id:'diag-me' }, container, onAction:() => {} });

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
    const cosmeticsSheetLoaded = await waitForSheet('link[data-mgw-domino-live-cosmetics]');
    const effectsSheetLoaded = await waitForSheet('link[data-mgw-domino-live-effects]');
    await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));

    const hand = container.querySelector('.domino-hand');
    const content = screen.querySelector(':scope > .content');
    const leave = document.getElementById('leaveGame');
    const host = document.querySelector('.domino-live-fx-host.is-precision');
    const actor = host?.querySelector('.mgw-domino-v13-impact-piece');
    if (!(hand instanceof HTMLElement) || !(content instanceof HTMLElement) || !(leave instanceof HTMLElement)) {
      throw new Error('Domino diagnostic layout nodes are unavailable.');
    }

    const handStyle = getComputedStyle(hand);
    const contentStyle = getComputedStyle(content);
    const actorStyle = actor instanceof HTMLElement ? getComputedStyle(actor) : null;
    hand.scrollLeft = hand.scrollWidth;
    content.scrollTop = content.scrollHeight;
    await new Promise(resolve => requestAnimationFrame(resolve));
    const leaveRect = leave.getBoundingClientRect();

    return {
      entry:String(location.pathname + location.search),
      importMapHasV3:String(document.querySelector('script[type="importmap"]')?.textContent || '').includes('live-effects-scroll-corrective-v3'),
      cosmeticsSheetLoaded,
      effectsSheetLoaded,
      cosmeticsHref:String(document.querySelector('link[data-mgw-domino-live-cosmetics]')?.href || ''),
      effectsHref:String(document.querySelector('link[data-mgw-domino-live-effects]')?.href || ''),
      marker:String(container.dataset.mgwDominoLiveCosmetics || ''),
      theme:String(container.dataset.dominoTheme || ''),
      elements:String(container.dataset.dominoElements || ''),
      effect:String(container.dataset.dominoEffect || ''),
      hand:{
        display:handStyle.display,
        overflowX:handStyle.overflowX,
        clientWidth:hand.clientWidth,
        scrollWidth:hand.scrollWidth,
        scrollLeft:hand.scrollLeft,
      },
      content:{
        overflowY:contentStyle.overflowY,
        clientHeight:content.clientHeight,
        scrollHeight:content.scrollHeight,
        scrollTop:content.scrollTop,
      },
      leave:{ top:leaveRect.top, bottom:leaveRect.bottom, height:leaveRect.height, viewportHeight:innerHeight },
      effectHost:{
        exists:host instanceof HTMLElement,
        actorExists:actor instanceof HTMLElement,
        animationName:actorStyle?.animationName || '',
        animationDuration:actorStyle?.animationDuration || '',
        iterationCount:actorStyle?.animationIterationCount || '',
        display:host instanceof HTMLElement ? getComputedStyle(host).display : '',
      },
    };
  });

  console.log(`DOMINO_LIVE_DIAGNOSTIC=${JSON.stringify(diagnostic)}`);
  expect(diagnostic.importMapHasV3).toBe(true);
  expect(diagnostic.cosmeticsSheetLoaded).toBe(true);
  expect(diagnostic.effectsSheetLoaded).toBe(true);
  expect(diagnostic.cosmeticsHref).toContain('full-live-v3-scroll');
  expect(diagnostic.effectsHref).toContain('accepted-preview-live-v3');
  expect(diagnostic.marker).toBe('full-v3');
  expect(diagnostic.theme).toBe('walnut');
  expect(diagnostic.elements).toBe('neon');
  expect(diagnostic.effect).toBe('game-domino-effect-precision-drop');
  expect(diagnostic.hand.display).toBe('flex');
  expect(diagnostic.hand.overflowX).toBe('auto');
  expect(diagnostic.hand.scrollWidth).toBeGreaterThan(diagnostic.hand.clientWidth);
  expect(diagnostic.hand.scrollLeft).toBeGreaterThan(0);
  expect(diagnostic.content.overflowY).toBe('auto');
  expect(diagnostic.content.scrollHeight).toBeGreaterThan(diagnostic.content.clientHeight);
  expect(diagnostic.content.scrollTop).toBeGreaterThan(0);
  expect(diagnostic.leave.bottom).toBeLessThanOrEqual(diagnostic.leave.viewportHeight + 1);
  expect(diagnostic.leave.top).toBeGreaterThanOrEqual(-1);
  expect(diagnostic.effectHost.exists).toBe(true);
  expect(diagnostic.effectHost.actorExists).toBe(true);
  expect(diagnostic.effectHost.display).not.toBe('none');
  expect(diagnostic.effectHost.animationName).toContain('mgw-domino-v18-precision-flight');
  expect(diagnostic.effectHost.iterationCount).toBe('1');
});
