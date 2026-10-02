import { api } from '../api/client.js?v=47';
import { state } from '../state.js?v=27';
import { currentScreen, showScreen } from '../router.js?v=27';
import { openSheet, closeSheet } from '../components/sheet.js?v=1109';
import { toast } from '../components/toast.js?v=1109';
import { openSocialPlayerInvite } from '../games/game-invites-v110.js?v=1143&zone=unified&rematch=optimistic&terminal=self-silent&social=1';
import { t, formatDate as formatLocalizedDate, formatNumber as formatLocalizedNumber } from '@mgw/i18n';

const STYLE_URL = './assets/css/friends-v110.css?v=5&mvp18=instant-route&optimistic-relations';
const FRIENDS_REFRESH_MS = 5000;
const GAME_TYPES = Object.freeze([
  'tictactoe','four_in_a_row','battleship','checkers','reversi','chess','go','domino',
]);
const REPORT_REASON_CODES = Object.freeze([
  'nickname','avatar','spam','cheating','stalling','other',
]);
const friendsText = (key, params = {}) => t(`friends.${key}`, params);
const reportReasons = () => REPORT_REASON_CODES.map(value => [value, t(`home.report.reasons.${value}`)]);

let initialized = false;
let loading = false;
let mutationPending = false;
let snapshot = emptySnapshot();
let activeTab = 'friends';
let searching = false;
let searchQuery = '';
let searchResults = [];
let searchMessage = '';
let snapshotRefreshPromise = null;
let snapshotGeneration = 0;
let snapshotPollTimer = null;

export function initFriendsScreen(){
  if (initialized) return;
  initialized = true;
  ensureStyles();
  ensureScreen();
  document.addEventListener('mgw:open-friends', event => void openFriends(event?.detail));
  document.addEventListener('mgw:screen-changed', event => {
    if (event?.detail?.to === 'friends') startSnapshotPolling();
    if (event?.detail?.from === 'friends') stopSnapshotPolling();
  });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && currentScreen() === 'friends') {
      void refreshSnapshot({ silent:true });
    }
  });
}

async function openFriends(options = {}){
  if (activeMatchLocked()) {
    closeSheet();
    showScreen('game');
    return;
  }
  const requestedTab = String(options?.tab || '');
  if (['friends','requests','recent','blocked'].includes(requestedTab)) activeTab = requestedTab;
  ensureScreen();
  closeSheet();
  showScreen('friends');
  render();
  await refreshSnapshot({ silent:false });
}

function startSnapshotPolling(){
  stopSnapshotPolling();
  snapshotPollTimer = window.setInterval(() => {
    if (document.visibilityState === 'visible' && currentScreen() === 'friends') {
      void refreshSnapshot({ silent:true });
    }
  }, FRIENDS_REFRESH_MS);
}

function stopSnapshotPolling(){
  if (snapshotPollTimer !== null) window.clearInterval(snapshotPollTimer);
  snapshotPollTimer = null;
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
  screen.style.cssText = 'z-index:2;transition:none;transform:none;background:var(--sk-gradient-bg-accent),linear-gradient(180deg,var(--sk-bg-elevated),var(--sk-bg-app))';
  screen.innerHTML = '<div class="content"><div class="friends-v110" id="friendsV110Root"></div></div>';
  screen.addEventListener('click', handleClick);
  screen.addEventListener('submit', handleSubmit);
  app.append(screen);
}

async function refreshSnapshot({ silent = false, force = false } = {}){
  if (snapshotRefreshPromise) {
    if (!force) return snapshotRefreshPromise;
    await snapshotRefreshPromise;
  }
  const generation = snapshotGeneration;
  snapshotRefreshPromise = (async () => {
    if (!silent) {
      loading = true;
      render();
    }
    try {
      const response = await api.friends({ action:'snapshot' });
      if (generation !== snapshotGeneration) return;
      const nextSnapshot = normalizeSnapshot(response?.result);
      const changed = snapshotSignature(nextSnapshot) !== snapshotSignature(snapshot);
      snapshot = nextSnapshot;
      if (changed && silent) renderSilentSnapshotUpdate();
      if (changed) document.dispatchEvent(new CustomEvent('mgw:notifications-refresh'));
    } catch (error) {
      if (!silent && generation === snapshotGeneration) toast(error?.message || friendsText('errors.load'));
    } finally {
      if (!silent && generation === snapshotGeneration) {
        loading = false;
        render();
      }
    }
  })();
  try {
    await snapshotRefreshPromise;
  } finally {
    snapshotRefreshPromise = null;
  }
}

