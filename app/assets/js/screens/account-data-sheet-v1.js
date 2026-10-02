import { api } from '../api/client.js?v=1152&mvp22_8=account-data-v1&mvp26_4=android-reauth-v1&mvp26_4_2=android-download-v1';
import { openSheet } from '../components/sheet.js?v=1109';
import { toast } from '../components/toast.js?v=41';
import { t, formatDateTime as formatLocalizedDateTime, formatNumber as formatLocalizedNumber } from '@mgw/i18n';

const STYLE_URL = './assets/css/account-data-v1.css?v=5&mvp22_8=account-data-v1&ux=final-manual-polish-v2&mvp26_4_2=per-action-pending-v1';
const accountDataText = (key, params = {}) => t(`account_data.${key}`, params);

let snapshot = null;
let snapshotFetchedAt = 0;
let snapshotPromise = null;
let stylesReadyPromise = null;
let loading = false;
const pendingActions = new Set();
let androidReauthPromise = null;
let androidDownloadPromise = null;

const ACTION_CREATE_EXPORT = 'create_export';
const ACTION_DOWNLOAD_EXPORT = 'download_export';
const ACTION_CANCEL_DELETE = 'cancel_delete';
const ACTION_SCHEDULE_DELETE = 'schedule_delete';

const SNAPSHOT_TTL_MS = 15000;
const ANDROID_REAUTH_TIMEOUT_MS = 135000;
const STYLE_READY_TIMEOUT_MS = 900;

export async function primeAccountDataFirstOpen(){
  await Promise.all([
    ensureStylesReady(),
    loadSnapshot(false),
  ]);
  return snapshot;
}

export async function openAccountDataSheet(){
  let initialError = null;
  try {
    await primeAccountDataFirstOpen();
  } catch (error) {
    initialError = error;
  }

  // Do not expose the sheet until both the module/style and the first server
  // snapshot are ready. On slower Telegram WebViews the previous implementation
  // showed a real loading-state frame before the first response and
  // then replaced the whole body, which was visible as a blank/empty flash.
  openSheet(shellHtml());
  bind();

  if (snapshot) {
    render();
    if (Date.now() - snapshotFetchedAt > SNAPSHOT_TTL_MS) void refresh(true);
    return;
  }

  renderLoadError(initialError);
}

function ensureStylesReady(){
  let link = document.querySelector('link[data-mgw-account-data-style]');
  if (!(link instanceof HTMLLinkElement)) {
    link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = STYLE_URL;
    link.dataset.mgwAccountDataStyle = '1';
    document.head.append(link);
  }

  if (link.sheet) return Promise.resolve();
  if (stylesReadyPromise) return stylesReadyPromise;

  stylesReadyPromise = new Promise(resolve => {
    let settled = false;
    const done = () => {
      if (settled) return;
      settled = true;
      link.removeEventListener('load', done);
      link.removeEventListener('error', done);
      resolve();
    };
    link.addEventListener('load', done, { once:true });
    link.addEventListener('error', done, { once:true });
    window.setTimeout(done, STYLE_READY_TIMEOUT_MS);
  });
  return stylesReadyPromise;
}

function loadSnapshot(force = false){
  const fresh = snapshot && Date.now() - snapshotFetchedAt <= SNAPSHOT_TTL_MS;
  if (!force && fresh) return Promise.resolve(snapshot);
  if (snapshotPromise) return snapshotPromise;

  snapshotPromise = api.accountDataSnapshot()
    .then(response => {
      snapshot = normalizeSnapshot(response?.account_data);
      snapshotFetchedAt = Date.now();
      return snapshot;
    })
    .finally(() => {
      snapshotPromise = null;
    });

  return snapshotPromise;
}

async function refresh(force = false){
  if (loading) return;
  loading = true;
  if (snapshot) render();
  try {
    await loadSnapshot(force);
  } catch (error) {
    toast(error?.message || accountDataText('errors.load'));
  } finally {
    loading = false;
    render();
  }
}

