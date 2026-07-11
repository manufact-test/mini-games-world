import { state } from '../../state.js?v=27';
import { APP_CONFIG } from '../../config.js?v=38';
import { api } from '../../api/client.js?v=47';
import { toast } from '../../components/toast.js?v=41';
import { openSheet, closeSheet } from '../../components/sheet.js?v=27';
import { showScreen } from '../../router.js?v=27';
import { haptic } from '../../telegram/telegram-app.js?v=27';
import { renderBalances, roomName } from '../../ui.js?v=27';
import { startSearchPolling } from '../../screens/search-screen.js?v=53';
import { startGamePolling } from '../../screens/game-screen.js?v=53';
import { isSessionLocked, sessionMessage } from '../../session.js?v=27';

let initialized = false;

export function initTicTacToeEntry(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('click', event => {
    const button = event.target.closest('#playTicTacToe');
    if (!button) return;

    // This game module is the single active owner of the Tic Tac Toe setup flow.
    // Stop the legacy home-screen listener from opening its old duplicate setup.
    event.stopImmediatePropagation();
    openGameSetup();
  });
}

function openGameSetup(){
  if (isSessionLocked(state.session)) return toast(sessionMessage(state.session));
  haptic('light');
  state.selectedGame = 'tictactoe';

  const isGold = state.room === 'gold';
  const betChoices = isGold
    ? APP_CONFIG.goldBets.map(bet => `<button class="choice gold ${bet === state.selectedBet ? 'active' : ''}" data-bet="${bet}" type="button">${bet} коинов</button>`).join('')
    : `<button class="choice active" data-bet="${APP_CONFIG.matchBet}" type="button">${APP_CONFIG.matchBet} коинов</button>`;

  openSheet(`
    <div class="sheet-head">
      <div><h2>Крестики-нолики</h2><p>${roomName(state.room)}. Выберите поле${isGold ? ' и стоимость участия' : ''}.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="setup-scroll">
      <div class="small-note">${isGold ? 'Матч начнётся только с соперником, который выбрал такие же условия.' : 'В Match-комнате участие всегда стоит 10 коинов.'}</div>
      <div class="section-title"><h2>Поле</h2></div>
      <div class="choice-grid field-size-grid" id="boardChoices">
        ${APP_CONFIG.boardSizes.map(size => `<button class="choice ${size === state.selectedBoardSize ? 'active' : ''}" data-board-size="${size}" type="button">${size}×${size}</button>`).join('')}
      </div>
      <div class="section-title"><h2>Стоимость участия</h2></div>
      <div class="choice-grid ${isGold ? '' : 'single-choice'}" id="betChoices">${betChoices}</div>
    </div>
    <button class="btn ${isGold ? 'gold' : 'primary'} full setup-start-btn" id="startSearchBtn" type="button">Начать поиск</button>
  `);

  document.querySelectorAll('[data-board-size]').forEach(btn => btn.addEventListener('click', () => {
    state.selectedBoardSize = Number(btn.dataset.boardSize);
    document.querySelectorAll('[data-board-size]').forEach(item => item.classList.toggle('active', item === btn));
  }));
  document.querySelectorAll('[data-bet]').forEach(btn => btn.addEventListener('click', () => {
    state.selectedBet = Number(btn.dataset.bet);
    document.querySelectorAll('[data-bet]').forEach(item => item.classList.toggle('active', item === btn));
  }));
  document.getElementById('startSearchBtn')?.addEventListener('click', startSearch);
}

async function startSearch(){
  if (isSessionLocked(state.session)) return toast(sessionMessage(state.session));
  state.selectedGame = 'tictactoe';

  try {
    closeSheet();
    const result = await api.startSearch(state.room, state.selectedBet, state.selectedBoardSize, 'tictactoe');
    state.user = result.user || state.user;
    renderBalances(state.user);

    if (result.game) {
      state.activeGame = result.game;
      state.selectedGame = 'tictactoe';
      showScreen('game');
      startGamePolling(result.game.id);
      return;
    }

    const info = document.getElementById('searchInfo');
    if (info) {
      info.textContent = `${roomName(state.room)} · участие ${state.selectedBet} коинов · поле ${state.selectedBoardSize}×${state.selectedBoardSize}`;
    }
    showScreen('search');
    startSearchPolling();
  } catch (error) {
    toast(error.message);
  }
}
