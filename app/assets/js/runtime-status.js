import { t } from '@mgw/i18n';
import { state } from './state.js?v=27';
import { toast } from './components/toast.js?v=41';

const HEALTH_URL = `${window.location.origin}/bot/health.php`;
const REFRESH_MS = 30000;
const runtimeUi = window.__MGW_RUNTIME_STATUS__ ||= {
  initialized:false,
  timer:null,
};

export function initRuntimeStatus(){
  if (runtimeUi.initialized) return;
  runtimeUi.initialized = true;
  installStyles();
  document.addEventListener('click', interceptBlockedAction, true);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') refreshRuntimeStatus();
  });
  refreshRuntimeStatus();
  runtimeUi.timer = window.setInterval(refreshRuntimeStatus, REFRESH_MS);
}

export async function refreshRuntimeStatus(){
  try {
    const response = await fetch(HEALTH_URL, {
      method:'GET',
      cache:'no-store',
      headers:{'Accept':'application/json'},
    });
    const data = await response.json().catch(() => null);
    if (!data || !data.runtime) return;
    applyRuntimeStatus(data.runtime);
  } catch (error) {
    // Health is an extra safety signal. API guards remain authoritative.
  }
}

export function applyRuntimeStatus(runtime){
  state.runtime = runtime || null;
  renderBanner();
  renderGameAvailability();
  document.dispatchEvent(new CustomEvent('mgw:runtime-updated', { detail:state.runtime }));
}

function interceptBlockedAction(event){
  const target = event.target.closest('button, [role="button"]');
  if (!target || target.matches('[data-game-rules], [data-game-rules-current]')) return;

  const card = target.closest('[data-game-card]');
  if (card) {
    const gameType = String(card.dataset.gameCard || '');
    const reason = target.matches('[data-invite-friend]')
      ? invitationBlockReason(gameType)
      : newMatchBlockReason(gameType);
    if (reason) return stop(event, reason);
  }

  if (target.matches('#topUpMatch, #topUpGold, #topupContinue')) {
    const reason = paymentBlockReason();
    if (reason) return stop(event, reason);
  }

  if (target.matches('#storeOpen, [data-store-order], [data-shop-order]')) {
    const reason = shopBlockReason();
    if (reason) return stop(event, reason);
  }
}

function stop(event, reason){
  event.preventDefault();
  event.stopImmediatePropagation();
  toast(reason);
}

function newMatchBlockReason(gameType){
  const runtime = state.runtime;
  if (!runtime) return '';
  if (runtime.maintenance?.enabled) return runtime.maintenance.message || t('runtime_status.maintenance_default');
  if (runtime.features?.matchmaking === false) return t('runtime_status.matchmaking_disabled');
  if (runtime.financial_read_only) return t('runtime_status.new_matches_read_only');
  if (gameType && runtime.games?.[gameType] === false) return t('runtime_status.game_unavailable');
  return '';
}

function invitationBlockReason(gameType){
  const runtime = state.runtime;
  if (runtime?.features?.invitations === false) return t('runtime_status.invitations_disabled');
  return newMatchBlockReason(gameType);
}

function paymentBlockReason(){
  const runtime = state.runtime;
  if (!runtime) return '';
  if (runtime.maintenance?.enabled) return runtime.maintenance.message || t('runtime_status.maintenance_default');
  if (runtime.financial_read_only) return t('runtime_status.finance_read_only');
  if (runtime.features?.payments === false) return t('runtime_status.payments_disabled');
  return '';
}

function shopBlockReason(){
  const runtime = state.runtime;
  if (!runtime) return '';
  if (runtime.maintenance?.enabled) return runtime.maintenance.message || t('runtime_status.maintenance_default');
  if (runtime.financial_read_only) return t('runtime_status.finance_read_only');
  if (runtime.features?.shop === false) return t('runtime_status.shop_disabled');
  return '';
}

function renderBanner(){
  const content = document.querySelector('#screen-home .content');
  if (!content) return;

  let banner = document.getElementById('runtimeStatusBanner');
  if (!banner) {
    banner = document.createElement('section');
    banner.id = 'runtimeStatusBanner';
    banner.className = 'runtime-status-banner';
    banner.setAttribute('role', 'status');
    const topbar = content.querySelector('.topbar');
    if (topbar?.nextSibling) content.insertBefore(banner, topbar.nextSibling);
    else content.prepend(banner);
  }

  const runtime = state.runtime;
  let message = '';
  let title = '';
  if (runtime?.maintenance?.enabled) {
    title = t('runtime_status.banner.maintenance_title');
    message = runtime.maintenance.message || t('runtime_status.banner.maintenance_message');
  } else if (runtime?.financial_read_only) {
    title = t('runtime_status.banner.restriction_title');
    message = t('runtime_status.banner.restriction_message');
  } else if (hasPartialRestriction(runtime)) {
    title = t('runtime_status.banner.partial_title');
    message = t('runtime_status.banner.partial_message');
  }

  banner.hidden = message === '';
  banner.replaceChildren();
  if (message === '') return;

  const strong = document.createElement('strong');
  strong.textContent = title;
  const span = document.createElement('span');
  span.textContent = message;
  banner.append(strong, span);
}

function renderGameAvailability(){
  const runtime = state.runtime;
  document.querySelectorAll('[data-game-card]').forEach(card => {
    const gameType = String(card.dataset.gameCard || '');
    const reason = newMatchBlockReason(gameType);
    card.classList.toggle('runtime-unavailable', Boolean(reason));
    card.querySelectorAll('button:not([data-game-rules])').forEach(button => {
      button.setAttribute('aria-disabled', reason ? 'true' : 'false');
      if (reason) button.dataset.runtimeReason = reason;
      else delete button.dataset.runtimeReason;
    });
  });
}

function hasPartialRestriction(runtime){
  if (!runtime) return false;
  if (runtime.features?.matchmaking === false || runtime.features?.invitations === false) return true;
  if (runtime.features?.payments === false || runtime.features?.shop === false) return true;
  return Object.values(runtime.games || {}).some(value => value === false);
}

function installStyles(){
  if (document.getElementById('runtimeStatusStyles')) return;
  const style = document.createElement('style');
  style.id = 'runtimeStatusStyles';
  style.textContent = `
    .runtime-status-banner{margin:12px 0 16px;padding:13px 14px;border:1px solid rgba(255,194,92,.32);border-radius:14px;background:rgba(255,174,66,.10);display:grid;gap:4px}
    .runtime-status-banner[hidden]{display:none}
    .runtime-status-banner strong{font-size:14px;color:#ffd493}
    .runtime-status-banner span{font-size:12px;line-height:1.45;color:rgba(255,255,255,.78)}
    .game-card.runtime-unavailable{opacity:.62}
    .game-card.runtime-unavailable button[aria-disabled="true"]{cursor:not-allowed;filter:saturate(.4)}
  `;
  document.head.appendChild(style);
}
