import { APP_CONFIG } from '../config.js?v=38';
import { state } from '../state.js?v=27';
import { getInitData } from '../telegram/telegram-app.js?v=21';
import { getSessionId, getDeviceId } from '../session.js?v=1131';

const RESULT_WATCH_URL = `${window.location.origin}/bot/game-watch.php`;
const FRIENDS_URL = `${window.location.origin}/bot/friends.php`;
const COSMETIC_STORE_URL = `${window.location.origin}/bot/cosmetic-store.php`;

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
  profile: () => request('profile'),
  profileV2: (profileUpdate = null) => requestUrl(`${window.location.origin}/bot/profile-v2.php`, profileUpdate ? { profile_update:profileUpdate } : {}),
  mgwProfile: () => requestUrl(`${window.location.origin}/bot/profile.php`),
  friends: (payload = {}) => requestUrl(FRIENDS_URL, payload),
  history: () => requestHistory(),
  historyFast: () => request('history'),
  support: (type, message) => request('support', { type, message }),
  cosmeticStoreStatus: () => requestUrl(COSMETIC_STORE_URL, { action:'status' }),
  cosmeticStorePurchase: (offerId, requestToken) => requestUrl(COSMETIC_STORE_URL, { action:'purchase', offer_id:offerId, request_token:requestToken }),
  cosmeticStoreEquip: itemId => requestUrl(COSMETIC_STORE_URL, { action:'equip', item_id:itemId }),
  shopStatus: () => request('shop_status'),
  shopOrders: () => requestUrl(APP_CONFIG.shopHistoryBase),
  notifications: (markRead = false) => requestUrl(APP_CONFIG.notificationsBase, { markRead }),
  shopOrder: (itemId, denominationId, requestToken) => request('shop_order', { itemId, denominationId, requestToken }),
  paymentCreateDraft: (room, amount) => request('payment_create_draft', { room, amount })
};
