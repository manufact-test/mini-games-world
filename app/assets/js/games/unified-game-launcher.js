import { state } from '../state.js?v=27';
import { APP_CONFIG } from '../config.js?v=38';
import { openSheet } from '../components/sheet.js?v=68';
import { toast } from '../components/toast.js?v=41';
import { haptic } from '../telegram/telegram-app.js?v=27';
import { isSessionLocked, sessionMessage } from '../session.js?v=27';
import { beginSearch } from '../screens/search-screen-v102.js?v=103';
import { GO_BOARD_SIZES, GO_DEFAULT_BOARD_SIZE } from './go/meta.js?v=70';
import { DOMINO_META } from './domino/meta.js?v=72';
import { t, formatNumber } from '@mgw/i18n';

const GAME_SETUP = Object.freeze({
  tictactoe:Object.freeze({
    defaultSize:3,
    options:() => APP_CONFIG.boardSizes.map(size => ({ value:Number(size), label:`${size}×${size}` })),
  }),
  four_in_a_row:Object.freeze({
    defaultSize:7,
    options:() => [
      { value:6, label:'6×5' },
      { value:7, label:'7×6' },
      { value:8, label:'8×7' },
    ],
  }),
  battleship:Object.freeze({ defaultSize:10, options:() => [{ value:10, label:'10×10' }] }),
  checkers:Object.freeze({ defaultSize:8, options:() => [{ value:8, label:'8×8' }] }),
  reversi:Object.freeze({
    defaultSize:8,
    options:() => [6, 8, 10].map(size => ({ value:size, label:`${size}×${size}` })),
  }),
  chess:Object.freeze({ defaultSize:8, options:() => [{ value:8, label:'8×8' }] }),
  go:Object.freeze({
    defaultSize:GO_DEFAULT_BOARD_SIZE,
    options:() => GO_BOARD_SIZES.map(size => ({ value:Number(size), label:`${size}×${size}` })),
  }),
  domino:Object.freeze({
    defaultSize:Number(DOMINO_META.boardSize || 7),
    options:() => [{ value:Number(DOMINO_META.boardSize || 7), label:t('setup.one_on_one') }],
  }),
});

let initialized = false;
let activeGameType = null;
let activeSize = null;
let launchPending = false;

export function initUnifiedGameLauncher(){
  if (initialized) return;
  initialized = true;

  document.querySelectorAll('[data-game-card]').forEach(card => {
    const gameType = String(card.dataset.gameCard || '');
    if (!GAME_SETUP[gameType]) return;
    const play = card.querySelector('.btn.primary');
    if (!(play instanceof HTMLButtonElement)) return;
    play.dataset.unifiedGameLaunch = gameType;
  });

  document.addEventListener('click', event => {
    const trigger = event.target instanceof Element
      ? event.target.closest('[data-unified-game-launch]')
      : null;
    if (!(trigger instanceof HTMLButtonElement)) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    if (trigger.disabled) return;
    openUnifiedSetup(String(trigger.dataset.unifiedGameLaunch || ''));
  }, true);
}

function openUnifiedSetup(gameType){
  if (!GAME_SETUP[gameType]) return;
  if (isSessionLocked(state.session)) return toast(sessionMessage(state.session));

  const bet = Number(APP_CONFIG.matchBet);
  if (!Number.isFinite(bet) || bet <= 0) return toast(t('setup.economy_unavailable'));

  haptic('light');
  activeGameType = gameType;
  activeSize = selectedSizeFor(gameType);
  state.selectedGame = gameType;
  state.room = 'match';
  state.selectedBet = bet;

  renderSetup();
}

