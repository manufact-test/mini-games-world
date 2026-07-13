import { state } from '../../state.js?v=27';
import { APP_CONFIG } from '../../config.js?v=38';
import { api } from '../../api/client.js?v=47';
import { toast } from '../../components/toast.js?v=41';
import { openSheet, closeSheet } from '../../components/sheet.js?v=68';
import { showScreen } from '../../router.js?v=27';
import { haptic } from '../../telegram/telegram-app.js?v=27';
import { renderBalances, roomName } from '../../ui.js?v=27';
import { startSearchPolling } from '../../screens/search-screen.js?v=72';
import { startGamePolling } from '../../screens/game-screen.js?v=72';
import { isSessionLocked, sessionMessage } from '../../session.js?v=27';

let initialized = false;

export function initChessEntry(){
  if (initialized) return;
  initialized = true;
  document.addEventListener('click', event => {
    if (!event.target.closest('#playChess')) return;
    openChessSetup();
  });
}

function openChessSetup(){
  if (isSessionLocked(state.session)) return toast(sessionMessage(state.session));
  haptic('light');
  state.selectedGame = 'chess';
  const isGold = state.room === 'gold';
  const selectedBet = isGold ? Number(state.selectedBet || APP_CONFIG.goldBets[0]) : APP_CONFIG.matchBet;
  state.selectedBet = selectedBet;
  const betChoices = isGold
    ? APP_CONFIG.goldBets.map(bet => `<button class="choice gold ${bet === selectedBet ? 'active' : ''}" data-chess-bet="${bet}" type="button">${bet} коинов</button>`).join('')
    : `<button class="choice active" data-chess-bet="${APP_CONFIG.matchBet}" type="button">${APP_CONFIG.matchBet} коинов</button>`;

  openSheet(`
    <div class="sheet-head">
      <div><h2>Шахматы</h2><p>${roomName(state.room)}. Классическая партия на поле 8×8.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="setup-scroll chess-start-scroll">
      <div class="small-note">Стороны распределяются случайно. Белые ходят первыми. На каждый ход даётся 60 секунд.</div>
      <div class="section-title"><h2>Поле</h2></div>
      ${chessPreviewMarkup()}
      <div class="section-title"><h2>Стоимость участия</h2></div>
      <div class="choice-grid ${isGold ? '' : 'single-choice'}" id="chessBetChoices">${betChoices}</div>
    </div>
    <button class="btn ${isGold ? 'gold' : 'primary'} full setup-start-btn" id="startChessSearchBtn" type="button">Начать поиск</button>
  `);

  document.querySelectorAll('[data-chess-bet]').forEach(button => button.addEventListener('click', () => {
    state.selectedBet = Number(button.dataset.chessBet);
    document.querySelectorAll('[data-chess-bet]').forEach(item => item.classList.toggle('active', item === button));
  }));
  document.getElementById('startChessSearchBtn')?.addEventListener('click', startChessSearch);
}

async function startChessSearch(){
  if (isSessionLocked(state.session)) return toast(sessionMessage(state.session));
  const bet = state.room === 'match' ? APP_CONFIG.matchBet : Number(state.selectedBet || APP_CONFIG.goldBets[0]);
  try {
    closeSheet();
    const result = await api.startSearch(state.room, bet, 8, 'chess');
    state.user = result.user || state.user;
    state.selectedGame = 'chess';
    state.selectedBet = bet;
    renderBalances(state.user);
    if (result.game) {
      state.activeGame = result.game;
      showScreen('game');
      startGamePolling(result.game.id);
      return;
    }
    const info = document.getElementById('searchInfo');
    if (info) info.textContent = `Шахматы · ${roomName(state.room)} · участие ${bet} коинов · поле 8×8`;
    showScreen('search');
    startSearchPolling();
  } catch (error) {
    toast(error.message);
  }
}

function chessPreviewMarkup(){
  const board = [
    'bR','bN','bB','bQ','bK','bB','bN','bR','bP','bP','bP','bP','bP','bP','bP','bP',
    '','','','','','','','','','','','','','','','','','','','','','','','','','','','','','','','',
    'wP','wP','wP','wP','wP','wP','wP','wP','wR','wN','wB','wQ','wK','wB','wN','wR',
  ];
  return `
    <div class="chess-start-preview" aria-hidden="true">
      ${board.map((piece, index) => `<span class="${(Math.floor(index / 8) + index % 8) % 2 ? 'dark' : 'light'}">${piece ? `<i class="${piece[0] === 'w' ? 'white' : 'black'}">${pieceGlyph(piece)}</i>` : ''}</span>`).join('')}
    </div>
    <div class="chess-preview-caption">8×8 · классические шахматы</div>
  `;
}

function pieceGlyph(piece){
  const type = String(piece || '').slice(1);
  return ({K:'♚',Q:'♛',R:'♜',B:'♝',N:'♞',P:'♟'})[type] || '';
}
