import { api } from '../api/client.js?v=46';
import { openSheet } from '../components/sheet.js?v=27';
import { haptic } from '../telegram/telegram-app.js?v=27';

let cachedStatus = null;
let refreshPromise = null;

export function initWeeklyMatchInfo(){
  document.addEventListener('click', event => {
    const target = event.target.closest('button, [role="button"]');
    if (!target) return;

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
  const button = document.getElementById('weeklyMatchInfo');
  if (!button) return;
  button.textContent = 'Бонусы';
  button.setAttribute('aria-label', 'Открыть бонусы Mini Games World');
}

export async function refreshWeeklyMatchProgress(){
  if (refreshPromise) return refreshPromise;

  refreshPromise = api.weeklyMatchStatus()
    .then(result => {
      cachedStatus = result.weekly_match || {};
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
      <div><h2>Бонусы</h2><p>Бесплатные коины за игровую активность.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="notifications-loading">
      <div>🎲</div>
      <strong>Загружаем бонусы</strong>
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
  const amount = Number(status.bonus_amount ?? 0);
  const minGames = Math.max(1, Number(status.min_completed_games ?? status.min_completed_matches ?? 3));
  const completed = Math.min(
    minGames,
    Math.max(0, Number(status.completed_games ?? status.completed_match_games ?? 0))
  );
  const remaining = Math.max(0, Number(status.remaining_games ?? status.remaining_match_games ?? 0));
  const weeklyComplete = completed >= minGames || remaining === 0;
  const nextDate = formatScheduleDate(status.next_bonus_at, status.timezone);

  const firstGameAmount = Math.max(0, Number(status.first_game_amount ?? 50));
  const firstGameMax = Math.max(1, Number(status.first_game_grant_max ?? 8));
  const firstGameCount = Math.min(
    firstGameMax,
    Math.max(0, Number(status.first_game_grant_count ?? 0))
  );

  const progressText = weeklyComplete
    ? 'Условие на эту неделю выполнено.'
    : `До бонуса осталось завершить ${remaining} ${pluralizeGames(remaining)}.`;

  const weeklyProgressStyle = weeklyComplete ? ' style="color:var(--green)"' : '';

  openSheet(`
    <div class="sheet-head">
      <div><h2>Бонусы</h2><p>Бесплатные коины за игровую активность.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="section-title"><h2>Еженедельный бонус</h2></div>
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
        <strong${weeklyProgressStyle}>${completed} из ${minGames}</strong>
      </div>
    </div>
    <div class="small-note">${escapeHtml(progressText)}</div>

    <div class="section-title"><h2>Бонусы за новые игры</h2></div>
    <div class="small-note">+${firstGameAmount.toLocaleString('ru-RU')} коинов за первую завершённую партию в каждой новой игре.</div>
    <div class="topup-success">
      <div>
        <span>Освоено игр</span>
        <strong>${firstGameCount} из ${firstGameMax}</strong>
      </div>
    </div>

    <button class="btn primary full sheet-bottom-btn" data-close-sheet type="button">Понятно</button>
  `);
}

function renderWeeklyMatchError(error){
  openSheet(`
    <div class="sheet-head">
      <div><h2>Бонусы</h2><p>Не удалось загрузить прогресс.</p></div>
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
    timeZone: timezone || 'Europe/Moscow',
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
