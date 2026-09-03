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
const DEFAULT_AVATAR_ITEM_ID = 'starter-default-01';

function initStoreAvatarSelection(){
  document.addEventListener('mgw:app-ready', () => {
    void startStoreAvatarSelection();
  }, { once:true });
}

async function startStoreAvatarSelection(){
  if (storeAvatarStarted) return;
  storeAvatarStarted = true;
  try {
    const [apiModule, stateModule, toastModule, uiModule, telegramModule, profileModelModule, sheetModule] = await Promise.all([
      import('./api/client.js?v=47'),
      import('./state.js?v=27'),
      import('./components/toast.js?v=27'),
      import('./ui.js?v=89'),
      import('./telegram/telegram-app.js?v=27'),
      import('./profile/mgw-profile-model.js?v=1'),
      import('./components/sheet.js?v=68'),
    ]);
    storeAvatarRuntime = {
      api:apiModule.api,
      state:stateModule.state,
      toast:toastModule.toast,
      renderUser:uiModule.renderUser,
      haptic:telegramModule.haptic,
      mergeCanonicalMgwUser:profileModelModule.mergeCanonicalMgwUser,
      closeSheet:sheetModule.closeSheet,
    };
  } catch (_) {
    storeAvatarStarted = false;
    return;
  }

  storeAvatarObserver?.disconnect();
  storeAvatarObserver = new MutationObserver(scheduleStoreAvatarDecoration);
  observeStoreAvatarRoots();
  document.addEventListener('click', handleStoreAvatarSelection, true);
  document.addEventListener('mgw:screen-changed', scheduleStoreAvatarDecoration);
  decorateStoreAvatarCards();
}

