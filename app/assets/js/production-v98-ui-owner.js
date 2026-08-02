import { state } from './state.js?v=27';
import { api } from './api/client.js?v=47';
import { closeSheet } from './components/sheet.js?v=68';
import { toast } from './components/toast.js?v=41';
import { showScreen } from './router.js?v=27';
import { clearTimer, renderBalances } from './ui.js?v=89';
import { APP_CONFIG } from './config.js?v=38';
import { gameTypeOf } from './games/game-router.js?v=74';
import { startGamePolling } from './screens/game-screen-v98.js?v=98';

const START_SEARCH_IDS = new Set([
  'startSearchBtn',
  'startFourSearchBtn',
  'startBattleshipSearchBtn',
  'startCheckersSearchBtn',
  'startReversiSearchBtn',
  'startChessSearchBtn',
  'startGoSearchBtn',
  'startDominoSearchBtn',
]);
const SILENT_SEARCH_MESSAGES = new Set([
  'Поиск отменён.',
  'Поиск остановлен.',
  'Поиск остановлен. Соперник не найден или связь прервалась.',
]);

let earlyInitialized = false;
let afterInitialized = false;
let resultSearchBusy = false;
let lastEnteredGameId = '';

export function initV98UiOwnerEarly(){
  if (earlyInitialized) return;
  earlyInitialized = true;
  installSilentSearchToastGuard();
  installPlayerPickerHold();
  installExplicitSessionLockGuard();
  installResultSearchOwner();
}

export function initV98UiOwnerAfter(){
  if (afterInitialized) return;
  afterInitialized = true;

  installV98GameBridge();
  document.addEventListener('mgw:v98-game-found', event => {
    const game = event.detail?.game || null;
    if (game?.id) enterV98Game(game, event.detail?.me || null);
  });
  document.addEventListener('mgw:app-ready', consumePendingGame);

  const gameScreen = document.getElementById('screen-game');
  if (gameScreen) {
    const observer = new MutationObserver(() => {
      if (!gameScreen.classList.contains('active')) return;
      const game = state.activeGame;
      if (game?.id && String(game.status || '') === 'active') startGamePolling(game.id);
    });
    observer.observe(gameScreen, { attributes:true, attributeFilter:['class'] });
  }

  window.setTimeout(installV98GameBridge, 0);
  window.setTimeout(installV98GameBridge, 160);
  document.addEventListener('mgw:app-ready', installV98GameBridge);
}

function installV98GameBridge(){
  window.__MGW_V97_START_GAME_POLLING__ = startGamePolling;
  window.__MGW_V98_START_GAME_POLLING__ = startGamePolling;
}

function consumePendingGame(){
  const pending = window.__MGW_V98_PENDING_GAME__;
  if (!pending?.game?.id) return;
  window.__MGW_V98_PENDING_GAME__ = null;
  enterV98Game(pending.game, pending.me || null);
}

function enterV98Game(game, me = null){
  const id = String(game?.id || '');
  if (!id) return;
  if (id === lastEnteredGameId && String(state.activeGame?.id || '') === id
    && document.getElementById('screen-game')?.classList.contains('active')) return;

  lastEnteredGameId = id;
  state.timers.search = clearTimer(state.timers.search);
  state.activeGame = game;
  state.selectedGame = gameTypeOf(game);
  if (me?.id) {
    game.players = (game.players || []).map(player => ({
      ...player,
      is_me:String(player?.id || '') === String(me.id),
    }));
  }
  closeSheet();
  startGamePolling(id);
  showScreen('game');
}

function installSilentSearchToastGuard(){
  const element = document.getElementById('toast');
  if (!element) return;

  const suppress = () => {
    const message = String(element.textContent || '').trim();
    if (!SILENT_SEARCH_MESSAGES.has(message)) return;
    element.classList.remove('show');
    element.textContent = '';
  };
  new MutationObserver(suppress).observe(element, {
    childList:true,
    characterData:true,
    subtree:true,
    attributes:true,
    attributeFilter:['class'],
  });
  suppress();
}

function installExplicitSessionLockGuard(){
  document.addEventListener('click', event => {
    const origin = event.target;
    if (!(origin instanceof Element)) return;
    const button = origin.closest('button, [role="button"]');
    if (!(button instanceof Element)) return;

    const explicitLaunch = START_SEARCH_IDS.has(button.id)
      || button.matches('[data-invite-action="start"]');
    if (!explicitLaunch) return;

    const lock = window.__MGW_V98_PASSIVE_SESSION_LOCK__;
    if (!lock?.locked) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    toast(String(lock.message || 'У вас уже идёт активная игра на другом устройстве.'));
  }, true);
}

