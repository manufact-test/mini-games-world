import { APP_CONFIG } from '../config.js?v=38';
import { state } from '../state.js?v=27';
import { getInitData } from '../telegram/telegram-app.js?v=21';
import { getSessionId, getDeviceId } from '../session.js?v=1131';

const RESULT_WATCH_URL = `${window.location.origin}/bot/game-watch.php`;
const FRIENDS_URL = `${window.location.origin}/bot/friends.php`;
const COSMETIC_STORE_URL = `${window.location.origin}/bot/cosmetic-store.php`;
const PROFILE_V2_URL = `${window.location.origin}/bot/profile-v2.php`;
const GAME_REACTION_URL = `${window.location.origin}/bot/game-reaction.php`;

let profileV2ReadPromise = null;

async function requestUrl(url, payload = {}){
  const response = await fetch(url, {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({ initData:getInitData(), sessionId:getSessionId(), deviceId:getDeviceId(), ...payload })
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) {
    const error = new Error(data?.error || `Ошибка API: ${response.status}`);
    error.code = data?.code || '';
    throw error;
  }
  return data;
}
async function request(action, payload = {}){ return requestUrl(APP_CONFIG.apiBase, { action, ...payload }); }

function publishCosmeticInventory(result){
  const equipped = result?.store?.inventory?.equipped;
  if (equipped && typeof equipped === 'object') {
    const current = state.profileInventory && typeof state.profileInventory === 'object' ? state.profileInventory : {};
    state.profileInventory = { ...current, equipped:{ ...equipped } };
    document.dispatchEvent(new CustomEvent('mgw:cosmetic-inventory-changed', { detail:{ equipped:{ ...equipped } } }));
  }
  return result;
}

async function requestCosmeticStore(payload){
  return publishCosmeticInventory(await requestUrl(COSMETIC_STORE_URL, payload));
}

function finishedActiveGameId(){
  const game = state.activeGame;
  return String(game?.status || '') === 'finished' ? String(game?.id || '') : '';
}

async function requestHistory(){
  const targetGameId = finishedActiveGameId();
  if (targetGameId) {
    return requestUrl(RESULT_WATCH_URL, { gameId:targetGameId, mode:'result' });
  }
  return request('history');
}

async function requestMgwProfile(){
  const result = await requestUrl(`${window.location.origin}/bot/profile.php`);
  if (result?.inventory && typeof result.inventory === 'object') {
    state.profileInventory = result.inventory;
  }
  return result;
}

function requestProfileV2(profileUpdate = null){
  if (profileUpdate) {
    return requestUrl(PROFILE_V2_URL, { profile_update:profileUpdate });
  }
  if (profileV2ReadPromise) return profileV2ReadPromise;
  profileV2ReadPromise = requestUrl(PROFILE_V2_URL)
    .finally(() => { profileV2ReadPromise = null; });
  return profileV2ReadPromise;
}

export const api = {
  bootstrap: () => request('bootstrap'),
  stats: () => request('stats'),
  weeklyMatchStatus: () => request('weekly_match_status'),
  startSearch: (room, bet, boardSize, gameType = 'tictactoe') => request('start_search', { room, bet, boardSize, gameType }),
  leaveSearch: () => request('leave_search'),
  gameState: (gameId = null) => request('game_state', { gameId }),
  gameAction: (gameId, gameAction) => request('game_action', { gameId, gameAction }),
  makeMove: (gameId, cell) => request('make_move', { gameId, cell }),
  leaveGame: (gameId) => request('leave_game', { gameId }),
  gameReaction: (gameId, reaction) => requestUrl(GAME_REACTION_URL, { gameId, reaction }),
  profile: () => request('profile'),
  profileV2: (profileUpdate = null) => requestProfileV2(profileUpdate),
  mgwProfile: () => requestMgwProfile(),
  friends: (payload = {}) => requestUrl(FRIENDS_URL, payload),
  history: () => requestHistory(),
  historyFast: () => request('history'),
  support: (type, message) => request('support', { type, message }),
  cosmeticStoreStatus: () => requestCosmeticStore({ action:'status' }),
  cosmeticStorePurchase: (offerId, requestToken) => requestCosmeticStore({ action:'purchase', offer_id:offerId, request_token:requestToken }),
  cosmeticStoreEquip: itemId => requestCosmeticStore({ action:'equip', item_id:itemId }),
  cosmeticStoreUnequip: equipSlot => requestCosmeticStore({ action:'unequip', equip_slot:equipSlot }),
  shopStatus: () => request('shop_status'),
  shopOrders: () => requestUrl(APP_CONFIG.shopHistoryBase),
  notifications: (markRead = false) => requestUrl(APP_CONFIG.notificationsBase, { markRead }),
  shopOrder: (itemId, denominationId, requestToken) => request('shop_order', { itemId, denominationId, requestToken }),
  paymentCreateDraft: (room, amount) => request('payment_create_draft', { room, amount })
};