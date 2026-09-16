import {
  renderDominoSurface as renderBaseDominoSurface,
  dominoMeta,
  dominoPlayerMark,
  dominoStatus,
} from './renderer.js?v=75&base=mvp19-9-live-effects-v1';
import { dominoPreviewMarkup } from '../../screens/store-screen-domino-store-v1.js?v=9&mvp19_9=domino-native-render-v9';

const EFFECT_SLOT = 'game_domino_effect';
const PRECISION_ID = 'game-domino-effect-precision-drop';
const STOCK_ID = 'game-domino-effect-stock-pulse';
const FINALE_ID = 'game-domino-effect-chain-finale';
const EFFECT_IDS = new Set([PRECISION_ID, STOCK_ID, FINALE_ID]);
const cosmeticsByGamePlayer = new Map();
const seenEventByGame = new Map();

ensureLiveStyles();

export { dominoMeta, dominoPlayerMark, dominoStatus };

export function renderDominoSurface(args){
  const { game, me, container } = args || {};
  cachePlayerCosmetics(game);
  renderBaseDominoSurface(args);
  decorateLiveDomino({ game, me, container });
}

function decorateLiveDomino({ game, me, container }){
  if (!(container instanceof HTMLElement)) return;

  const gameId = String(game?.id || '');
  if (!gameId) return;

  container.dataset.mgwDominoLiveCosmetics = 'effects-v1';

  const players = Array.isArray(game?.players) ? game.players : [];
  const action = game?.last_action || {};
  const actionType = String(action?.type || '');
  const actor = actionPlayer(game, players);
  const actorEffect = effectForPlayer(gameId, actor);
  const finishOwner = finishPlayer(game, players, me);
  const finishEffect = effectForPlayer(gameId, finishOwner);
  const signature = eventSignature(game);

  if (!signature || seenEventByGame.get(gameId) === signature) return;
  seenEventByGame.set(gameId, signature);
  clearLiveEffectHosts(gameId);

  if (prefersReducedMotion()) return;

  if (String(game?.status || '') === 'finished' && finishEffect === FINALE_ID) {
    mountFinale(gameId, container);
    return;
  }

  if (actionType === 'play' && actorEffect === PRECISION_ID) {
    container.querySelector('.domino-chain-slot.latest')?.classList.remove('animate-in');
    mountPrecision(gameId, container);
    return;
  }

  if (actionType === 'draw' && actorEffect === STOCK_ID) {
    container.querySelector('.domino-hand')?.classList.remove('draw-pulse');
    mountStock(gameId, container);
  }
}

function mountPrecision(gameId, container){
  const latest = container.querySelector('.domino-chain-slot.latest');
  const chainArea = container.querySelector('.domino-chain-area');
  if (!(chainArea instanceof HTMLElement)) return;

  const anchorRect = latest instanceof HTMLElement ? latest.getBoundingClientRect() : chainArea.getBoundingClientRect();
  const rotation = cssNumber(latest, '--domino-rotation', 0);
  const host = makeEffectHost(gameId, 'precision-drop');
  host.classList.add('is-precision');
  host.style.left = `${anchorRect.left + anchorRect.width / 2}px`;
  host.style.top = `${anchorRect.top + anchorRect.height / 2}px`;
  host.style.setProperty('--mgw-domino-live-rotation', `${rotation}deg`);
  document.body.appendChild(host);
  finishOnCanonicalAnimation(host, '.mgw-domino-v13-impact-piece', 'mgw-domino-v18-precision-flight');
}

function mountStock(gameId, container){
  const stock = container.querySelector('.domino-stock-count');
  const table = container.querySelector('.domino-table');
  if (!(table instanceof HTMLElement)) return;

  const anchorRect = stock instanceof HTMLElement ? stock.getBoundingClientRect() : table.getBoundingClientRect();
  const host = makeEffectHost(gameId, 'stock-pulse');
  host.classList.add('is-stock');
  host.style.left = `${anchorRect.left + anchorRect.width / 2}px`;
  host.style.top = `${anchorRect.top + anchorRect.height / 2}px`;
  document.body.appendChild(host);
  finishOnCanonicalAnimation(host, '.mgw-domino-v13-draw-piece', 'mgw-domino-v18-stock-flight');
}

function mountFinale(gameId, container){
  const chainArea = container.querySelector('.domino-chain-area');
  const table = container.querySelector('.domino-table');
  if (!(table instanceof HTMLElement)) return;

  const anchorRect = chainArea instanceof HTMLElement ? chainArea.getBoundingClientRect() : table.getBoundingClientRect();
  const host = makeEffectHost(gameId, 'chain-finale');
  host.classList.add('is-finale');
  host.style.left = `${anchorRect.left + anchorRect.width / 2}px`;
  host.style.top = `${anchorRect.top + anchorRect.height / 2}px`;
  document.body.appendChild(host);
  finishOnCanonicalAnimation(host, '.mgw-domino-v13-cascade-row > i:nth-child(5)', 'mgw-domino-v18-cascade-5');
}

