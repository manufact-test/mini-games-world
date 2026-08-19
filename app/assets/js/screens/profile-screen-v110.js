import { api } from '../api/client.js?v=47';
import { state } from '../state.js?v=27';
import { currentScreen, showScreen } from '../router.js?v=27';
import { toast } from '../components/toast.js?v=41';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { renderUser, renderBalances } from '../ui.js?v=89';
import { canonicalAvatarItemId, mergeCanonicalMgwUser, publicMgwId } from '../profile/mgw-profile-model.js?v=1';
import { t, formatNumber, formatDate, formatDateTime } from '@mgw/i18n';

const PROFILE_STATS_CACHE_KEY = 'mgw_profile_stats_v2';
const GAME_TYPES = Object.freeze(['tictactoe','four_in_a_row','battleship','checkers','reversi','chess','go','domino']);
const LAUNCH_AVATARS = Object.freeze([
  'starter-default-01','starter-default-02','starter-default-03',
  'store-avatar-01','store-avatar-02','store-avatar-03','store-avatar-04','store-avatar-05',
]);
const NICKNAME_MAX_LENGTH = 13;
let profileLoading = false;
let nicknameSaving = false;
let avatarSaving = false;

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
  if (currentScreen() === 'profile') return;
  showProfileImmediately();
  if (profileLoading) return;
  profileLoading = true;
  try {
    applyProfileResponse(await api.profileV2());
  } catch (error) {
    toast(error.message || t('profile.load_error'));
  } finally {
    profileLoading = false;
  }
}

function applyProfileResponse(result){
  state.mgwProfile = result.profile || state.mgwProfile || null;
  state.profileInventory = result.inventory || state.profileInventory || null;
  state.user = mergeCanonicalMgwUser(state.user, result.user, state.mgwProfile);
  state.profileStats = result.stats || state.profileStats || null;
  state.profileHistory = result.history || state.profileHistory || null;
  state.profileAuth = result.auth || state.profileAuth || null;
  if (hasProfileStats(state.profileStats)) saveCachedProfileStats(state.profileStats);
  renderUser(state.user);
  renderBalances(state.user);
  renderProfileV2();
}

function showProfileImmediately(){
  if (state.mgwProfile) state.user = mergeCanonicalMgwUser(state.user, {}, state.mgwProfile);
  if (state.user) { renderUser(state.user); renderBalances(state.user); }
  renderProfileV2();
  showScreen('profile');
}

function bindProfileActions(){
  const screen = document.getElementById('screen-profile');
  if (!screen || screen.dataset.profileV2Bound === '1') return;
  screen.dataset.profileV2Bound = '1';
  screen.addEventListener('click', async event => {
    const copyButton = event.target.closest('[data-copy-mgw-id]');
    if (copyButton) {
      const mgwId = String(copyButton.dataset.copyMgwId || '').trim();
      if (!mgwId) return;
      await copyText(mgwId);
      toast(t('profile.id_copied'));
      return;
    }
    if (event.target.closest('[data-edit-mgw-nickname]')) {
      openNicknameEditor();
      return;
    }
    if (event.target.closest('[data-edit-mgw-avatar]')) {
      openAvatarEditor();
      return;
    }
    const avatarCard = event.target.closest('[data-profile-avatar-preview]');
    if (avatarCard) {
      openAvatarPreview(String(avatarCard.dataset.profileAvatarPreview || ''));
      return;
    }
    if (event.target.closest('[data-open-language-settings]')) {
      document.dispatchEvent(new CustomEvent('mgw:open-language-settings'));
    }
  });
}