function renderSetup(){
  const gameType = activeGameType;
  if (!gameType || !GAME_SETUP[gameType]) return;

  const title = gameTitle(gameType);
  const options = GAME_SETUP[gameType].options();
  activeSize = normalizeSize(gameType, activeSize);
  const bet = Number(APP_CONFIG.matchBet);

  openSheet(`
    <div class="sheet-head">
      <div>
        <h2>${escapeHtml(title)}</h2>
        <p>${escapeHtml(t('setup.subtitle'))}</p>
      </div>
      <button class="close" data-close-sheet type="button" aria-label="${escapeHtml(t('common.close'))}">×</button>
    </div>

    <div class="setup-scroll unified-game-setup">
      <div class="section-title"><h2>${escapeHtml(t('setup.variant_label'))}</h2></div>
      <div class="choice-grid ${options.length === 1 ? 'single-choice' : ''}" id="unifiedGameSizeChoices">
        ${options.map(option => `
          <button
            class="choice ${Number(option.value) === Number(activeSize) ? 'active' : ''}"
            data-unified-game-size="${Number(option.value)}"
            type="button"
            aria-pressed="${Number(option.value) === Number(activeSize) ? 'true' : 'false'}"
          >${escapeHtml(option.label)}</button>
        `).join('')}
      </div>

      <div class="section-title"><h2>${escapeHtml(t('setup.entry_label'))}</h2></div>
      <div class="choice-grid single-choice">
        <div class="choice active" role="status">${escapeHtml(t('setup.entry_value', { count:formatNumber(bet) }))}</div>
      </div>

      <div class="btn-row">
        <button class="btn ghost" data-game-rules="${escapeAttr(gameType)}" type="button">${escapeHtml(t('rules.open'))}</button>
        <button class="btn ghost" data-invite-friend="${escapeAttr(gameType)}" type="button">${escapeHtml(t('setup.invite'))}</button>
      </div>
    </div>

    <button class="btn primary full setup-start-btn" id="startUnifiedGameSearchBtn" type="button">${escapeHtml(t('setup.confirm'))}</button>
  `);

  document.querySelectorAll('[data-unified-game-size]').forEach(button => {
    button.addEventListener('click', () => {
      activeSize = normalizeSize(gameType, Number(button.dataset.unifiedGameSize));
      document.querySelectorAll('[data-unified-game-size]').forEach(item => {
        const active = item === button;
        item.classList.toggle('active', active);
        item.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      haptic('light');
    });
  });

  document.getElementById('startUnifiedGameSearchBtn')?.addEventListener('click', startUnifiedSearch);
}

async function startUnifiedSearch(){
  if (launchPending || !activeGameType) return;
  if (isSessionLocked(state.session)) return toast(sessionMessage(state.session));

  const bet = Number(APP_CONFIG.matchBet);
  const balance = Number(state.user?.balance);
  if (Number.isFinite(balance) && balance < bet) {
    openInsufficientBalance(balance, bet);
    return;
  }

  const gameType = activeGameType;
  const size = normalizeSize(gameType, activeSize);
  const button = document.getElementById('startUnifiedGameSearchBtn');
  launchPending = true;
  if (button instanceof HTMLButtonElement) button.disabled = true;

  state.selectedGame = gameType;
  state.room = 'match';
  state.selectedBet = bet;

  const variant = variantLabel(gameType, size);
  const label = t('setup.search_label', {
    game:gameTitle(gameType),
    count:formatNumber(bet),
    variant,
  });

  try {
    await beginSearch({
      gameType,
      room:'match',
      bet,
      size,
      title:gameTitle(gameType),
      label,
    });
  } finally {
    launchPending = false;
    if (button instanceof HTMLButtonElement && button.isConnected) button.disabled = false;
  }
}

function openInsufficientBalance(balance, needed){
  haptic('light');
  openSheet(`
    <div class="sheet-head">
      <div>
        <h2>${escapeHtml(t('setup.insufficient_title'))}</h2>
        <p>${escapeHtml(t('setup.insufficient_note', {
          needed:formatNumber(needed),
          balance:formatNumber(Math.max(0, balance)),
        }))}</p>
      </div>
      <button class="close" data-close-sheet type="button" aria-label="${escapeHtml(t('common.close'))}">×</button>
    </div>
    <button class="btn primary full" data-close-sheet type="button">${escapeHtml(t('common.close'))}</button>
  `);
}

function selectedSizeFor(gameType){
  const selected = {
    tictactoe:state.selectedBoardSize,
    four_in_a_row:state.selectedFourBoardSize,
    reversi:state.selectedReversiBoardSize,
    go:state.selectedGoBoardSize,
  }[gameType];
  return normalizeSize(gameType, selected);
}

function normalizeSize(gameType, value){
  const setup = GAME_SETUP[gameType];
  if (!setup) return 3;
  const options = setup.options();
  const numeric = Number(value);
  return options.some(option => Number(option.value) === numeric)
    ? numeric
    : Number(setup.defaultSize);
}

function variantLabel(gameType, size){
  const option = GAME_SETUP[gameType]?.options().find(item => Number(item.value) === Number(size));
  return String(option?.label || `${size}×${size}`);
}

function gameTitle(gameType){
  return t(`games.${gameType}.name`);
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function escapeAttr(value){
  return escapeHtml(value);
}