function makeEffectHost(gameId, variant){
  const host = document.createElement('span');
  host.className = 'domino-live-fx-host';
  host.dataset.dominoLiveGame = gameId;
  host.dataset.dominoLiveEffect = variant;
  host.setAttribute('aria-hidden', 'true');

  const preview = document.createElement('span');
  preview.className = 'store-v2-game-preview domino-live-fx-preview';
  preview.dataset.gameType = 'domino';
  preview.dataset.cosmeticLayer = 'effect';
  preview.dataset.cosmeticVariant = variant;
  preview.innerHTML = dominoPreviewMarkup('effect', variant);
  host.appendChild(preview);
  return host;
}

function finishOnCanonicalAnimation(host, selector, animationName){
  const actor = host.querySelector(selector);
  if (!(actor instanceof HTMLElement)) {
    host.remove();
    return;
  }
  const finish = event => {
    if (event.target !== actor || String(event.animationName || '') !== animationName) return;
    actor.removeEventListener('animationend', finish);
    host.remove();
  };
  actor.addEventListener('animationend', finish);
}

function clearLiveEffectHosts(gameId){
  document.querySelectorAll('.domino-live-fx-host[data-domino-live-game]').forEach(host => {
    if (!(host instanceof HTMLElement)) return;
    if (String(host.dataset.dominoLiveGame || '') === gameId) host.remove();
  });
}

function cachePlayerCosmetics(game){
  const gameId = String(game?.id || '');
  if (!gameId) return;
  const players = Array.isArray(game?.players) ? game.players : [];
  players.forEach(player => {
    const playerId = String(player?.id || '');
    const slots = player?.game_cosmetics?.slots;
    if (!playerId || !slots || typeof slots !== 'object') return;
    cosmeticsByGamePlayer.set(`${gameId}:${playerId}`, { ...slots });
  });
}

function slotsFor(gameId, player){
  const direct = player?.game_cosmetics?.slots;
  if (direct && typeof direct === 'object') return direct;
  const playerId = String(player?.id || '');
  if (!gameId || !playerId) return {};
  return cosmeticsByGamePlayer.get(`${gameId}:${playerId}`) || {};
}

function effectForPlayer(gameId, player){
  const itemId = String(slotsFor(gameId, player)[EFFECT_SLOT] || '');
  return EFFECT_IDS.has(itemId) ? itemId : '';
}

function actionPlayer(game, players){
  const playerId = String(game?.last_action?.player_id || '');
  if (playerId) {
    const direct = players.find(player => String(player?.id || '') === playerId);
    if (direct) return direct;
  }

  const turnId = String(game?.turn || '');
  if (turnId && players.length === 2) {
    return players.find(player => String(player?.id || '') !== turnId) || null;
  }
  return null;
}

function finishPlayer(game, players, me){
  const winnerId = String(game?.winner_id || '');
  if (winnerId) {
    const winner = players.find(player => String(player?.id || '') === winnerId);
    if (winner) return winner;
  }
  return actionPlayer(game, players)
    || players.find(player => String(player?.id || '') === String(me?.id || ''))
    || players[0]
    || null;
}

function eventSignature(game){
  const gameId = String(game?.id || '');
  const status = String(game?.status || '');
  const moveCount = Number(game?.move_count || 0);
  const action = game?.last_action || {};
  const type = String(action?.type || '');

  if (status === 'finished') {
    return `finish:${gameId}:${moveCount}:${String(game?.winner_id || '')}:${String(game?.end_reason || game?.finish_reason || '')}`;
  }
  if (type === 'play') {
    return `play:${gameId}:${moveCount}:${String(action?.player_id || '')}:${String(action?.tile || '')}:${String(action?.side || '')}`;
  }
  if (type === 'draw') {
    return `draw:${gameId}:${moveCount}:${String(action?.player_id || '')}:${Number(action?.drawn_count || 0)}:${Number(game?.stock_count || 0)}`;
  }
  return '';
}

function cssNumber(element, property, fallback){
  if (!(element instanceof HTMLElement)) return fallback;
  const raw = getComputedStyle(element).getPropertyValue(property);
  const parsed = Number.parseFloat(raw);
  return Number.isFinite(parsed) ? parsed : fallback;
}

function prefersReducedMotion(){
  return typeof window !== 'undefined'
    && typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function ensureLiveStyles(){
  if (typeof document === 'undefined') return;
  const href = new URL('../../../css/games/domino/live-effects-v1.css?v=1&mvp19_9=accepted-preview-parity-v1', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-domino-live-effects]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwDominoLiveEffects = 'mvp19-9-live-effects-v1';
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwDominoLiveEffects = 'mvp19-9-live-effects-v1';
  link.href = href;
  document.head.appendChild(link);
}