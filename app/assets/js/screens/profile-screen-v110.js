import { api } from '../api/client.js?v=47';
import { state } from '../state.js?v=27';
import { showScreen } from '../router.js?v=27';
import { toast } from '../components/toast.js?v=41';
import { renderUser, renderBalances } from '../ui.js?v=89';
import { applyCanonicalMgwProfile, canonicalAvatarUrl } from '../profile/mgw-profile-model.js?v=1';
import { t, formatNumber, formatDate, formatDateTime } from '@mgw/i18n';

const PROFILE_STATS_CACHE_KEY = 'mgw_profile_stats_v2';
const GAME_TYPES = Object.freeze([
  'tictactoe',
  'four_in_a_row',
  'battleship',
  'checkers',
  'reversi',
  'chess',
  'go',
  'domino',
]);

let profileLoading = false;

export function initProfileScreen(){
  document.querySelector('#screen-profile [data-back-home]')?.remove();
  if (!hasProfileStats(state.profileStats)) {
    const cached = loadCachedProfileStats();
    if (cached) state.profileStats = cached;
  }
  bindProfileActions();
  renderProfileV2();
  document.addEventListener('mgw:open-profile', openProfile);
}

export async function openProfile(){
  showProfileImmediately();
  if (profileLoading) return;

  profileLoading = true;
  setProfileBusy(true);

  try {
    const result = await api.profileV2();
    state.mgwProfile = result.profile || null;
    state.user = applyCanonicalMgwProfile({
      ...(state.user && typeof state.user === 'object' ? state.user : {}),
      ...(result.user && typeof result.user === 'object' ? result.user : {}),
    }, state.mgwProfile);
    state.profileStats = result.stats || state.profileStats || null;
    state.profileHistory = result.history || state.profileHistory || null;
    state.profileAuth = result.auth || state.profileAuth || null;

    if (hasProfileStats(state.profileStats)) saveCachedProfileStats(state.profileStats);
    renderUser(state.user);
    renderBalances(state.user);
    renderProfileV2();
  } catch (error) {
    toast(error.message || t('profile.load_error'));
  } finally {
    profileLoading = false;
    setProfileBusy(false);
  }
}

function showProfileImmediately(){
  if (state.user) {
    renderUser(state.user);
    renderBalances(state.user);
  }
  renderProfileV2();
  showScreen('profile');
}

function bindProfileActions(){
  const screen = document.getElementById('screen-profile');
  if (!screen || screen.dataset.profileV2Bound === '1') return;
  screen.dataset.profileV2Bound = '1';
  screen.addEventListener('click', async event => {
    const copyButton = event.target.closest('[data-copy-mgw-id]');
    if (!copyButton) return;
    const mgwId = String(copyButton.dataset.copyMgwId || '').trim();
    if (!mgwId) return;
    await copyText(mgwId);
    toast(t('profile.id_copied'));
  });
}

function setProfileBusy(busy){
  const screen = document.getElementById('screen-profile');
  if (!screen) return;
  screen.classList.toggle('is-loading', Boolean(busy));
  screen.setAttribute('aria-busy', busy ? 'true' : 'false');
}

