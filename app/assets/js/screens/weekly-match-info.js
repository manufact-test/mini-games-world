import { api } from '../api/client.js?v=44';
import { openSheet } from '../components/sheet.js?v=27';
import { haptic } from '../telegram/telegram-app.js?v=27';

export function initWeeklyMatchInfo(){
  document.addEventListener('click', event => {
    const target = event.target.closest('button, [role="button"]');
    if (!target) return;

    if (target.matches('[data-room]')) {
      queueMicrotask(syncWeeklyMatchButton);
      return;
    }

    if (target.id !== 'weeklyMatchInfo') return;

    event.preventDefault();
    event.stopImmediatePropagation();
    openWeeklyMatchInfo();
  }, true);

  setTimeout(syncWeeklyMatchButton, 0);
}

export function syncWeeklyMatchButton(){
  const roomCard = document.getElementById('roomCard');
  const topUpButton = document.getElementById('topUpMatch');
  if (!roomCard || !topUpButton || document.getElementById('weeklyMatchInfo')) return;

  const actions = topUpButton.closest('.room-actions');
  if (!actions) return;

  actions.classList.remove('single');
  actions.insertAdjacentHTML('beforeend', `
    <button class="btn ghost" id="weeklyMatchInfo" type="button">Подробнее</button>
  `);
}

async function openWeeklyMatchInfo(){
  haptic('light');
  openSheet(`
    <div class="sheet-head">
      <div><h2>Бесплатные Match-коины</h2><p>Проверяем ваш прогресс за текущую неделю.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="notifications-loading">
      <div>🎲</div>
      <strong>Загружаем прогресс</strong>
      <span>Считаем только завершённые матчи.</span>
    </div>
  `);

  try {
    const result = await api.weeklyMatchStatus();
    renderWeeklyMatchInfo(result.weekly_match || {});
  } catch (error) {
    renderWeeklyMatchError(error);
  }
}

function renderWeeklyMatchInfo(status){
  const amount = Number(status.bonus_amount || 50);
  const minGames = Number(status.min_completed_matches || 3);
  const completed = Math.min(minGames, Math.max(0, Number(status.completed_match_games || 0)));
  const remaining = Math.max(0, Number(status.remaining_match_games || 0));
  const eligible = Boolean(status.eligible_for_next);
  const nextDate = formatScheduleDate(status.next_bonus_at, status.timezone);

  openSheet(`
    <div class="sheet-head">
      <div><h2>Бесплатные Match-коины</h2><p>Еженедельный бонус за активность.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="topup-success">
      <div>
        <span>Следующее начисление</span>
        <strong>${escapeHtml(nextDate)}</strong>
      </div>
      <div>
        <span>Размер бонуса</span>
        <strong>+${amount.toLocaleString('ru-RU')} Match</strong>
      </div>
      <div>
        <span>Ваш прогресс</span>
        <strong>${completed} из ${minGames} матчей</strong>
      </div>
    </div>

    <div class="small-note">
      ${eligible
        ? `Условие выполнено. В ближайший понедельник в 12:00 вам будет начислено +${amount.toLocaleString('ru-RU')} Match-коинов.`
        : `Завершите ещё ${remaining} ${pluralizeMatches(remaining)}, чтобы получить ближайшее еженедельное начисление.`}
    </div>

    <div class="small-note">
      Считаются только завершённые матчи в Match-комнате. Gold-матчи не учитываются. Начисление добавляет +${amount.toLocaleString('ru-RU')} к текущему балансу и не пополняет его «до» фиксированной суммы.
    </div>

    <button class="btn primary full sheet-bottom-btn" data-close-sheet type="button">Понятно</button>
  `);
}

function renderWeeklyMatchError(error){
  openSheet(`
    <div class="sheet-head">
      <div><h2>Бесплатные Match-коины</h2><p>Не удалось загрузить прогресс.</p></div>
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

function pluralizeMatches(value){
  const n = Math.abs(Number(value || 0)) % 100;
  const n1 = n % 10;
  if (n > 10 && n < 20) return 'матчей';
  if (n1 > 1 && n1 < 5) return 'матча';
  if (n1 === 1) return 'матч';
  return 'матчей';
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
