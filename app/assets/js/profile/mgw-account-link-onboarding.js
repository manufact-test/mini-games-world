import { state } from '../state.js?v=27';
import { currentScreen } from '../router.js?v=27';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { openAccountLinkSheet } from './mgw-account-link-ui.js?v=2';

const DISMISS_KEY = 'mgw_android_account_link_onboarding_v1';
const PENDING_LINK_KEY = 'mgw_android_account_link_v1';
const ONBOARDING_OVERLAY_CLASS = 'mgw-account-link-onboarding-overlay';
const ONBOARDING_SHEET_CLASS = 'mgw-account-link-onboarding-sheet';
const STAGING_PREVIEW_HOST = 'seashell-okapi-889488.hostingersite.com';

let initialized = false;
let appReady = false;
let onboardingVisible = false;
let previewVisible = false;
let attemptTimer = null;

export function initAccountLinkHomeOnboarding(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('mgw:app-ready', () => {
    appReady = true;
    scheduleAttempt(280);
  });

  document.addEventListener('mgw:screen-changed', () => {
    if (appReady) scheduleAttempt(120);
  });

  document.addEventListener('mgw:sheet-closed', () => {
    clearOnboardingPresentation();
    if (previewVisible) {
      previewVisible = false;
      return;
    }
    if (onboardingVisible) {
      onboardingVisible = false;
      persistDismissal();
      return;
    }
    if (appReady) scheduleAttempt(120);
  });
}

export function accountLinkOnboardingEligibility(snapshot = {}){
  const provider = String(snapshot.provider || '').trim().toLowerCase();
  const mgwId = String(snapshot.mgwId || '').trim();
  const identities = Array.isArray(snapshot.identities) ? snapshot.identities : [];
  const dismissedMgwId = String(snapshot.dismissedMgwId || '').trim();

  if (provider !== 'android_device') return false;
  if (!mgwId) return false;
  if (snapshot.hasPending === true) return false;
  if (hasTelegramIdentity(identities)) return false;
  return dismissedMgwId !== mgwId;
}

export function accountLinkOnboardingPreviewMarkup(auth, identities){
  if (!isStagingPreviewContext()) return '';
  const provider = String(auth?.provider || state.user?.mgw_identity_provider || '').trim().toLowerCase();
  if (provider !== 'android_device' || !hasTelegramIdentity(identities)) return '';
  return `
    <button class="profile-v2-setting-row profile-v2-setting-button" type="button" data-open-account-link-onboarding-preview>
      <span>
        <strong>Предпросмотр приветствия Android</strong>
        <small>Только staging: посмотреть первый экран привязки без отвязки Telegram</small>
      </span>
      <b>Показать</b>
    </button>
    <div class="profile-v2-account-divider"></div>
  `;
}

export function openAccountLinkOnboardingPreview(){
  if (!isStagingPreviewContext()) return;
  previewVisible = true;
  onboardingVisible = false;
  renderOnboardingCard({ preview:true });
}

function scheduleAttempt(delay){
  if (attemptTimer !== null) window.clearTimeout(attemptTimer);
  attemptTimer = window.setTimeout(() => {
    attemptTimer = null;
    tryShowOnboarding();
  }, Math.max(0, Number(delay) || 0));
}

function tryShowOnboarding(){
  if (!appReady || onboardingVisible || previewVisible) return;

  const preloader = document.getElementById('preloader');
  if (preloader instanceof HTMLElement && !preloader.classList.contains('hidden')) {
    scheduleAttempt(120);
    return;
  }

  if (currentScreen() !== 'home') return;

  const overlay = document.getElementById('sheetOverlay');
  if (overlay instanceof HTMLElement && overlay.classList.contains('active')) return;

  const snapshot = currentEligibilitySnapshot();
  if (!accountLinkOnboardingEligibility(snapshot)) return;

  onboardingVisible = true;
  renderOnboardingCard({ preview:false });
}