function snapshotSignature(value){
  return JSON.stringify(value);
}

function renderSilentSnapshotUpdate(){
  const scrollSurface = document.querySelector('#screen-friends > .content');
  const scrollTop = scrollSurface instanceof HTMLElement ? scrollSurface.scrollTop : 0;
  const previousInput = document.querySelector('#friendsV110Root input[name="query"]');
  const restoreFocus = previousInput instanceof HTMLInputElement && document.activeElement === previousInput;
  const selectionStart = restoreFocus ? previousInput.selectionStart : null;
  const selectionEnd = restoreFocus ? previousInput.selectionEnd : null;
  if (previousInput instanceof HTMLInputElement) searchQuery = previousInput.value;
  render();
  if (scrollSurface instanceof HTMLElement) scrollSurface.scrollTop = scrollTop;
  if (!restoreFocus) return;
  const nextInput = document.querySelector('#friendsV110Root input[name="query"]');
  if (!(nextInput instanceof HTMLInputElement)) return;
  nextInput.focus({ preventScroll:true });
  if (selectionStart !== null && selectionEnd !== null) nextInput.setSelectionRange(selectionStart, selectionEnd);
}

function render(){
  const root = document.getElementById('friendsV110Root');
  if (!root) return;
  const incoming = snapshot.incoming;
  const outgoing = snapshot.outgoing;
  const friends = snapshot.friends;
  const recent = snapshot.recent_opponents;
  const blocked = snapshot.blocked;
  const requestCount = incoming.length + outgoing.length;

  root.innerHTML = `
    <div class="page-head friends-v110-head">
      <div><h1 class="page-title">${escapeHtml(friendsText('page.title'))}</h1><p class="page-sub">${escapeHtml(friendsText('page.subtitle'))}</p></div>
      <button class="close" data-friends-back type="button" aria-label="${escapeHtml(t('common.back'))}">×</button>
    </div>
    <form class="friends-v110-search" data-friends-search>
      <div class="friends-v110-search-row">
        <input class="form-input" name="query" autocomplete="off" maxlength="40" value="${escapeHtml(searchQuery)}" placeholder="${escapeHtml(friendsText('search.placeholder'))}" aria-label="${escapeHtml(friendsText('search.aria'))}" />
        <button class="btn primary" type="submit" ${searching ? 'disabled' : ''}>${searching ? escapeHtml(friendsText('search.searching_short')) : escapeHtml(friendsText('search.action'))}</button>
      </div>
    </form>
    ${searchSurface()}
    ${loading ? '<div class="friends-v110-loading">' + escapeHtml(friendsText('loading.refresh')) + '</div>' : `
      <div class="friends-v110-tabs" role="tablist" aria-label="${escapeHtml(friendsText('tabs.aria'))}">
        ${tabButton('friends', friendsText('tabs.friends'), friends.length)}
        ${tabButton('requests', friendsText('tabs.requests'), requestCount)}
        ${tabButton('recent', friendsText('tabs.recent'), recent.length)}
        ${tabButton('blocked', friendsText('tabs.blocked'), blocked.length)}
      </div>
      <div class="friends-v110-panels">
        ${tabPanel('friends', section(friendsText('sections.friends'), friends, 'friends'))}
        ${tabPanel('requests', `${section(friendsText('sections.incoming'), incoming, 'incoming')}${section(friendsText('sections.outgoing'), outgoing, 'outgoing')}`)}
        ${tabPanel('recent', section(friendsText('sections.recent'), recent, 'recent'))}
        ${tabPanel('blocked', section(friendsText('sections.blocked'), blocked, 'blocked'))}
      </div>
    `}
  `;
}

