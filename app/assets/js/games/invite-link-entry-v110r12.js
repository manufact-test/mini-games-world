import { openSheet } from '../components/sheet.js?v=1109';
import { toast } from '../components/toast.js?v=1109';
import { getTelegram, getInitData, haptic } from '../telegram/telegram-app.js?v=27';
import { getSessionId } from '../session.js?v=27';

const INVITES_URL = `${window.location.origin}/bot/invites.php`;
let handled = false;

export async function openIncomingInviteFromTelegram(){
  const token = incomingToken();
  if (!token || handled) return false;
  handled = true;

  try {
    const result = await inviteRequest(token);
    publishNotificationSnapshot(result, token);

    const invite = result?.opened_invite || null;
    if (!invite?.token || String(invite.status || '') !== 'pending' || !invite.is_invitee) {
      return false;
    }

    showIncomingInvite(invite);
    return true;
  } catch (error) {
    console.warn('Mini Games World Telegram invite entry failed.', error);
    toast('Не удалось открыть приглашение. Попробуйте открыть ссылку ещё раз.');
    return false;
  }
}

async function inviteRequest(token){
  const response = await fetch(INVITES_URL, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body:JSON.stringify({
      initData:getInitData(),
      sessionId:getSessionId(),
      action:'open_link',
      token,
    }),
    priority:'high',
    cache:'no-store',
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) {
    throw new Error(data?.error || `Invite entry failed: ${response.status}`);
  }
  return data;
}

function publishNotificationSnapshot(result, token){
  const unreadCount = Number(result?.unread_count || 0);
  document.dispatchEvent(new CustomEvent('mgw:notification-count', {
    detail:{ unreadCount },
  }));

  const item = (Array.isArray(result?.invite_events) ? result.invite_events : []).find(value => {
    return String(value?.invite_token || '') === String(token || '');
  }) || null;
  if (!item?.id) return;

  document.dispatchEvent(new CustomEvent('mgw:notification-sync', {
    detail:{ item, unreadCount, announce:false },
  }));
}

function showIncomingInvite(invite){
  haptic('light');
  openSheet(`
    <span data-invite-sheet data-invite-token="${escapeHtml(invite.token || '')}" data-invite-state="pending:invitee" hidden></span>
    <div class="sheet-head">
      <div><h2>Вас приглашают сыграть</h2><p>От ${escapeHtml(invite.inviter_name || 'игрока')}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    <div class="stack invite-actions">
      <button class="btn primary full" data-invite-action="accept" data-invite-token="${escapeHtml(invite.token || '')}" type="button">Принять приглашение</button>
      <button class="btn ghost full" data-invite-action="decline" data-invite-token="${escapeHtml(invite.token || '')}" type="button">Отклонить</button>
    </div>
  `);
}

function inviteSummary(invite){
  return `
    <div class="topup-success">
      <div><span>Игра</span><strong>${escapeHtml(invite?.game_title || 'Игра')}</strong></div>
      <div><span>Комната</span><strong>${escapeHtml(invite?.room_label || roomLabel(invite?.room))}</strong></div>
      <div><span>Вариант</span><strong>${escapeHtml(inviteBoardLabel(invite))}</strong></div>
      <div><span>Ставка</span><strong>${Number(invite?.bet || 0)} коинов</strong></div>
    </div>
  `;
}

function inviteBoardLabel(invite){
  const gameType = String(invite?.game_type || '');
  if (gameType === 'four_in_a_row') {
    return `${Number(invite?.board_columns || invite?.board_size || 0)}×${Number(invite?.board_rows || 0)}`;
  }
  if (gameType === 'domino') return 'Классика 0–6';
  const size = Number(invite?.board_size || 0);
  return `${size}×${size}`;
}

function roomLabel(room){
  return String(room || '') === 'gold' ? 'Gold-комната' : 'Матч-комната';
}

function incomingToken(){
  const startParam = String(getTelegram()?.initDataUnsafe?.start_param || '');
  const fromTelegram = startParam.startsWith('invite_') ? startParam.slice(7) : '';
  const fromQuery = new URLSearchParams(window.location.search).get('invite') || '';
  const token = String(fromTelegram || fromQuery).toLowerCase();
  return /^[a-f0-9]{24}$/.test(token) ? token : '';
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
