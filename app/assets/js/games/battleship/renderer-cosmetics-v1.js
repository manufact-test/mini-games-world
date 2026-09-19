import {
  renderBattleshipSurface as renderBaseBattleshipSurface,
  battleshipMeta,
  battleshipPlayerMark,
  battleshipStatus,
} from './renderer.js?v=60&shot=miss-no-impact&base=mvp19_12-live-maps-fleets-v2';
import { state } from '../../state.js?v=27';

const THEME_SLOT = 'game_battleship_theme';
const ELEMENTS_SLOT = 'game_battleship_elements';

const MAP_PREFIX = 'game-battleship-map-';
const FLEET_PREFIX = 'game-battleship-fleet-';

const MAP_VARIANTS = new Set(['sea','dark-military','storm','neon']);
const FLEET_VARIANTS = new Set(['classic','modern','armored','neon']);

ensureLiveStyles();

export { battleshipMeta, battleshipPlayerMark, battleshipStatus };

export function renderBattleshipSurface(args){
  const { game, me, container } = args || {};
  const slots = presentationSlots(game, me);
  const mapVariant = variantFromItem(slots[THEME_SLOT], MAP_PREFIX, MAP_VARIANTS);
  const fleetVariant = variantFromItem(slots[ELEMENTS_SLOT], FLEET_PREFIX, FLEET_VARIANTS);

  renderBaseBattleshipSurface(args);

  if (!(container instanceof HTMLElement)) return;
  container.dataset.mgwBattleshipLiveCosmetics = 'maps-fleets-v2';
  container.dataset.battleshipMap = mapVariant;
  container.dataset.battleshipFleet = fleetVariant;
}

function presentationSlots(game, me){
  const local = state?.profileInventory?.equipped;
  if (local && typeof local === 'object') return local;

  const directMe = me?.game_cosmetics?.slots;
  if (directMe && typeof directMe === 'object') return directMe;

  const directGame = game?.my_game_cosmetics?.slots;
  if (directGame && typeof directGame === 'object') return directGame;

  const myId = String(me?.id || '');
  const players = Array.isArray(game?.players) ? game.players : [];
  const viewer = players.find(player => String(player?.id || '') === myId) || null;
  const playerSlots = viewer?.game_cosmetics?.slots;
  return playerSlots && typeof playerSlots === 'object' ? playerSlots : {};
}

function variantFromItem(value, prefix, allowed){
  const itemId = String(value || '');
  if (!itemId.startsWith(prefix)) return 'base';
  const variant = itemId.slice(prefix.length);
  return allowed.has(variant) ? variant : 'base';
}

function ensureLiveStyles(){
  if (typeof document === 'undefined') return;
  const href = new URL('../../../css/games/battleship/live-cosmetics-v1.css?v=2&mvp19_12=live-maps-fleets-v2&frame=full-v1&neon_fleet=filled-v2', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-battleship-live-cosmetics]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwBattleshipLiveCosmetics = 'maps-fleets-v2';
    return;
  }

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwBattleshipLiveCosmetics = 'maps-fleets-v2';
  link.href = href;
  document.head.appendChild(link);
}
