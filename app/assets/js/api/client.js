import { APP_CONFIG } from '../config.js?v=38';
import { state } from '../state.js?v=27';
import { getInitData } from '../telegram/telegram-app.js?v=21';
import { getSessionId, getDeviceId } from '../session.js?v=1131';

const HISTORY_FRESHNESS_DELAYS_MS = [80, 120, 180, 260];

async function requestUrl(url, payload = {}){
  const response = await fetch(url, {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({ initData:getInitData(), sessionId:getSessionId(), deviceId:getDeviceId(), ...payload })
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) throw new Error(data?.error || `Ошибка API: ${response.status}`);
  return data;
}
async function request(action, payload = {}){ return requestUrl(APP_CONFIG.apiBase, { action, ...payload }); }

function finishedActiveGameId(){
  const game = state.activeGame;
  return String(game?.status || '') === 'finished' ? String(game?.id || '') : '';
}

function historyHasMatch(result, gameId){
  if (!gameId) return true;
  const matches = Array.isArray(result?.history?.matches) ? result.history.matches : [];
  return matches.some(item => String(item?.id || '') === gameId);
}

async function requestHistory(){
  const targetGameId = finishedActiveGameId();
  let lastResult = null;
  let lastError = null;

  for (let attempt = 0; attempt <= HISTORY_FRESHNESS_DELAYS_MS.length; attempt++) {
    if (attempt > 0) await delay(HISTORY_FRESHNESS_DELAYS_MS[attempt - 1]);
    try {
      lastResult = await request('history');
      lastError = null;
      if (!targetGameId || historyHasMatch(lastResult, targetGameId)) return lastResult;
    } catch (error) {
      lastError = error;
      if (!targetGameId || attempt === HISTORY_FRESHNESS_DELAYS_MS.length) throw error;
    }
  }

  if (lastResult) return lastResult;
  throw lastError || new Error('Не удалось загрузить историю.');
}

function delay(ms){
  return new Promise(resolve => window.setTimeout(resolve, ms));
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
  history: () => requestHistory(),
  historyFast: () => request('history'),
  support: (type, message) => request('support', { type, message }),
  shopStatus: () => request('shop_status'),
  shopOrders: () => requestUrl(APP_CONFIG.shopHistoryBase),
  notifications: (markRead = false) => requestUrl(APP_CONFIG.notificationsBase, { markRead }),
  shopOrder: (itemId, denominationId, requestToken) => request('shop_order', { itemId, denominationId, requestToken }),
  paymentCreateDraft: (room, amount) => request('payment_create_draft', { room, amount })
};