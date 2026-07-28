import { state } from './state.js?v=27';
import { APP_CONFIG } from './config.js?v=38';
import { openSheet } from './components/sheet.js?v=68';
import { toast } from './components/toast.js?v=41';
import { getTelegram, getInitData, haptic } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';

const INVITES_URL = `${window.location.origin}/bot/invites.php`;
const DEFAULT_SIZE = {
  tictactoe:3,
  four_in_a_row:7,
  battleship:10,
  checkers:8,
  reversi:8,
  chess:8,
  go:9,
  domino:7,
};
const runtime = window.__MGW_V108_SHARE__ ||= {
  initialized:false,
  busy:false,
};

export function initV108Share(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  window.addEventListener('pointerdown', blockPreparedWarmup, true);
  window.addEventListener('click', ownLinkShare, true);
}

function blockPreparedWarmup(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  if (!origin.closest('[data-invite-friend], [data-create-link-invite]')) return;
  event.stopImmediatePropagation();
}

function ownLinkShare(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  const button = origin.closest('[data-create-link-invite]');
  if (!(button instanceof HTMLButtonElement)) return;

  event.preventDefault();
  event.stopImmediatePropagation();
  if (runtime.busy) return;
  void createReliableLinkInvite(button);
}

async function createReliableLinkInvite(button){
  runtime.busy = true;
  haptic('light');
  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = 'Готовим ссылку…';

  try {
    const context = readInviteContext();
    const result = await inviteRequest('create_link_draft', {...context, prepareMessage:false});
    const invite = result?.invite || null;
    const token = String(invite?.token || '');
    const shareUrl = String(invite?.share_url || '');
    if (!token || !shareUrl) throw new Error('Не удалось подготовить ссылку.');

    showLinkOwnerSheet(invite);
    openTelegramShare(invite);
  } catch (error) {
    toast(error?.message || 'Не удалось подготовить приглашение.');
    button.disabled = false;
    button.textContent = originalText;
  } finally {
    runtime.busy = false;
  }
}

function openTelegramShare(invite){
  const shareUrl = String(invite?.share_url || '');
  const text = String(invite?.share_text || '').replace(shareUrl, '').trim();
  const url = `https://t.me/share/url?url=${encodeURIComponent(shareUrl)}&text=${encodeURIComponent(text)}`;
  const telegram = getTelegram();
  try {
    if (typeof telegram?.openTelegramLink === 'function') telegram.openTelegramLink(url);
    else window.open(url, '_blank', 'noopener,noreferrer');
  } catch (error) {
    window.open(url, '_blank', 'noopener,noreferrer');
  }
}

function showLinkOwnerSheet(invite){
  const token = String(invite?.token || '');
  openSheet(`
    <span data-invite-sheet data-invite-token="${escapeHtml(token)}" data-invite-state="draft:owner" hidden></span>
    <div class="sheet-head">
      <div><h2>Ссылка подготовлена</h2><p>Выберите человека в Telegram и отправьте приглашение.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    <div class="small-note invite-status-note">Матч появится здесь, когда получатель откроет ссылку.</div>
    <div class="stack invite-actions">
      <button class="btn primary full" data-v108-share-again type="button">Открыть Telegram ещё раз</button>
      <button class="btn ghost full" data-invite-action="cancel" data-invite-token="${escapeHtml(token)}" type="button">Отменить ссылку</button>
    </div>
  `);
  document.querySelector('[data-v108-share-again]')?.addEventListener('click', () => openTelegramShare(invite));
}

function readInviteContext(){
  const retained = window.__MGW_V104_INVITE_CONTROLS__?.pickerContext;
  if (retained && typeof retained === 'object') {
    const gameType = String(retained.gameType || state.selectedGame || 'tictactoe');
    const room = String(retained.room || state.room || '') === 'gold' ? 'gold' : 'match';
    const boardSize = Number(retained.boardSize || DEFAULT_SIZE[gameType] || 3);
    const bet = Number(retained.bet || (room === 'gold' ? APP_CONFIG.goldBets?.[0] : APP_CONFIG.matchBet) || 10);
    return {gameType, room, boardSize, bet};
  }

  const gameType = String(state.selectedGame || 'tictactoe');
  const room = String(state.room || '') === 'gold' ? 'gold' : 'match';
  const boardSize = Number(document.querySelector('[data-invite-size].active')?.dataset.inviteSize || DEFAULT_SIZE[gameType] || 3);
  const fallbackBet = room === 'gold'
    ? Number(state.selectedBet || APP_CONFIG.goldBets?.[0] || 10)
    : Number(APP_CONFIG.matchBet || 10);
  const bet = Number(document.querySelector('[data-invite-bet].active')?.dataset.inviteBet || fallbackBet);
  return {gameType, room, boardSize, bet};
}

async function inviteRequest(action, payload){
  const response = await fetch(INVITES_URL, {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({
      initData:getInitData(),
      sessionId:getSessionId(),
      action,
      ...payload,
    }),
    priority:'high',
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) throw new Error(data?.error || `Ошибка приглашения: ${response.status}`);
  return data;
}

function inviteSummary(invite){
  const gameType = String(invite?.game_type || 'tictactoe');
  const size = Number(invite?.board_size || 3);
  const columns = Number(invite?.board_columns || size);
  const rows = Number(invite?.board_rows || size);
  const variant = gameType === 'domino' ? 'Классика 0–6' : `${columns}×${rows}`;
  return `
    <div class="topup-success">
      <div><span>Игра</span><strong>${escapeHtml(invite?.game_title || 'Игра')}</strong></div>
      <div><span>Комната</span><strong>${escapeHtml(invite?.room_label || (invite?.room === 'gold' ? 'Gold-комната' : 'Матч-комната'))}</strong></div>
      <div><span>Вариант</span><strong>${escapeHtml(variant)}</strong></div>
      <div><span>Ставка</span><strong>${Number(invite?.bet || 0)} коинов</strong></div>
    </div>
  `;
}

function escapeHtml(value){
  return String(value ?? '').replace(/[&<>'"]/g, character => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#039;', '"':'&quot;',
  }[character]));
}
