import {
  renderDominoSurface as renderNativeV1,
  dominoMeta,
  dominoPlayerMark,
  dominoStatus,
} from './renderer-cosmetics-v1.js?v=7&mvp19_9=live-native-effects-v1';
import { state } from '../../state.js?v=27';

const EFFECT_SLOT = 'game_domino_effect';
const FINALE_ID = 'game-domino-effect-chain-finale';
const HAND_DRAG_THRESHOLD = 8;
let handScrollGameId = '';
let handScrollLeft = 0;

ensureCorrectiveStyles();

export { dominoMeta, dominoPlayerMark, dominoStatus };

export function renderDominoSurface(args){
  const { game, me, container } = args || {};
  const gameId = String(game?.id || '');
  captureHandScroll(gameId, container);

  renderNativeV1(args);

  if (!(container instanceof HTMLElement)) return;

  container.dataset.mgwDominoManualCorrective = 'v25';
  restoreAndBindHandDrag(gameId, container);
  mountFinaleQaControl(game, me, container);
}

function captureHandScroll(gameId, container){
  if (!gameId || gameId !== handScrollGameId) {
    handScrollGameId = gameId;
    handScrollLeft = 0;
    return;
  }

  const hand = container instanceof HTMLElement ? container.querySelector('.domino-hand') : null;
  if (hand instanceof HTMLElement) handScrollLeft = hand.scrollLeft;
}

function restoreAndBindHandDrag(gameId, container){
  const hand = container.querySelector('.domino-hand');
  if (!(hand instanceof HTMLElement)) return;

  const maxScroll = Math.max(0, hand.scrollWidth - hand.clientWidth);
  hand.scrollLeft = Math.max(0, Math.min(handScrollLeft, maxScroll));
  hand.dataset.dominoHandDrag = 'v26';

  let touchId = null;
  let startX = 0;
  let startY = 0;
  let startScrollLeft = 0;
  let horizontalDrag = false;
  let verticalGesture = false;
  let suppressNextClick = false;

  const begin = touch => {
    touchId = Number(touch.identifier);
    startX = Number(touch.clientX);
    startY = Number(touch.clientY);
    startScrollLeft = hand.scrollLeft;
    horizontalDrag = false;
    verticalGesture = false;
    suppressNextClick = false;
  };

  const finish = () => {
    if (horizontalDrag) {
      suppressNextClick = true;
      hand.classList.remove('is-dragging');
    }
    touchId = null;
    horizontalDrag = false;
    verticalGesture = false;
  };

  hand.addEventListener('touchstart', event => {
    if (touchId !== null || event.changedTouches.length === 0) return;
    begin(event.changedTouches[0]);
  }, { passive:true });

  hand.addEventListener('touchmove', event => {
    if (touchId === null || verticalGesture) return;
    const touch = [...event.touches].find(item => Number(item.identifier) === touchId);
    if (!touch) return;

    const dx = Number(touch.clientX) - startX;
    const dy = Number(touch.clientY) - startY;

    if (!horizontalDrag) {
      if (Math.abs(dx) < HAND_DRAG_THRESHOLD && Math.abs(dy) < HAND_DRAG_THRESHOLD) return;
      if (Math.abs(dx) <= Math.abs(dy) * 1.05) {
        verticalGesture = true;
        return;
      }
      horizontalDrag = true;
      hand.classList.add('is-dragging');
    }

    event.preventDefault();
    const next = Math.max(0, Math.min(startScrollLeft - dx, Math.max(0, hand.scrollWidth - hand.clientWidth)));
    hand.scrollLeft = next;
    if (gameId === handScrollGameId) handScrollLeft = next;
  }, { passive:false });

  hand.addEventListener('touchend', event => {
    if (touchId === null) return;
    const ended = [...event.changedTouches].some(item => Number(item.identifier) === touchId);
    if (ended) finish();
  }, { passive:true });

  hand.addEventListener('touchcancel', event => {
    if (touchId === null) return;
    const cancelled = [...event.changedTouches].some(item => Number(item.identifier) === touchId);
    if (cancelled || event.changedTouches.length === 0) finish();
  }, { passive:true });

  hand.addEventListener('click', event => {
    if (!suppressNextClick) return;
    suppressNextClick = false;
    event.preventDefault();
    event.stopImmediatePropagation();
  }, true);

  hand.addEventListener('scroll', () => {
    if (gameId === handScrollGameId) handScrollLeft = hand.scrollLeft;
  }, { passive:true });
}

