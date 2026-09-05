import { state } from '../state.js?v=27';
import { initMgwProfileEntryEffects as initBaseMgwProfileEntryEffects } from './mgw-profile-entry-effects.js?v=2&mvp19_3=acceptance-corrective';

const ENTRY_EFFECT_SLOT = 'profile_entry_effect';
const ENTRY_EFFECT_IDS = new Set([
  'profile-entry-effect-01',
  'profile-entry-effect-02',
  'profile-entry-effect-03',
]);

let initialized = false;

function normalizedId(value){
  return String(value ?? '').trim();
}

function selectedLocalEntryEffectId(){
  const itemId = normalizedId(state.profileInventory?.equipped?.[ENTRY_EFFECT_SLOT]);
  return ENTRY_EFFECT_IDS.has(itemId) ? itemId : '';
}

function localPlayerIds(){
  const telegramId = globalThis.Telegram?.WebApp?.initDataUnsafe?.user?.id;
  return new Set([
    state.user?.id,
    state.user?.provider_subject,
    state.user?.telegram_id,
    state.session?.user_id,
    state.session?.provider_subject,
    telegramId,
  ].map(normalizedId).filter(Boolean));
}

function isLocalPlayer(player, ids){
  if (!player || typeof player !== 'object') return false;
  if (player.is_me === true || player.me === true || player.viewer === true) return true;
  const playerId = normalizedId(player.id);
  return playerId !== '' && ids.has(playerId);
}

function applyLocalEntryEffectProjection(game){
  if (!game || typeof game !== 'object') return false;
  const selected = selectedLocalEntryEffectId();
  if (!selected) return false;

  const players = Array.isArray(game.players) ? game.players : [];
  if (!players.length) return false;
  const ids = localPlayerIds();
  let changed = false;

  for (const player of players) {
    if (!isLocalPlayer(player, ids)) continue;
    const projected = normalizedId(player.entry_effect_item_id);
    if (ENTRY_EFFECT_IDS.has(projected)) return false;
    player.entry_effect_item_id = selected;
    changed = true;
    break;
  }
  return changed;
}

function primeCurrentGameProjection(){
  applyLocalEntryEffectProjection(state.activeGame);
}

export function initMgwProfileEntryEffects(){
  if (initialized) return;
  initialized = true;

  // Register the projection adapter before the base presentation owner so a
  // screen-change event can repair the local player's missing cosmetic before
  // the existing Entry Effect probe reads state.activeGame.
  document.addEventListener('mgw:screen-changed', event => {
    if (normalizedId(event?.detail?.to) !== 'game') return;
    primeCurrentGameProjection();
    queueMicrotask(primeCurrentGameProjection);
  });

  // Phase-B may start another match while the route is already `game`, so there
  // is no screen transition to wake the base probe. Repair the entering payload
  // before enterGame adopts it, then let the existing presentation owner handle
  // the visual layer exactly once.
  document.addEventListener('mgw:phase-b-game-entering', event => {
    applyLocalEntryEffectProjection(event?.detail?.game);
    queueMicrotask(primeCurrentGameProjection);
  });

  // If the user changes the selected Entry Effect while an active game payload
  // is still in memory, keep the local presentation projection coherent without
  // adding any network request or touching game rules/state ownership.
  document.addEventListener('mgw:cosmetic-inventory-changed', event => {
    if (normalizedId(event?.detail?.slot) !== ENTRY_EFFECT_SLOT) return;
    primeCurrentGameProjection();
  });

  initBaseMgwProfileEntryEffects();
}