function renderLoadError(error){
  const body = document.querySelector('[data-account-data-body]');
  if (!(body instanceof HTMLElement)) return;
  body.innerHTML = `
    <div class="account-data-v1-loading">
      ${escapeHtml(error?.message || accountDataText('errors.load'))}
    </div>
  `;
}

function normalizeSnapshot(value){
  const source = value && typeof value === 'object' ? value : {};
  return {
    deletion:source.deletion && typeof source.deletion === 'object' ? source.deletion : null,
    export:source.export && typeof source.export === 'object' ? source.export : null,
    policy:source.policy && typeof source.policy === 'object' ? source.policy : {},
  };
}

function shellHtml(){
  return `
    <div class="account-data-v1" data-account-data-root>
      <div class="account-data-v1-head">
        <h2>${escapeHtml(accountDataText('title'))}</h2>
        <button class="close account-data-v1-close" data-close-sheet type="button" aria-label="${escapeHtml(t('common.close'))}">×</button>
      </div>
      <div class="account-data-v1-body" data-account-data-body>
        <div class="account-data-v1-loading">${escapeHtml(accountDataText('loading'))}</div>
      </div>
    </div>
  `;
}

function render(){
  const body = document.querySelector('[data-account-data-body]');
  if (!(body instanceof HTMLElement)) return;
  if (loading && !snapshot) {
    body.innerHTML = `<div class="account-data-v1-loading">${escapeHtml(accountDataText('loading'))}</div>`;
    return;
  }

  const deletion = snapshot?.deletion || null;
  const exportState = snapshot?.export || null;
  const deletionScheduled = deletion?.status === 'scheduled';
  const exportReady = exportState?.status === 'ready' && exportState?.request_id;
  const exportBusy = exportState?.status === 'processing';
  const exportFailed = exportState?.status === 'failed';
  const exportExpired = exportState?.status === 'expired';
  const nextExportAt = exportNextAllowedAt(exportState, snapshot?.policy);
  const exportRateLimited = nextExportAt instanceof Date && nextExportAt.getTime() > Date.now();

  body.innerHTML = `
    <div class="account-data-v1-intro">
      <span class="account-data-v1-intro-icon" aria-hidden="true">i</span>
      <div>
        <strong>${escapeHtml(accountDataText('intro.title'))}</strong>
        <p>${escapeHtml(accountDataText('intro.text'))}</p>
      </div>
    </div>

    <section class="account-data-v1-card account-data-v1-card--export">
      <div class="account-data-v1-card-icon" aria-hidden="true">⇩</div>
      <div class="account-data-v1-card-copy">
        <h3>${escapeHtml(accountDataText('export.title'))}</h3>
        <p>${escapeHtml(accountDataText('export.text'))}</p>
        ${exportMeta(exportState)}
      </div>
      <div class="account-data-v1-actions">
        ${exportReady ? `
          <button class="btn primary account-data-v1-action" data-account-data-download type="button" ${pendingAttr(ACTION_DOWNLOAD_EXPORT)}>
            ${pendingButtonContent(ACTION_DOWNLOAD_EXPORT, accountDataText('export.download'), accountDataText('export.downloading'))}
          </button>
          ${exportRateLimited ? '' : `
            <button class="btn account-data-v1-secondary" data-account-data-create-export type="button" ${pendingAttr(ACTION_CREATE_EXPORT)}>
              ${pendingButtonContent(ACTION_CREATE_EXPORT, accountDataText('export.create_new'), accountDataText('export.creating'))}
            </button>
          `}
        ` : `
          <button class="btn primary account-data-v1-action" data-account-data-create-export type="button" ${pendingAttr(ACTION_CREATE_EXPORT, exportBusy)}>
            ${pendingButtonContent(ACTION_CREATE_EXPORT, accountDataText('export.create_archive'), exportBusy ? accountDataText('export.creating_archive') : accountDataText('export.creating'), exportBusy)}
          </button>
        `}
      </div>
      ${exportRateLimited && nextExportAt ? `<p class="account-data-v1-note">${escapeHtml(accountDataText('export.rate_limited_until', { date:formatDate(nextExportAt.toISOString()) }))}</p>` : ''}
      ${exportFailed ? `<p class="account-data-v1-note account-data-v1-note--error">${escapeHtml(accountDataText('export.failed_note'))}</p>` : ''}
      ${exportExpired ? `<p class="account-data-v1-note">${escapeHtml(accountDataText('export.expired_note'))}</p>` : ''}
    </section>

    <section class="account-data-v1-card account-data-v1-card--danger">
      <div class="account-data-v1-card-icon account-data-v1-card-icon--danger" aria-hidden="true">!</div>
      <div class="account-data-v1-card-copy">
        <h3>${escapeHtml(accountDataText('delete.title'))}</h3>
        ${deletionScheduled ? `
          <p>${escapeHtml(accountDataText('delete.scheduled_text'))}</p>
          <div class="account-data-v1-deletion-state">
            <span>${escapeHtml(accountDataText('delete.after'))}</span>
            <strong>${escapeHtml(formatDate(deletion.execute_after_utc))}</strong>
          </div>
        ` : `
          <p>${escapeHtml(accountDataText('delete.grace_text'))}</p>
        `}
      </div>
      <div class="account-data-v1-actions">
        ${deletionScheduled ? `
          <button class="btn account-data-v1-secondary" data-account-data-cancel-delete type="button" ${pendingAttr(ACTION_CANCEL_DELETE)}>
            ${pendingButtonContent(ACTION_CANCEL_DELETE, accountDataText('delete.cancel'), accountDataText('delete.cancelling'))}
          </button>
        ` : `
          <button class="btn account-data-v1-danger" data-account-data-confirm-delete type="button">
            ${escapeHtml(accountDataText('delete.action'))}
          </button>
        `}
      </div>
    </section>
  `;

  bind();
}

