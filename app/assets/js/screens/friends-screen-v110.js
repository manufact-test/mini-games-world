import { api } from '../api/client.js?v=47';
import { state } from '../state.js?v=27';
import { showScreen } from '../router.js?v=27';
import { openSheet, closeSheet } from '../components/sheet.js?v=1109';
import { toast } from '../components/toast.js?v=1109';
import { openSocialPlayerInvite } from '../games/game-invites-v110.js?v=1143&zone=unified&rematch=optimistic&terminal=self-silent&social=1';

const STYLE_URL = './assets/css/friends-v110.css?v=1&mvp18=friends-ui';
const GAME_NAMES = Object.freeze({
  tictactoe:'Крестики-нолики', four_in_a_row:'4 в ряд', battleship:'Морской бой',
  checkers:'Шашки', reversi:'Реверси', chess:'Шахматы', go:'Го', domino:'Домино',
});
const REPORT_REASONS = Object.freeze([
  ['abuse','Оскорбления или травля'],
  ['cheating','Нечестная игра'],
  ['spam','Спам или навязчивые сообщения'],
  ['offensive_profile','Недопустимый профиль'],
  ['other','Другое'],
]);

let initialized = false;
let loading = false;
let mutationPending = false;
let snapshot = emptySnapshot();
let searchResult = null;
let searchMessage = '';

export function initFriendsScreen(){
  if (initialized) return;
  initialized = true;
  ensureStyles();
  ensureScreen();
  document.addEventListener('mgw:open-friends', () => void openFriends());
}

async function openFriends(){
  if (activeMatchLocked()) {
    closeSheet();
    showScreen('game');
    return;
  }
  ensureScreen();
  closeSheet();
  showScreen('friends');
  render();
  await refreshSnapshot();
}

