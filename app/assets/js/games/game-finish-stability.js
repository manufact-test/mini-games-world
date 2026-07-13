import { state } from '../state.js?v=27';
import { closeSheet } from '../components/sheet.js?v=68';
import { showScreen } from '../router.js?v=27';
import { clearTimer } from '../ui.js?v=27';

let initialized = false;
let lastDismissedAt = 0;

export function initGameFinishStability(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('click', event => {
    const button = event.target.closest('#leaveGame, #goHome');
    if (!button) return;

    const game = state.activeGame;
    if (!game || String(game.status || '') === 'active') return;

    event.preventDefault();
    event.stopImmediatePropagation();
    dismissFinishedGame(game);
  }, true);

  const timer = document.getElementById('timerText');
  if (timer) {
    const observer = new MutationObserver(() => syncFinishedTimer(timer));
    observer.observe(timer, { childList:true, characterData:true, subtree:true });

    const gameScreen = document.getElementById('screen-game');
    if (gameScreen) {
      const screenObserver = new MutationObserver(() => {
        window.setTimeout(() => syncFinishedTimer(timer), 0);
      });
      screenObserver.observe(gameScreen, { attributes:true, attributeFilter:['class'] });
    }

    syncFinishedTimer(timer);
  }

  const sheet = document.getElementById('sheet');
  if (sheet) {
    const sheetObserver = new MutationObserver(() => {
      if (Date.now() - lastDismissedAt > 6000) return;
      if (state.activeGame) return;
      if (sheet.querySelector('#goHome, #newOpponent')) closeSheet();
    });
    sheetObserver.observe(sheet, { childList:true, subtree:true });
  }
}

function dismissFinishedGame(game){
  const gameId = String(game?.id || '');
  state.timers.game = clearTimer(state.timers.game);
  state.timers.search = clearTimer(state.timers.search);
  state.activeGame = null;
  lastDismissedAt = Date.now();
  closeSheet();
  showScreen('home');
  document.dispatchEvent(new CustomEvent('mgw:game-dismissed', {
    detail:{ gameId },
  }));
}

function syncFinishedTimer(timer){
  const value = String(timer.textContent || '').trim();
  const finished = value === '—' || value === '' || String(state.activeGame?.status || '') !== 'active';
  timer.hidden = finished;
  if (value === '—') timer.textContent = '';
}
