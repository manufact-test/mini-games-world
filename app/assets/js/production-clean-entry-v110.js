import { initSessionOwnershipFix } from './production-session-ownership-fix.js?v=96';
import { initV99SessionTransport } from './production-v99-session-transport.js?v=99';
import { initV99ExplicitLockGuard } from './production-v99-explicit-lock-guard.js?v=99';
import { initV100SearchEventBridge } from './production-v100-search-event-bridge.js?v=100';
import { initV101PollTuning } from './production-v101-poll-tuning.js?v=101';
import { initV101SpeedRuntime } from './production-v101-speed-runtime-v102.js?v=102&b=786d11d53360';
import { initV101InviteSyncDedupe } from './production-v101-invite-sync-dedupe.js?v=101';
import { initV101CacheSafety } from './production-v101-cache-safety.js?v=101';
import { initV102BattleshipBridge } from './production-v102-battleship-bridge.js?v=102';
import { initV104GamePollTuning } from './production-v104-game-poll-tuning.js?v=104';
import { initV109SearchSpeed } from './production-v109-search-speed.js?v=109';
import { initV110AcceptanceRuntime } from './production-v110-acceptance-runtime.js?v=110';
import { initV110MatchLifecycle } from './production-v110-match-lifecycle.js?v=1106&release=battleship-action-quarantine';
import { initV110TargetedInteractions } from './production-v110-targeted-interactions.js?v=1102';
import { initDeterministicGameIcons } from './production-deterministic-icons.js?v=97&sk=6';
import { initMgwAvatarPresentation } from './profile/mgw-avatar-presentation.js?v=6&mvp19_4=illustrated-raster-roster-v5&mvp19_3=name-colors';
import { initMgwProfileBadges } from './profile/mgw-profile-badges.js?v=5&mvp19_3=profile-badge-avatar-shape';
import { initMgwProfileFrames } from './profile/mgw-profile-frames.js?v=4&mvp19_3=profile-frame-avatar-card-parity';
import { initMgwProfileBackgrounds } from './profile/mgw-profile-backgrounds.js?v=2&mvp19_3=profile-backgrounds-ux-corrective';

window.__MGW_REGRESSION_BUILD__ = 'v110-mvp14-interface-invite-speed-v1135';

// The canonical v110 invitation module owns setup, player selection, link
// creation, sharing and invite actions. Historical share/picker layers must not
// capture the same controls or create speculative drafts in the background.
initV110AcceptanceRuntime();
initV110MatchLifecycle();
initV109SearchSpeed();

initSessionOwnershipFix();
initV99SessionTransport();
initV99ExplicitLockGuard();

initV101PollTuning();
initV104GamePollTuning();
initV101SpeedRuntime();
initV101InviteSyncDedupe();
initV101CacheSafety();
initV102BattleshipBridge();
initV110TargetedInteractions();

initV100SearchEventBridge();
initDeterministicGameIcons();
initMgwAvatarPresentation();
initMgwProfileBadges();
initMgwProfileFrames();
initStoreAvatarSelection();

// Store owns discovery/purchase, while Profile remains the canonical avatar
// selection owner. Keep this integration completely outside the critical
// pre-bootstrap module graph: app-bootstrap-v2 awaits clean-entry before loading
// main, and main owns api.bootstrap(). Dependencies are therefore resolved only
// after the authoritative app-ready signal has already been dispatched.
let storeAvatarSaving = false;
let storeAvatarObserver = null;
let storeAvatarDecorateScheduled = false;
let storeAvatarRuntime = null;
let storeAvatarStarted = false;

function initStoreAvatarSelection(){
  document.addEventListener('mgw:app-ready', () => {
    void startStoreAvatarSelection();
  }, { once:true });
}

async function startStoreAvatarSelection(){
  if (storeAvatarStarted) return;
  storeAvatarStarted = true;
  try {
    const [apiModule, stateModule, toastModule, uiModule, telegramModule, profileModelModule] = await Promise.all([
      import('./api/client.js?v=47'),
      import('./state.js?v=27'),
      import('./components/toast.js?v=27'),
      import('./ui.js?v=89'),
      import('./telegram/telegram-app.js?v=27'),
      import('./profile/mgw-profile-model.js?v=1'),
    ]);
    storeAvatarRuntime = {
      api:apiModule.api,
      state:stateModule.state,
      toast:toastModule.toast,
      renderUser:uiModule.renderUser,
      haptic:telegramModule.haptic,
      mergeCanonicalMgwUser:profileModelModule.mergeCanonicalMgwUser,
    };
  } catch (_) {
    storeAvatarStarted = false;
    return;
  }

  storeAvatarObserver?.disconnect();
  storeAvatarObserver = new MutationObserver(scheduleStoreAvatarDecoration);
  [document.getElementById('storeTabSurface'), document.getElementById('sheet')].forEach(root => {
    if (root) storeAvatarObserver.observe(root, { childList:true, subtree:true });
  });
  document.addEventListener('click', handleStoreAvatarSelection, true);
  document.addEventListener('mgw:screen-changed', scheduleStoreAvatarDecoration);
  decorateStoreAvatarCards();
}

