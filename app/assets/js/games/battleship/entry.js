import { state } from '../../state.js?v=27';
import { APP_CONFIG } from '../../config.js?v=38';
import { api } from '../../api/client.js?v=47';
import { toast } from '../../components/toast.js?v=41';
import { openSheet, closeSheet } from '../../components/sheet.js?v=27';
import { showScreen } from '../../router.js?v=27';
import { haptic } from '../../telegram/telegram-app.js?v=27';
import { renderBalances, roomName } from '../../ui.js?v=27';
import { startSearchPolling } from '../../screens/search-screen.js?v=56';
import { startGamePolling } from '../../screens/game-screen.js?v=56';
import { isSessionLocked, sessionMessage } from '../../session.js?v=27';

let initialized = false;

export function initBattleshipEntry(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('click', event => {
    const button = event.target.closest('#playBattleship');
    if (!button) return;
    openBattleshipSetup();
  });
}

function openBattleshipSetup(){
  if (isSessionLocked(state.session)) return toast(sessionMessage(state.session));
  haptic('light');
  state.selectedGame = 'battleship';

  const isGold = state.room === 'gold';
  const selectedBet = state.room === 'match'
    ? APP_CONFIG.matchBet
    : Number(state.selectedBet || APP_CONFIG.goldBets[0]);
  state.selectedBet = selectedBet;

  const betChoices = isGold
    ? APP_CONFIG.goldBets.map(bet => `<button class="choice gold ${bet === selectedBet ? 'active' : ''}" data-battleship-bet="${bet}" type="button">${bet} коинов</button>`).join('')
    : `<button class="choice active" data-battleship-bet="${APP_CONFIG.matchBet}" type="button">${APP_CONFIG.matchBet} коинов</button>`;

  openSheet(`
    <div class="sheet-head">
      <div><h2>Морской бой</h2><p>${roomName(state.room)}. Классическое поле 10×10.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="setup-scroll battleship-start-scroll">
      <div class="small-note">После подбора соперника у вас будет 2 минуты на расстановку. Флот можно перемешивать без ограничений или расставить вручную.</div>

      <div class="battleship-start-preview" aria-hidden="true">
        ${previewCells()}
      </div>
      <div class="battleship-preview-caption">Поле 10×10 · 10 кораблей</div>

      <div class="battleship-start-fleet">
        ${fleetPreviewItem(4, 1)}
        ${fleetPreviewItem(3, 2)}
        ${fleetPreviewItem(2, 3)}
        ${fleetPreviewItem(1, 4)}
      </div>

      <div class="section-title"><h2>Стоимость участия</h2></div>
      <div class="choice-grid ${isGold ? '' : 'single-choice'}" id="battleshipBetChoices">${betChoices}</div>
    </div>

    <button class="btn ${isGold ? 'gold' : 'primary'} full setup-start-btn" id="startBattleshipSearchBtn" type="button">Начать поиск</button>
  `);

  document.querySelectorAll('[data-battleship-bet]').forEach(button => button.addEventListener('click', () => {
    state.selectedBet = Number(button.dataset.battleshipBet);
    document.querySelectorAll('[data-battleship-bet]').forEach(item => item.classList.toggle('active', item === button));
  }));

  document.getElementById('startBattleshipSearchBtn')?.addEventListener('click', startBattleshipSearch);
}

async function startBattleshipSearch(){
  if (isSessionLocked(state.session)) return toast(sessionMessage(state.session));

  const bet = state.room === 'match'
    ? APP_CONFIG.matchBet
    : Number(state.selectedBet || APP_CONFIG.goldBets[0]);
  state.selectedGame = 'battleship';

  try {
    closeSheet();
    const result = await api.startSearch(state.room, bet, 10, 'battleship');
    state.user = result.user || state.user;
    state.selectedBet = bet;
    renderBalances(state.user);

    if (result.game) {
      state.activeGame = result.game;
      state.selectedGame = 'battleship';
      showScreen('game');
      startGamePolling(result.game.id);
      return;
    }

    const info = document.getElementById('searchInfo');
    if (info) info.textContent = `Морской бой · ${roomName(state.room)} · участие ${bet} коинов · поле 10×10`;
    showScreen('search');
    startSearchPolling();
  } catch (error) {
    toast(error.message);
  }
}

function previewCells(){
  const ships = new Set([
    2, 12, 22, 32,
    56, 57, 58,
    71, 72,
    89,
  ]);

  return Array.from({ length: 100 }, (_, cell) => `<span class="${ships.has(cell) ? 'ship' : ''}"></span>`).join('');
}

function fleetPreviewItem(size, count){
  return `
    <div>
      <span class="ship-shape">${'<i></i>'.repeat(size)}</span>
      <strong>×${count}</strong>
    </div>
  `;
}