function mountFinaleQaControl(game, me, container){
  if (String(game?.status || '') !== 'active') return;
  if (viewerEffect(game, me) !== FINALE_ID) return;

  const panel = container.querySelector('.domino-panel');
  if (!(panel instanceof HTMLElement)) return;

  const row = document.createElement('div');
  row.className = 'domino-finale-qa-row';
  row.dataset.dominoFinaleQaControl = 'v25';

  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'btn secondary full domino-finale-qa-button';
  button.dataset.dominoFinaleQa = 'preview';
  button.textContent = 'Тест «Финиш цепи»';
  button.setAttribute('aria-label', 'Запустить локальный тест эффекта Финиш цепи без завершения партии');
  button.addEventListener('click', event => {
    event.preventDefault();
    event.stopPropagation();
    if (button.disabled) return;
    runFinaleQa(game, container, button);
  });

  row.appendChild(button);

  const actionNode = panel.querySelector('.domino-action-note,.domino-draw-button');
  if (actionNode instanceof HTMLElement) {
    actionNode.insertAdjacentElement('afterend', row);
  } else {
    panel.appendChild(row);
  }
}

function runFinaleQa(game, container, button){
  const gameId = String(game?.id || 'qa-domino');
  const table = container.querySelector('.domino-table');
  if (!(table instanceof HTMLElement)) return;

  cleanupQaAccent(gameId);

  const tiles = [...container.querySelectorAll('.domino-chain-slot .domino-tile')]
    .filter(tile => tile instanceof HTMLElement);
  if (tiles.length === 0) return;

  button.disabled = true;
  button.textContent = 'Финиш идёт…';

  const step = tiles.length > 24 ? 34 : (tiles.length > 14 ? 48 : 62);
  tiles.forEach((tile, index) => {
    tile.classList.remove('mgw-domino-native-finale-tile');
    tile.style.setProperty('--mgw-domino-native-finale-index', String(index));
    tile.style.setProperty('--mgw-domino-native-finale-step', `${step}ms`);
  });
  table.classList.remove('mgw-domino-native-finale-table');
  void table.offsetWidth;
  tiles.forEach(tile => tile.classList.add('mgw-domino-native-finale-tile'));
  table.classList.add('mgw-domino-native-finale-table');

  const tableRect = table.getBoundingClientRect();
  const accent = document.createElement('span');
  accent.className = 'domino-native-fx-accent is-finale is-qa';
  accent.dataset.dominoNativeGame = gameId;
  accent.dataset.dominoNativeEffect = 'finale';
  accent.dataset.dominoNativeQa = 'finale';
  accent.setAttribute('aria-hidden', 'true');
  accent.style.left = `${tableRect.left}px`;
  accent.style.top = `${tableRect.top}px`;
  accent.style.width = `${tableRect.width}px`;
  accent.style.height = `${tableRect.height}px`;
  accent.style.setProperty('--mgw-domino-native-finale-delay', `${Math.max(240, (tiles.length - 1) * step)}ms`);
  accent.innerHTML = '<i class="finale-sweep"></i><i class="finale-flash"></i><i class="finale-ring"></i>';
  document.body.appendChild(accent);

  const finisher = accent.querySelector('.finale-ring');
  const finish = event => {
    if (event.target !== finisher) return;
    if (event.type === 'animationend' && String(event.animationName || '') !== 'mgw-domino-native-finale-ring') return;
    finisher.removeEventListener('animationend', finish);
    finisher.removeEventListener('animationcancel', finish);
    accent.remove();
    tiles.forEach(tile => tile.classList.remove('mgw-domino-native-finale-tile'));
    table.classList.remove('mgw-domino-native-finale-table');
    if (button.isConnected) {
      button.disabled = false;
      button.textContent = 'Повторить «Финиш цепи»';
    }
  };

  if (finisher instanceof HTMLElement) {
    finisher.addEventListener('animationend', finish);
    finisher.addEventListener('animationcancel', finish);
  } else {
    accent.remove();
    tiles.forEach(tile => tile.classList.remove('mgw-domino-native-finale-tile'));
    table.classList.remove('mgw-domino-native-finale-table');
    button.disabled = false;
    button.textContent = 'Повторить «Финиш цепи»';
  }
}

function cleanupQaAccent(gameId){
  document.querySelectorAll('.domino-native-fx-accent[data-domino-native-qa="finale"]').forEach(node => {
    if (!(node instanceof HTMLElement)) return;
    if (String(node.dataset.dominoNativeGame || '') !== gameId) return;
    node.remove();
  });
}

function viewerEffect(game, me){
  const equipped = state?.profileInventory?.equipped;
  if (equipped && typeof equipped === 'object') {
    const itemId = String(equipped[EFFECT_SLOT] || '');
    if (itemId) return itemId;
  }

  const myId = String(me?.id || '');
  const players = Array.isArray(game?.players) ? game.players : [];
  const viewer = players.find(player => String(player?.id || '') === myId) || null;
  return String(viewer?.game_cosmetics?.slots?.[EFFECT_SLOT] || '');
}

function ensureCorrectiveStyles(){
  if (typeof document === 'undefined') return;
  const href = new URL('../../../css/games/domino/live-native-manual-v25.css?v=1&mvp19_9=manual-corrective-v25&hand_drag=v26', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-domino-manual-corrective]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwDominoManualCorrective = 'v25';
    return;
  }

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwDominoManualCorrective = 'v25';
  link.href = href;
  document.head.appendChild(link);
}