function scheduleStoreAvatarDecoration(){
  if (!storeAvatarRuntime || storeAvatarDecorateScheduled) return;
  storeAvatarDecorateScheduled = true;
  queueMicrotask(() => {
    storeAvatarDecorateScheduled = false;
    decorateStoreAvatarCards();
  });
}

function decorateStoreAvatarCards(){
  const state = storeAvatarRuntime?.state;
  if (!state) return;
  document.querySelectorAll('.store-v2-product.owned .store-v2-avatar-preview[data-avatar-item-id]').forEach(preview => {
    if (!(preview instanceof HTMLElement)) return;
    const card = preview.closest('.store-v2-product');
    const foot = card?.querySelector(':scope > .store-v2-product-foot');
    const itemId = String(preview.dataset.avatarItemId || '').trim();
    if (!(card instanceof HTMLElement) || !(foot instanceof HTMLElement) || !itemId) return;

    const selectedItemId = String(state.selectedAvatarId || '').trim();
    const active = selectedItemId ? selectedItemId === itemId : card.classList.contains('equipped');
    card.classList.toggle('equipped', active);

    const boughtLabel = foot.querySelector(':scope > b');
    if (boughtLabel instanceof HTMLElement) boughtLabel.hidden = true;

    let action = foot.querySelector(':scope > [data-mgw-store-avatar-select]');
    if (!(action instanceof HTMLButtonElement)) {
      action = document.createElement('button');
      action.type = 'button';
      foot.append(action);
    }
    action.dataset.mgwStoreAvatarSelect = itemId;
    action.className = `store-v2-equip store-v2-avatar-select${active ? ' active' : ''}`;
    action.textContent = active ? 'Выбрана' : 'Выбрать';
    action.disabled = active;
    action.setAttribute('aria-pressed', active ? 'true' : 'false');

    const existingCheck = preview.querySelector(':scope > .store-v2-selected-check');
    if (active && !(existingCheck instanceof HTMLElement)) {
      const check = document.createElement('i');
      check.className = 'store-v2-selected-check';
      check.setAttribute('aria-label', 'Выбрана');
      check.textContent = '✓';
      preview.append(check);
    } else if (!active && existingCheck instanceof HTMLElement) {
      existingCheck.remove();
    }
  });
}

function handleStoreAvatarSelection(event){
  const runtime = storeAvatarRuntime;
  if (!runtime) return;
  const action = event.target instanceof Element ? event.target.closest('[data-mgw-store-avatar-select]') : null;
  if (!(action instanceof HTMLButtonElement)) return;
  event.preventDefault();
  event.stopPropagation();
  const itemId = String(action.dataset.mgwStoreAvatarSelect || '').trim();
  if (!itemId || action.disabled || storeAvatarSaving || itemId === String(runtime.state.selectedAvatarId || '')) return;
  if (!(action.closest('.store-v2-product.owned') instanceof HTMLElement)) return;
  void selectOwnedStoreAvatar(itemId);
}

async function selectOwnedStoreAvatar(itemId){
  const runtime = storeAvatarRuntime;
  if (!runtime || storeAvatarSaving) return;
  const { api, state, toast, renderUser, haptic, mergeCanonicalMgwUser } = runtime;
  const previousSelectedAvatarId = state.selectedAvatarId;
  storeAvatarSaving = true;
  state.selectedAvatarId = itemId;
  decorateStoreAvatarCards();
  if (state.user) renderUser(state.user);
  haptic('light');

  try {
    const result = await api.profileV2({ avatar_item_id:itemId });
    const confirmedItemId = String(result?.profile?.avatar?.item_id || result?.user?.avatar_item_id || '').trim();
    if (confirmedItemId !== itemId) throw new Error('Профиль не подтвердил выбранную аватарку.');
    state.mgwProfile = result?.profile || state.mgwProfile;
    state.profileInventory = result?.inventory || state.profileInventory;
    state.user = mergeCanonicalMgwUser(state.user, result?.user || {}, state.mgwProfile);
    state.selectedAvatarId = confirmedItemId;
    decorateStoreAvatarCards();
    if (state.user) renderUser(state.user);
    haptic('success');
    toast('Аватарка выбрана.');
  } catch (error) {
    state.selectedAvatarId = previousSelectedAvatarId;
    decorateStoreAvatarCards();
    if (state.user) renderUser(state.user);
    haptic('error');
    toast(error?.message || 'Не удалось выбрать аватарку.');
  } finally {
    storeAvatarSaving = false;
  }
}

// Profile backgrounds are a Store/Profile-only surface. Do not initialize their
// fallback Profile snapshot reader during Home or active-game bootstrap: the
// two-player reload path is intentionally kept free of this optional read.
let profileBackgroundsInitialized = false;
function initMgwProfileBackgroundsOnDemand(){
  if (profileBackgroundsInitialized) return;
  profileBackgroundsInitialized = true;
  initMgwProfileBackgrounds();
}
function isProfileBackgroundSurface(screen){
  return screen === 'store' || screen === 'profile';
}
document.addEventListener('mgw:screen-changed', event => {
  const next = String(event?.detail?.to || '').trim();
  if (isProfileBackgroundSurface(next)) initMgwProfileBackgroundsOnDemand();
});
const activeScreen = String(document.querySelector('.screen.active')?.dataset.screen || '').trim();
if (isProfileBackgroundSurface(activeScreen)) initMgwProfileBackgroundsOnDemand();