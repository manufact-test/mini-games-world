import { APP_CONFIG } from '../config.js?v=38';
import { getInitData } from '../telegram/telegram-app.js?v=21';
import { getSessionId } from '../session.js?v=21';

async function requestUrl(url, payload = {}){
  const response = await fetch(url, {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({ initData:getInitData(), sessionId:getSessionId(), ...payload })
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) {
    throw new Error(data?.error || `Ошибка API: ${response.status}`);
  }
  return data;
}

async function request(action, payload = {}){
  return requestUrl(APP_CONFIG.apiBase, { action, ...payload });
}

function normalizeHistoryResponse(result){
  const operations = Array.isArray(result?.history?.operations) ? result.history.operations : [];
  const seenShopOrders = new Set();

  result.history = result.history || {};
  result.history.operations = operations.filter(item => {
    if (String(item?.title || '') !== 'Заказ приза') return true;

    // One shop order currently has a balance ledger row and a technical shop_order row.
    // They share the same timestamp and amount. Show the financial event only once.
    const key = [
      String(item?.created_at || ''),
      String(item?.amount || 0),
      String(item?.title || ''),
    ].join('|');

    if (seenShopOrders.has(key)) return false;
    seenShopOrders.add(key);
    return true;
  });

  return result;
}

export const api = {
  bootstrap: () => request('bootstrap'),
  stats: () => request('stats'),
  startSearch: (room, bet, boardSize) => request('start_search', { room, bet, boardSize, gameType:'tictactoe' }),
  leaveSearch: () => request('leave_search'),
  gameState: (gameId = null) => request('game_state', { gameId }),
  makeMove: (gameId, cell) => request('make_move', { gameId, cell }),
  leaveGame: (gameId) => request('leave_game', { gameId }),
  profile: () => request('profile'),
  history: () => request('history').then(normalizeHistoryResponse),
  support: (type, message) => request('support', { type, message }),
  shopStatus: () => request('shop_status'),
  shopOrders: () => requestUrl(APP_CONFIG.shopHistoryBase),
  notifications: (markRead = false) => requestUrl(APP_CONFIG.notificationsBase, { markRead }),
  shopOrder: (itemId, denominationId, requestToken) => request('shop_order', {
    itemId,
    denominationId,
    requestToken,
  }),
  paymentCreateDraft: (room, amount) => request('payment_create_draft', { room, amount })
};