function ensureStyles(){
  if (document.querySelector('link[data-mgw-friends-style]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = STYLE_URL;
  link.dataset.mgwFriendsStyle = '1';
  document.head.append(link);
}

function ensureScreen(){
  if (document.getElementById('screen-friends')) return;
  const app = document.getElementById('app');
  if (!app) return;
  const screen = document.createElement('section');
  screen.className = 'screen';
  screen.id = 'screen-friends';
  screen.dataset.screen = 'friends';
  screen.innerHTML = '<div class="content"><div class="friends-v110" id="friendsV110Root"></div></div>';
  screen.addEventListener('click', handleClick);
  screen.addEventListener('submit', handleSubmit);
  app.append(screen);
}

async function refreshSnapshot(){
  if (loading) return;
  loading = true;
  render();
  try {
    const response = await api.friends({ action:'snapshot' });
    snapshot = normalizeSnapshot(response?.result);
  } catch (error) {
    toast(error?.message || 'Не удалось загрузить друзей.');
  } finally {
    loading = false;
    render();
  }
}

function render(){
  const root = document.getElementById('friendsV110Root');
  if (!root) return;
  const incoming = snapshot.incoming;
  const outgoing = snapshot.outgoing;
  const friends = snapshot.friends;
  const recent = snapshot.recent_opponents;
  const blocked = snapshot.blocked;

  root.innerHTML = `
    <div class="page-head">
      <div><h1 class="page-title">Друзья</h1><p class="page-sub">Игроки Mini Games World по нику или MGW-ID.</p></div>
      <button class="close" data-friends-back type="button" aria-label="Назад">×</button>
    </div>
    <form class="friends-v110-search" data-friends-search>
      <input class="form-input" name="query" autocomplete="off" maxlength="40" placeholder="Ник или MGW-ID" aria-label="Найти игрока по нику или MGW-ID" />
      <button class="btn primary" type="submit">Найти</button>
    </form>
    ${searchSurface()}
    ${loading ? '<div class="friends-v110-loading">Обновляем список…</div>' : `
      <div class="friends-v110-sections">
        ${section('Входящие заявки', incoming, 'incoming')}
        ${section('Исходящие заявки', outgoing, 'outgoing')}
        ${section('Друзья', friends, 'friends')}
        ${section('Недавние соперники', recent, 'recent')}
        ${section('Заблокированные', blocked, 'blocked')}
      </div>
    `}
  `;
}

function searchSurface(){
  if (searchResult) {
    return `<div class="friends-v110-search-result">${playerCard(searchResult, 'search')}</div>`;
  }
  if (searchMessage) return `<div class="friends-v110-empty friends-v110-search-result">${escapeHtml(searchMessage)}</div>`;
  return '';
}

function section(title, items, kind){
  const safeItems = Array.isArray(items) ? items : [];
  return `
    <section class="friends-v110-section" data-friends-section="${kind}">
      <div class="friends-v110-section-head"><h2>${escapeHtml(title)}</h2><span>${safeItems.length}</span></div>
      <div class="friends-v110-list">
        ${safeItems.length ? safeItems.map(player => playerCard(player, kind)).join('') : `<div class="friends-v110-empty">${emptyText(kind)}</div>`}
      </div>
    </section>
  `;
}

function playerCard(player, kind){
  const id = String(player?.mgw_id || '');
  const name = String(player?.nickname || player?.display_name || 'Игрок');
  const publicId = String(player?.public_mgw_id || '');
  const avatar = String(player?.avatar?.item_id || 'starter-default-01');
  const relation = relationStatus(id, kind);
  const secondary = kind === 'recent' && player?.last_match_at
    ? `Последний матч: ${formatDate(player.last_match_at)}`
    : publicId;

  return `
    <article class="friends-v110-card" data-social-player="${escapeHtml(id)}">
      <span class="friends-v110-avatar" data-avatar-item-id="${escapeHtml(avatar)}" aria-hidden="true">${escapeHtml(initials(name))}</span>
      <span class="friends-v110-copy"><strong>${escapeHtml(name)}</strong><small>${escapeHtml(secondary)}</small></span>
      <span class="friends-v110-actions">
        ${inlineActions(id, relation)}
        ${relation === 'blocked' ? '' : `<button class="friends-v110-more" data-friends-menu="${escapeHtml(id)}" type="button" aria-label="Действия с игроком">⋯</button>`}
      </span>
    </article>
  `;
}

function inlineActions(id, relation){
  if (relation === 'incoming') {
    return `<button class="btn primary" data-friends-action="accept" data-target-mgw-id="${escapeHtml(id)}" type="button">Принять</button><button class="btn ghost" data-friends-action="decline" data-target-mgw-id="${escapeHtml(id)}" type="button">Отклонить</button>`;
  }
  if (relation === 'outgoing') {
    return `<button class="btn ghost" data-friends-action="cancel" data-target-mgw-id="${escapeHtml(id)}" type="button">Отменить</button>`;
  }
  if (relation === 'blocked') {
    return `<button class="btn ghost" data-friends-action="unblock" data-target-mgw-id="${escapeHtml(id)}" type="button">Разблокировать</button>`;
  }
  if (relation === 'none') {
    return `<button class="btn primary" data-friends-action="request" data-target-mgw-id="${escapeHtml(id)}" type="button">Добавить</button>`;
  }
  return '';
}

function handleSubmit(event){
  const form = event.target instanceof HTMLFormElement ? event.target.closest('[data-friends-search]') : null;
  if (!form) return;
  event.preventDefault();
  const data = new FormData(form);
  void lookupPlayer(String(data.get('query') || ''));
}

function handleClick(event){
  const back = event.target.closest('[data-friends-back]');
  if (back) {
    showScreen('home');
    return;
  }

  const action = event.target.closest('[data-friends-action]');
  if (action) {
    const mutation = String(action.dataset.friendsAction || '');
    const targetMgwId = String(action.dataset.targetMgwId || '');
    if (mutation === 'unblock') {
      const player = playerById(targetMgwId);
      openConfirmSheet(
        'Разблокировать игрока?',
        `${String(player?.nickname || 'Игрок')} снова сможет взаимодействовать с вами через социальные функции.`,
        'Разблокировать',
        () => mutateFromSheet('unblock', targetMgwId)
      );
      return;
    }
    void mutateRelation(mutation, targetMgwId, action);
    return;
  }

  const menu = event.target.closest('[data-friends-menu]');
  if (menu) openPlayerMenu(String(menu.dataset.friendsMenu || ''));
}

async function lookupPlayer(query){
  const normalized = String(query || '').trim();
  searchResult = null;
  searchMessage = '';
  if (!normalized) {
    searchMessage = 'Введите точный ник или MGW-ID.';
    render();
    return;
  }
  try {
    const response = await api.friends({ action:'lookup', query:normalized });
    searchResult = response?.result || null;
    searchMessage = searchResult ? '' : 'Игрок не найден.';
  } catch (error) {
    searchMessage = error?.message || 'Не удалось выполнить поиск.';
  }
  render();
}

async function mutateRelation(action, targetMgwId, button = null){
  if (mutationPending || !targetMgwId) return;
  mutationPending = true;
  if (button instanceof HTMLButtonElement) button.disabled = true;
  try {
    await api.friends({ action, target_mgw_id:targetMgwId });
    if (searchResult?.mgw_id === targetMgwId && ['block','remove'].includes(action)) searchResult = null;
    await refreshSnapshot();
  } catch (error) {
    toast(error?.message || 'Не удалось выполнить действие.');
  } finally {
    mutationPending = false;
    if (button instanceof HTMLButtonElement && button.isConnected) button.disabled = false;
  }
}

function openPlayerMenu(targetMgwId){
  const player = playerById(targetMgwId);
  if (!player) return;
  const relation = relationStatus(targetMgwId);
  openSheet(`
    <div class="sheet-head"><div><h2>${escapeHtml(player.nickname || 'Игрок')}</h2><p>${escapeHtml(player.public_mgw_id || '')}</p></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="friends-v110-context">
      <button class="btn primary full" data-social-menu-action="invite" type="button">Пригласить в игру</button>
      <button class="btn ghost full" data-social-menu-action="profile" type="button">Профиль и статистика</button>
      ${relation === 'friends' ? '<button class="btn ghost full" data-social-menu-action="remove" type="button">Удалить из друзей</button>' : ''}
      <button class="btn ghost full" data-social-menu-action="report" type="button">Пожаловаться</button>
      <button class="btn ghost full friends-v110-danger" data-social-menu-action="block" type="button">Заблокировать</button>
    </div>
  `);
  document.querySelectorAll('#sheet [data-social-menu-action]').forEach(button => {
    button.addEventListener('click', () => void performMenuAction(String(button.dataset.socialMenuAction || ''), player));
  });
}

async function performMenuAction(action, player){
  const targetMgwId = String(player?.mgw_id || '');
  if (!targetMgwId) return;
  if (action === 'invite') {
    closeSheet();
    openSocialPlayerInvite(targetMgwId, String(player?.nickname || 'Игрок'));
    return;
  }
  if (action === 'profile') {
    await openPublicProfile(targetMgwId);
    return;
  }
  if (action === 'report') {
    openReportSheet(player);
    return;
  }
  if (action === 'remove') {
    openConfirmSheet('Удалить из друзей?', `Игрок ${String(player?.nickname || '')} исчезнет из списка друзей.`, 'Удалить', () => mutateFromSheet('remove', targetMgwId));
    return;
  }
  if (action === 'block') {
    openConfirmSheet('Заблокировать игрока?', 'Заявки в друзья и новые приглашения в игру будут недоступны, текущая дружба будет удалена.', 'Заблокировать', () => mutateFromSheet('block', targetMgwId), true);
  }
}

async function openPublicProfile(targetMgwId){
  try {
    const response = await api.friends({ action:'player_profile', target_mgw_id:targetMgwId });
    const profile = response?.result;
    if (!profile) throw new Error('Профиль недоступен.');
    openSheet(profileMarkup(profile));
  } catch (error) {
    toast(error?.message || 'Не удалось открыть профиль игрока.');
  }
}

function profileMarkup(profile){
  const stats = profile?.stats || {};
  const byGame = stats?.by_game || {};
  const name = String(profile?.nickname || 'Игрок');
  const avatar = String(profile?.avatar?.item_id || 'starter-default-01');
  return `
    <div class="sheet-head"><div><h2>Профиль игрока</h2><p>Публичная статистика MGW</p></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="friends-v110-profile">
      <div class="friends-v110-profile-main"><span class="friends-v110-avatar" data-avatar-item-id="${escapeHtml(avatar)}">${escapeHtml(initials(name))}</span><div><strong>${escapeHtml(name)}</strong><div class="friends-v110-profile-id">${escapeHtml(profile?.public_mgw_id || '')}</div></div></div>
      <div class="friends-v110-stats">${stat('Матчи', stats.games_played)}${stat('Победы', stats.wins)}${stat('Поражения', stats.losses)}${stat('Ничьи', stats.draws)}</div>
      <div class="friends-v110-game-grid">${Object.entries(GAME_NAMES).map(([gameType, title]) => gameStat(title, byGame?.[gameType])).join('')}</div>
    </div>
  `;
}

function openReportSheet(player){
  openSheet(`
    <div class="sheet-head"><div><h2>Пожаловаться</h2><p>${escapeHtml(player?.nickname || 'Игрок')} · ${escapeHtml(player?.public_mgw_id || '')}</p></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="friends-v110-report">
      <label><span>Причина</span><select class="form-input" id="socialReportReason">${REPORT_REASONS.map(([value,label]) => `<option value="${escapeHtml(value)}">${escapeHtml(label)}</option>`).join('')}</select></label>
      <label><span>Комментарий</span><textarea class="form-input" id="socialReportText" maxlength="800" placeholder="Необязательно. Кратко опишите ситуацию"></textarea></label>
    </div>
    <button class="btn primary full" id="socialReportSend" type="button">Отправить жалобу</button>
  `);
  document.getElementById('socialReportSend')?.addEventListener('click', async event => {
    const reason = String(document.getElementById('socialReportReason')?.value || '').trim();
    const details = String(document.getElementById('socialReportText')?.value || '').trim();
    const button = event.currentTarget;
    if (!reason) return toast('Выберите причину жалобы.');
    if (button instanceof HTMLButtonElement) button.disabled = true;
    try {
      const response = await api.friends({
        action:'report',
        target_mgw_id:String(player?.mgw_id || ''),
        reason,
        details,
        related_match_id:String(player?.related_match_id || ''),
      });
      const caseId = String(response?.result?.report_id || '');
      closeSheet();
      toast(caseId ? `Жалоба отправлена · ${caseId}` : 'Жалоба отправлена.');
    } catch (error) {
      toast(error?.message || 'Не удалось отправить жалобу.');
      if (button instanceof HTMLButtonElement && button.isConnected) button.disabled = false;
    }
  });
}

function openConfirmSheet(title, note, actionLabel, callback, danger = false){
  openSheet(`
    <div class="sheet-head"><div><h2>${escapeHtml(title)}</h2><p>${escapeHtml(note)}</p></div><button class="close" data-close-sheet type="button">×</button></div>
    <button class="btn ${danger ? 'ghost friends-v110-danger' : 'primary'} full" id="socialConfirmAction" type="button">${escapeHtml(actionLabel)}</button>
  `);
  document.getElementById('socialConfirmAction')?.addEventListener('click', callback, { once:true });
}

async function mutateFromSheet(action, targetMgwId){
  const button = document.getElementById('socialConfirmAction');
  if (button instanceof HTMLButtonElement) button.disabled = true;
  try {
    await api.friends({ action, target_mgw_id:targetMgwId });
    closeSheet();
    if (searchResult?.mgw_id === targetMgwId) searchResult = null;
    await refreshSnapshot();
  } catch (error) {
    toast(error?.message || 'Не удалось выполнить действие.');
    if (button instanceof HTMLButtonElement && button.isConnected) button.disabled = false;
  }
}

function relationStatus(targetMgwId, fallback = ''){
  if (snapshot.blocked.some(item => item.mgw_id === targetMgwId)) return 'blocked';
  if (snapshot.friends.some(item => item.mgw_id === targetMgwId)) return 'friends';
  if (snapshot.incoming.some(item => item.mgw_id === targetMgwId)) return 'incoming';
  if (snapshot.outgoing.some(item => item.mgw_id === targetMgwId)) return 'outgoing';
  if (['blocked','friends','incoming','outgoing'].includes(fallback)) return fallback;
  return 'none';
}

function playerById(targetMgwId){
  if (searchResult?.mgw_id === targetMgwId) return searchResult;
  for (const key of ['incoming','outgoing','friends','recent_opponents','blocked']) {
    const found = snapshot[key].find(item => item.mgw_id === targetMgwId);
    if (found) return found;
  }
  return null;
}

function normalizeSnapshot(value){
  const source = value && typeof value === 'object' ? value : {};
  return {
    incoming:Array.isArray(source.incoming) ? source.incoming : [],
    outgoing:Array.isArray(source.outgoing) ? source.outgoing : [],
    friends:Array.isArray(source.friends) ? source.friends : [],
    blocked:Array.isArray(source.blocked) ? source.blocked : [],
    recent_opponents:Array.isArray(source.recent_opponents) ? source.recent_opponents : [],
  };
}

function emptySnapshot(){ return { incoming:[], outgoing:[], friends:[], blocked:[], recent_opponents:[] }; }
function emptyText(kind){ return ({ incoming:'Нет входящих заявок.', outgoing:'Нет исходящих заявок.', friends:'Добавьте игрока по точному нику или MGW-ID.', recent:'Завершённые матчи с людьми появятся здесь.', blocked:'Список заблокированных пуст.' })[kind] || 'Пока пусто.'; }
function activeMatchLocked(){ const game = state.activeGame; const id = String(game?.id || ''); const status = String(game?.status || '').toLowerCase(); return Boolean(id && !['finished','cancelled','canceled','abandoned'].includes(status)); }
function stat(label, value){ return `<div class="friends-v110-stat"><strong>${escapeHtml(number(value))}</strong><span>${escapeHtml(label)}</span></div>`; }
function gameStat(title, value){ const s = value || {}; return `<div class="friends-v110-game"><strong>${escapeHtml(title)}</strong><small>${escapeHtml(number(s.games_played))} матч. · ${escapeHtml(number(s.wins))} побед</small></div>`; }
function number(value){ const n = Number(value); return Number.isFinite(n) ? String(Math.max(0, Math.trunc(n))) : '0'; }
function formatDate(value){ const date = new Date(value); return Number.isNaN(date.getTime()) ? 'недавно' : date.toLocaleDateString('ru-RU', { day:'2-digit', month:'2-digit', year:'numeric' }); }
function initials(value){ return String(value || 'MG').trim().split(/\s+/u).slice(0,2).map(part => part.slice(0,1).toUpperCase()).join('') || 'MG'; }
function escapeHtml(value){ return String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;'); }

initFriendsScreen();