function openNicknameEditor(){
  const nickname = String(state.mgwProfile?.nickname || state.user?.display_name || '').trim();
  openSheet(`
    <div class="sheet-head"><div><h2>${escapeHtml(t('profile.nickname_edit_title'))}</h2><p>${escapeHtml(t('profile.nickname_edit_note'))}</p></div><button class="close" data-close-sheet type="button">×</button></div>
    <input class="form-input mgw-nickname-input" id="mgwNicknameInput" maxlength="${NICKNAME_MAX_LENGTH}" autocomplete="off" value="${escapeHtml(nickname)}" aria-label="${escapeHtml(t('profile.nickname_edit_title'))}" />
    <button class="btn primary full" id="mgwNicknameSave" type="button">${escapeHtml(t('profile.nickname_save'))}</button>
  `);
  const input = document.getElementById('mgwNicknameInput');
  const save = document.getElementById('mgwNicknameSave');
  input?.focus({ preventScroll:true });
  save?.addEventListener('click', async () => {
    const value = normalizeNicknameInput(input?.value || '');
    if (value.length < 3) {
      toast(t('profile.nickname_too_short'));
      return;
    }
    if (value.length > NICKNAME_MAX_LENGTH) {
      toast(t('profile.nickname_too_long'));
      return;
    }
    if (nicknameSaving) return;
    if (value === String(state.mgwProfile?.nickname || state.user?.display_name || '').trim()) {
      closeSheet();
      return;
    }

    const previousProfile = cloneObject(state.mgwProfile);
    const previousUser = cloneObject(state.user);
    const optimisticProfile = {
      ...(state.mgwProfile && typeof state.mgwProfile === 'object' ? state.mgwProfile : {}),
      nickname:value,
      display_name:value,
    };

    nicknameSaving = true;
    state.mgwProfile = optimisticProfile;
    state.user = mergeCanonicalMgwUser(state.user, {}, optimisticProfile);
    renderUser(state.user);
    renderProfileV2();
    closeSheet();

    try {
      applyProfileResponse(await api.profileV2({ nickname:value }));
    } catch (error) {
      state.mgwProfile = previousProfile;
      state.user = previousProfile ? mergeCanonicalMgwUser(previousUser, {}, previousProfile) : previousUser;
      renderUser(state.user);
      renderProfileV2();
      toast(error.message || t('profile.save_error'));
    } finally {
      nicknameSaving = false;
    }
  });
}

function openAvatarEditor(){
  const avatars = ownedAvatarItems();
  const activeAvatar = currentAvatarItemId();
  openSheet(`
    <div class="sheet-head"><div><h2>Аватарки</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="profile-v2-avatar-sheet-grid profile-v2-avatar-sheet-grid--owned" aria-label="Аватарки">
      ${avatars.map(item => avatarChoiceMarkup(item, activeAvatar)).join('')}
    </div>
  `);
  document.querySelectorAll('#sheet [data-mgw-avatar-choice]').forEach(button => {
    button.addEventListener('click', () => openAvatarPreview(String(button.dataset.mgwAvatarChoice || '')));
  });
}

function openAvatarPreview(itemId){
  const item = ownedAvatarItems().find(candidate => candidate.item_id === itemId);
  if (!item) return;
  const active = itemId === currentAvatarItemId();
  openSheet(`
    <div class="sheet-head"><div><h2>${escapeHtml(avatarName(itemId))}</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="profile-v2-avatar-preview-wrap">
      <div class="profile-v2-avatar-preview" data-avatar-item-id="${escapeHtml(itemId)}" aria-hidden="true">MG</div>
    </div>
    <button class="btn primary full" id="mgwAvatarEquip" type="button" ${active ? 'disabled' : ''}>${active ? 'Выбрана' : 'Выбрать'}</button>
  `);
  document.getElementById('mgwAvatarEquip')?.addEventListener('click', event => {
    void chooseAvatar(itemId, event.currentTarget);
  });
}

async function chooseAvatar(itemId, button = null){
  if (!ownedAvatarIds().includes(itemId) || avatarSaving || itemId === currentAvatarItemId()) return;
  const previousProfile = cloneObject(state.mgwProfile);
  const previousUser = cloneObject(state.user);
  const optimisticProfile = {
    ...(state.mgwProfile && typeof state.mgwProfile === 'object' ? state.mgwProfile : {}),
    avatar:{ item_id:itemId },
  };

  avatarSaving = true;
  if (button) {
    button.disabled = true;
    button.textContent = 'Выбираем…';
  }
  state.mgwProfile = optimisticProfile;
  state.user = mergeCanonicalMgwUser(state.user, {}, optimisticProfile);
  renderUser(state.user);
  renderProfileV2();

  try {
    applyProfileResponse(await api.profileV2({ avatar_item_id:itemId }));
    closeSheet();
  } catch (error) {
    state.mgwProfile = previousProfile;
    state.user = previousProfile ? mergeCanonicalMgwUser(previousUser, {}, previousProfile) : previousUser;
    renderUser(state.user);
    renderProfileV2();
    if (button) {
      button.disabled = false;
      button.textContent = 'Выбрать';
    }
    toast(error.message || t('profile.avatar_save_error'));
  } finally {
    avatarSaving = false;
  }
}

