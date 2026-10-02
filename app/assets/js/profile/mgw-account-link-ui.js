import { api } from '../api/client.js?v=47';
import { state } from '../state.js?v=27';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { toast } from '../components/toast.js?v=41';
import { t } from '@mgw/i18n';

const STORAGE_KEY = 'mgw_android_account_link_v1';
const CONFIRMED_STATUSES = new Set(['confirmed','db_linked','linked']);
const TERMINAL_RESTART_STATUSES = new Set(['cancelled','expired']);
let initialized = false;
let busy = false;
let volatileTelegramUrl = '';

const accountLinkText = (key, params = {}) => t(`account_link.${key}`, params);

export function accountLinkProfileMarkup(auth, identities){
  if (!isAndroidProvider(auth) || hasTelegramIdentity(identities)) return '';
  return `
    <button class="profile-v2-setting-row profile-v2-setting-button" type="button" data-open-account-link>
      <span>
        <strong>${accountLinkText('profile.title')}</strong>
        <small>${accountLinkText('profile.note')}</small>
      </span>
      <b>${accountLinkText('profile.connect')}</b>
    </button>
    <div class="profile-v2-account-divider"></div>
  `;
}

export function initAccountLinkUi(){
  if (initialized) return;
  initialized = true;

  const resume = () => {
    if (document.visibilityState === 'hidden') return;
    void resumePendingSilently();
  };
  document.addEventListener('visibilitychange', resume);
  globalThis.addEventListener?.('focus', resume, { passive:true });
  globalThis.addEventListener?.('pageshow', resume, { passive:true });
  queueMicrotask(resume);
}

export async function settlePendingAccountLinkBeforeBoot(){
  const pending = loadPending();
  if (!pending) return { status:'none' };

  try {
    // This path intentionally does not depend on state.user/profile hydration.
    // The Android HttpOnly auth cookie is already authoritative at the endpoint,
    // so a confirmed link can finish before bootstrap paints any account data.
    for (const delay of [0, 180, 360, 720]) {
      if (delay > 0) await new Promise(resolve => globalThis.setTimeout(resolve, delay));
      const result = await api.accountLinkStatus(pending.challenge_id);
      const link = result?.link || {};
      const status = String(link.status || '');

      if (status === 'linked') {
        clearPending();
        return { status:'linked' };
      }

      if (status === 'confirmed' || status === 'db_linked') {
        const finalized = await api.accountLinkFinalize(pending.challenge_id);
        if (String(finalized?.link?.status || '') !== 'linked') {
          throw new Error(accountLinkText('errors.server_unconfirmed'));
        }
        clearPending();
        return { status:'linked' };
      }

      if (TERMINAL_RESTART_STATUSES.has(status)) {
        clearPending();
        return { status };
      }

      if (!['pending','claimed'].includes(status)) return { status:status || 'unknown' };
    }
    return { status:'waiting' };
  } catch (error) {
    if (['challenge_not_found','challenge_expired'].includes(String(error?.code || ''))) {
      clearPending();
      return { status:'expired' };
    }
    // A transient link-status failure must not turn the whole application boot
    // into a hard failure. Normal authenticated bootstrap remains the fallback.
    return { status:'error', code:String(error?.code || '') };
  }
}

export async function openAccountLinkSheet(){
  if (!isCurrentAndroidProvider()) {
    toast(accountLinkText('errors.android_only'));
    return;
  }
  if (hasTelegramIdentity(state.mgwProfile?.identities)) {
    clearPending();
    toast(accountLinkText('errors.already_linked'));
    return;
  }

  renderLoading(accountLinkText('loading.checking_state'));
  const pending = loadPending();
  if (!pending) {
    renderIntro();
    return;
  }

  await refreshPending(pending, { showSheet:true });
}

async function createChallenge(){
  if (busy) return;
  busy = true;
  renderLoading(accountLinkText('loading.creating_safe_link'));
  try {
    const result = await api.accountLinkCreate();
    const link = result?.link || {};
    if (String(link.status || '') === 'linked') {
      clearPending();
      completeUiAndReload();
      return;
    }

    const challengeId = String(link.challenge_id || '').trim();
    const telegramUrl = String(link.telegram_url || '').trim();
    if (!/^lnk_[a-f0-9]{20}$/.test(challengeId) || !safeTelegramUrl(telegramUrl)) {
      throw new Error(accountLinkText('errors.invalid_server_link'));
    }

    volatileTelegramUrl = telegramUrl;
    savePending({
      challenge_id:challengeId,
      expires_at:String(link.expires_at || ''),
    });
    renderPendingState({ status:'pending', challenge_id:challengeId }, true);
    openTelegram(telegramUrl);
  } catch (error) {
    renderError(error?.message || accountLinkText('errors.create_failed'));
  } finally {
    busy = false;
  }
}

