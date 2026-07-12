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
import { GO_BOARD_SIZES, GO_DEFAULT_BOARD_SIZE } from './meta.js?v=70';

let initialized = false;

export function initGoEntry(){
  if (initialized) return;
  initialized = true;
  document.addEventListener('click', event => {
    if (!event.target.closest('#playGo')) return;
    openGoSetup();
  });
}

function openGoSetup(){
  if (isSessionLocked(state.session)) return toast(sessionMessage(state.session));
  haptic('light');
  state.selectedGame = 'go';

  const isGold = state.room === 'gold';
  const selectedSize = normalizeGoSize(state.selectedGoBoardSize || GO_DEFAULT_BOARD_SIZE);
  const selectedBet = isGold
    ? Number(state.selectedBet || APP_CONFIG.goldBets[0])
    : APP_CONFIG.matchBet;

  state.selectedGoBoardSize = selectedSize;
  state.selectedBet = selectedBet;

  const sizeChoices = GO_BOARD_SIZES.map(size => `
    <button class="choice ${size === selectedSize ? 'active' : ''}" data-go-board-size="${size}" type="button">
      <strong>${size}×${size}</strong>
      <span>${size === 9 ? 'Быстрое' : 'Большое'}</span>
    </button>
  `).join('');

  const betChoices = isGold
    ? APP_CONFIG.goldBets.map(bet => `<button class="choice gold ${bet === selectedBet ? 'active' : ''}" data-go-bet="${bet}" type="button">${bet} коинов</button>`).join('')
    : `<button class="choice active" data-go-bet="${APP_CONFIG.matchBet}" type="button">${APP_CONFIG.matchBet} коинов</button>`;

  openSheet(`
    <div class="sheet-head">
      <div><h2>Го</h2><p>${roomName(state.room)}. Выберите размер поля${isGold ? ' и стоимость участия' : ''}.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="setup-scroll go-start-scroll">
      <div class="small-note">Ставьте камни на пересечения линий, окружайте группы соперника и захватывайте территорию. Чёрные ходят первыми.</div>

      <div class="section-title"><h2>Поле</h2></div>
      <div class="choice-grid go-size-grid" id="goSizeChoices">${sizeChoices}</div>
      <div id="goSetupPreview">${goPreviewMarkup(selectedSize)}</div>

      <div class="section-title"><h2>Стоимость участия</h2></div>
      <div class="choice-grid ${isGold ? '' : 'single-choice'}" id="goBetChoices">${betChoices}</div>
    </div>

    <button class="btn ${isGold ? 'gold' : 'primary'} full setup-start-btn" id="startGoSearchBtn" type="button">Начать поиск</button>
  `);

  document.querySelectorAll('[data-go-board-size]').forEach(button => button.addEventListener('click', () => {
    const size = normalizeGoSize(Number(button.dataset.goBoardSize));
    state.selectedGoBoardSize = size;
    document.querySelectorAll('[data-go-board-size]').forEach(item => item.classList.toggle('active', item === button));
    const preview = document.getElementById('goSetupPreview');
    if (preview) preview.innerHTML = goPreviewMarkup(size);
  }));

  document.querySelectorAll('[data-go-bet]').forEach(button => button.addEventListener('click', () => {
    state.selectedBet = Number(button.dataset.goBet);
    document.querySelectorAll('[data-go-bet]').forEach(item => item.classList.toggle('active', item === button));
  }));

  document.getElementById('startGoSearchBtn')?.addEventListener('click', startGoSearch);
}

async function startGoSearch(){
  if (isSessionLocked(state.session)) return toast(sessionMessage(state.session));
  const bet = state.room === 'match'
    ? APP_CONFIG.matchBet
    : Number(state.selectedBet || APP_CONFIG.goldBets[0]);
  const boardSize = normalizeGoSize(state.selectedGoBoardSize || GO_DEFAULT_BOARD_SIZE);
  state.selectedGame = 'go';

  try {
    closeSheet();
    const result = await api.startSearch(state.room, bet, boardSize, 'go');
    state.user = result.user || state.user;
    state.selectedBet = bet;
    state.selectedGoBoardSize = boardSize;
    renderBalances(state.user);

    if (result.game) {
      state.activeGame = result.game;
      state.selectedGame = 'go';
      showScreen('game');
      startGamePolling(result.game.id);
      return;
    }

    const info = document.getElementById('searchInfo');
    if (info) info.textContent = `Го · ${roomName(state.room)} · участие ${bet} коинов · поле ${boardSize}×${boardSize}`;
    showScreen('search');
    startSearchPolling();
  } catch (error) {
    toast(error.message);
  }
}

function normalizeGoSize(value){
  const size = Number(value);
  return GO_BOARD_SIZES.includes(size) ? size : GO_DEFAULT_BOARD_SIZE;
}

function goPreviewMarkup(size){
  return `
    <div class="go-start-preview" style="--go-size:${size}" aria-hidden="true">
      ${goGridSvg(size)}
      ${goStarMarkup(size)}
    </div>
    <div class="go-preview-caption">${size}×${size} · пустое поле · чёрные ходят первыми</div>
  `;
}

function goGridSvg(size){
  const inset = 6;
  const span = 88;
  const lines = [];
  for (let index = 0; index < size; index += 1) {
    const position = inset + (index / (size - 1)) * span;
    lines.push(`<line x1="${inset}" y1="${position}" x2="${100 - inset}" y2="${position}"></line>`);
    lines.push(`<line x1="${position}" y1="${inset}" x2="${position}" y2="${100 - inset}"></line>`);
  }
  return `<svg class="go-grid-svg" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">${lines.join('')}</svg>`;
}

function goStarMarkup(size){
  const indexes = size === 9 ? [2, 4, 6] : [3, 6, 9];
  const inset = 6;
  const span = 88;
  return indexes.flatMap(row => indexes.map(col => {
    const x = inset + (col / (size - 1)) * span;
    const y = inset + (row / (size - 1)) * span;
    return `<i class="go-star" style="--go-x:${x}%;--go-y:${y}%"></i>`;
  })).join('');
}