function renderProfileV2(){
  const root = ensureProfileRoot();
  if (!root) return;
  const profile = state.mgwProfile && typeof state.mgwProfile === 'object' ? state.mgwProfile : {};
  const user = state.user && typeof state.user === 'object' ? state.user : {};
  const stats = state.profileStats && typeof state.profileStats === 'object' ? state.profileStats : loadCachedProfileStats();
  const history = state.profileHistory && typeof state.profileHistory === 'object' ? state.profileHistory : {};
  const nickname = String(profile.nickname || user.display_name || t('profile.player')).trim();
  const mgwId = publicMgwId(profile.public_mgw_id || profile.mgw_id || user.public_mgw_id || user.mgw_id);
  const balance = Number(user.balance || 0);
  const registeredAt = profile.created_at || user.registered_at || null;
  const identities = Array.isArray(profile.identities) ? profile.identities : [];
  const matches = Array.isArray(history.matches) ? history.matches.slice(0, 6) : [];
  const activeAvatar = canonicalAvatarItemId(profile.avatar || { item_id:user.avatar_item_id });
  const ownedAvatars = ownedAvatarItems(activeAvatar);

  root.innerHTML = `
    <header class="profile-v2-head"><div><h1>${escapeHtml(t('profile.title'))}</h1><p>${escapeHtml(t('profile.subtitle'))}</p></div></header>
    <section class="profile-v2-identity">
      <button class="profile-v2-avatar-edit" type="button" data-edit-mgw-avatar aria-label="${escapeHtml(t('profile.avatar_edit'))}">
        <span class="profile-v2-avatar" id="profileV2Avatar" data-avatar-item-id="${escapeHtml(activeAvatar)}" aria-hidden="true">MG</span>
        <span class="profile-v2-avatar-pencil" aria-hidden="true">✎</span>
      </button>
      <div class="profile-v2-person">
        <strong>${escapeHtml(nickname)}</strong>
        <button class="profile-v2-edit-link" type="button" data-edit-mgw-nickname>${escapeHtml(t('profile.nickname_edit'))}</button>
        <small>${escapeHtml(registeredAt ? t('profile.member_since', { date:formatDate(registeredAt) }) : t('profile.member_since_unknown'))}</small>
      </div>
      <div class="profile-v2-id-card"><button type="button" data-copy-mgw-id="${escapeHtml(mgwId)}" ${mgwId ? '' : 'disabled'} aria-label="${escapeHtml(t('profile.copy_id'))}"><b>${escapeHtml(mgwId || '—')}</b><small>${escapeHtml(t('profile.copy_id'))}</small></button></div>
    </section>
    <section class="profile-v2-section profile-v2-collection-section">
      <div class="profile-v2-section-head"><div><h2>Моя коллекция</h2></div></div>
      <div class="profile-v2-collection-title">Аватарки</div>
      <div class="profile-v2-collection-grid" aria-label="Мои аватарки">
        ${ownedAvatars.map(item => collectionAvatarMarkup(item, activeAvatar)).join('')}
      </div>
    </section>
    <section class="profile-v2-balance"><div><span>${escapeHtml(t('profile.balance'))}</span><small>${escapeHtml(t('profile.balance_note'))}</small></div><strong>${escapeHtml(formatNumber(balance))}</strong></section>
    <section class="profile-v2-section">${sectionHead('profile.stats_title','profile.stats_note')}<div class="profile-v2-summary-grid">${summaryStat(stats?.games_played,'profile.games_played')}${summaryStat(stats?.wins,'profile.wins')}${summaryStat(stats?.losses,'profile.losses')}${summaryStat(stats?.draws,'profile.draws')}</div></section>
    <section class="profile-v2-section">${sectionHead('profile.by_game_title','profile.by_game_note')}<div class="profile-v2-games-grid">${GAME_TYPES.map(gameType => gameStatCard(gameType, stats?.by_game?.[gameType])).join('')}</div></section>
    <section class="profile-v2-section">${sectionHead('profile.history_title')}<div class="profile-v2-history">${matches.length ? matches.map(historyRow).join('') : emptyState('profile.history_empty')}</div></section>
    <section class="profile-v2-section">${sectionHead('profile.achievements_title','profile.achievements_note')}<div class="profile-v2-achievements" aria-label="${escapeHtml(t('profile.achievements_title'))}">${[1,2,3].map(() => `<div class="profile-v2-achievement"><span aria-hidden="true">◇</span><strong>${escapeHtml(t('profile.achievement_locked'))}</strong><small>${escapeHtml(t('profile.achievement_soon'))}</small></div>`).join('')}</div></section>
    <section class="profile-v2-section">${sectionHead('profile.account_title','profile.account_note')}<div class="profile-v2-account-card">
      <button class="profile-v2-setting-row profile-v2-setting-button" type="button" data-open-language-settings><span><strong>${escapeHtml(t('profile.language'))}</strong><small>${escapeHtml(t('profile.language_note'))}</small></span><b>${escapeHtml(t('profile.language_value'))}</b></button>
      <div class="profile-v2-account-divider"></div>
      <div class="profile-v2-linked-head"><strong>${escapeHtml(t('profile.linked_accounts'))}</strong><small>${escapeHtml(t('profile.linked_accounts_note'))}</small></div>
      <div class="profile-v2-linked-list">${identities.length ? identities.map(identityRow).join('') : emptyState('profile.linked_empty')}</div>
    </div></section>
  `;
}

