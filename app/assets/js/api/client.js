import { APP_CONFIG } from '../config.js?v=29';
import { getInitData } from '../telegram/telegram-app.js?v=29';
import { getSessionId } from '../session.js?v=29';

const RETRY_HTTP_STATUSES = new Set([429, 502, 503, 504]);

function wait(ms){
  return new Promise(resolve => setTimeout(resolve, ms));
}

function apiError(message, status = 0, retryable = false){
  const error = new Error(message);
  error.status = status;
  error.retryable = retryable;
  return error;
}

async function request(action, payload = {}){
  const maxAttempts = 3;

  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    let response;
    let data = null;

    try {
      response = await fetch(APP_CONFIG.apiBase, {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({ action, initData:getInitData(), sessionId:getSessionId(), ...payload })
      });
      data = await response.json().catch(() => null);
    } catch (error) {
      if (attempt < maxAttempts) {
        await wait(700 * attempt);
        continue;
      }
      throw apiError('Связь нестабильна. Повторите действие через пару секунд.', 0, true);
    }

    if (response.ok && data && data.ok !== false) {
      return data;
    }

    const retryable = RETRY_HTTP_STATUSES.has(response.status);
    if (retryable && attempt < maxAttempts) {
      await wait(response.status === 429 ? 1200 * attempt : 800 * attempt);
      continue;
    }

    if (response.status === 429) {
      throw apiError('Сервер занят. Повторяем поиск, подождите пару секунд.', response.status, true);
    }

    throw apiError(data?.error || `Ошибка API: ${response.status}`, response.status, retryable);
  }

  throw apiError('Сервер не ответил. Повторите действие через пару секунд.', 0, true);
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
  shopOrder: (country, provider, amount) => request('shop_order', { country, provider, amount }),
  paymentCreateDraft: (room, amount) => request('payment_create_draft', { room, amount })
};
