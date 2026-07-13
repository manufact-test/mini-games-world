import { state } from '../../state.js?v=27';
import { APP_CONFIG } from '../../config.js?v=38';
import { api } from '../../api/client.js?v=47';
import { toast } from '../../components/toast.js?v=41';
import { openSheet, closeSheet } from '../../components/sheet.js?v=68';
import { showScreen } from '../../router.js?v=27';
import { haptic } from '../../telegram/telegram-app.js?v=27';
import { renderBalances, roomName } from '../../ui.js?v=27';
import { startSearchPolling } from '../../screens/search-screen.js?v=73';
import { startGamePolling } from '../../screens/game-screen.js?v=73';
import { isSessionLocked, sessionMessage } from '../../session.js?v=27';

const REVERSI_VARIANTS = {
  6: { label: 'Быстрое', note: 'Короткая партия' },
  8: { label: 'Классика', note: 'Стандартное поле' },
  10: { label: 'Большое', note: 'Больше стратегии' },
};

let initialized = false;

export function initReversiEntry(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('click', event => {
    const button = event.target.closest('#playReversi');
    if (!button) return;
    openReversiSetup();
  });
}

function openReversiSetup(){
  if (isSessionLocked(state.session)) return toast(sessionMessage(state.session));
  haptic('light');
  state.selectedGame = 'reversi';

  const isGold = state.room === 'gold';
  const selectedSize = normalizeReversiSize(state.selectedReversiBoardSize || 8);
  const selectedBet = isGold
    ? Number(state.selectedBet || APP_CONFIG.goldBets[0])
    : APP_CONFIG.matchBet;

  state.selectedReversiBoardSize = selectedSize;
  state.selectedBet = selectedBet;

  const sizeChoices = Object.entries(REVERSI_VARIANTS).map(([size, variant]) => `
    <button class="choice ${Number(size) === selectedSize ? 'active' : ''}" data-reversi-board-size="${size}" type="button">
      <strong>${size}×${size}</strong>
      <span>${variant.label}</span>
    </button>
  `).join('');

  const betChoices = isGold
    ? APP_CONFIG.goldBets.map(bet => `<button class="choice gold ${bet === selectedBet ? 'active' : ''}" data-reversi-bet="${bet}" type="button">${bet} коинов</button>`).join('')
    : `<button class="choice active" data-reversi-bet="${APP_CONFIG.matchBet}" type="button">${APP_CONFIG.matchBet} коинов</button>`;

  openSheet(`
    <div class="sheet-head">
      <div><h2>Реверси</h2><p>${roomName(state.room)}. Выберите размер поля${isGold ? ' и стоимость участия' : ''}.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="setup-scroll reversi-start-scroll">
      <div class="small-note">Зажимайте фишки соперника между своими. Побеждает тот, у кого в конце больше фишек.</div>

      <div class="section-title"><h2>Поле</h2></div>
      <div class="choice-grid reversi-size-grid" id="reversiSizeChoices">${sizeChoices}</div>
      <div id="reversiSetupPreview">${reversiPreviewMarkup(selectedSize)}</div>

      <div class="section-title"><h2>Стоимость участия</h2></div>
      <div class="choice-grid ${isGold ? '' : 'single-choice'}" id="reversiBetChoices">${betChoices}</div>
    </div>

    <button class="btn ${isGold ? 'gold' : 'primary'} full setup-start-btn" id="startReversiSearchBtn" type="button">Начать поиск</button>
  `);

  document.querySelectorAll('[data-reversi-board-size]').forEach(button => button.addEventListener('click', () => {
    const size = normalizeReversiSize(Number(button.dataset.reversiBoardSize));
    state.selectedReversiBoardSize = size;
    document.querySelectorAll('[data-reversi-board-size]').forEach(item => item.classList.toggle('active', item === button));
    const preview = document.getElementById('reversiSetupPreview');
    if (preview) preview.innerHTML = reversiPreviewMarkup(size);
  }));

  document.querySelectorAll('[data-reversi-bet]').forEach(button => button.addEventListener('click', () => {
    state.selectedBet = Number(button.dataset.reversiBet);
    document.querySelectorAll('[data-reversi-bet]').forEach(item => item.classList.toggle('active', item === button));
  }));

  document.getElementById('startReversiSearchBtn')?.addEventListener('click', startReversiSearch);
}

async function startReversiSearch(){
  if (isSessionLocked(state.session)) return toast(sessionMessage(state.session));

  const bet = state.room === 'match'
    ? APP_CONFIG.matchBet
    : Number(state.selectedBet || APP_CONFIG.goldBets[0]);
  const boardSize = normalizeReversiSize(state.selectedReversiBoardSize || 8);
  state.selectedGame = 'reversi';

  try {
    closeSheet();
    const result = await api.startSearch(state.room, bet, boardSize, 'reversi');
    state.user = result.user || state.user;
    state.selectedBet = bet;
    state.selectedReversiBoardSize = boardSize;
    renderBalances(state.user);

    if (result.game) {
      state.activeGame = result.game;
      state.selectedGame = 'reversi';
      showScreen('game');
      startGamePolling(result.game.id);
      return;
    }

    const info = document.getElementById('searchInfo');
    if (info) info.textContent = `Реверси · ${roomName(state.room)} · участие ${bet} коинов · поле ${boardSize}×${boardSize}`;
    showScreen('search');
    startSearchPolling();
  } catch (error) {
    toast(error.message);
  }
}

function normalizeReversiSize(value){
  return Object.prototype.hasOwnProperty.call(REVERSI_VARIANTS, Number(value)) ? Number(value) : 8;
}

function reversiPreviewMarkup(size){
  const cells = Array.from({ length:size * size }, () => '');
  const upper = Math.floor(size / 2) - 1;
  const lower = Math.floor(size / 2);
  cells[upper * size + upper] = 'white';
  cells[lower * size + lower] = 'white';
  cells[upper * size + lower] = 'black';
  cells[lower * size + upper] = 'black';

  return `
    <div class="reversi-start-preview" style="--reversi-size:${size}" aria-hidden="true">
      ${cells.map(piece => `<span>${piece ? `<i class="${piece}"></i>` : ''}</span>`).join('')}
    </div>
    <div class="reversi-preview-caption">${size}×${size} · ${REVERSI_VARIANTS[size].note} · первыми ходят чёрные</div>
  `;
}
