import { api } from '../api/client.js?v=45';
import { openSheet } from '../components/sheet.js?v=27';
import { haptic } from '../telegram/telegram-app.js?v=27';

let cachedStatus = null;
let refreshPromise = null;

export function initWeeklyMatchInfo(){
  document.addEventListener('click', event => {
    const target = event.target.closest('button, [role="button"]');
    if (!target) return;

    if (target.matches('[data-room]')) {
      queueMicrotask(() => syncWeeklyMatchButton());
      return;
    }

    if (target.id !== 'weeklyMatchInfo') return;

    event.preventDefault();
    event.stopImmediatePropagation();
    openWeeklyMatchInfo();
  }, true);

  document.addEventListener('mgw:game-finished', () => {
    refreshWeeklyMatchProgress();
  });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') refreshWeeklyMatchProgress();
  });

  setTimeout(() => syncWeeklyMatchButton(), 0);
}

export function syncWeeklyMatchButton(status = null){
  if (status && typeof status === 'object') cachedStatus = status;

  const roomCard = document.getElementById('roomCard');
  const topUpButton = document.getElementById('topUpMatch');
  if (!roomCard || !topUpButton) return;

  const actions = topUpButton.closest('.room-actions');
  if (!actions) return;

  actions.classList.remove('single');

  let button = document.getElementById('weeklyMatchInfo');
  if (!button) {
    actions.insertAdjacentHTML('beforeend', `
      <button class="btn ghost" id="weeklyMatchInfo" type="button">Прогресс</button>
    `);
    button = document.getElementById('weeklyMatchInfo');
  }

  if (!button) return;

  const minGames = Number(cachedStatus?.min_completed_games ?? cachedStatus?.min_completed_matches ?? 3);
  const completed = Math.min(
    minGames,
    Math.max(0, Number(cachedStatus?.completed_games ?? cachedStatus?.completed_match_games ?? 0))
  );

  button.textContent = cachedStatus ? `Прогресс ${completed}/${minGames}` : 'Прогресс';
  button.setAttribute('aria-label', cachedStatus
    ? `Прогресс еженедельного бонуса: ${completed} из ${minGames} игр`
    : 'Прогресс еженедельного бонуса');
}

export async function refreshWeeklyMatchProgress(){
  if (refreshPromise) return refreshPromise;

  refreshPromise = api.weeklyMatchStatus()
    .then(result => {
      cachedStatus = result.weekly_match || {};
      syncWeeklyMatchButton(cachedStatus);
      return cachedStatus;
    })
    .catch(() => cachedStatus)
    .finally(() => {
      refreshPromise = null;
    });

  return refreshPromise;
}

async function openWeeklyMatchInfo(){
  haptic('light');
  openSheet(`
    <div class="sheet-head">
      <div><h2>Бесплатные коины</h2><p>Проверяем ваш прогресс за текущую неделю.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="notifications-loading">
      <div>🎲</div>
      <strong>Загружаем прогресс</strong>
      <span>Считаем завершённые игры.</span>
    </div>
  `);

  try {
    const status = await refreshWeeklyMatchProgress();
    renderWeeklyMatchInfo(status || {});
  } catch (error) {
    renderWeeklyMatchError(error);
  }
}

function renderWeeklyMatchInfo(status){
  const amount = Number(status.bonus_amount || 50);
  const minGames = Number(status.min_completed_games ?? status.min_completed_matches ?? 3);
  const completed = Math.min(
    minGames,
    Math.max(0, Number(status.completed_games ?? status.completed_match_games ?? 0))
  );
  const remaining = Math.max(0, Number(status.remaining_games ?? status.remaining_match_games ?? 0));
  const eligible = Boolean(status.eligible_for_next);
  const firstGrantPending = Boolean(status.first_grant_pending);
  const nextDate = formatScheduleDate(status.next_bonus_at, status.timezone);

  let eligibilityText = '';
  if (firstGrantPending) {
    eligibilityText = `Это ваше первое еженедельное начисление: +${amount.toLocaleString('ru-RU')} коинов будет доступно независимо от текущего прогресса. После первого начисления для следующих недель нужно завершать ${minGames} игры.`;
  } else if (eligible) {
    eligibilityText = `Условие выполнено. В ближайший понедельник в 12:00 в Матч-комнату будет начислено +${amount.toLocaleString('ru-RU')} коинов.`;
  } else {
    eligibilityText = `Завершите ещё ${remaining} ${pluralizeGames(remaining)}, чтобы получить ближайшее еженедельное начисление.`;
  }

  openSheet(`
    <div class="sheet-head">
      <div><h2>Бесплатные коины</h2><p>Еженедельный бонус за игровую активность.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="topup-success">
      <div>
        <span>Следующее начисление</span>
        <strong>${escapeHtml(nextDate)}</strong>
      </div>
      <div>
        <span>Размер бонуса</span>
        <strong>+${amount.toLocaleString('ru-RU')} коинов</strong>
      </div>
      <div>
        <span>Игры за неделю</span>
        <strong>${completed} из ${minGames}</strong>
      </div>
    </div>

    <div class="small-note">${escapeHtml(eligibilityText)}</div>

    <div class="small-note">
      Для прогресса подходит любая завершённая игра в Mini Games World: любая мини-игра, размер поля и игровая комната. Поиск соперника без завершённой игры не считается. Начисление добавляет +${amount.toLocaleString('ru-RU')} коинов к текущему балансу Матч-комнаты.
    </div>

    <button class="btn primary full sheet-bottom-btn" data-close-sheet type="button">Понятно</button>
  `);
}

function renderWeeklyMatchError(error){
  openSheet(`
    <div class="sheet-head">
      <div><h2>Бесплатные коины</h2><p>Не удалось загрузить прогресс.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="small-note">${escapeHtml(error?.message || 'Попробуйте открыть раздел ещё раз.')}</div>
    <button class="btn ghost full sheet-bottom-btn" id="weeklyMatchRetry" type="button">Попробовать снова</button>
  `);

  document.getElementById('weeklyMatchRetry')?.addEventListener('click', openWeeklyMatchInfo);
}

function formatScheduleDate(value, timezone){
  const date = new Date(value || '');
  if (Number.isNaN(date.getTime())) return 'Ближайший понедельник, 12:00';

  return new Intl.DateTimeFormat('ru-RU', {
    timeZone: timezone || 'Europe/Warsaw',
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date);
}

function pluralizeGames(value){
  const n = Math.abs(Number(value || 0)) % 100;
  const n1 = n % 10;
  if (n > 10 && n < 20) return 'игр';
  if (n1 > 1 && n1 < 5) return 'игры';
  if (n1 === 1) return 'игру';
  return 'игр';
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