function renderProfileV2(){
  const root = ensureProfileRoot();
  if (!root) return;

  const profile = state.mgwProfile && typeof state.mgwProfile === 'object' ? state.mgwProfile : {};
  const user = state.user && typeof state.user === 'object' ? state.user : {};
  const stats = state.profileStats && typeof state.profileStats === 'object'
    ? state.profileStats
    : loadCachedProfileStats();
  const history = state.profileHistory && typeof state.profileHistory === 'object' ? state.profileHistory : {};

  const displayName = String(profile.display_name || user.display_name || user.first_name || t('profile.player')).trim();
  const username = String(profile.username || user.username || '').trim();
  const mgwId = String(profile.mgw_id || user.mgw_id || '').trim();
  const balance = Number(user.balance || 0);
  const registeredAt = profile.created_at || user.registered_at || null;
  const identities = Array.isArray(profile.identities) ? profile.identities : [];
  const matches = Array.isArray(history.matches) ? history.matches.slice(0, 8) : [];

  root.innerHTML = `
    <header class="profile-v2-head">
      <div>
        <h1>${escapeHtml(t('profile.title'))}</h1>
        <p>${escapeHtml(t('profile.subtitle'))}</p>
      </div>
    </header>

    <section class="profile-v2-identity">
      <div class="profile-v2-avatar" id="profileV2Avatar" aria-hidden="true">${escapeHtml(initials(displayName))}</div>
      <div class="profile-v2-person">
        <strong>${escapeHtml(displayName)}</strong>
        <span>${escapeHtml(username ? `@${username.replace(/^@/, '')}` : t('profile.nick_missing'))}</span>
        <small>${escapeHtml(registeredAt ? t('profile.member_since', { date:formatDate(registeredAt) }) : t('profile.member_since_unknown'))}</small>
      </div>
      <div class="profile-v2-id-card">
        <span>${escapeHtml(t('profile.mgw_id'))}</span>
        <button type="button" data-copy-mgw-id="${escapeHtml(mgwId)}" ${mgwId ? '' : 'disabled'}>
          <b>${escapeHtml(mgwId || '—')}</b>
          <small>${escapeHtml(t('profile.copy_id'))}</small>
        </button>
      </div>
    </section>

    <section class="profile-v2-balance">
      <div>
        <span>${escapeHtml(t('profile.balance'))}</span>
        <small>${escapeHtml(t('profile.balance_note'))}</small>
      </div>
      <strong>${escapeHtml(formatNumber(balance))}</strong>
    </section>

    <section class="profile-v2-section">
      ${sectionHead('profile.stats_title', 'profile.stats_note')}
      <div class="profile-v2-summary-grid">
        ${summaryStat(stats?.games_played, 'profile.games_played')}
        ${summaryStat(stats?.wins, 'profile.wins')}
        ${summaryStat(stats?.losses, 'profile.losses')}
        ${summaryStat(stats?.draws, 'profile.draws')}
      </div>
    </section>

    <section class="profile-v2-section">
      ${sectionHead('profile.by_game_title', 'profile.by_game_note')}
      <div class="profile-v2-games-grid">
        ${GAME_TYPES.map(gameType => gameStatCard(gameType, stats?.by_game?.[gameType])).join('')}
      </div>
    </section>

    <section class="profile-v2-section">
      ${sectionHead('profile.history_title', 'profile.history_note')}
      <div class="profile-v2-history">
        ${matches.length ? matches.map(match => historyRow(match)).join('') : emptyState('profile.history_empty')}
      </div>
    </section>

    <section class="profile-v2-section">
      ${sectionHead('profile.achievements_title', 'profile.achievements_note')}
      <div class="profile-v2-achievements" aria-label="${escapeHtml(t('profile.achievements_title'))}">
        ${[1,2,3].map(() => `<div class="profile-v2-achievement"><span aria-hidden="true">◇</span><strong>${escapeHtml(t('profile.achievement_locked'))}</strong><small>${escapeHtml(t('profile.achievement_soon'))}</small></div>`).join('')}
      </div>
    </section>

    <section class="profile-v2-section">
      ${sectionHead('profile.account_title', 'profile.account_note')}
      <div class="profile-v2-account-card">
        <div class="profile-v2-setting-row">
          <span><strong>${escapeHtml(t('profile.language'))}</strong><small>${escapeHtml(t('profile.language_note'))}</small></span>
          <b>${escapeHtml(t('profile.language_value'))}</b>
        </div>
        <div class="profile-v2-account-divider"></div>
        <div class="profile-v2-linked-head"><strong>${escapeHtml(t('profile.linked_accounts'))}</strong><small>${escapeHtml(t('profile.linked_accounts_note'))}</small></div>
        <div class="profile-v2-linked-list">
          ${identities.length ? identities.map(identity => identityRow(identity)).join('') : emptyState('profile.linked_empty')}
        </div>
      </div>
    </section>
  `;

  renderProfileAvatar(profile, displayName);
}

function ensureProfileRoot(){
  const content = document.querySelector('#screen-profile .content');
  if (!content) return null;
  let root = document.getElementById('profileV2Root');
  if (root) return root;
  content.innerHTML = '<div class="profile-v2" id="profileV2Root"></div>';
  return document.getElementById('profileV2Root');
}

function sectionHead(titleKey, noteKey){
  return `<div class="profile-v2-section-head"><div><h2>${escapeHtml(t(titleKey))}</h2><p>${escapeHtml(t(noteKey))}</p></div></div>`;
}

function summaryStat(value, labelKey){
  const normalized = Number.isFinite(Number(value)) ? formatNumber(Number(value)) : '—';
  return `<div class="profile-v2-summary-stat"><strong>${escapeHtml(normalized)}</strong><span>${escapeHtml(t(labelKey))}</span></div>`;
}