function observeStoreAvatarRoots(){
  if (!storeAvatarObserver) return;
  storeAvatarObserver.disconnect();
  [document.getElementById('storeTabSurface'), document.getElementById('sheet')].forEach(root => {
    if (root) storeAvatarObserver.observe(root, { childList:true, subtree:true });
  });
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

  // This function mutates Store/sheet subtrees watched by the same observer.
  // Pause observation while applying our own decoration so those mutations can
  // never feed back into an endless observer -> microtask -> DOM loop.
  storeAvatarObserver?.disconnect();
  try {
    // Real Store avatars live only in the top-level avatar grid. Frame previews
    // intentionally reuse starter-default-01 as demo artwork and must never be
    // mistaken for selectable avatar products.
    document.querySelectorAll('.store-v2-content[data-store-v2-panel="profile"] > .store-v2-product-grid > .store-v2-product.owned .store-v2-avatar-preview[data-avatar-item-id]').forEach(preview => {
      if (!(preview instanceof HTMLElement)) return;
      const card = preview.closest('.store-v2-product');
      const foot = card?.querySelector(':scope > .store-v2-product-foot');
      const itemId = String(preview.dataset.avatarItemId || '').trim();
      if (!(card instanceof HTMLElement) || !(foot instanceof HTMLElement) || !itemId) return;

      const selectedItemId = String(state.selectedAvatarId || '').trim();
      const active = selectedItemId ? selectedItemId === itemId : card.classList.contains('equipped');
      const removable = active && itemId !== DEFAULT_AVATAR_ITEM_ID;
      card.classList.toggle('equipped', active);

      const boughtLabel = foot.querySelector(':scope > b');
      if (boughtLabel instanceof HTMLElement) {
        boughtLabel.hidden = false;
        const nextStatus = active ? 'Выбрано' : 'В коллекции';
        if (boughtLabel.textContent !== nextStatus) boughtLabel.textContent = nextStatus;
      }

      let action = foot.querySelector(':scope > [data-mgw-store-avatar-select]');
      if (!(action instanceof HTMLButtonElement)) {
        action = document.createElement('button');
        action.type = 'button';
        foot.append(action);
      }
      action.dataset.mgwStoreAvatarSelect = itemId;
      const nextClassName = `store-v2-equip store-v2-avatar-select${active ? ' active' : ''}`;
      if (action.className !== nextClassName) action.className = nextClassName;
      const nextLabel = active ? (removable ? 'Снять' : 'Выбрана') : 'Выбрать';
      if (action.textContent !== nextLabel) action.textContent = nextLabel;
      action.disabled = active && !removable;
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

    decorateStoreFrameCards();
    decorateProfileAvatarSheetAction();
    decorateStoreProfileCosmeticCardParity();
    decorateProfileCosmeticSheetState();
  } finally {
    observeStoreAvatarRoots();
  }
}

function decorateStoreFrameCards(){
  document.querySelectorAll('[data-profile-frame-store-section] .store-v2-product').forEach(card => {
    if (!(card instanceof HTMLElement)) return;
    card.classList.add('store-v2-profile-frame-card');
    const foot = card.querySelector(':scope > .store-v2-product-foot');
    if (!(foot instanceof HTMLElement) || !card.classList.contains('owned')) return;

    let status = foot.querySelector(':scope > [data-mgw-frame-card-status]');
    if (!(status instanceof HTMLElement)) {
      status = document.createElement('b');
      status.dataset.mgwFrameCardStatus = '1';
      foot.prepend(status);
    }
    const nextStatus = card.classList.contains('equipped') ? 'Выбрано' : 'В коллекции';
    if (status.textContent !== nextStatus) status.textContent = nextStatus;
  });
}

// Presentation-only parity layer for the four completed Profile cosmetic
// families. It never buys, equips or mutates inventory; each existing owner
// keeps its action handlers. This layer only gives every Store card the same
// visible state language and stable footer geometry hooks.
function decorateStoreProfileCosmeticCardParity(){
  const specs = [
    {
      selector:'.store-v2-content[data-store-v2-panel="profile"] > .store-v2-product-grid > .store-v2-product',
      foot:':scope > .store-v2-product-foot',
    },
    {
      selector:'.store-v2-content[data-store-v2-panel="profile"] .store-v2-name-color-card',
      foot:':scope > .store-v2-name-color-foot',
    },
    {
      selector:'[data-profile-badge-store-section] .store-v2-profile-badge-card',
      foot:':scope > .store-v2-profile-badge-foot',
    },
    {
      selector:'[data-profile-frame-store-section] .store-v2-product',
      foot:':scope > .store-v2-product-foot',
    },
  ];

  specs.forEach(spec => {
    document.querySelectorAll(spec.selector).forEach(card => {
      if (!(card instanceof HTMLElement)) return;
      const foot = card.querySelector(spec.foot);
      if (!(foot instanceof HTMLElement)) return;

      const owned = card.classList.contains('owned');
      const selected = owned && card.classList.contains('equipped');
      card.classList.add('mgw-profile-cosmetic-card');
      card.dataset.mgwProfileCosmeticState = owned ? (selected ? 'selected' : 'owned') : 'available';
      foot.classList.add('mgw-profile-cosmetic-foot');

      foot.querySelectorAll(':scope > button').forEach(button => {
        if (button instanceof HTMLButtonElement) button.classList.add('mgw-profile-cosmetic-action');
      });

      if (!owned) {
        foot.querySelector(':scope > [data-mgw-profile-cosmetic-status]')?.remove();
        return;
      }

      let status = foot.querySelector(':scope > [data-mgw-profile-cosmetic-status]');
      if (!(status instanceof HTMLElement)) {
        status = foot.querySelector(':scope > [data-mgw-frame-card-status], :scope > b');
      }
      if (!(status instanceof HTMLElement)) {
        status = document.createElement('b');
        foot.prepend(status);
      }
      status.dataset.mgwProfileCosmeticStatus = '1';
      status.hidden = false;
      const nextStatus = selected ? 'Выбрано' : 'В коллекции';
      if (status.textContent !== nextStatus) status.textContent = nextStatus;
    });
  });
}

function decorateProfileCosmeticSheetState(){
  const sheet = document.getElementById('sheet');
  if (!(sheet instanceof HTMLElement)) return;
  const action = sheet.querySelector('#mgwAvatarEquip, #mgwNameColorEquip, #mgwProfileBadgeEquip, #mgwProfileFrameEquip');
  const existing = sheet.querySelector('[data-mgw-profile-cosmetic-sheet-status]');
  if (!(action instanceof HTMLButtonElement)) {
    existing?.remove();
    return;
  }

  const actionLabel = String(action.textContent || '').replace(/\s+/gu, ' ').trim();
  const selected = actionLabel === 'Снять' || actionLabel === 'Выбрана' || actionLabel === 'Выбрано';
  let status = existing;
  if (!(status instanceof HTMLElement)) {
    status = document.createElement('div');
    status.dataset.mgwProfileCosmeticSheetStatus = '1';
    status.className = 'mgw-profile-cosmetic-sheet-status';
    action.insertAdjacentElement('beforebegin', status);
  }
  const nextStatus = selected ? 'Выбрано' : 'В коллекции';
  if (status.textContent !== nextStatus) status.textContent = nextStatus;
  action.classList.add('mgw-profile-cosmetic-sheet-action');
}

function decorateProfileAvatarSheetAction(){
  const runtime = storeAvatarRuntime;
  if (!runtime) return;
  const preview = document.querySelector('#sheet .profile-v2-avatar-preview[data-avatar-item-id]');
  const action = document.querySelector('#sheet #mgwAvatarEquip');
  if (!(preview instanceof HTMLElement) || !(action instanceof HTMLButtonElement)) return;

  const itemId = String(preview.dataset.avatarItemId || '').trim();
  const active = itemId !== '' && itemId === String(runtime.state.selectedAvatarId || '').trim();
  const removable = active && itemId !== DEFAULT_AVATAR_ITEM_ID;
  if (removable) {
    action.dataset.mgwStoreAvatarSelect = itemId;
    action.disabled = false;
    if (action.textContent !== 'Снять') action.textContent = 'Снять';
    action.classList.remove('primary');
    action.classList.add('ghost');
  } else {
    delete action.dataset.mgwStoreAvatarSelect;
  }
}

function handleStoreAvatarSelection(event){
  const runtime = storeAvatarRuntime;
  if (!runtime) return;
  const action = event.target instanceof Element ? event.target.closest('[data-mgw-store-avatar-select]') : null;
  if (!(action instanceof HTMLButtonElement)) return;
  event.preventDefault();
  event.stopPropagation();

  const itemId = String(action.dataset.mgwStoreAvatarSelect || '').trim();
  const selectedItemId = String(runtime.state.selectedAvatarId || '').trim();
  const remove = itemId !== '' && itemId === selectedItemId && itemId !== DEFAULT_AVATAR_ITEM_ID;
  const nextItemId = remove ? DEFAULT_AVATAR_ITEM_ID : itemId;
  if (!nextItemId || action.disabled || storeAvatarSaving || (!remove && itemId === selectedItemId)) return;

  const fromProfileSheet = action.id === 'mgwAvatarEquip' && action.closest('#sheet');
  if (!fromProfileSheet && !(action.closest('.store-v2-product.owned') instanceof HTMLElement)) return;
  void selectOwnedStoreAvatar(nextItemId, { removed:remove, closePreview:Boolean(fromProfileSheet) });
}

async function selectOwnedStoreAvatar(itemId, { removed = false, closePreview = false } = {}){
  const runtime = storeAvatarRuntime;
  if (!runtime || storeAvatarSaving) return;
  const { api, state, toast, renderUser, haptic, mergeCanonicalMgwUser, closeSheet } = runtime;
  const previousSelectedAvatarId = state.selectedAvatarId;
  storeAvatarSaving = true;
  state.selectedAvatarId = itemId;
  decorateStoreAvatarCards();
  if (state.user) renderUser(state.user);
  haptic('light');
  if (closePreview) closeSheet();

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
    toast(removed ? 'Аватарка снята.' : 'Аватарка выбрана.');
  } catch (error) {
    state.selectedAvatarId = previousSelectedAvatarId;
    decorateStoreAvatarCards();
    if (state.user) renderUser(state.user);
    haptic('error');
    toast(error?.message || (removed ? 'Не удалось снять аватарку.' : 'Не удалось выбрать аватарку.'));
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