async function refreshPending(pending, options = {}){
  if (busy) return;
  busy = true;
  try {
    const result = await api.accountLinkStatus(pending.challenge_id);
    const link = result?.link || {};
    const status = String(link.status || '');

    if (CONFIRMED_STATUSES.has(status)) {
      await finalizePending(pending.challenge_id, options);
      return;
    }

    if (TERMINAL_RESTART_STATUSES.has(status)) {
      clearPending();
      if (options.showSheet) {
        renderIntro(status === 'expired'
          ? accountLinkText('restart.expired')
          : accountLinkText('restart.cancelled'));
      }
      return;
    }

    if (options.showSheet) renderPendingState(link, false);
  } catch (error) {
    if (['challenge_not_found','challenge_expired'].includes(String(error?.code || ''))) {
      clearPending();
      if (options.showSheet) renderIntro(accountLinkText('restart.inactive'));
      return;
    }
    if (options.showSheet) renderError(error?.message || accountLinkText('errors.check_failed'), true);
  } finally {
    busy = false;
  }
}

async function finalizePending(challengeId, options = {}){
  if (options.showSheet) renderLoading(accountLinkText('loading.finalizing_profile'));
  try {
    const result = await api.accountLinkFinalize(challengeId);
    if (String(result?.link?.status || '') !== 'linked') {
      throw new Error(accountLinkText('errors.server_unconfirmed'));
    }
    clearPending();
    completeUiAndReload();
  } catch (error) {
    if (options.showSheet) {
      renderError(error?.message || accountLinkText('errors.finalize_failed'), true);
    } else {
      toast(error?.message || accountLinkText('errors.finalize_telegram_failed'));
    }
  }
}

async function resumePendingSilently(){
  if (busy || !isCurrentAndroidProvider()) return;
  if (hasTelegramIdentity(state.mgwProfile?.identities)) {
    clearPending();
    return;
  }

  const pending = loadPending();
  if (!pending) return;

  // Returning from Telegram can race the confirmation callback by a fraction of
  // a second. Keep the existing sheet as the visible owner and perform a bounded
  // confirmation watch instead of one silent check that may miss the transition.
  renderLoading(accountLinkText('loading.checking_telegram'));
  for (const delay of [0, 220, 420, 780, 1200]) {
    if (!loadPending()) return;
    if (delay > 0) await new Promise(resolve => globalThis.setTimeout(resolve, delay));
    const current = loadPending();
    if (!current) return;
    await refreshPending(current, { showSheet:true });
    if (!loadPending()) return;
  }
}

function renderIntro(note = ''){
  volatileTelegramUrl = '';
  openSheet(`
    <div class="sheet-head">
      <div>
        <h2>${accountLinkText('intro.title')}</h2>
        <p>${accountLinkText('intro.note')}</p>
      </div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${note ? `<div class="profile-v2-empty">${escapeHtml(note)}</div>` : ''}
    <div class="profile-v2-account-card">
      <div class="profile-v2-setting-row">
        <span><strong>${accountLinkText('intro.saved_title')}</strong><small>${accountLinkText('intro.saved_note')}</small></span>
      </div>
      <div class="profile-v2-account-divider"></div>
      <div class="profile-v2-setting-row">
        <span><strong>${accountLinkText('intro.not_moved_title')}</strong><small>${accountLinkText('intro.not_moved_note')}</small></span>
      </div>
    </div>
    <div class="btn-row">
      <button class="btn primary full" type="button" data-account-link-start>${accountLinkText('intro.open_confirm')}</button>
    </div>
  `);
  bindSheetButton('[data-account-link-start]', createChallenge);
}