function ownedAvatarItems(activeAvatar = currentAvatarItemId()){
  const inventory = state.profileInventory && typeof state.profileInventory === 'object' ? state.profileInventory : null;
  const catalog = Array.isArray(inventory?.catalog) ? inventory.catalog : [];
  const owned = catalog
    .filter(item => item && item.item_family === 'avatar' && item.owned === true)
    .map(item => ({ ...item, item_id:String(item.item_id || '') }))
    .filter(item => LAUNCH_AVATARS.includes(item.item_id));

  if (owned.length) {
    return owned.sort((a,b) => LAUNCH_AVATARS.indexOf(a.item_id) - LAUNCH_AVATARS.indexOf(b.item_id));
  }

  // Every canonical account owns all three starter avatars. This fallback only
  // prevents a blank collection during the first profile request; DB inventory
  // replaces it as soon as the authoritative snapshot arrives.
  const fallbackIds = ['starter-default-01','starter-default-02','starter-default-03'];
  if (LAUNCH_AVATARS.includes(activeAvatar) && !fallbackIds.includes(activeAvatar)) fallbackIds.push(activeAvatar);
  return fallbackIds.map(itemId => ({ item_id:itemId, item_family:'avatar', owned:true }));
}

function ownedAvatarIds(){ return ownedAvatarItems().map(item => item.item_id); }

function collectionAvatarMarkup(item, activeAvatar){
  const itemId = String(item?.item_id || '');
  const active = itemId === activeAvatar;
  return `<button class="profile-v2-collection-card${active ? ' active' : ''}" type="button" data-profile-avatar-preview="${escapeHtml(itemId)}" aria-label="${escapeHtml(avatarName(itemId))}" aria-pressed="${active ? 'true' : 'false'}"><span class="profile-v2-collection-avatar" data-avatar-item-id="${escapeHtml(itemId)}" aria-hidden="true">MG</span>${active ? '<i class="profile-v2-selected-check" aria-hidden="true">✓</i>' : ''}</button>`;
}

function avatarChoiceMarkup(item, activeAvatar){
  const itemId = String(item?.item_id || '');
  const active = itemId === activeAvatar;
  return `<button type="button" data-mgw-avatar-choice="${escapeHtml(itemId)}" class="profile-v2-avatar-choice${active ? ' active' : ''}" aria-label="${escapeHtml(avatarName(itemId))}" aria-pressed="${active ? 'true' : 'false'}"><span data-avatar-item-id="${escapeHtml(itemId)}">MG</span>${active ? '<i class="profile-v2-selected-check" aria-hidden="true">✓</i>' : ''}</button>`;
}