function renderOnboardingCard({ preview = false } = {}){
  openSheet(`
    <div class="mgw-account-link-onboarding" role="dialog" aria-labelledby="mgwAccountLinkOnboardingTitle">
      <button class="close mgw-account-link-onboarding-close" data-close-sheet type="button" aria-label="Закрыть">×</button>

      <div class="mgw-account-link-onboarding-brand">
        <span class="mgw-account-link-onboarding-eyebrow">ANDROID · ЕДИНЫЙ ПРОФИЛЬ</span>
        <span class="mgw-account-link-onboarding-mark" aria-hidden="true">
          <img src="./assets/icons/shield-king/mgw-mark.svg" alt="">
        </span>
      </div>

      <div class="mgw-account-link-onboarding-copy">
        <h2 id="mgwAccountLinkOnboardingTitle">Уже играете в MINI GAMES WORLD в Telegram?</h2>
        <p>Подключите Telegram и продолжайте в Android с тем же игровым профилем.</p>
      </div>

      <div class="mgw-account-link-onboarding-bridge" aria-label="Один профиль в Telegram и Android">
        <span>Telegram</span>
        <i aria-hidden="true">→</i>
        <span>Android</span>
      </div>

      <div class="mgw-account-link-onboarding-benefits">
        <div><b>✓</b><span>Баланс и покупки</span></div>
        <div><b>✓</b><span>Статистика и рейтинг</span></div>
        <div><b>✓</b><span>Друзья и прогресс</span></div>
      </div>

      <p class="mgw-account-link-onboarding-note">
        После привязки Android использует ваш существующий Telegram-профиль. Временные стартовые коины Android к нему не добавляются.
      </p>

      <div class="mgw-account-link-onboarding-actions">
        <button class="btn primary full" type="button" data-account-link-onboarding-connect>Привязать Telegram</button>
        <button class="btn ghost full" type="button" data-account-link-onboarding-later>Позже</button>
      </div>
    </div>
  `);
  applyOnboardingPresentation();

  document.querySelector('[data-account-link-onboarding-connect]')?.addEventListener('click', event => {
    event.preventDefault();
    if (preview) {
      previewVisible = false;
      clearOnboardingPresentation();
      closeSheet();
      return;
    }
    persistDismissal();
    onboardingVisible = false;
    clearOnboardingPresentation();
    closeSheet();
    queueMicrotask(() => { void openAccountLinkSheet(); });
  }, { once:true });

  document.querySelector('[data-account-link-onboarding-later]')?.addEventListener('click', event => {
    event.preventDefault();
    if (preview) {
      previewVisible = false;
      clearOnboardingPresentation();
      closeSheet();
      return;
    }
    persistDismissal();
    onboardingVisible = false;
    clearOnboardingPresentation();
    closeSheet();
  }, { once:true });
}

function applyOnboardingPresentation(){
  document.getElementById('sheetOverlay')?.classList.add(ONBOARDING_OVERLAY_CLASS);
  document.getElementById('sheet')?.classList.add(ONBOARDING_SHEET_CLASS);
}

function clearOnboardingPresentation(){
  document.getElementById('sheetOverlay')?.classList.remove(ONBOARDING_OVERLAY_CLASS);
  document.getElementById('sheet')?.classList.remove(ONBOARDING_SHEET_CLASS);
}

function currentEligibilitySnapshot(){
  return {
    provider:state.profileAuth?.provider || state.user?.mgw_identity_provider || '',
    mgwId:state.mgwProfile?.mgw_id || state.user?.mgw_id || '',
    identities:Array.isArray(state.mgwProfile?.identities) ? state.mgwProfile.identities : [],
    dismissedMgwId:loadDismissedMgwId(),
    hasPending:hasPendingLink(),
  };
}

function hasTelegramIdentity(identities){
  return (Array.isArray(identities) ? identities : [])
    .some(identity => String(identity?.provider || '').trim().toLowerCase() === 'telegram');
}

function isStagingPreviewContext(){
  return String(globalThis.location?.hostname || '').trim().toLowerCase() === STAGING_PREVIEW_HOST;
}

function persistDismissal(){
  const mgwId = String(state.mgwProfile?.mgw_id || state.user?.mgw_id || '').trim();
  if (!mgwId) return;
  try {
    localStorage.setItem(DISMISS_KEY, JSON.stringify({
      mgw_id:mgwId,
      dismissed_at:Date.now(),
    }));
  } catch (_) {}
}

function loadDismissedMgwId(){
  try {
    const parsed = JSON.parse(localStorage.getItem(DISMISS_KEY) || 'null');
    return String(parsed?.mgw_id || '').trim();
  } catch (_) {
    return '';
  }
}

function hasPendingLink(){
  try {
    const parsed = JSON.parse(localStorage.getItem(PENDING_LINK_KEY) || 'null');
    return /^lnk_[a-f0-9]{20}$/.test(String(parsed?.challenge_id || '').trim());
  } catch (_) {
    return false;
  }
}
