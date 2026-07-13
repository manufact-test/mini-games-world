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
import { DOMINO_META } from './meta.js?v=72';

let initialized = false;

export function initDominoEntry(){
  if (initialized) return;
  initialized = true;
  document.addEventListener('click', event => {
    if (!event.target.closest('#playDomino')) return;
    openDominoSetup();
  });
}

function openDominoSetup(){
  if (isSessionLocked(state.session)) return toast(sessionMessage(state.session));
  haptic('light');
  state.selectedGame = DOMINO_META.id;

  const isGold = state.room === 'gold';
  const selectedBet = isGold
    ? Number(state.selectedBet || APP_CONFIG.goldBets[0])
    : APP_CONFIG.matchBet;
  state.selectedBet = selectedBet;

  const betChoices = isGold
    ? APP_CONFIG.goldBets.map(bet => `<button class="choice gold ${bet === selectedBet ? 'active' : ''}" data-domino-bet="${bet}" type="button">${bet} коинов</button>`).join('')
    : `<button class="choice active" data-domino-bet="${APP_CONFIG.matchBet}" type="button">${APP_CONFIG.matchBet} коинов</button>`;

  openSheet(`
    <div class="sheet-head">
      <div><h2>Домино</h2><p>${roomName(state.room)}. Классическая партия один на один.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="setup-scroll domino-start-scroll">
      <div class="small-note">Соединяйте одинаковые числа. Если подходящей костяшки нет, добирайте из закрытого запаса.</div>
      ${dominoPreviewMarkup()}
      <div class="domino-setup-facts">
        <div><strong>7</strong><span>костяшек у каждого</span></div>
        <div><strong>14</strong><span>в закрытом запасе</span></div>
        <div><strong>60</strong><span>секунд на ход</span></div>
      </div>

      <div class="section-title"><h2>Стоимость участия</h2></div>
      <div class="choice-grid ${isGold ? '' : 'single-choice'}" id="dominoBetChoices">${betChoices}</div>
    </div>

    <button class="btn ${isGold ? 'gold' : 'primary'} full setup-start-btn" id="startDominoSearchBtn" type="button">Начать поиск</button>
  `);

  document.querySelectorAll('[data-domino-bet]').forEach(button => button.addEventListener('click', () => {
    state.selectedBet = Number(button.dataset.dominoBet);
    document.querySelectorAll('[data-domino-bet]').forEach(item => item.classList.toggle('active', item === button));
  }));
  document.getElementById('startDominoSearchBtn')?.addEventListener('click', startDominoSearch);
}

async function startDominoSearch(){
  if (isSessionLocked(state.session)) return toast(sessionMessage(state.session));
  const bet = state.room === 'match'
    ? APP_CONFIG.matchBet
    : Number(state.selectedBet || APP_CONFIG.goldBets[0]);
  state.selectedGame = DOMINO_META.id;

  try {
    closeSheet();
    const result = await api.startSearch(state.room, bet, DOMINO_META.boardSize, DOMINO_META.id);
    state.user = result.user || state.user;
    state.selectedBet = bet;
    renderBalances(state.user);

    if (result.game) {
      state.activeGame = result.game;
      showScreen('game');
      startGamePolling(result.game.id);
      return;
    }

    const info = document.getElementById('searchInfo');
    if (info) info.textContent = `Домино · ${roomName(state.room)} · участие ${bet} коинов`;
    showScreen('search');
    startSearchPolling();
  } catch (error) {
    toast(error.message);
  }
}

function dominoPreviewMarkup(){
  const tiles = [[6,6],[6,3],[3,1],[1,4],[4,4]];
  return `
    <div class="domino-start-preview" aria-hidden="true">
      <div class="domino-preview-stock">${Array.from({length:6}, () => '<i></i>').join('')}<b>+8</b></div>
      <div class="domino-preview-chain">
        ${tiles.map(([a,b], index) => tileMarkup(a, b, index === 0)).join('')}
      </div>
      <div class="domino-preview-hand">
        ${[[0,5],[2,5],[2,2],[1,6],[0,3],[3,5],[1,1]].map(([a,b]) => tileMarkup(a,b,false)).join('')}
      </div>
    </div>
    <div class="domino-preview-caption">28 уникальных костяшек · от 0–0 до 6–6</div>
  `;
}

function tileMarkup(a, b, double){
  return `<span class="domino-mini-tile ${double ? 'is-double' : ''}">${halfMarkup(a)}${halfMarkup(b)}</span>`;
}

function halfMarkup(value){
  const active = new Set(pipPositions(value));
  return `<i class="domino-mini-half">${Array.from({length:9}, (_, index) => `<b class="${active.has(index + 1) ? 'active' : ''}"></b>`).join('')}</i>`;
}

function pipPositions(value){
  return ({0:[],1:[5],2:[1,9],3:[1,5,9],4:[1,3,7,9],5:[1,3,5,7,9],6:[1,3,4,6,7,9]})[Number(value)] || [];
}