function searchSurface(){
  if (searching) {
    return `<section class="friends-v110-search-surface"><div class="friends-v110-loading">${escapeHtml(friendsText('search.searching'))}</div></section>`;
  }
  if (searchResults.length) {
    return `
      <section class="friends-v110-search-surface" aria-live="polite">
        <div class="friends-v110-search-head"><strong>${escapeHtml(friendsText('search.results'))}</strong><button data-friends-clear-search type="button">${escapeHtml(friendsText('search.hide'))}</button></div>
        <div class="friends-v110-list">${searchResults.map(player => playerCard(player, 'search')).join('')}</div>
      </section>
    `;
  }
  if (searchMessage) return `<div class="friends-v110-empty friends-v110-search-result" aria-live="polite">${escapeHtml(searchMessage)}</div>`;
  return '';
}

function tabButton(tab, label, count){
  const selected = activeTab === tab;
  return `<button class="friends-v110-tab${selected ? ' active' : ''}" data-friends-tab="${tab}" type="button" role="tab" aria-selected="${selected ? 'true' : 'false'}"><span>${escapeHtml(label)}</span><b>${number(count)}</b></button>`;
}

function tabPanel(tab, content){
  return `<div class="friends-v110-panel" data-friends-panel="${tab}" role="tabpanel"${activeTab === tab ? '' : ' hidden'}>${content}</div>`;
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
  const name = String(player?.nickname || player?.display_name || friendsText('player_fallback'));
  const publicId = String(player?.public_mgw_id || '');
  const avatar = String(player?.avatar?.item_id || 'starter-default-01');
  const relation = relationStatus(id, kind);
  const secondary = kind === 'recent' && player?.last_match_at
    ? friendsText('recent_match', { date:formatDate(player.last_match_at) })
    : publicId;

  return `
    <article class="friends-v110-card friends-v110-card--${escapeHtml(kind)}" data-social-player="${escapeHtml(id)}">
      <span class="friends-v110-avatar" data-avatar-item-id="${escapeHtml(avatar)}" aria-hidden="true">${escapeHtml(initials(name))}</span>
      <span class="friends-v110-copy"><strong>${escapeHtml(name)}</strong><small>${escapeHtml(secondary)}</small></span>
      <span class="friends-v110-actions">
        ${inlineActions(id, relation)}
        ${relation === 'blocked' ? '' : `<button class="friends-v110-more" data-friends-menu="${escapeHtml(id)}" type="button" aria-label="${escapeHtml(friendsText('actions.aria'))}">⋯</button>`}
      </span>
    </article>
  `;
}

function inlineActions(id, relation){
  if (relation === 'incoming') {
    return `<button class="btn primary" data-friends-action="accept" data-target-mgw-id="${escapeHtml(id)}" type="button">${escapeHtml(friendsText('actions.accept'))}</button><button class="btn ghost" data-friends-action="decline" data-target-mgw-id="${escapeHtml(id)}" type="button">${escapeHtml(friendsText('actions.decline'))}</button>`;
  }
  if (relation === 'outgoing') {
    return `<button class="btn ghost" data-friends-action="cancel" data-target-mgw-id="${escapeHtml(id)}" type="button">${escapeHtml(friendsText('actions.cancel'))}</button>`;
  }
  if (relation === 'blocked') {
    return `<button class="btn ghost" data-friends-action="unblock" data-target-mgw-id="${escapeHtml(id)}" type="button">${escapeHtml(friendsText('actions.unblock'))}</button>`;
  }
  if (relation === 'none') {
    return `<button class="btn primary" data-friends-action="request" data-target-mgw-id="${escapeHtml(id)}" type="button">${escapeHtml(friendsText('actions.add'))}</button>`;
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
  const tab = event.target.closest('[data-friends-tab]');
  if (tab) {
    const nextTab = String(tab.dataset.friendsTab || '');
    if (['friends','requests','recent','blocked'].includes(nextTab)) {
      activeTab = nextTab;
      render();
    }
    return;
  }

  const clearSearch = event.target.closest('[data-friends-clear-search]');
  if (clearSearch) {
    searchQuery = '';
    searchResults = [];
    searchMessage = '';
    render();
    return;
  }

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
        friendsText('confirm.unblock_title'),
        friendsText('confirm.unblock_note', { name:String(player?.nickname || friendsText('player_fallback')) }),
        friendsText('actions.unblock'),
        () => mutateFromSheet('unblock', targetMgwId)
      );
      return;
    }
    void mutateRelation(mutation, targetMgwId);
    return;
  }

  const menu = event.target.closest('[data-friends-menu]');
  if (menu) openPlayerMenu(String(menu.dataset.friendsMenu || ''));
}

