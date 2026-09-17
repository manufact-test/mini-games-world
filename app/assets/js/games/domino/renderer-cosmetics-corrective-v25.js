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
const pointerOwners = new WeakMap();

ensureCorrectiveStyles();
ensureHandGestureStyles();

export { dominoMeta, dominoPlayerMark, dominoStatus };

export function renderDominoSurface(args){
  const { game, me, container } = args || {};
  const gameId = String(game?.id || '');
  captureHandScroll(gameId, container);

  renderNativeV1(args);

  if (!(container instanceof HTMLElement)) return;

  container.dataset.mgwDominoManualCorrective = 'v25';
  container.dataset.mgwDominoHandPointer = 'v28';
  restoreAndBindHandDrag(gameId, container);
  ensureStablePointerOwner(container, gameId);
  markCurrentHand(container);
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
  hand.dataset.dominoHandGesture = 'v27';
  hand.dataset.dominoHandPointer = 'v28';

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

function markCurrentHand(container){
  const hand = container.querySelector('.domino-hand');
  if (!(hand instanceof HTMLElement)) return null;
  hand.dataset.dominoHandPointer = 'v28';
  return hand;
}

function ensureStablePointerOwner(container, gameId){
  const existing = pointerOwners.get(container);
  if (existing) {
    existing.gameId = gameId;
    return;
  }

  const drag = {
    gameId,
    pointerId:null,
    hand:null,
    startX:0,
    startY:0,
    startScrollLeft:0,
    lastScrollLeft:0,
    horizontal:false,
    vertical:false,
    suppressClick:false,
  };
  pointerOwners.set(container, drag);

  const reset = event => {
    if (drag.pointerId === null) return;
    if (event && Number(event.pointerId) !== drag.pointerId) return;

    const pointerId = drag.pointerId;
    if (drag.horizontal) drag.suppressClick = true;
    if (drag.hand instanceof HTMLElement) drag.hand.classList.remove('is-dragging');

    drag.pointerId = null;
    drag.hand = null;
    drag.horizontal = false;
    drag.vertical = false;

    if (typeof container.hasPointerCapture === 'function'
      && typeof container.releasePointerCapture === 'function'
      && container.hasPointerCapture(pointerId)) {
      try { container.releasePointerCapture(pointerId); } catch (_) {}
    }
  };

  container.addEventListener('pointerdown', event => {
    if (!event.isPrimary || event.pointerType === 'mouse' || drag.pointerId !== null) return;
    const target = event.target instanceof Element ? event.target : null;
    const hand = target?.closest('.domino-hand');
    if (!(hand instanceof HTMLElement) || !container.contains(hand)) return;

    drag.gameId = gameId;
    drag.pointerId = Number(event.pointerId);
    drag.hand = hand;
    drag.startX = Number(event.clientX);
    drag.startY = Number(event.clientY);
    drag.startScrollLeft = hand.scrollLeft;
    drag.lastScrollLeft = hand.scrollLeft;
    drag.horizontal = false;
    drag.vertical = false;
    drag.suppressClick = false;
    hand.dataset.dominoHandPointer = 'v28';
  }, { passive:true });

  container.addEventListener('pointermove', event => {
    if (drag.pointerId === null || Number(event.pointerId) !== drag.pointerId || drag.vertical) return;

    let hand = drag.hand;
    if (!(hand instanceof HTMLElement) || !hand.isConnected || !container.contains(hand)) {
      hand = markCurrentHand(container);
      if (!(hand instanceof HTMLElement)) return;
      hand.scrollLeft = drag.lastScrollLeft;
      drag.hand = hand;
      drag.startScrollLeft = drag.lastScrollLeft;
      drag.startX = Number(event.clientX);
      drag.startY = Number(event.clientY);
      return;
    }

    const dx = Number(event.clientX) - drag.startX;
    const dy = Number(event.clientY) - drag.startY;

    if (!drag.horizontal) {
      if (Math.abs(dx) < HAND_DRAG_THRESHOLD && Math.abs(dy) < HAND_DRAG_THRESHOLD) return;
      if (Math.abs(dx) <= Math.abs(dy) * 1.05) {
        drag.vertical = true;
        return;
      }

      drag.horizontal = true;
      hand.classList.add('is-dragging');
      if (typeof container.setPointerCapture === 'function') {
        try { container.setPointerCapture(drag.pointerId); } catch (_) {}
      }
    }

    if (event.cancelable) event.preventDefault();
    const maxScroll = Math.max(0, hand.scrollWidth - hand.clientWidth);
    const next = Math.max(0, Math.min(drag.startScrollLeft - dx, maxScroll));
    hand.scrollLeft = next;
    drag.lastScrollLeft = next;
    if (drag.gameId === handScrollGameId) handScrollLeft = next;
  }, { passive:false });

  container.addEventListener('pointerup', reset, { passive:true });
  container.addEventListener('pointercancel', reset, { passive:true });

  container.addEventListener('click', event => {
    if (!drag.suppressClick) return;
    const target = event.target instanceof Element ? event.target : null;
    if (!(target?.closest('.domino-hand') instanceof HTMLElement)) return;

    drag.suppressClick = false;
    event.preventDefault();
    event.stopImmediatePropagation();
  }, true);
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

function ensureHandGestureStyles(){
  if (typeof document === 'undefined') return;
  const href = new URL('../../../css/games/domino/live-hand-gesture-v27.css?v=2&mvp19_9=hand-gesture-owner-v27&hand_layout=v29', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-domino-hand-gesture]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwDominoHandGesture = 'v27';
    return;
  }

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwDominoHandGesture = 'v27';
  link.href = href;
  document.head.appendChild(link);
}