function exportMeta(item){
  if (!item) return `<div class="account-data-v1-meta">${escapeHtml(accountDataText('export.never'))}</div>`;
  if (item.status === 'ready') {
    const bytes = Number(item.artifact_size || 0);
    return `
      <div class="account-data-v1-meta account-data-v1-meta--success">
        <span>${escapeHtml(accountDataText('export.ready'))}${bytes > 0 ? ' · ' + escapeHtml(formatBytes(bytes)) : ''}</span>
        ${item.artifact_expires_at_utc ? `<small>${escapeHtml(accountDataText('export.stored_until', { date:formatDate(item.artifact_expires_at_utc) }))}</small>` : ''}
      </div>
    `;
  }
  if (item.status === 'processing') return `<div class="account-data-v1-meta">${escapeHtml(accountDataText('export.processing'))}</div>`;
  if (item.status === 'failed') return `<div class="account-data-v1-meta account-data-v1-meta--error">${escapeHtml(accountDataText('export.last_failed'))}</div>`;
  if (item.status === 'expired') return `<div class="account-data-v1-meta">${escapeHtml(accountDataText('export.last_expired'))}</div>`;
  return `<div class="account-data-v1-meta">${escapeHtml(accountDataText('export.last_request', { status:String(item.status || accountDataText('status.unknown')) }))}</div>`;
}

function isActionPending(action){
  return pendingActions.has(String(action || ''));
}

function beginAction(action){
  const key = String(action || '');
  if (!key || pendingActions.has(key)) return false;
  pendingActions.add(key);
  render();
  return true;
}

function finishAction(action){
  pendingActions.delete(String(action || ''));
  render();
}

function pendingAttr(action, forced = false){
  return isActionPending(action) || forced
    ? 'disabled aria-busy="true"'
    : '';
}

function pendingButtonContent(action, idleLabel, pendingLabel, forced = false){
  if (!isActionPending(action) && !forced) return escapeHtml(idleLabel);
  return '<span class="account-data-v1-spinner" aria-hidden="true"></span><span>' + escapeHtml(pendingLabel) + '</span>';
}

function isAndroidShell(){
  return /MiniGamesWorldAndroid\/\d+/.test(String(navigator.userAgent || ''));
}

