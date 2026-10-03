import { api } from '../api/client.js?v=46';
import { openSheet } from '../components/sheet.js?v=27';
import { haptic } from '../telegram/telegram-app.js?v=27';
import { t, formatNumber, formatDateTime } from '@mgw/i18n';

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
  button.textContent = t('weekly_match.button');
  button.setAttribute('aria-label', t('weekly_match.button_aria'));
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
      <div><h2>${t('weekly_match.button')}</h2><p>${t('weekly_match.subtitle')}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="notifications-loading">
      <div>🎲</div>
      <strong>${t('weekly_match.loading_title')}</strong>
      <span>${t('weekly_match.loading_note')}</span>
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
  const weeklyComplete = completed >= minGames;
  const nextDate = formatScheduleDate(status.next_bonus_at, status.timezone);

  const firstGameAmount = Math.max(0, Number(status.first_game_amount ?? 50));
  const firstGameMax = Math.max(1, Number(status.first_game_grant_max ?? 8));
  const firstGameCount = Math.min(
    firstGameMax,
    Math.max(0, Number(status.first_game_grant_count ?? 0))
  );
  const firstGameComplete = firstGameCount >= firstGameMax;

  const completedProgressStyle = ' style="color:var(--sk-success);text-shadow:0 0 18px rgba(72,214,165,.20)"';
  const weeklyProgressStyle = weeklyComplete ? completedProgressStyle : '';
  const firstGameProgressStyle = firstGameComplete ? completedProgressStyle : '';

  openSheet(`
    <div class="sheet-head">
      <div><h2>${t('weekly_match.button')}</h2><p>${t('weekly_match.subtitle')}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div data-bonus-scroll style="min-height:0;flex:1 1 auto;overflow:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none;display:flex;flex-direction:column;gap:14px;padding-right:1px;">
      <div><h2 style="margin:0">${t('weekly_match.weekly_title')}</h2></div>
      <div class="topup-success">
        <div>
          <span>${t('weekly_match.next_credit')}</span>
          <strong>${escapeHtml(nextDate)}</strong>
        </div>
        <div>
          <span>${t('weekly_match.bonus_amount')}</span>
          <strong>${t('weekly_match.bonus_value', { amount:formatNumber(amount) })}</strong>
        </div>
        <div>
          <span>${t('weekly_match.games_week')}</span>
          <strong${weeklyProgressStyle}>${t('weekly_match.progress', { completed:formatNumber(completed), required:formatNumber(minGames) })}</strong>
        </div>
      </div>

      <div>
        <h2 style="margin:0 0 5px">${t('weekly_match.new_games_title')}</h2>
        <p>${t('weekly_match.new_games_note', { amount:formatNumber(firstGameAmount) })}</p>
      </div>
      <div class="topup-success">
        <div>
          <span>${t('weekly_match.games_mastered')}</span>
          <strong${firstGameProgressStyle}>${t('weekly_match.progress', { completed:formatNumber(firstGameCount), required:formatNumber(firstGameMax) })}</strong>
        </div>
      </div>

      <button class="btn primary full sheet-bottom-btn" data-close-sheet type="button">${t('rules.understood')}</button>
    </div>
  `);
}

function renderWeeklyMatchError(error){
  openSheet(`
    <div class="sheet-head">
      <div><h2>${t('weekly_match.button')}</h2><p>${t('weekly_match.load_error')}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="small-note">${escapeHtml(error?.message || t('weekly_match.retry_note'))}</div>
    <button class="btn ghost full sheet-bottom-btn" id="weeklyMatchRetry" type="button">${t('weekly_match.retry')}</button>
  `);

  document.getElementById('weeklyMatchRetry')?.addEventListener('click', openWeeklyMatchInfo);
}

function formatScheduleDate(value, timezone){
  const date = new Date(value || '');
  if (Number.isNaN(date.getTime())) return t('weekly_match.schedule_fallback');

  return formatDateTime(date, 'long', {
    timeZone: timezone || 'Europe/Moscow',
    weekday:'long',
    year:undefined,
  });
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