function renderPendingState(link, freshLink){
  const status = String(link?.status || 'pending');
  const claimed = status === 'claimed';
  const canReopen = safeTelegramUrl(volatileTelegramUrl);
  const lead = claimed
    ? accountLinkText('pending.lead_claimed')
    : (freshLink
      ? accountLinkText('pending.lead_fresh')
      : accountLinkText('pending.lead_waiting'));

  openSheet(`
    <div class="sheet-head">
      <div><h2>${accountLinkText('title')}</h2><p>${escapeHtml(lead)}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="profile-v2-account-card">
      <div class="profile-v2-setting-row">
        <span><strong>${accountLinkText('pending.status_label')}</strong><small>${claimed ? accountLinkText('pending.status_claimed_note') : accountLinkText('pending.status_pending_note')}</small></span>
        <b>${claimed ? accountLinkText('pending.status_claimed_badge') : accountLinkText('pending.status_pending_badge')}</b>
      </div>
    </div>
    <div class="btn-row">
      ${canReopen ? '<button class="btn ghost full" type="button" data-account-link-open>' + accountLinkText('pending.open_telegram') + '</button>' : ''}
      <button class="btn primary full" type="button" data-account-link-check>${accountLinkText('pending.confirmed')}</button>
      ${!canReopen && !claimed ? '<button class="btn ghost full" type="button" data-account-link-restart>' + accountLinkText('pending.restart') + '</button>' : ''}
    </div>
  `);

  bindSheetButton('[data-account-link-open]', () => openTelegram(volatileTelegramUrl));
  bindSheetButton('[data-account-link-check]', async () => {
    const pending = loadPending();
    if (pending) await refreshPending(pending, { showSheet:true });
  });
  bindSheetButton('[data-account-link-restart]', createChallenge);
}

function renderLoading(message){
  openSheet(`
    <div class="sheet-head">
      <div><h2>${accountLinkText('title')}</h2><p>${escapeHtml(message)}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="profile-v2-empty">${accountLinkText('loading.wait')}</div>
  `);
}

function renderError(message, keepPending = false){
  if (!keepPending) clearPending();
  openSheet(`
    <div class="sheet-head">
      <div><h2>${accountLinkText('error_title')}</h2><p>${escapeHtml(message)}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="btn-row">
      <button class="btn primary full" type="button" data-account-link-retry>${keepPending ? accountLinkText('retry.keep') : accountLinkText('retry.again')}</button>
    </div>
  `);
  bindSheetButton('[data-account-link-retry]', async () => {
    const pending = keepPending ? loadPending() : null;
    if (pending) await refreshPending(pending, { showSheet:true });
    else renderIntro();
  });
}

function completeUiAndReload(){
  closeSheet();
  toast(accountLinkText('success'));
  globalThis.setTimeout(() => globalThis.location.reload(), 320);
}

function openTelegram(candidate){
  if (!safeTelegramUrl(candidate)) {
    renderError(accountLinkText('errors.invalid_telegram_link'));
    return;
  }
  globalThis.location.assign(candidate);
}

function bindSheetButton(selector, handler){
  document.querySelector(selector)?.addEventListener('click', event => {
    event.preventDefault();
    void handler();
  }, { once:true });
}

function isCurrentAndroidProvider(){
  const provider = state.profileAuth?.provider || state.user?.mgw_identity_provider || '';
  return String(provider).trim().toLowerCase() === 'android_device';
}

function isAndroidProvider(auth){
  return String(auth?.provider || '').trim().toLowerCase() === 'android_device';
}

function hasTelegramIdentity(identities){
  return Array.isArray(identities)
    && identities.some(identity => String(identity?.provider || '').trim().toLowerCase() === 'telegram');
}

function safeTelegramUrl(candidate){
  try {
    const url = new URL(String(candidate || ''));
    return url.protocol === 'https:' && url.hostname === 't.me' && url.username === '' && url.password === '';
  } catch (_) {
    return false;
  }
}

function loadPending(){
  try {
    const parsed = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
    const challengeId = String(parsed?.challenge_id || '').trim();
    if (!/^lnk_[a-f0-9]{20}$/.test(challengeId)) {
      clearPending();
      return null;
    }
    return {
      challenge_id:challengeId,
      expires_at:String(parsed?.expires_at || ''),
    };
  } catch (_) {
    clearPending();
    return null;
  }
}

function savePending(value){
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({
      challenge_id:String(value?.challenge_id || ''),
      expires_at:String(value?.expires_at || ''),
    }));
  } catch (_) {}
}

function clearPending(){
  volatileTelegramUrl = '';
  try { localStorage.removeItem(STORAGE_KEY); } catch (_) {}
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'",'&#39;');
}