function bind(){
  const root = document.querySelector('[data-account-data-root]');
  if (!(root instanceof HTMLElement) || root.dataset.bound === '1') return;
  root.dataset.bound = '1';

  root.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target.closest('button') : null;
    if (!(target instanceof HTMLButtonElement)) return;
    if (target.hasAttribute('data-account-data-create-export')) void createExport();
    if (target.hasAttribute('data-account-data-download')) void downloadExport();
    if (target.hasAttribute('data-account-data-cancel-delete')) void cancelDeletion();
    if (target.hasAttribute('data-account-data-confirm-delete')) openDeleteConfirmation();
    if (target.hasAttribute('data-account-data-schedule-delete')) void scheduleDeletion(target);
  });
}

function openDeleteConfirmation(){
  openSheet(`
    <div class="account-data-v1 account-data-v1-confirm">
      <div class="account-data-v1-head">
        <h2>${escapeHtml(accountDataText('confirm.title'))}</h2>
        <button class="close account-data-v1-close" data-close-sheet type="button" aria-label="${escapeHtml(t('common.close'))}">×</button>
      </div>

      <div class="account-data-v1-confirm-scroll">
        <div class="account-data-v1-confirm-warning">
          <strong>${escapeHtml(accountDataText('confirm.warning_title'))}</strong>
          <p>${escapeHtml(accountDataText('confirm.warning_text'))}</p>
        </div>

        <div class="account-data-v1-confirm-archive">
          <div>
            <strong>${escapeHtml(accountDataText('confirm.save_first_title'))}</strong>
            <span>${escapeHtml(accountDataText('confirm.save_first_text'))}</span>
          </div>
          <button class="btn account-data-v1-secondary" data-account-data-save-first type="button">${escapeHtml(accountDataText('confirm.save_first_action'))}</button>
        </div>

        <p class="account-data-v1-confirm-note">${escapeHtml(accountDataText('confirm.retention_note'))}</p>
      </div>

      <div class="account-data-v1-confirm-actions">
        <button class="btn" data-account-data-back type="button">${escapeHtml(accountDataText('confirm.keep'))}</button>
        <button class="btn account-data-v1-danger" data-account-data-schedule-delete type="button">${escapeHtml(accountDataText('confirm.schedule'))}</button>
      </div>
    </div>
  `, { returnToPrevious:true });

  const confirmRoot = document.querySelector('.account-data-v1-confirm');
  confirmRoot?.addEventListener('click', event => {
    const button = event.target instanceof Element ? event.target.closest('button') : null;
    if (!(button instanceof HTMLButtonElement)) return;
    if (button.hasAttribute('data-account-data-back')) {
      closeSheet();
      return;
    }
    if (button.hasAttribute('data-account-data-save-first')) {
      closeSheet();
      window.requestAnimationFrame(() => {
        document.querySelector('.account-data-v1-card--export')?.scrollIntoView({ block:'start', behavior:'smooth' });
      });
      return;
    }
    if (button.hasAttribute('data-account-data-schedule-delete')) void scheduleDeletion(button);
  });
}

async function withSensitiveReauth(operation){
  try {
    return await operation();
  } catch (error) {
    if (String(error?.code || '') !== 'android_reauth_required') throw error;
    await requestAndroidNativeReauth();
    // Exactly one retry. If the short-lived grant was not established, surface
    // the second server response instead of entering a prompt loop.
    return operation();
  }
}

function requestAndroidNativeReauth(){
  if (androidReauthPromise) return androidReauthPromise;

  androidReauthPromise = api.androidReauthCreate()
    .then(response => {
      const nativeUrl = String(response?.reauth?.native_url || '').trim();
      if (!/^mgw:\/\/android-reauth\?challenge=ar_[a-f0-9]{24}$/.test(nativeUrl)) {
        const error = new Error(accountDataText('errors.android_reauth_unavailable'));
        error.code = 'android_reauth_unavailable';
        throw error;
      }
      return waitForNativeReauth(nativeUrl);
    })
    .finally(() => {
      androidReauthPromise = null;
    });

  return androidReauthPromise;
}

