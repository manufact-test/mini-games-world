import { state } from './state.js?v=27';
import { openSheet, closeSheet } from './components/sheet.js?v=68';
import { showScreen } from './router.js?v=27';
import { clearTimer } from './ui.js?v=89';
import { gameTypeOf } from './games/game-router-v102.js?v=102';
import { clearGameView } from './screens/game-screen-v102-safe.js?v=102';

const runtime = window.__MGW_V100_GAME_RUNTIME__ ||= {
  initialized:false,
  games:new Map(),
  pointerHoldUntil:0,
  resultOpened:new Set(),
  weeklyNotified:new Set(),
};
let initialized = false;

export function initV104ResultInstant(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('mgw:v101-finished-response', event => {
    const game = event.detail?.game || null;
    const me = normalizeViewer(event.detail?.me) || resolveViewer(game);
    if (!game?.id || String(game.status || '') !== 'finished' || !me?.id) return;
    if (String(state.activeGame?.id || '') !== String(game.id)) return;
    if (!document.getElementById('screen-game')?.classList.contains('active')) return;
    openFinishedResult(game, me);
  });
}

function openFinishedResult(game, me){
  const id = String(game.id || '');
  if (!id) return;
  const visibleId = String(document.querySelector('#sheet [data-result-game-id]')?.dataset.resultGameId || '');
  if (visibleId === id) return;

  runtime.resultOpened.add(id);
  state.timers.game = clearTimer(state.timers.game);
  state.activeGame = game;

  if (!runtime.weeklyNotified.has(id)) {
    runtime.weeklyNotified.add(id);
    document.dispatchEvent(new CustomEvent('mgw:game-finished', { detail:{ gameId:id, game } }));
  }

  const copy = resultCopy(game, me);
  openSheet(`
    <span data-result-game-id="${escapeHtml(id)}" hidden></span>
    <div class="sheet-head">
      <div><h2>${escapeHtml(copy.title)}</h2><p>${escapeHtml(copy.text)}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="stack">
      <button class="btn primary full" id="newOpponent" type="button">Найти нового соперника</button>
      <button class="btn ghost full" id="goHome" type="button">В меню</button>
    </div>
  `);

  document.getElementById('newOpponent')?.addEventListener('click', () => {
    closeSheet();
    document.dispatchEvent(new CustomEvent('mgw:v100-search-request', { detail:searchContextFromGame(game) }));
  });
  document.getElementById('goHome')?.addEventListener('click', () => {
    closeSheet();
    state.activeGame = null;
    clearGameView();
    showScreen('home');
    document.dispatchEvent(new CustomEvent('mgw:game-dismissed'));
  });
}

function resultCopy(game, me){
  let title = 'Ничья';
  let text = chessDrawText(game) || 'Коины возвращены на баланс.';
  if (game.winner_id) {
    const isWin = String(game.winner_id) === String(me.id);
    title = isWin ? 'Победа!' : 'Поражение';
    if (game.finish_reason === 'timeout') {
      text = isWin
        ? `Соперник не сделал ход вовремя. Вы получили ${game.payout ?? 0} коинов.`
        : 'Время хода вышло. Засчитано техническое поражение.';
    } else if (game.finish_reason === 'player_left') {
      text = isWin
        ? `Соперник вышел из матча. Вы получили ${game.payout ?? 0} коинов.`
        : 'Вы вышли из матча. Засчитано техническое поражение.';
    } else if (gameTypeOf(game) === 'chess' && game.chess_end_reason === 'checkmate') {
      text = isWin ? `Мат. Вы получили ${game.payout ?? 0} коинов.` : 'Вашему королю поставлен мат.';
    } else if (gameTypeOf(game) === 'domino' && game.end_reason === 'empty_hand') {
      text = isWin
        ? `Вы первыми избавились от всех костяшек и получили ${game.payout ?? 0} коинов.`
        : 'Соперник первым избавился от всех костяшек.';
    } else {
      text = isWin ? `Вы получили ${game.payout ?? 0} коинов.` : 'Соперник оказался сильнее.';
    }
  }
  text += reversiScoreText(game, me);
  text += goScoreText(game, me);
  text += dominoScoreText(game);
  return { title, text };
}

function searchContextFromGame(game){
  return {
    gameType:gameTypeOf(game),
    room:String(game?.room || state.room || 'match') === 'gold' ? 'gold' : 'match',
    bet:Number(game?.bet || state.selectedBet || 10),
    size:Number(game?.board_size || state.selectedBoardSize || 3),
  };
}

function resolveViewer(game){
  const players = Array.isArray(game?.players) ? game.players : [];
  const explicit = players.find(player => player?.is_me === true || player?.viewer === true);
  if (explicit?.id !== undefined) return normalizeViewer(explicit);
  const candidates = [state.user?.id, state.user?.mgw_id, state.user?.telegram_id]
    .map(value => String(value || ''))
    .filter(Boolean);
  for (const candidate of candidates) {
    const found = players.find(player => String(player?.id || '') === candidate);
    if (found) return normalizeViewer(found);
  }
  return null;
}

function normalizeViewer(viewer){
  const id = String(viewer?.id || '');
  return id ? { ...viewer, id } : null;
}

function chessDrawText(game){
  if (gameTypeOf(game) !== 'chess') return '';
  const label = {
    stalemate:'Пат.',
    insufficient_material:'Недостаточно фигур для мата.',
    threefold_repetition:'Позиция повторилась три раза.',
    fifty_move:'Сработало правило 50 ходов.',
  }[String(game?.chess_end_reason || '')] || 'Партия завершилась вничью.';
  return `${label} Коины возвращены на баланс.`;
}

function reversiScoreText(game, me){
  if (gameTypeOf(game) !== 'reversi') return '';
  const player = (game?.players || []).find(item => String(item?.id || '') === String(me?.id || ''));
  const side = String(player?.side || game?.viewer_side || 'black');
  const black = Number(game?.final_counts?.black ?? game?.black_count ?? 0);
  const white = Number(game?.final_counts?.white ?? game?.white_count ?? 0);
  return ` Итоговый счёт: ${side === 'black' ? black : white}:${side === 'black' ? white : black}.`;
}

function goScoreText(game, me){
  if (gameTypeOf(game) !== 'go' || !game?.final_score) return '';
  const player = (game?.players || []).find(item => String(item?.id || '') === String(me?.id || ''));
  const side = String(player?.side || game?.viewer_side || 'black');
  const black = formatScore(game.final_score.black_total);
  const white = formatScore(game.final_score.white_total);
  return ` Итоговый счёт: ${side === 'black' ? black : white}:${side === 'black' ? white : black}.`;
}

function dominoScoreText(game){
  if (gameTypeOf(game) !== 'domino' || game?.my_points === null || game?.my_points === undefined) return '';
  return ` Оставшиеся точки: ${Number(game.my_points || 0)}:${Number(game.opponent_points || 0)}.`;
}

function formatScore(value){
  const number = Number(value || 0);
  return Number.isInteger(number) ? String(number) : number.toFixed(1).replace('.', ',');
}

function escapeHtml(value){
  return String(value ?? '').replace(/[&<>'"]/g, character => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#039;', '"':'&quot;',
  }[character]));
}
