import { state } from '../../state.js?v=27';
import { APP_CONFIG } from '../../config.js?v=38';
import { api } from '../../api/client.js?v=47';
import { toast } from '../../components/toast.js?v=41';
import { openSheet, closeSheet } from '../../components/sheet.js?v=68';
import { showScreen } from '../../router.js?v=27';
import { haptic } from '../../telegram/telegram-app.js?v=27';
import { renderBalances, roomName } from '../../ui.js?v=27';
import { startSearchPolling } from '../../screens/search-screen.js?v=70';
import { startGamePolling } from '../../screens/game-screen.js?v=70';
import { isSessionLocked, sessionMessage } from '../../session.js?v=27';

const FOUR_VARIANTS = {
  6: { columns: 6, rows: 5, label: 'Компактное', meta: '6×5' },
  7: { columns: 7, rows: 6, label: 'Классика', meta: '7×6' },
  8: { columns: 8, rows: 7, label: 'Большое', meta: '8×7' },
};

let initialized = false;

export function initFourInARowEntry(){
  if (initialized) return;
  initialized = true;
  document.addEventListener('click', event => {
    const button = event.target.closest('#playFourInARow');
    if (!button) return;
    openFourInARowSetup();
  });
}

function openFourInARowSetup(){
  if (isSessionLocked(state.session)) return toast(sessionMessage(state.session));
  haptic('light');
  state.selectedGame = 'four_in_a_row';
  const isGold = state.room === 'gold';
  const selectedSize = normalizeFourSize(state.selectedFourBoardSize || 7);
  state.selectedFourBoardSize = selectedSize;
  const betChoices = isGold
    ? APP_CONFIG.goldBets.map(bet => `<button class="choice gold ${bet === state.selectedBet ? 'active' : ''}" data-four-bet="${bet}" type="button">${bet} коинов</button>`).join('')
    : `<button class="choice active" data-four-bet="${APP_CONFIG.matchBet}" type="button">${APP_CONFIG.matchBet} коинов</button>`;
  const sizeChoices = Object.entries(FOUR_VARIANTS).map(([size, variant]) => `<button class="choice ${Number(size) === selectedSize ? 'active' : ''}" data-four-board-size="${size}" type="button"><strong>${variant.meta}</strong><span>${variant.label}</span></button>`).join('');
  openSheet(`
    <div class="sheet-head"><div><h2>4 в ряд</h2><p>${roomName(state.room)}. Выберите размер поля${isGold ? ' и стоимость участия' : ''}.</p></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="setup-scroll four-setup-scroll"><div class="small-note">Выбирайте столбец — фишка сама упадёт вниз. Побеждает тот, кто первым соберёт четыре фишки подряд.</div><div class="section-title"><h2>Поле</h2></div><div class="choice-grid four-size-grid" id="fourSizeChoices">${sizeChoices}</div><div id="fourSetupPreview">${fourPreviewMarkup(selectedSize)}</div><div class="section-title"><h2>Стоимость участия</h2></div><div class="choice-grid ${isGold ? '' : 'single-choice'}" id="fourBetChoices">${betChoices}</div></div>
    <button class="btn ${isGold ? 'gold' : 'primary'} full setup-start-btn" id="startFourSearchBtn" type="button">Начать поиск</button>
  `);
  document.querySelectorAll('[data-four-board-size]').forEach(button => button.addEventListener('click', () => {
    const size = normalizeFourSize(Number(button.dataset.fourBoardSize));
    state.selectedFourBoardSize = size;
    document.querySelectorAll('[data-four-board-size]').forEach(item => item.classList.toggle('active', item === button));
    const preview = document.getElementById('fourSetupPreview');
    if (preview) preview.innerHTML = fourPreviewMarkup(size);
  }));
  document.querySelectorAll('[data-four-bet]').forEach(button => button.addEventListener('click', () => {
    state.selectedBet = Number(button.dataset.fourBet);
    document.querySelectorAll('[data-four-bet]').forEach(item => item.classList.toggle('active', item === button));
  }));
  document.getElementById('startFourSearchBtn')?.addEventListener('click', startFourInARowSearch);
}

async function startFourInARowSearch(){
  if (isSessionLocked(state.session)) return toast(sessionMessage(state.session));
  const bet = state.room === 'match' ? APP_CONFIG.matchBet : Number(state.selectedBet || APP_CONFIG.goldBets[0]);
  const boardSize = normalizeFourSize(state.selectedFourBoardSize || 7);
  const variant = FOUR_VARIANTS[boardSize];
  state.selectedGame = 'four_in_a_row';
  try {
    closeSheet();
    const result = await api.startSearch(state.room, bet, boardSize, 'four_in_a_row');
    state.user = result.user || state.user;
    state.selectedBet = bet;
    state.selectedFourBoardSize = boardSize;
    renderBalances(state.user);
    if (result.game) {
      state.activeGame = result.game;
      state.selectedGame = 'four_in_a_row';
      showScreen('game');
      startGamePolling(result.game.id);
      return;
    }
    const info = document.getElementById('searchInfo');
    if (info) info.textContent = `4 в ряд · ${roomName(state.room)} · участие ${bet} коинов · поле ${variant.meta}`;
    showScreen('search');
    startSearchPolling();
  } catch (error) { toast(error.message); }
}

function normalizeFourSize(value){ return Object.prototype.hasOwnProperty.call(FOUR_VARIANTS, Number(value)) ? Number(value) : 7; }

function fourPreviewMarkup(size){
  const variant = FOUR_VARIANTS[normalizeFourSize(size)];
  const cells = Array(variant.columns * variant.rows).fill('');
  const place = (row, col, value) => { if (row >= 0 && row < variant.rows && col >= 0 && col < variant.columns) cells[row * variant.columns + col] = value; };
  const bottom = variant.rows - 1;
  const center = Math.floor(variant.columns / 2);
  place(bottom, Math.max(0, center - 3), 'yellow');
  place(bottom, Math.max(0, center - 2), 'red');
  place(bottom, Math.max(0, center - 1), 'yellow');
  place(bottom, center, 'red');
  place(bottom - 1, Math.max(0, center - 2), 'red');
  place(bottom - 1, Math.max(0, center - 1), 'yellow');
  place(bottom - 1, center, 'red');
  place(bottom - 2, Math.max(0, center - 1), 'yellow');
  place(bottom - 2, center, 'yellow');
  place(bottom - 3, center, 'yellow');
  return `<div class="four-setup-preview" style="--four-columns:${variant.columns};--four-rows:${variant.rows}" aria-hidden="true">${cells.map(value => `<span class="${value}"></span>`).join('')}</div><div class="four-preview-caption">${variant.label} поле · ${variant.meta}</div>`;
}
