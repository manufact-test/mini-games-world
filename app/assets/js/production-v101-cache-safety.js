import { invalidateV101Cache } from './production-v101-speed-runtime.js?v=101';

const GAME_BALANCE_CACHE = ['stats','profile','weekly_match_status','shop_status'];
let initialized = false;

export function initV101CacheSafety(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('pointerdown', event => {
    const origin = event.target;
    if (!(origin instanceof Element)) return;
    const button = origin.closest('button, [role="button"]');
    if (!button) return;

    const id = String(button.id || '');
    const inviteAction = String(button.closest('[data-invite-action]')?.dataset.inviteAction || '');
    if (button.closest('[data-open-player-picker]')) abortBackgroundReads();
    if (id.startsWith('start') && id.endsWith('SearchBtn')) invalidateSafely(GAME_BALANCE_CACHE);
    if (['accept','start'].includes(inviteAction)) invalidateSafely(GAME_BALANCE_CACHE);
    if (button.closest('[data-create-rematch]')) invalidateSafely(['notifications','invite_opponents']);
    if (id === 'storeCreateOrder') invalidateSafely(['shop_status','shop_orders','profile','notifications']);
  }, true);

  document.addEventListener('mgw:v99-game-found', () => invalidateSafely(GAME_BALANCE_CACHE));
  document.addEventListener('mgw:game-finished', () => invalidateSafely(GAME_BALANCE_CACHE));
}

function invalidateSafely(ids){
  abortBackgroundReads();
  invalidateV101Cache(ids);
}

function abortBackgroundReads(){
  const background = window.__MGW_V101_SPEED__?.backgroundControllers;
  if (!background || typeof background[Symbol.iterator] !== 'function') return;
  for (const controller of [...background]) {
    try { controller.abort('cache-invalidated-by-state-change'); } catch (error) {}
  }
  background.clear?.();
}