async function lookupPlayer(query){
  const normalized = String(query || '').trim();
  searchQuery = normalized;
  searchResults = [];
  searchMessage = '';
  if (!normalized) {
    searchMessage = friendsText('search.enter_query');
    render();
    return;
  }
  const nicknameQuery = normalized.replace(/^@/u, '');
  const looksLikeMgwId = /^MGW-(?:ID-)?/iu.test(normalized);
  if (!looksLikeMgwId && Array.from(nicknameQuery).length < 2) {
    searchMessage = friendsText('search.nickname_min');
    render();
    return;
  }
  searching = true;
  render();
  try {
    const response = await api.friends({ action:'lookup', query:normalized });
    const players = response?.result?.players;
    searchResults = Array.isArray(players) ? players.filter(player => player && typeof player === 'object') : [];
    searchMessage = searchResults.length ? '' : friendsText('search.not_found');
  } catch (error) {
    searchMessage = error?.message || friendsText('errors.search');
  } finally {
    searching = false;
  }
  render();
}

async function mutateRelation(action, targetMgwId){
  if (mutationPending || !targetMgwId) return;
  mutationPending = true;
  snapshotGeneration += 1;
  loading = false;
  const previousSnapshot = cloneObject(snapshot);
  const previousSearchResults = cloneObject(searchResults);
  const optimistic = applyOptimisticRelation(action, targetMgwId);
  if (optimistic) renderSilentSnapshotUpdate();
  try {
    await api.friends({ action, target_mgw_id:targetMgwId });
    if (['block','remove'].includes(action)) searchResults = searchResults.filter(player => player?.mgw_id !== targetMgwId);
    document.dispatchEvent(new CustomEvent('mgw:notifications-refresh'));
    await refreshSnapshot({ silent:true, force:true });
  } catch (error) {
    snapshot = previousSnapshot;
    searchResults = previousSearchResults;
    renderSilentSnapshotUpdate();
    toast(error?.message || friendsText('errors.action'));
  } finally {
    mutationPending = false;
  }
}

function applyOptimisticRelation(action, targetMgwId){
  const player = playerById(targetMgwId);
  if (!player) return false;
  const next = cloneObject(snapshot);
  for (const key of ['incoming','outgoing','friends','blocked']) {
    next[key] = next[key].filter(item => String(item?.mgw_id || '') !== targetMgwId);
  }

  const target = cloneObject(player);
  if (action === 'request') next.outgoing.unshift(target);
  else if (action === 'accept') next.friends.unshift(target);
  else if (action === 'block') next.blocked.unshift(target);
  else if (!['cancel','decline','remove','unblock'].includes(action)) return false;

  snapshot = next;
  return true;
}

