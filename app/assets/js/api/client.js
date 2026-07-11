import { APP_CONFIG } from '../config.js?v=21';
import { getInitData } from '../telegram/telegram-app.js?v=21';
import { getSessionId } from '../session.js?v=21';

async function request(action, payload = {}){
  const response = await fetch(APP_CONFIG.apiBase, {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({ action, initData:getInitData(), sessionId:getSessionId(), ...payload })
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) {
    throw new Error(data?.error || `Ошибка API: ${response.status}`);
  }
  return data;
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
  history: () => request('history'),
  support: (type, message) => request('support', { type, message }),
  shopStatus: () => request('shop_status'),
  // Текущий endpoint пока использует старые транспортные имена полей.
  // На сервере amount трактуется только как ключ повтора и никогда как цена заказа.
  shopOrder: (itemId, denominationId, requestToken) => request('shop_order', {
    country: itemId,
    provider: denominationId,
    amount: requestToken,
  }),
  paymentCreateDraft: (room, amount) => request('payment_create_draft', { room, amount })
};
