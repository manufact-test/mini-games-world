import { api } from '../api/client.js?v=47';
import { openSheet } from '../components/sheet.js?v=1109';
import { toast } from '../components/toast.js?v=41';

const STYLE_URL = './assets/css/account-data-v1.css?v=1&mvp22_8=account-data-v1';

let snapshot = null;
let loading = false;
let actionPending = false;

export async function openAccountDataSheet(){
  ensureStyles();
  openSheet(shellHtml());
  bind();
  await refresh();
}

function ensureStyles(){
  if (document.querySelector('link[data-mgw-account-data-style]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = STYLE_URL;
  link.dataset.mgwAccountDataStyle = '1';
  document.head.append(link);
}

async function refresh(){
  if (loading) return;
  loading = true;
  render();
  try {
    const response = await api.accountDataSnapshot();
    snapshot = normalizeSnapshot(response?.account_data);
  } catch (error) {
    toast(error?.message || 'Не удалось загрузить управление данными аккаунта.');
  } finally {
    loading = false;
    render();
  }
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
        <div>
          <span class="account-data-v1-kicker">Приватность</span>
          <h2>Данные и аккаунт</h2>
          <p>Скачайте копию данных или запланируйте удаление аккаунта MINI GAMES WORLD.</p>
        </div>
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

  body.innerHTML = `
    <section class="account-data-v1-card account-data-v1-card--export">
      <div class="account-data-v1-card-icon" aria-hidden="true">⇩</div>
      <div class="account-data-v1-card-copy">
        <h3>Экспорт данных</h3>
        <p>Получите ZIP-архив: удобная HTML-сводка, JSON, CSV-файлы и раздел с изображениями профиля.</p>
        ${exportMeta(exportState)}
      </div>
      <div class="account-data-v1-actions">
        ${exportReady ? `
          <button class="btn primary account-data-v1-action" data-account-data-download type="button" ${actionPending ? 'disabled' : ''}>
            Скачать ZIP
          </button>
          <button class="btn account-data-v1-secondary" data-account-data-create-export type="button" ${actionPending ? 'disabled' : ''}>
            Создать новый
          </button>
        ` : `
          <button class="btn primary account-data-v1-action" data-account-data-create-export type="button" ${actionPending || exportBusy ? 'disabled' : ''}>
            ${exportBusy ? 'Создаём архив…' : 'Создать архив'}
          </button>
        `}
      </div>
      ${exportFailed ? '<p class="account-data-v1-note account-data-v1-note--error">Предыдущий экспорт не удалось подготовить. Можно повторить запрос.</p>' : ''}
      ${exportExpired ? '<p class="account-data-v1-note">Предыдущий архив уже удалён по сроку хранения.</p>' : ''}
    </section>

    <section class="account-data-v1-card account-data-v1-card--danger">
      <div class="account-data-v1-card-icon account-data-v1-card-icon--danger" aria-hidden="true">!</div>
      <div class="account-data-v1-card-copy">
        <h3>Удаление аккаунта</h3>
        ${deletionScheduled ? `
          <p>Удаление уже запланировано. До указанного срока вы можете отменить запрос и продолжить пользоваться аккаунтом.</p>
          <div class="account-data-v1-deletion-state">
            <span>Удаление после</span>
            <strong>${escapeHtml(formatDate(deletion.execute_after_utc))}</strong>
          </div>
        ` : `
          <p>После подтверждения начнётся 7-дневный период ожидания. Затем профиль и привязанные персональные данные будут обезличены, а активные сессии закрыты.</p>
          <p class="account-data-v1-note">История, которую система обязана сохранять для целостности матчей и финансового аудита, остаётся только в обезличенном/аудитном виде.</p>
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

    <div class="account-data-v1-security">
      <span aria-hidden="true">⌁</span>
      <p>Опасные действия требуют свежего подтверждения входа через Telegram. Если сессия устарела, закройте MINI GAMES WORLD и откройте снова.</p>
    </div>
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
        <div>
          <span class="account-data-v1-kicker account-data-v1-kicker--danger">Необратимое действие</span>
          <h2>Удалить аккаунт?</h2>
          <p>Запрос можно отменить в течение 7 дней. После истечения срока профиль будет обезличен, персональные привязки удалены, а текущие сессии закрыты.</p>
        </div>
        <button class="close account-data-v1-close" data-close-sheet type="button" aria-label="Закрыть">×</button>
      </div>
      <div class="account-data-v1-confirm-box">
        <strong>Перед удалением рекомендуем скачать архив данных.</strong>
        <span>Для подтверждения сервер потребует свежий вход через Telegram.</span>
      </div>
      <div class="account-data-v1-confirm-actions">
        <button class="btn" data-account-data-back type="button">Отмена</button>
        <button class="btn account-data-v1-danger" data-account-data-schedule-delete type="button">Запланировать удаление</button>
      </div>
    </div>
  `, { returnToPrevious:true });

  const confirmRoot = document.querySelector('.account-data-v1-confirm');
  confirmRoot?.addEventListener('click', event => {
    const button = event.target instanceof Element ? event.target.closest('button') : null;
    if (!(button instanceof HTMLButtonElement)) return;
    if (button.hasAttribute('data-account-data-back')) {
      document.querySelector('.account-data-v1-confirm [data-close-sheet]')?.dispatchEvent(new MouseEvent('click', { bubbles:true }));
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
    toast('Удаление аккаунта отменено.');
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
    toast('Удаление аккаунта запланировано на 7 дней.');
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

function formatDate(value){
  if (!value) return '—';
  const raw = String(value).trim();
  const normalized = /Z$|[+-]\d\d:\d\d$/.test(raw) ? raw : raw.replace(' ', 'T') + 'Z';
  const date = new Date(normalized);
  if (Number.isNaN(date.getTime())) return raw;
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