function installPlayerPickerHold(){
  const sheet = document.getElementById('sheet');
  const overlay = document.getElementById('sheetOverlay');
  if (!sheet || !overlay) return;

  let timeout = null;
  let hold = null;
  let trigger = null;
  let triggerText = '';

  const finish = () => {
    document.body.classList.remove('mgw-player-picker-transition');
    hold?.remove();
    hold = null;
    window.clearTimeout(timeout);
    timeout = null;
    if (trigger && document.body.contains(trigger)) {
      trigger.removeAttribute('aria-busy');
      if (triggerText) trigger.textContent = triggerText;
    }
    trigger = null;
    triggerText = '';
  };

  document.addEventListener('click', event => {
    const origin = event.target;
    if (!(origin instanceof Element)) return;
    const button = origin.closest('[data-open-player-picker]');
    if (!(button instanceof HTMLButtonElement) || button.disabled) return;

    finish();
    trigger = button;
    triggerText = String(button.textContent || 'Пригласить игрока');
    button.setAttribute('aria-busy', 'true');
    document.body.classList.add('mgw-player-picker-transition');

    hold = document.createElement('div');
    hold.className = 'sheet mgw-player-picker-hold';
    hold.setAttribute('aria-hidden', 'true');
    hold.setAttribute('inert', '');
    hold.innerHTML = sheet.innerHTML;
    hold.querySelectorAll('[id]').forEach(node => node.removeAttribute('id'));
    hold.querySelectorAll('button,input,textarea,select,a').forEach(node => {
      node.setAttribute('tabindex', '-1');
      node.setAttribute('aria-hidden', 'true');
      if ('disabled' in node) node.disabled = true;
    });
    overlay.append(hold);

    timeout = window.setTimeout(finish, 5000);
  }, true);

  const observer = new MutationObserver(() => {
    if (!document.body.classList.contains('mgw-player-picker-transition')) return;
    const ready = Boolean(
      sheet.querySelector('.invite-player-list')
      || sheet.querySelector('.invite-empty-state')
      || sheet.querySelector('[data-back-to-invite-setup]')
    );
    if (ready) finish();
  });
  observer.observe(sheet, { childList:true, subtree:true });
}

function installResultSearchOwner(){
  document.addEventListener('click', event => {
    const origin = event.target;
    if (!(origin instanceof Element)) return;
    const button = origin.closest('#newOpponent');
    if (!(button instanceof HTMLButtonElement) || button.disabled) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    startResultSearch();
  }, true);
}

async function startResultSearch(){
  if (resultSearchBusy) return;
  const game = state.activeGame;
  if (!game) {
    closeSheet();
    showScreen('home');
    return;
  }

  const lock = window.__MGW_V98_PASSIVE_SESSION_LOCK__;
  if (lock?.locked) {
    toast(String(lock.message || 'У вас уже идёт активная игра на другом устройстве.'));
    return;
  }

  resultSearchBusy = true;
  const room = game.room === 'gold' ? 'gold' : 'match';
  const type = gameTypeOf(game);
  const size = Number(game.board_size || 3);
  const bet = room === 'match' ? APP_CONFIG.matchBet : Number(game.bet || state.selectedBet || APP_CONFIG.goldBets[0]);

  state.room = room;
  state.selectedBet = bet;
  state.selectedGame = type;
  state.activeGame = null;
  state.timers.game = clearTimer(state.timers.game);
  closeSheet();
  showScreen('search');

  try {
    const result = await api.startSearch(room, bet, size, type);
    if (result?.user) {
      state.user = result.user;
      state.session = result.session || state.session;
      renderBalances(state.user);
    }
    if (result?.game?.id) {
      enterV98Game(result.game, result.me || null);
      return;
    }
    startResultSearchPolling();
  } catch (error) {
    showScreen('home');
    toast(error?.message || 'Не удалось начать поиск.');
  } finally {
    resultSearchBusy = false;
  }
}

function startResultSearchPolling(){
  state.timers.search = clearTimer(state.timers.search);
  state.timers.search = window.setInterval(pollResultSearch, APP_CONFIG.searchIntervalMs);
  pollResultSearch();
}

async function pollResultSearch(){
  try {
    const result = await api.gameState();
    if (result?.user) {
      state.user = result.user;
      state.session = result.session || state.session;
      renderBalances(state.user);
    }
    if (result?.game?.id && String(result.game.status || '') === 'active') {
      state.timers.search = clearTimer(state.timers.search);
      enterV98Game(result.game, result.me || null);
      return;
    }
    if (result?.user && result.user.status !== 'searching') {
      state.timers.search = clearTimer(state.timers.search);
      showScreen('home');
    }
  } catch (error) {
    // Background search polling remains silent; the next interval retries.
  }
}