function gameStatCard(gameType, stats = null){
  const safeStats = stats && typeof stats === 'object' ? stats : {};
  return `
    <article class="profile-v2-game-stat">
      <div class="profile-v2-game-stat-head"><strong>${escapeHtml(gameName(gameType))}</strong><b>${escapeHtml(formatNumber(Number(safeStats.games_played || 0)))}</b></div>
      <div class="profile-v2-game-metrics">
        <span><b>${escapeHtml(formatNumber(Number(safeStats.wins || 0)))}</b><small>${escapeHtml(t('profile.metric_wins'))}</small></span>
        <span><b>${escapeHtml(formatNumber(Number(safeStats.losses || 0)))}</b><small>${escapeHtml(t('profile.metric_losses'))}</small></span>
        <span><b>${escapeHtml(formatNumber(Number(safeStats.draws || 0)))}</b><small>${escapeHtml(t('profile.metric_draws'))}</small></span>
      </div>
    </article>
  `;
}

function historyRow(match){
  const gameType = String(match?.game_type || 'tictactoe');
  const columns = Number(match?.board_columns || match?.board_size || 0);
  const rows = Number(match?.board_rows || match?.board_size || 0);
  const variant = columns > 0 && rows > 0 ? `${columns}×${rows}` : '';
  const when = match?.finished_at || match?.created_at || null;
  const tone = ['pos','neg','zero'].includes(String(match?.tone || '')) ? String(match.tone) : 'zero';
  return `
    <article class="profile-v2-history-row ${tone}">
      <div class="profile-v2-history-main">
        <strong>${escapeHtml(gameName(gameType))}${variant ? ` · ${escapeHtml(variant)}` : ''}</strong>
        <span>${escapeHtml(String(match?.opponent || t('profile.opponent')))}</span>
      </div>
      <div class="profile-v2-history-result">
        <b>${escapeHtml(String(match?.result || '—'))}</b>
        <small>${escapeHtml(when ? formatDateTime(when) : '')}</small>
      </div>
    </article>
  `;
}

function identityRow(identity){
  const provider = String(identity?.provider || '').trim().toLowerCase();
  const linkedAt = identity?.linked_at || null;
  return `
    <div class="profile-v2-linked-row">
      <span class="profile-v2-provider-mark" aria-hidden="true">${escapeHtml(provider.slice(0,1).toUpperCase() || '•')}</span>
      <span><strong>${escapeHtml(providerName(provider))}</strong><small>${escapeHtml(linkedAt ? t('profile.linked_since', { date:formatDate(linkedAt) }) : t('profile.linked'))}</small></span>
      <b>${escapeHtml(t('profile.connected'))}</b>
    </div>
  `;
}

function emptyState(key){
  return `<div class="profile-v2-empty">${escapeHtml(t(key))}</div>`;
}

function gameName(gameType){
  try { return t(`games.${gameType}.name`); }
  catch (error) { return gameType; }
}

function providerName(provider){
  const key = `profile.providers.${provider}`;
  try { return t(key); }
  catch (error) { return provider || t('profile.provider_unknown'); }
}

function renderProfileAvatar(profile, displayName){
  const avatar = document.getElementById('profileV2Avatar');
  if (!avatar) return;
  const url = canonicalAvatarUrl(profile?.avatar || null);
  avatar.textContent = initials(displayName);
  avatar.style.backgroundImage = '';
  avatar.classList.remove('has-photo');
  if (!url) return;
  avatar.textContent = '';
  avatar.style.backgroundImage = `url("${String(url).replace(/["\\]/g, '\\$&')}")`;
  avatar.classList.add('has-photo');
}

function initials(name){
  const clean = String(name || 'MG').replace('@','').trim();
  return clean.slice(0, 2).toUpperCase() || 'MG';
}

function hasProfileStats(stats){
  if (!stats || typeof stats !== 'object') return false;
  return ['games_played', 'wins', 'losses', 'draws'].every(key => Number.isFinite(Number(stats[key])));
}

function loadCachedProfileStats(){
  try {
    const parsed = JSON.parse(localStorage.getItem(PROFILE_STATS_CACHE_KEY) || 'null');
    return hasProfileStats(parsed) ? parsed : null;
  } catch (error) {
    return null;
  }
}

function saveCachedProfileStats(stats){
  try {
    localStorage.setItem(PROFILE_STATS_CACHE_KEY, JSON.stringify(stats));
  } catch (error) {
    // Profile remains usable when storage is unavailable.
  }
}

async function copyText(value){
  if (navigator.clipboard?.writeText) {
    await navigator.clipboard.writeText(value);
    return;
  }
  const textarea = document.createElement('textarea');
  textarea.value = value;
  textarea.setAttribute('readonly', '');
  textarea.style.position = 'fixed';
  textarea.style.opacity = '0';
  document.body.appendChild(textarea);
  textarea.select();
  document.execCommand('copy');
  textarea.remove();
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}