function waitForNativeReauth(nativeUrl){
  return new Promise((resolve, reject) => {
    let settled = false;
    const finish = callback => {
      if (settled) return;
      settled = true;
      window.clearTimeout(timer);
      window.removeEventListener('mgw:android-reauth-success', onSuccess);
      window.removeEventListener('mgw:android-reauth-cancelled', onCancelled);
      window.removeEventListener('mgw:android-reauth-failed', onFailed);
      callback();
    };
    const onSuccess = () => finish(resolve);
    const onCancelled = () => finish(() => {
      const error = new Error(accountDataText('errors.reauth_cancelled'));
      error.code = 'android_reauth_cancelled';
      reject(error);
    });
    const onFailed = () => finish(() => {
      const error = new Error(accountDataText('errors.reauth_failed'));
      error.code = 'android_reauth_failed';
      reject(error);
    });
    const timer = window.setTimeout(() => finish(() => {
      const error = new Error(accountDataText('errors.reauth_timeout'));
      error.code = 'android_reauth_timeout';
      reject(error);
    }), ANDROID_REAUTH_TIMEOUT_MS);

    window.addEventListener('mgw:android-reauth-success', onSuccess, { once:true });
    window.addEventListener('mgw:android-reauth-cancelled', onCancelled, { once:true });
    window.addEventListener('mgw:android-reauth-failed', onFailed, { once:true });

    window.location.assign(nativeUrl);
  });
}

async function createExport(){
  if (!beginAction(ACTION_CREATE_EXPORT)) return;
  try {
    const response = await withSensitiveReauth(() => api.accountDataCreateExport());
    snapshot = normalizeSnapshot(response?.account_data);
    toast(accountDataText('toasts.archive_ready'));
  } catch (error) {
    toast(actionError(error, accountDataText('errors.create_export')));
  } finally {
    finishAction(ACTION_CREATE_EXPORT);
  }
}

