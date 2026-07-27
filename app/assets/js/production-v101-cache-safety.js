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
    if (id.startsWith('start') && id.endsWith('SearchBtn')) invalidateV101Cache(GAME_BALANCE_CACHE);
    if (['accept','start'].includes(inviteAction)) invalidateV101Cache(GAME_BALANCE_CACHE);
    if (button.closest('[data-create-rematch]')) invalidateV101Cache(['notifications','invite_opponents']);
    if (id === 'storeCreateOrder') invalidateV101Cache(['shop_status','shop_orders','profile','notifications']);
  }, true);

  document.addEventListener('mgw:v99-game-found', () => invalidateV101Cache(GAME_BALANCE_CACHE));
  document.addEventListener('mgw:game-finished', () => invalidateV101Cache(GAME_BALANCE_CACHE));
}
