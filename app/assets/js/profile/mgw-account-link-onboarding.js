import { state } from '../state.js?v=27';
import { currentScreen } from '../router.js?v=27';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { openAccountLinkSheet } from './mgw-account-link-ui.js?v=2';

const DISMISS_KEY = 'mgw_android_account_link_onboarding_v1';
const PENDING_LINK_KEY = 'mgw_android_account_link_v1';

let initialized = false;
let appReady = false;
let onboardingVisible = false;
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
  if (identities.some(identity => String(identity?.provider || '').trim().toLowerCase() === 'telegram')) {
    return false;
  }
  return dismissedMgwId !== mgwId;
}

function scheduleAttempt(delay){
  if (attemptTimer !== null) window.clearTimeout(attemptTimer);
  attemptTimer = window.setTimeout(() => {
    attemptTimer = null;
    tryShowOnboarding();
  }, Math.max(0, Number(delay) || 0));
}

function tryShowOnboarding(){
  if (!appReady || onboardingVisible) return;

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
  openSheet(`
    <div class="sheet-head">
      <div>
        <h2>Уже играете в MINI GAMES WORLD в Telegram?</h2>
        <p>Привяжите аккаунт и используйте в Android тот же профиль.</p>
      </div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="profile-v2-account-card">
      <div class="profile-v2-setting-row">
        <span>
          <strong>Всё важное останется с вами</strong>
          <small>Баланс, покупки, статистика, рейтинг, друзья и прогресс будут взяты из вашего существующего Telegram-профиля.</small>
        </span>
      </div>
    </div>
    <div class="btn-row">
      <button class="btn primary full" type="button" data-account-link-onboarding-connect>Привязать Telegram</button>
      <button class="btn ghost full" type="button" data-account-link-onboarding-later>Позже</button>
    </div>
  `);

  document.querySelector('[data-account-link-onboarding-connect]')?.addEventListener('click', event => {
    event.preventDefault();
    persistDismissal();
    onboardingVisible = false;
    closeSheet();
    queueMicrotask(() => { void openAccountLinkSheet(); });
  }, { once:true });

  document.querySelector('[data-account-link-onboarding-later]')?.addEventListener('click', event => {
    event.preventDefault();
    persistDismissal();
    onboardingVisible = false;
    closeSheet();
  }, { once:true });
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