async function downloadExport(){
  const requestId = String(snapshot?.export?.request_id || '');
  if (!requestId || !beginAction(ACTION_DOWNLOAD_EXPORT)) return;
  try {
    if (isAndroidShell()) {
      await beginAndroidDownload(requestId);
      toast(accountDataText('toasts.download_started'));
      return;
    }

    const result = await withSensitiveReauth(() => api.accountDataDownloadExport(requestId));
    const url = URL.createObjectURL(result.blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = String(result.filename || 'mini-games-world-data.zip');
    anchor.style.display = 'none';
    document.body.append(anchor);
    anchor.click();
    anchor.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  } catch (error) {
    toast(actionError(error, accountDataText('errors.download_export')));
  } finally {
    finishAction(ACTION_DOWNLOAD_EXPORT);
  }
}

async function beginAndroidDownload(requestId){
  const response = await withSensitiveReauth(() => api.accountDataAuthorizeDownload(requestId));
  const nativeUrl = String(response?.download?.native_url || '').trim();
  if (!/^mgw:\/\/android-account-download\?request=adr_[a-f0-9]{32}$/.test(nativeUrl)) {
    const error = new Error(accountDataText('errors.android_download_unavailable'));
    error.code = 'android_download_unavailable';
    throw error;
  }
  await waitForAndroidDownloadEnqueue(nativeUrl);
}

function waitForAndroidDownloadEnqueue(nativeUrl){
  if (androidDownloadPromise) return androidDownloadPromise;

  androidDownloadPromise = new Promise((resolve, reject) => {
    let settled = false;
    const finish = callback => {
      if (settled) return;
      settled = true;
      window.clearTimeout(timer);
      window.removeEventListener('mgw:android-download-enqueued', onSuccess);
      window.removeEventListener('mgw:android-download-failed', onFailed);
      callback();
    };
    const onSuccess = () => finish(resolve);
    const onFailed = () => finish(() => {
      const error = new Error(accountDataText('errors.android_download_failed'));
      error.code = 'android_download_failed';
      reject(error);
    });
    const timer = window.setTimeout(() => finish(() => {
      const error = new Error(accountDataText('errors.android_download_timeout'));
      error.code = 'android_download_timeout';
      reject(error);
    }), 15000);

    window.addEventListener('mgw:android-download-enqueued', onSuccess, { once:true });
    window.addEventListener('mgw:android-download-failed', onFailed, { once:true });
    window.location.assign(nativeUrl);
  }).finally(() => {
    androidDownloadPromise = null;
  });

  return androidDownloadPromise;
}

async function cancelDeletion(){
  if (!beginAction(ACTION_CANCEL_DELETE)) return;
  try {
    const response = await withSensitiveReauth(() => api.accountDataCancelDelete());
    snapshot = normalizeSnapshot(response?.account_data);
    toast(accountDataText('toasts.deletion_cancelled'));
  } catch (error) {
    toast(actionError(error, accountDataText('errors.cancel_delete')));
  } finally {
    finishAction(ACTION_CANCEL_DELETE);
  }
}

async function scheduleDeletion(triggerButton = null){
  if (isActionPending(ACTION_SCHEDULE_DELETE)) return;
  pendingActions.add(ACTION_SCHEDULE_DELETE);
  if (triggerButton instanceof HTMLButtonElement) {
    triggerButton.disabled = true;
    triggerButton.setAttribute('aria-busy', 'true');
    triggerButton.innerHTML = pendingButtonContent(ACTION_SCHEDULE_DELETE, accountDataText('confirm.schedule'), accountDataText('confirm.scheduling'));
  }
  try {
    const response = await withSensitiveReauth(() => api.accountDataScheduleDelete());
    snapshot = normalizeSnapshot(response?.account_data);
    openSheet(shellHtml());
    bind();
    render();
    const deleteAt = snapshot?.deletion?.execute_after_utc;
    toast(deleteAt
      ? accountDataText('toasts.deletion_scheduled_date', { date:formatDate(deleteAt) })
      : accountDataText('toasts.deletion_scheduled'));
  } catch (error) {
    toast(actionError(error, accountDataText('errors.schedule_delete')));
  } finally {
    pendingActions.delete(ACTION_SCHEDULE_DELETE);
    render();
  }
}

function actionError(error, fallback){
  if (['reauth_required','android_reauth_required'].includes(String(error?.code || ''))) {
    return error?.message || accountDataText('errors.reauth_required');
  }
  if (String(error?.code || '') === 'rate_limited') {
    return error?.message || accountDataText('errors.rate_limited');
  }
  return error?.message || fallback;
}

function parseUtcDate(value){
  if (!value) return null;
  const raw = String(value).trim();
  const normalized = /Z$|[+-]\d\d:\d\d$/.test(raw) ? raw : raw.replace(' ', 'T') + 'Z';
  const date = new Date(normalized);
  return Number.isNaN(date.getTime()) ? null : date;
}

function exportNextAllowedAt(item, policy){
  if (!item?.requested_at_utc) return null;
  const requested = parseUtcDate(item.requested_at_utc);
  const rateLimitSec = Math.max(0, Number(policy?.export_rate_limit_sec || 0));
  if (!(requested instanceof Date) || rateLimitSec <= 0) return null;
  return new Date(requested.getTime() + rateLimitSec * 1000);
}

function formatDate(value){
  if (!value) return '—';
  const raw = String(value).trim();
  const date = parseUtcDate(raw);
  if (!(date instanceof Date)) return raw;
  return formatLocalizedDateTime(date, 'short', {
    day:'2-digit', month:'2-digit', year:'numeric',
    hour:'2-digit', minute:'2-digit',
  });
}

function formatBytes(bytes){
  if (bytes < 1024) return accountDataText('bytes.b', { value:formatLocalizedNumber(bytes, { maximumFractionDigits:0 }) });
  if (bytes < 1024 * 1024) return accountDataText('bytes.kb', { value:formatLocalizedNumber(bytes / 1024, { minimumFractionDigits:1, maximumFractionDigits:1 }) });
  return accountDataText('bytes.mb', { value:formatLocalizedNumber(bytes / (1024 * 1024), { minimumFractionDigits:1, maximumFractionDigits:1 }) });
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'","&#039;");
}
