import { api } from '../api/client.js?v=1147&mvp22_8=account-data-v1';
import { openSheet } from '../components/sheet.js?v=1109';
import { toast } from '../components/toast.js?v=41';

const STYLE_URL = './assets/css/account-data-v1.css?v=4&mvp22_8=account-data-v1&ux=final-manual-polish-v2';

let snapshot = null;
let snapshotFetchedAt = 0;
let snapshotPromise = null;
let stylesReadyPromise = null;
let loading = false;
let actionPending = false;

const SNAPSHOT_TTL_MS = 15000;
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
  // showed a real "Загружаем состояние…" frame before the first response and
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
    toast(error?.message || 'Не удалось загрузить управление данными аккаунта.');
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
      ${escapeHtml(error?.message || 'Не удалось загрузить управление данными аккаунта.')}
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
        <h2>Данные и аккаунт</h2>
        <button class="close account-data-v1-close" data-close-sheet type="button" aria-label="Закрыть">×</button>
      </div>
      <div class="account-data-v1-body" data-account-data-body>
        <div class="account-data-v1-loading">Загружаем состояние…</div>
      </div>
    </div>
  `;
}

function render(){
  const body = document.querySelector('[data-account-data-body]');
  if (!(body instanceof HTMLElement)) return;
  if (loading && !snapshot) {
    body.innerHTML = '<div class="account-data-v1-loading">Загружаем состояние…</div>';
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
        <strong>Управление данными аккаунта</strong>
        <p>Здесь можно скачать копию своих данных или запросить удаление аккаунта.</p>
      </div>
    </div>

    <section class="account-data-v1-card account-data-v1-card--export">
      <div class="account-data-v1-card-icon" aria-hidden="true">⇩</div>
      <div class="account-data-v1-card-copy">
        <h3>Скачать мои данные</h3>
        <p>Создадим ZIP-архив. Внутри — удобная HTML-сводка, JSON/CSV и изображения профиля.</p>
        ${exportMeta(exportState)}
      </div>
      <div class="account-data-v1-actions">
        ${exportReady ? `
          <button class="btn primary account-data-v1-action" data-account-data-download type="button" ${actionPending ? 'disabled' : ''}>
            Скачать ZIP
          </button>
          ${exportRateLimited ? '' : `
            <button class="btn account-data-v1-secondary" data-account-data-create-export type="button" ${actionPending ? 'disabled' : ''}>
              Создать новый
            </button>
          `}
        ` : `
          <button class="btn primary account-data-v1-action" data-account-data-create-export type="button" ${actionPending || exportBusy ? 'disabled' : ''}>
            ${exportBusy ? 'Создаём архив…' : 'Создать архив'}
          </button>
        `}
      </div>
      ${exportRateLimited && nextExportAt ? '<p class="account-data-v1-note">Новый архив можно создать после ' + escapeHtml(formatDate(nextExportAt.toISOString())) + '.</p>' : ''}
      ${exportFailed ? '<p class="account-data-v1-note account-data-v1-note--error">Предыдущий экспорт не удалось подготовить. Можно повторить запрос.</p>' : ''}
      ${exportExpired ? '<p class="account-data-v1-note">Предыдущий архив уже удалён по сроку хранения.</p>' : ''}
    </section>

    <section class="account-data-v1-card account-data-v1-card--danger">
      <div class="account-data-v1-card-icon account-data-v1-card-icon--danger" aria-hidden="true">!</div>
      <div class="account-data-v1-card-copy">
        <h3>Удалить аккаунт</h3>
        ${deletionScheduled ? `
          <p>Удаление уже запланировано. До указанного срока запрос можно отменить — аккаунт продолжит работать.</p>
          <div class="account-data-v1-deletion-state">
            <span>Удаление после</span>
            <strong>${escapeHtml(formatDate(deletion.execute_after_utc))}</strong>
          </div>
        ` : `
          <p>Удаление не происходит сразу. После подтверждения у вас будет 7 дней, чтобы передумать и отменить запрос.</p>
        `}
      </div>
      <div class="account-data-v1-actions">
        ${deletionScheduled ? `
          <button class="btn account-data-v1-secondary" data-account-data-cancel-delete type="button" ${actionPending ? 'disabled' : ''}>
            Отменить удаление
          </button>
        ` : `
          <button class="btn account-data-v1-danger" data-account-data-confirm-delete type="button" ${actionPending ? 'disabled' : ''}>
            Удалить аккаунт
          </button>
        `}
      </div>
    </section>
  `;

  bind();
}