function avatarName(itemId){
  const index = LAUNCH_AVATARS.indexOf(String(itemId || ''));
  return index >= 0 ? `Аватарка ${index + 1}` : 'Аватарка';
}
function currentAvatarItemId(){ return canonicalAvatarItemId(state.mgwProfile?.avatar || { item_id:state.user?.avatar_item_id }); }
function normalizeNicknameInput(value){ return String(value || '').replace(/\s+/gu, ' ').trim(); }
function cloneObject(value){ return value && typeof value === 'object' ? JSON.parse(JSON.stringify(value)) : value; }
function ensureProfileRoot(){
  const content = document.querySelector('#screen-profile .content');
  if (!content) return null;
  let root = document.getElementById('profileV2Root');
  if (root) return root;
  content.innerHTML = '<div class="profile-v2" id="profileV2Root"></div>';
  return document.getElementById('profileV2Root');
}
function sectionHead(titleKey, noteKey = null){ return `<div class="profile-v2-section-head"><div><h2>${escapeHtml(t(titleKey))}</h2>${noteKey ? `<p>${escapeHtml(t(noteKey))}</p>` : ''}</div></div>`; }
function summaryStat(value, labelKey){ const normalized = Number.isFinite(Number(value)) ? formatNumber(Number(value)) : '—'; return `<div class="profile-v2-summary-stat"><strong>${escapeHtml(normalized)}</strong><span>${escapeHtml(t(labelKey))}</span></div>`; }
function gameStatCard(gameType, stats = null){
  const s = stats && typeof stats === 'object' ? stats : {};
  return `<article class="profile-v2-game-stat"><div class="profile-v2-game-stat-head"><strong>${escapeHtml(gameName(gameType))}</strong><b>${escapeHtml(formatNumber(Number(s.games_played || 0)))}</b></div><div class="profile-v2-game-metrics"><span><b>${escapeHtml(formatNumber(Number(s.wins || 0)))}</b><small>${escapeHtml(t('profile.metric_wins'))}</small></span><span><b>${escapeHtml(formatNumber(Number(s.losses || 0)))}</b><small>${escapeHtml(t('profile.metric_losses'))}</small></span><span><b>${escapeHtml(formatNumber(Number(s.draws || 0)))}</b><small>${escapeHtml(t('profile.metric_draws'))}</small></span></div></article>`;
}
function historyRow(match){
  const gameType = String(match?.game_type || 'tictactoe');
  const columns = Number(match?.board_columns || match?.board_size || 0);
  const rows = Number(match?.board_rows || match?.board_size || 0);
  const variant = columns > 0 && rows > 0 ? `${columns}×${rows}` : '';
  const when = match?.finished_at || match?.created_at || null;
  const tone = ['pos','neg','zero'].includes(String(match?.tone || '')) ? String(match.tone) : 'zero';
  const economy = match?.economy && typeof match.economy === 'object' ? match.economy : null;
  const economyText = economy
    ? `Вход ${historyCoins(economy.entry)} · Награда ${historyCoins(economy.reward)} · Итог ${historyDelta(economy.ledger_delta)} · Баланс ${historyCoins(economy.new_balance)}`
    : '';
  const meta = [economyText, when ? formatDateTime(when) : ''].filter(Boolean).join(' · ');
  return `<article class="profile-v2-history-row ${tone}"><div class="profile-v2-history-main"><strong>${escapeHtml(gameName(gameType))}${variant ? ` · ${escapeHtml(variant)}` : ''}</strong><span>${escapeHtml(String(match?.opponent || t('profile.opponent')))}</span></div><div class="profile-v2-history-result"><b>${escapeHtml(String(match?.result || '—'))}</b><small>${escapeHtml(meta)}</small></div></article>`;
}
function historyCoins(value){
  if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';
  return `${Math.trunc(Number(value))}`;
}
function historyDelta(value){
  if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';
  const normalized = Math.trunc(Number(value));
  return `${normalized > 0 ? '+' : ''}${normalized}`;
}
function identityRow(identity){
  const provider = String(identity?.provider || '').trim().toLowerCase();
  const linkedAt = identity?.linked_at || null;
  return `<div class="profile-v2-linked-row"><span class="profile-v2-provider-mark" aria-hidden="true">${escapeHtml(provider.slice(0,1).toUpperCase() || '•')}</span><span><strong>${escapeHtml(providerName(provider))}</strong><small>${escapeHtml(linkedAt ? t('profile.linked_since',{ date:formatDate(linkedAt) }) : t('profile.linked'))}</small></span><b>${escapeHtml(t('profile.connected'))}</b></div>`;
}
function emptyState(key){ return `<div class="profile-v2-empty">${escapeHtml(t(key))}</div>`; }
function gameName(gameType){ try { return t(`games.${gameType}.name`); } catch (error) { return gameType; } }
function providerName(provider){ try { return t(`profile.providers.${provider}`); } catch (error) { return provider || t('profile.provider_unknown'); } }
function hasProfileStats(stats){ return Boolean(stats && typeof stats === 'object' && ['games_played','wins','losses','draws'].every(key => Number.isFinite(Number(stats[key])))); }
function loadCachedProfileStats(){ try { const parsed = JSON.parse(localStorage.getItem(PROFILE_STATS_CACHE_KEY) || 'null'); return hasProfileStats(parsed) ? parsed : null; } catch (error) { return null; } }
function saveCachedProfileStats(stats){ try { localStorage.setItem(PROFILE_STATS_CACHE_KEY, JSON.stringify(stats)); } catch (error) {} }
async function copyText(value){
  if (navigator.clipboard?.writeText) { await navigator.clipboard.writeText(value); return; }
  const textarea = document.createElement('textarea'); textarea.value = value; textarea.setAttribute('readonly',''); textarea.style.position='fixed'; textarea.style.opacity='0'; document.body.appendChild(textarea); textarea.select(); document.execCommand('copy'); textarea.remove();
}
function escapeHtml(value){ return String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#39;'); }