function openPlayerMenu(targetMgwId){
  const player = playerById(targetMgwId);
  if (!player) return;
  const relation = relationStatus(targetMgwId);
  openSheet(`
    <div class="sheet-head"><div><h2>${escapeHtml(player.nickname || friendsText('player_fallback'))}</h2><p>${escapeHtml(player.public_mgw_id || '')}</p></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="friends-v110-context">
      <button class="btn primary full" data-social-menu-action="invite" type="button">${escapeHtml(friendsText('menu_actions.invite'))}</button>
      <button class="btn ghost full" data-social-menu-action="profile" type="button">${escapeHtml(friendsText('menu_actions.profile'))}</button>
      ${relation === 'friends' ? `<button class="btn ghost full" data-social-menu-action="remove" type="button">${escapeHtml(friendsText('menu_actions.remove'))}</button>` : ''}
      <button class="btn ghost full" data-social-menu-action="report" type="button">${escapeHtml(friendsText('menu_actions.report'))}</button>
      <button class="btn ghost full friends-v110-danger" data-social-menu-action="block" type="button">${escapeHtml(friendsText('menu_actions.block'))}</button>
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
    openSocialPlayerInvite(targetMgwId, String(player?.nickname || friendsText('player_fallback')));
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
    openConfirmSheet(friendsText('confirm.remove_title'), friendsText('confirm.remove_note', { name:String(player?.nickname || '') }), friendsText('actions.remove'), () => mutateFromSheet('remove', targetMgwId));
    return;
  }
  if (action === 'block') {
    openConfirmSheet(friendsText('confirm.block_title'), friendsText('confirm.block_note'), friendsText('actions.block'), () => mutateFromSheet('block', targetMgwId), true);
  }
}

async function openPublicProfile(targetMgwId){
  try {
    const response = await api.friends({ action:'player_profile', target_mgw_id:targetMgwId });
    const profile = response?.result;
    if (!profile) throw new Error(friendsText('errors.profile_unavailable'));
    openSheet(profileMarkup(profile));
  } catch (error) {
    toast(error?.message || friendsText('errors.profile_open'));
  }
}

function profileMarkup(profile){
  const stats = profile?.stats || {};
  const byGame = stats?.by_game || {};
  const name = String(profile?.nickname || friendsText('player_fallback'));
  const avatar = String(profile?.avatar?.item_id || 'starter-default-01');
  return `
    <div class="sheet-head"><div><h2>${escapeHtml(friendsText('profile.title'))}</h2><p>${escapeHtml(friendsText('profile.subtitle'))}</p></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="friends-v110-profile">
      <div class="friends-v110-profile-main"><span class="friends-v110-avatar" data-avatar-item-id="${escapeHtml(avatar)}">${escapeHtml(initials(name))}</span><div><strong>${escapeHtml(name)}</strong><div class="friends-v110-profile-id">${escapeHtml(profile?.public_mgw_id || '')}</div></div></div>
      <div class="friends-v110-stats">${stat(friendsText('profile.matches'), stats.games_played)}${stat(friendsText('profile.wins'), stats.wins)}${stat(friendsText('profile.losses'), stats.losses)}${stat(friendsText('profile.draws'), stats.draws)}</div>
      <div class="friends-v110-game-grid">${GAME_TYPES.map(gameType => gameStat(t(`friends.profile.game_names.${gameType}`), byGame?.[gameType])).join('')}</div>
    </div>
  `;
}

function openReportSheet(player){
  const reasons = reportReasons();
  const [initialReason, initialReasonLabel] = reasons[0];
  openSheet(`
    <div class="sheet-head"><div><h2>${escapeHtml(friendsText('report.title'))}</h2><p>${escapeHtml(player?.nickname || friendsText('player_fallback'))} · ${escapeHtml(player?.public_mgw_id || '')}</p></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="friends-v110-report">
      <div class="friends-v110-field"><span id="socialReportReasonLabel">${escapeHtml(friendsText('report.reason'))}</span>
        <div class="friends-v110-report-select" data-report-reason-select>
          <button class="friends-v110-report-select-trigger" data-report-reason-trigger type="button" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="socialReportReasonLabel socialReportReasonValue"><span id="socialReportReasonValue">${escapeHtml(initialReasonLabel)}</span><i aria-hidden="true"></i></button>
          <div class="friends-v110-report-select-menu" data-report-reason-menu role="listbox" aria-labelledby="socialReportReasonLabel" hidden>
            ${reasons.map(([value,label], index) => `<button type="button" role="option" data-report-reason="${escapeHtml(value)}" aria-selected="${index === 0 ? 'true' : 'false'}">${escapeHtml(label)}</button>`).join('')}
          </div>
          <input id="socialReportReason" type="hidden" value="${escapeHtml(initialReason)}" />
        </div>
      </div>
      <label class="friends-v110-field"><span>${escapeHtml(friendsText('report.comment'))}</span><textarea class="form-input" id="socialReportText" maxlength="800" placeholder="${escapeHtml(friendsText('report.comment_placeholder'))}"></textarea></label>
    </div>
    <button class="btn primary full" id="socialReportSend" type="button">${escapeHtml(friendsText('report.send'))}</button>
  `);
  bindReportReasonSelect();
  document.getElementById('socialReportSend')?.addEventListener('click', async event => {
    const reason = String(document.getElementById('socialReportReason')?.value || '').trim();
    const details = String(document.getElementById('socialReportText')?.value || '').trim();
    const button = event.currentTarget;
    if (!reason) return toast(friendsText('report.choose_reason'));
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
      toast(caseId ? friendsText('report.sent_case', { case_id:caseId }) : friendsText('report.sent'));
    } catch (error) {
      toast(error?.message || friendsText('report.send_error'));
      if (button instanceof HTMLButtonElement && button.isConnected) button.disabled = false;
    }
  });
}

function bindReportReasonSelect(){
  const root = document.querySelector('#sheet [data-report-reason-select]');
  const trigger = root?.querySelector('[data-report-reason-trigger]');
  const menu = root?.querySelector('[data-report-reason-menu]');
  const value = document.getElementById('socialReportReason');
  const valueLabel = document.getElementById('socialReportReasonValue');
  if (!(root instanceof HTMLElement) || !(trigger instanceof HTMLButtonElement) || !(menu instanceof HTMLElement)) return;

  const close = () => {
    menu.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
  };
  trigger.addEventListener('click', () => {
    const open = menu.hidden;
    menu.hidden = !open;
    trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) menu.querySelector('[aria-selected="true"]')?.focus();
  });
  menu.addEventListener('click', event => {
    const option = event.target.closest('[data-report-reason]');
    if (!(option instanceof HTMLButtonElement)) return;
    menu.querySelectorAll('[data-report-reason]').forEach(item => item.setAttribute('aria-selected', item === option ? 'true' : 'false'));
    if (value instanceof HTMLInputElement) value.value = String(option.dataset.reportReason || '');
    if (valueLabel) valueLabel.textContent = String(option.textContent || '').trim();
    close();
    trigger.focus();
  });
  root.addEventListener('focusout', event => {
    if (!(event.relatedTarget instanceof Node) || !root.contains(event.relatedTarget)) close();
  });
  root.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    close();
    trigger.focus();
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
  if (mutationPending || !targetMgwId) return;
  mutationPending = true;
  snapshotGeneration += 1;
  loading = false;
  const previousSnapshot = cloneObject(snapshot);
  const previousSearchResults = cloneObject(searchResults);
  applyOptimisticRelation(action, targetMgwId);
  closeSheet();
  renderSilentSnapshotUpdate();
  try {
    await api.friends({ action, target_mgw_id:targetMgwId });
    searchResults = searchResults.filter(player => player?.mgw_id !== targetMgwId);
    document.dispatchEvent(new CustomEvent('mgw:notifications-refresh'));
    await refreshSnapshot({ silent:true, force:true });
  } catch (error) {
    snapshot = previousSnapshot;
    searchResults = previousSearchResults;
    renderSilentSnapshotUpdate();
    toast(error?.message || friendsText('errors.action'));
  } finally {
    mutationPending = false;
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
  const searchPlayer = searchResults.find(item => item?.mgw_id === targetMgwId);
  if (searchPlayer) return searchPlayer;
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

function cloneObject(value){
  return value && typeof value === 'object' ? JSON.parse(JSON.stringify(value)) : value;
}

function emptySnapshot(){ return { incoming:[], outgoing:[], friends:[], blocked:[], recent_opponents:[] }; }
function emptyText(kind){ return ({ incoming:friendsText('empty.incoming'), outgoing:friendsText('empty.outgoing'), friends:friendsText('empty.friends'), recent:friendsText('empty.recent'), blocked:friendsText('empty.blocked') })[kind] || friendsText('empty.default'); }
function activeMatchLocked(){ const game = state.activeGame; const id = String(game?.id || ''); const status = String(game?.status || '').toLowerCase(); return Boolean(id && !['finished','cancelled','canceled','abandoned'].includes(status)); }
function stat(label, value){ return `<div class="friends-v110-stat"><strong>${escapeHtml(number(value))}</strong><span>${escapeHtml(label)}</span></div>`; }
function gameStat(title, value){ const s = value || {}; return `<div class="friends-v110-game"><strong>${escapeHtml(title)}</strong><small>${escapeHtml(friendsText('profile.game_stats', { matches:number(s.games_played), wins:number(s.wins) }))}</small></div>`; }
function number(value){ const n = Number(value); return Number.isFinite(n) ? formatLocalizedNumber(Math.max(0, Math.trunc(n)), { maximumFractionDigits:0 }) : '0'; }
function formatDate(value){ const date = new Date(value); return Number.isNaN(date.getTime()) ? friendsText('recently') : formatLocalizedDate(date, 'short', { day:'2-digit', month:'2-digit', year:'numeric' }); }
function initials(value){ return String(value || 'MG').trim().split(/\s+/u).slice(0,2).map(part => part.slice(0,1).toUpperCase()).join('') || 'MG'; }
function escapeHtml(value){ return String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;'); }

initFriendsScreen();