function exportMeta(item){
  if (!item) return '<div class="account-data-v1-meta">Архив ещё не создавался.</div>';
  if (item.status === 'ready') {
    const bytes = Number(item.artifact_size || 0);
    return `
      <div class="account-data-v1-meta account-data-v1-meta--success">
        <span>Архив готов${bytes > 0 ? ' · ' + escapeHtml(formatBytes(bytes)) : ''}</span>
        ${item.artifact_expires_at_utc ? '<small>Хранится до ' + escapeHtml(formatDate(item.artifact_expires_at_utc)) + '</small>' : ''}
      </div>
    `;
  }
  if (item.status === 'processing') return '<div class="account-data-v1-meta">Архив формируется…</div>';
  if (item.status === 'failed') return '<div class="account-data-v1-meta account-data-v1-meta--error">Последняя попытка завершилась ошибкой.</div>';
  if (item.status === 'expired') return '<div class="account-data-v1-meta">Срок хранения прошлого архива истёк.</div>';
  return '<div class="account-data-v1-meta">Последний запрос: ' + escapeHtml(String(item.status || 'неизвестно')) + '</div>';
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
    if (target.hasAttribute('data-account-data-schedule-delete')) void scheduleDeletion();
  });
}

function openDeleteConfirmation(){
  openSheet(`
    <div class="account-data-v1 account-data-v1-confirm">
      <div class="account-data-v1-head">
        <h2>Удалить аккаунт?</h2>
        <button class="close account-data-v1-close" data-close-sheet type="button" aria-label="Закрыть">×</button>
      </div>

      <div class="account-data-v1-confirm-scroll">
        <div class="account-data-v1-confirm-warning">
          <strong>Вы действительно хотите удалить аккаунт?</strong>
          <p>После подтверждения начнётся 7-дневный период ожидания. Всё это время запрос можно отменить.</p>
        </div>

        <div class="account-data-v1-confirm-archive">
          <div>
            <strong>Сначала сохранить свои данные?</strong>
            <span>Можно вернуться на предыдущий экран, создать ZIP-архив и скачать его перед удалением.</span>
          </div>
          <button class="btn account-data-v1-secondary" data-account-data-save-first type="button">Сначала скачать данные</button>
        </div>

        <p class="account-data-v1-confirm-note">После завершения удаления профиль и персональные привязки будут удалены или обезличены. Ограниченная техническая история может сохраняться только там, где она нужна для целостности матчей, финансового аудита, безопасности или требований закона.</p>
      </div>

      <div class="account-data-v1-confirm-actions">
        <button class="btn" data-account-data-back type="button">Не удалять</button>
        <button class="btn account-data-v1-danger" data-account-data-schedule-delete type="button">Да, запланировать</button>
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
    if (button.hasAttribute('data-account-data-schedule-delete')) void scheduleDeletion();
  });
}

async function createExport(){
  if (actionPending) return;
  actionPending = true;
  render();
  try {
    const response = await api.accountDataCreateExport();
    snapshot = normalizeSnapshot(response?.account_data);
    toast('Архив данных готов.');
  } catch (error) {
    toast(actionError(error, 'Не удалось создать архив данных.'));
  } finally {
    actionPending = false;
    render();
  }
}

async function downloadExport(){
  const requestId = String(snapshot?.export?.request_id || '');
  if (!requestId || actionPending) return;
  actionPending = true;
  render();
  try {
    const result = await api.accountDataDownloadExport(requestId);
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
    toast(actionError(error, 'Не удалось скачать архив данных.'));
  } finally {
    actionPending = false;
    render();
  }
}

async function cancelDeletion(){
  if (actionPending) return;
  actionPending = true;
  render();
  try {
    const response = await api.accountDataCancelDelete();
    snapshot = normalizeSnapshot(response?.account_data);
    toast('Удаление отменено. Аккаунт сохранён.');
  } catch (error) {
    toast(actionError(error, 'Не удалось отменить удаление.'));
  } finally {
    actionPending = false;
    render();
  }
}

async function scheduleDeletion(){
  if (actionPending) return;
  actionPending = true;
  try {
    const response = await api.accountDataScheduleDelete();
    snapshot = normalizeSnapshot(response?.account_data);
    openSheet(shellHtml());
    bind();
    render();
    const deleteAt = snapshot?.deletion?.execute_after_utc;
    toast(deleteAt
      ? `Аккаунт будет удалён через 7 дней. Дата удаления: ${formatDate(deleteAt)}.`
      : 'Аккаунт будет удалён через 7 дней.');
  } catch (error) {
    toast(actionError(error, 'Не удалось запланировать удаление.'));
  } finally {
    actionPending = false;
    render();
  }
}

function actionError(error, fallback){
  if (String(error?.code || '') === 'reauth_required') {
    return error?.message || 'Для подтверждения заново откройте MINI GAMES WORLD из Telegram.';
  }
  if (String(error?.code || '') === 'rate_limited') {
    return error?.message || 'Новый экспорт можно запросить позже.';
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
  return new Intl.DateTimeFormat('ru-RU', {
    day:'2-digit', month:'2-digit', year:'numeric',
    hour:'2-digit', minute:'2-digit',
  }).format(date);
}

function formatBytes(bytes){
  if (bytes < 1024) return `${bytes} Б`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} КБ`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} МБ`;
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'","&#039;");
}
