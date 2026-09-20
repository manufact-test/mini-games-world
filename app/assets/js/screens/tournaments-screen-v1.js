import { api } from '../api/client.js?v=47';
import { currentScreen, onScreenEnter } from '../router.js?v=27';
import { t, formatNumber } from '@mgw/i18n';
import { state } from '../state.js?v=27';
import { renderBalances } from '../ui.js?v=90-wallet-15-3';

const GAME_TYPES = Object.freeze([
  'tictactoe',
  'four_in_a_row',
  'battleship',
  'checkers',
  'reversi',
  'chess',
  'go',
  'domino',
]);
const DEFAULT_GAME = 'tictactoe';
const CACHE_TTL_MS = 45_000;
const cache = new Map();
const inFlight = new Map();
const archiveSeasonCache = new Map();
let archiveOverviewCache = null;
let archiveOverviewLoadedAt = 0;
let archiveOverviewPromise = null;
let selectedArchiveSeasonId = '';
let activeGame = DEFAULT_GAME;
let initialized = false;
let dragState = null;
let suppressClickUntil = 0;
let tournamentSnapshot = null;
let tournamentRequest = null;
let tournamentBusy = false;
let tournamentPendingAction = '';
let tournamentRulesAccepted = false;
let tournamentRulesSha256 = '';
let tournamentCountdownTimer = null;

function lockVisibleBalance(){
  const ids = ['balanceUnified', 'topbarBalanceUnified'];
  const snapshots = new Map();
  const elements = [];

  for (const id of ids) {
    const element = document.getElementById(id);
    if (!(element instanceof HTMLElement)) continue;
    snapshots.set(element, String(element.textContent || '—'));
    elements.push(element);
  }

  if (elements.length === 0 || typeof MutationObserver !== 'function') {
    return () => {};
  }

  let restoring = false;
  const restore = () => {
    if (restoring) return;
    restoring = true;
    try {
      for (const element of elements) {
        const expected = snapshots.get(element);
        if (expected !== undefined && String(element.textContent || '') !== expected) {
          element.textContent = expected;
        }
      }
    } finally {
      restoring = false;
    }
  };

  const observer = new MutationObserver(restore);
  for (const element of elements) {
    observer.observe(element, { childList:true, subtree:true, characterData:true });
  }

  return () => {
    observer.disconnect();
  };
}

export function initTournamentsScreen(){
  if (initialized) return;
  const screen = document.getElementById('screen-tournaments');
  const content = screen?.querySelector('.content');
  if (!(screen instanceof HTMLElement) || !(content instanceof HTMLElement)) return;

  initialized = true;
  screen.dataset.mgwTournaments = 'leaderboards-v2 rating-archive-v1 official-tournament-registration-v2-rules';
  content.innerHTML = `
    <div class="tournaments-v2" id="tournamentsV2Root">
      <div class="page-head app-shell-page-head tournaments-v2-page-head">
        <div>
          <h1 class="page-title">${escapeHtml(t('shell.tournaments_title'))}</h1>
        </div>
      </div>

      <div class="tournaments-v2-mode-tabs" role="tablist" aria-label="${escapeHtml(t('shell.tournaments_title'))}">
        <button class="tournaments-v2-mode-tab active" type="button" role="tab" aria-selected="true" data-competition-mode="rating">${escapeHtml(t('shell.competition_rating'))}</button>
        <button class="tournaments-v2-mode-tab" type="button" role="tab" aria-selected="false" data-competition-mode="tournaments">${escapeHtml(t('shell.competition_tournaments'))}</button>
      </div>

      <div class="tournaments-v2-panel" data-competition-panel="rating">
      <section class="tournaments-v2-board" aria-labelledby="tournamentsLeaderboardTitle">
        <div class="tournaments-v2-board-head">
          <div>
            <h2 id="tournamentsLeaderboardTitle">${escapeHtml(t('profile.leaderboard_title'))}</h2>
            <p>${escapeHtml(t('profile.leaderboard_open_note'))}</p>
          </div>
        </div>

        <div class="tournaments-v2-tabs-shell">
          <button class="tournaments-v2-scroll tournaments-v2-scroll--left" type="button" data-tournaments-scroll="-1" aria-label="Прокрутить игры влево">${scrollIcon('left')}</button>
          <div class="tournaments-v2-tabs" id="tournamentsLeaderboardTabs" aria-label="${escapeHtml(t('profile.leaderboard_title'))}">
            ${GAME_TYPES.map(type => tabMarkup(type, activeGame)).join('')}
          </div>
          <button class="tournaments-v2-scroll tournaments-v2-scroll--right" type="button" data-tournaments-scroll="1" aria-label="Прокрутить игры вправо">${scrollIcon('right')}</button>
        </div>

        <div class="tournaments-v2-body" id="tournamentsLeaderboardBody" aria-live="polite">
          ${loadingMarkup()}
        </div>
      </section>

      <section class="tournaments-v2-board tournaments-v2-archive" aria-labelledby="ratingArchiveTitle">
        <div class="tournaments-v2-board-head">
          <div>
            <h2 id="ratingArchiveTitle">${escapeHtml(t('shell.competition_archive_title'))}</h2>
            <p>${escapeHtml(t('shell.competition_archive_note'))}</p>
          </div>
        </div>
        <div class="tournaments-v2-archive-tabs" role="tablist" aria-label="${escapeHtml(t('shell.competition_archive_title'))}">
          <button class="tournaments-v2-mode-tab active" type="button" role="tab" aria-selected="true" data-rating-history-mode="seasons">${escapeHtml(t('shell.competition_archive_seasons'))}</button>
          <button class="tournaments-v2-mode-tab" type="button" role="tab" aria-selected="false" data-rating-history-mode="tournaments">${escapeHtml(t('shell.competition_archive_tournaments'))}</button>
        </div>
        <div data-rating-history-panel="seasons">
          <div class="tournaments-v2-archive-body" id="ratingArchiveBody">${loadingMarkup()}</div>
        </div>
        <div data-rating-history-panel="tournaments" hidden>
          <div class="tournaments-v2-empty">${escapeHtml(t('shell.competition_archive_tournaments_empty'))}</div>
        </div>
      </section>
      </div>

      <div class="tournaments-v2-panel" data-competition-panel="tournaments" hidden>
        <section class="tournaments-v2-board tournaments-v2-tournament-card">
          <div class="tournaments-v2-board-head">
            <div>              <h2>Официальный турнир</h2>
            </div>
          </div>
          <div class="tournaments-v2-tournament-body" id="officialTournamentBody" aria-live="polite">
            ${loadingMarkup()}
          </div>
        </section>
      </div>
    </div>
  `;

  bindModeTabs(screen);
  bindArchiveModeTabs(screen);
  bindArchiveSeasonClicks(screen);
  bindTabs(screen);
  bindScrollButtons(screen);
  bindTournamentActions(screen);
  onScreenEnter('tournaments', () => {
    void activateGame(activeGame);
    void loadArchiveOverview();
    void loadTournamentSnapshot();
  });

  document.addEventListener('mgw:app-ready', () => {
    window.setTimeout(() => { void warmLeaderboard(DEFAULT_GAME); }, 260);
    window.setTimeout(() => { void warmArchiveOverview(); }, 520);
    window.setTimeout(() => { void warmTournamentStatus(); }, 760);
  }, { once:true });

  if (currentScreen() === 'tournaments') {
    void activateGame(activeGame);
    void loadArchiveOverview();
  }
  window.requestAnimationFrame(updateScrollAffordances);
}

function bindArchiveModeTabs(screen){
  screen.querySelectorAll('[data-rating-history-mode]').forEach(button => {
    button.addEventListener('click', () => {
      const mode = String(button.dataset.ratingHistoryMode || '');
      if (!['seasons','tournaments'].includes(mode)) return;
      screen.querySelectorAll('[data-rating-history-mode]').forEach(candidate => {
        const active = String(candidate.dataset.ratingHistoryMode || '') === mode;
        candidate.classList.toggle('active', active);
        candidate.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      screen.querySelectorAll('[data-rating-history-panel]').forEach(panel => {
        panel.hidden = String(panel.dataset.ratingHistoryPanel || '') !== mode;
      });
      if (mode === 'seasons') void loadArchiveOverview();
    });
  });
}

function bindArchiveSeasonClicks(screen){
  screen.addEventListener('click', event => {
    const button = event.target instanceof Element ? event.target.closest('[data-rating-archive-season]') : null;
    if (!(button instanceof HTMLButtonElement)) return;
    const seasonId = String(button.dataset.ratingArchiveSeason || '').trim();
    if (!seasonId) return;
    selectedArchiveSeasonId = seasonId;
    syncArchiveSeasonButtons();
    void loadArchiveSeason(seasonId, activeGame);
  });
}

function bindModeTabs(screen){
  screen.querySelectorAll('[data-competition-mode]').forEach(button => {
    button.addEventListener('click', () => {
      const mode = String(button.dataset.competitionMode || '');
      if (!['rating','tournaments'].includes(mode)) return;
      screen.querySelectorAll('[data-competition-mode]').forEach(candidate => {
        const active = String(candidate.dataset.competitionMode || '') === mode;
        candidate.classList.toggle('active', active);
        candidate.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      screen.querySelectorAll('[data-competition-panel]').forEach(panel => {
        panel.hidden = String(panel.dataset.competitionPanel || '') !== mode;
      });
      if (mode === 'rating') void activateGame(activeGame);
      if (mode === 'tournaments') void loadTournamentSnapshot();
    });
  });
}

function bindTournamentActions(screen){
  screen.addEventListener('change', event => {
    const input = event.target instanceof Element
      ? event.target.closest('[data-tournament-rules-consent]')
      : null;
    if (!(input instanceof HTMLInputElement)) return;
    tournamentRulesAccepted = input.checked;
    const button = screen.querySelector('[data-tournament-action="register"]');
    if (button instanceof HTMLButtonElement) {
      const tournament = tournamentSnapshot?.tournament;
      const registered = String(tournamentSnapshot?.registration?.state || '') === 'registered';
      const fee = Math.max(0, Number(tournament?.entry_fee?.amount || 50000));
      const available = Math.max(0, Number(tournamentSnapshot?.balance?.available_amount || 0));
      button.disabled = tournamentBusy || (!registered && available < fee) || !tournamentRulesAccepted;
    }
  });

  screen.addEventListener('click', event => {
    const button = event.target instanceof Element ? event.target.closest('[data-tournament-action]') : null;
    if (!(button instanceof HTMLButtonElement) || tournamentBusy) return;
    const action = String(button.dataset.tournamentAction || '');
    if (action === 'register') void mutateTournament('register');
    if (action === 'leave') void mutateTournament('leave');
  });
}

async function warmTournamentStatus(){
  if (tournamentRequest) return tournamentRequest;
  tournamentRequest = api.tournamentStatus()
    .then(result => {
      tournamentSnapshot = result?.snapshot && typeof result.snapshot === 'object' ? result.snapshot : {};
      syncTournamentRulesConsent();
      return tournamentSnapshot;
    })
    .finally(() => { tournamentRequest = null; });
  return tournamentRequest;
}

function syncTournamentRulesConsent(){
  const tournament = tournamentSnapshot?.tournament;
  const registration = tournamentSnapshot?.registration;
  const rules = tournament?.rules;
  const nextSha = String(rules?.sha256 || '');
  if (nextSha !== tournamentRulesSha256) {
    tournamentRulesSha256 = nextSha;
    tournamentRulesAccepted = false;
  }
  const accepted = registration?.rules_consent?.accepted === true
    && String(registration?.rules_consent?.sha256 || '') === nextSha;
  if (accepted) tournamentRulesAccepted = true;
}

async function loadTournamentSnapshot(){
  const body = document.getElementById('officialTournamentBody');
  if (!(body instanceof HTMLElement)) return;
  if (!tournamentSnapshot) body.innerHTML = loadingMarkup();
  try {
    await warmTournamentStatus();
    renderTournamentSnapshot();
  } catch (error) {
    body.innerHTML = `<div class="tournaments-v2-empty">${escapeHtml(error?.message || 'Не удалось загрузить турнир.')}</div>`;
  }
}

async function mutateTournament(action){
  if (tournamentBusy) return;
  const tournament = tournamentSnapshot?.tournament;
  if (!tournament || typeof tournament !== 'object') return;

  if (action === 'register') {
    const rules = tournament?.rules;
    if (!tournamentRulesAccepted
        || !rules
        || !rules.version
        || !rules.language
        || !rules.sha256) {
      renderTournamentSnapshot('Перед регистрацией прочитайте правила и подтвердите согласие.');
      return;
    }
    const alreadyRegistered = String(tournamentSnapshot?.registration?.state || '') === 'registered';
    if (alreadyRegistered) {
      if (!window.confirm('Подтвердить обновлённые правила турнира?')) return;
    } else {
      const fee = formatNumber(Math.max(0, Number(tournament?.entry_fee?.amount || 50000)));
      if (!window.confirm(`Подтвердить правила и зарезервировать ${fee} коинов для участия в официальном турнире?`)) return;
    }
  } else if (action === 'leave') {
    if (!window.confirm('Отменить регистрацию? Зарезервированные 50 000 коинов вернутся в доступный баланс.')) return;
  }

  const releaseVisibleBalance = lockVisibleBalance();
  tournamentBusy = true;
  tournamentPendingAction = action;
  let errorMessage = '';
  let verifiedCommit = null;
  let verifiedUser = null;
  renderTournamentSnapshot();

  try {
    const result = action === 'register'
      ? await api.tournamentRegister({
          accepted:true,
          version:String(tournament?.rules?.version || ''),
          language:String(tournament?.rules?.language || ''),
          sha256:String(tournament?.rules?.sha256 || ''),
        })
      : await api.tournamentLeave();

    const responseSnapshot = result?.snapshot && typeof result.snapshot === 'object'
      ? result.snapshot
      : {};
    const responseUser = result?.user && typeof result.user === 'object'
      ? result.user
      : null;

    // The write may already be committed on the server, but the visible seat and
    // balance stay on their last confirmed values until the independent status
    // read finishes. Do not publish the write response while the spinner is active.
    tournamentPendingAction = 'verify';
    renderTournamentSnapshot();

    const verified = await api.tournamentStatus();
    const verifiedSnapshot = verified?.snapshot && typeof verified.snapshot === 'object'
      ? verified.snapshot
      : responseSnapshot;

    const registrationState = String(verifiedSnapshot?.registration?.state || '');
    if (action === 'register' && registrationState !== 'registered') {
      throw new Error('Регистрация не сохранилась. Попробуйте ещё раз.');
    }
    if (action === 'leave' && registrationState === 'registered') {
      throw new Error('Отмена регистрации не сохранилась. Попробуйте ещё раз.');
    }

    const verifiedAvailable = Number(verifiedSnapshot?.balance?.available_amount);
    verifiedCommit = verifiedSnapshot;
    verifiedUser = responseUser
      ? {
          ...responseUser,
          ...(Number.isFinite(verifiedAvailable) ? { balance:verifiedAvailable } : {}),
        }
      : (state.user && typeof state.user === 'object' && Number.isFinite(verifiedAvailable)
          ? { ...state.user, balance:verifiedAvailable }
          : null);
  } catch (error) {
    errorMessage = humanizeTournamentError(error?.message || 'Не удалось изменить регистрацию.');
  } finally {
    // End the pending state first. Only then publish the verified tournament
    // snapshot and balance, so the user never sees money move under a live spinner.
    tournamentBusy = false;
    tournamentPendingAction = '';

    if (!errorMessage && verifiedCommit) {
      tournamentSnapshot = verifiedCommit;
      syncTournamentRulesConsent();
    }
    renderTournamentSnapshot(errorMessage);

    // Release the visible balance only after the pending button has already
    // been removed from the DOM. Any unrelated runtime poll that learned about
    // the server-side reservation while verification was still running was
    // prevented from repainting the header balance early.
    releaseVisibleBalance();

    if (!errorMessage && verifiedUser) {
      state.user = verifiedUser;
      renderBalances(state.user);
    }
  }
}

function renderTournamentSnapshot(errorMessage = ''){
  if (tournamentCountdownTimer) {
    window.clearInterval(tournamentCountdownTimer);
    tournamentCountdownTimer = null;
  }
  const body = document.getElementById('officialTournamentBody');
  if (!(body instanceof HTMLElement)) return;
  const snapshot = tournamentSnapshot && typeof tournamentSnapshot === 'object' ? tournamentSnapshot : {};
  const tournament = snapshot.tournament && typeof snapshot.tournament === 'object' ? snapshot.tournament : null;
  if (!tournament) {
    body.innerHTML = `<div class="tournaments-v2-empty">Официальный турнир пока не создан или не открыт для участников.</div>`;
    return;
  }

  const registration = snapshot.registration && typeof snapshot.registration === 'object' ? snapshot.registration : null;
  const registered = String(registration?.state || '') === 'registered';
  const state = String(tournament.state || '');
  const open = state === 'registration_open';
  const waitingForDate = state === 'waiting_for_date' || tournament.waiting_for_date === true;
  const scheduled = state === 'scheduled' && Boolean(tournament.scheduled_start_at_utc);
  const scheduledStart = scheduled ? parseTournamentUtc(tournament.scheduled_start_at_utc) : null;
  const full = tournament.is_full === true;
  const capacity = Math.max(1, Number(tournament.capacity || 0));
  const count = Math.max(0, Number(tournament.registered_count || 0));
  const pct = Math.max(0, Math.min(100, Math.round((count / capacity) * 100)));
  const fee = Math.max(0, Number(tournament?.entry_fee?.amount || 50000));
  const available = Math.max(0, Number(snapshot?.balance?.available_amount || 0));
  const rewards = tournament.reward_snapshot && typeof tournament.reward_snapshot === 'object'
    ? tournament.reward_snapshot
    : {};
  const first = rewards?.placements?.['1'] || {};
  const second = rewards?.placements?.['2'] || {};
  const third = rewards?.placements?.['3'] || {};
  const rules = tournament.rules && typeof tournament.rules === 'object' ? tournament.rules : {};
  const rulesSnapshot = rules.snapshot && typeof rules.snapshot === 'object' ? rules.snapshot : {};
  const rulesSections = Array.isArray(rulesSnapshot.sections) ? rulesSnapshot.sections : [];
  const rulesReady = Boolean(rules.version && rules.language && rules.sha256 && rulesSections.length);
  const consentAccepted = registration?.rules_consent?.accepted === true
    && String(registration?.rules_consent?.sha256 || '') === String(rules.sha256 || '');
  const rulesMarkup = rulesReady
    ? `<details class="tournaments-v2-tournament-rules">
        <summary><span>Правила турнира</span></summary>
        <div class="tournaments-v2-tournament-rules-body">
          ${rulesSections.map(section => `
            <section>
              <h4>${escapeHtml(String(section?.title || ''))}</h4>
              <ul>${(Array.isArray(section?.items) ? section.items : []).map(item => `<li>${escapeHtml(String(item || ''))}</li>`).join('')}</ul>
            </section>
          `).join('')}
        </div>
      </details>`
    : '<div class="tournaments-v2-tournament-error">Правила турнира временно недоступны.</div>';

  const needsConsent = open && (!registered || !consentAccepted);
  const consentMarkup = needsConsent && rulesReady
    ? `<label class="tournaments-v2-tournament-consent">
        <input type="checkbox" data-tournament-rules-consent${tournamentRulesAccepted ? ' checked' : ''}${tournamentBusy ? ' disabled' : ''}>
        <span>Я прочитал(а) и принимаю правила этого турнира.</span>
      </label>`
    : consentAccepted
      ? `<div class="tournaments-v2-tournament-consent-proof">Правила турнира приняты${registration?.rules_consent?.accepted_at_utc ? ` · ${escapeHtml(formatConsentTime(registration.rules_consent.accepted_at_utc))}` : ''}.</div>`
      : '';

  const insufficient = !registered && available < fee;
  let action = '';
  if (open && !registered && !full) {
    const disabled = tournamentBusy || insufficient || !tournamentRulesAccepted || !rulesReady;
    const label = insufficient
      ? 'Недостаточно коинов'
      : tournamentBusy
        ? (tournamentPendingAction === 'register' ? 'Регистрируем…' : 'Проверяем…')
        : `Зарегистрироваться · ${escapeHtml(formatNumber(fee))}`;
    action = `<button type="button" class="tournaments-v2-tournament-action${tournamentBusy ? ' is-pending' : ''}" data-tournament-action="register"${disabled ? ' disabled' : ''}${tournamentBusy ? ' aria-busy="true"' : ''}>${label}</button>`;
  } else if (open && registered && !consentAccepted) {
    const disabled = tournamentBusy || !tournamentRulesAccepted || !rulesReady;
    const label = tournamentBusy
      ? (tournamentPendingAction === 'register' ? 'Сохраняем согласие…' : 'Проверяем…')
      : 'Подтвердить правила';
    action = `<button type="button" class="tournaments-v2-tournament-action${tournamentBusy ? ' is-pending' : ''}" data-tournament-action="register"${disabled ? ' disabled' : ''}${tournamentBusy ? ' aria-busy="true"' : ''}>${label}</button>`;
  } else if (open && registered && !full) {
    const label = tournamentBusy
      ? (tournamentPendingAction === 'leave' ? 'Отменяем…' : 'Проверяем…')
      : 'Отменить регистрацию';
    action = `<button type="button" class="tournaments-v2-tournament-action tournaments-v2-tournament-action--secondary${tournamentBusy ? ' is-pending' : ''}" data-tournament-action="leave"${tournamentBusy ? ' disabled aria-busy="true"' : ''}>${label}</button>`;
  }

  const statusText = state === 'draft'
    ? 'Турнир готовится · регистрация ещё не открыта'
    : scheduled
      ? 'Дата назначена · готовимся к старту'
      : waitingForDate
        ? 'Состав набран · ожидаем назначения даты'
        : full
          ? 'Состав заполнен'
          : 'Регистрация открыта';

  const ownStatus = registered
    ? (scheduled
      ? `Вы в составе. Турнир начнётся ${scheduledStart ? formatTournamentDateTime(scheduledStart) : 'в назначенное время'}.`
      : waitingForDate
        ? 'Вы в составе. Регистрация закрыта — ожидайте назначения даты турнира.'
        : full
          ? 'Вы в составе. Турнир заполнен — место зафиксировано.'
          : 'Вы зарегистрированы. Место закреплено за вами.')
    : scheduled
      ? 'Состав турнира зафиксирован. Регистрация завершена.'
      : waitingForDate || full
        ? 'Регистрация завершена. Свободных мест больше нет.'
        : insufficient
          ? 'Недостаточно коинов для регистрации.'
          : '';

  const scheduleMarkup = scheduled && scheduledStart
    ? `<section class="tournaments-v2-tournament-schedule" aria-label="Дата и время турнира">
        <span>Начало турнира</span>
        <strong>${escapeHtml(formatTournamentDateTime(scheduledStart))}</strong>
        <div class="tournaments-v2-tournament-countdown">
          <small>До старта</small>
          <b data-tournament-countdown>${escapeHtml(formatTournamentCountdown(scheduledStart.getTime() - Date.now()))}</b>
        </div>
      </section>`
    : '';

  body.innerHTML = `
    ${errorMessage ? `<div class="tournaments-v2-tournament-error">${escapeHtml(errorMessage)}</div>` : ''}
    <div class="tournaments-v2-tournament-hero">
      <div>
        <span class="tournaments-v2-tournament-state${open && !full ? ' is-open' : ''}">${escapeHtml(statusText)}</span>
        <h3>${escapeHtml(String(tournament.title || 'Официальный турнир'))}</h3>
        <p>${escapeHtml(gameName(String(tournament.game_type || DEFAULT_GAME)))}</p>
      </div>
      <div class="tournaments-v2-tournament-entry"><small>Вход</small><strong>${escapeHtml(formatNumber(fee))}</strong><span>коинов</span></div>
    </div>

    ${scheduleMarkup}

    <div class="tournaments-v2-tournament-progress">
      <p class="tournaments-v2-tournament-capacity-copy">${scheduled ? 'Состав турнира зафиксирован. Дата назначена.' : waitingForDate ? 'Состав турнира набран. Регистрация закрыта.' : `В турнире участвуют ${escapeHtml(formatNumber(capacity))} игроков. Регистрация закроется, когда все места будут заняты.`}</p>
      <div class="tournaments-v2-tournament-participants"><span>Участники</span><strong>${escapeHtml(formatNumber(count))} / ${escapeHtml(formatNumber(capacity))}</strong></div>
      <div class="tournaments-v2-tournament-progress-track"><i style="width:${pct}%"></i></div>
    </div>

    <div class="tournaments-v2-tournament-prizes">
      <div><b>1 место</b><strong>${escapeHtml(formatNumber(Number(first.total || 200000)))}</strong><span>+ Golden Ticket</span></div>
      <div><b>2 место</b><strong>${escapeHtml(formatNumber(Number(second.total || 80000)))}</strong><span>серебряная награда</span></div>
      <div><b>3 место</b><strong>${escapeHtml(formatNumber(Number(third.total || 50000)))}</strong><span>бронзовая награда</span></div>
    </div>

    ${rulesMarkup}
    ${consentMarkup}
    ${ownStatus ? `<div class="tournaments-v2-tournament-own${registered ? ' is-registered' : ''}${insufficient ? ' is-insufficient' : ''}"><strong>${escapeHtml(ownStatus)}</strong></div>` : ''}
    ${action}
  `;

  if (scheduled && scheduledStart) {
    const countdown = body.querySelector('[data-tournament-countdown]');
    const updateCountdown = () => {
      if (!(countdown instanceof HTMLElement) || !countdown.isConnected) {
        if (tournamentCountdownTimer) window.clearInterval(tournamentCountdownTimer);
        tournamentCountdownTimer = null;
        return;
      }
      countdown.textContent = formatTournamentCountdown(scheduledStart.getTime() - Date.now());
    };
    updateCountdown();
    tournamentCountdownTimer = window.setInterval(updateCountdown, 1000);
  }
}

function parseTournamentUtc(value){
  const raw = String(value || '').trim();
  if (!raw) return null;
  let normalized = raw.replace(' ', 'T');
  normalized = normalized.replace(/(\.\d{3})\d+/, '$1');
  if (!/[zZ]|[+-]\d{2}:?\d{2}$/.test(normalized)) normalized += 'Z';
  const date = new Date(normalized);
  return Number.isNaN(date.getTime()) ? null : date;
}

function formatTournamentDateTime(value){
  const date = value instanceof Date ? value : parseTournamentUtc(value);
  if (!date) return String(value || '');
  return new Intl.DateTimeFormat('ru-RU', {
    day:'2-digit', month:'2-digit', year:'numeric',
    hour:'2-digit', minute:'2-digit', timeZoneName:'short',
  }).format(date);
}

function formatTournamentCountdown(remainingMs){
  const remaining = Math.max(0, Number(remainingMs || 0));
  if (remaining <= 0) return 'Время старта наступило';
  const totalSeconds = Math.ceil(remaining / 1000);
  const days = Math.floor(totalSeconds / 86400);
  const hours = Math.floor((totalSeconds % 86400) / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;
  const time = `${String(hours).padStart(2,'0')}:${String(minutes).padStart(2,'0')}:${String(seconds).padStart(2,'0')}`;
  return days > 0 ? `${days} дн. ${time}` : time;
}

function formatConsentTime(value){
  const date = new Date(String(value || '').replace(' ', 'T') + (String(value || '').includes('Z') ? '' : 'Z'));
  if (Number.isNaN(date.getTime())) return String(value || '');
  return new Intl.DateTimeFormat('ru-RU', {
    day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit',
  }).format(date);
}

function humanizeTournamentError(message){
  const raw = String(message || '').trim();
  const known = new Map([
    ['Insufficient available balance.', 'Недостаточно доступных коинов для взноса 50 000.'],
    ['Tournament registration requires canonical DB-primary runtime state.', 'Регистрация временно недоступна: игровое состояние ещё не переключено на основной сервер.'],
    ['Tournament registration changed concurrently.', 'Регистрация изменилась одновременно с вашим запросом. Обновите турнир и попробуйте ещё раз.'],
    ['Concurrent balance update was detected.', 'Баланс изменился одновременно с регистрацией. Попробуйте ещё раз.'],
    ['Balance identity does not match the account reference.', 'Не удалось подтвердить игровой баланс аккаунта.'],
    ['Canonical tournament account_ref is required.', 'Не удалось подтвердить игровой аккаунт для регистрации.'],
  ]);
  return known.get(raw) || raw || 'Не удалось изменить регистрацию.';
}

function bindTabs(screen){
  const tabs = screen.querySelector('#tournamentsLeaderboardTabs');
  if (!(tabs instanceof HTMLElement)) return;

  tabs.addEventListener('scroll', updateScrollAffordances, { passive:true });
  window.addEventListener('resize', updateScrollAffordances, { passive:true });

  tabs.addEventListener('wheel', event => {
    if (tabs.scrollWidth <= tabs.clientWidth + 2) return;
    const delta = Math.abs(event.deltaX) > Math.abs(event.deltaY) ? event.deltaX : event.deltaY;
    if (!Number.isFinite(delta) || delta === 0) return;
    tabs.scrollLeft += delta;
    event.preventDefault();
  }, { passive:false });

  tabs.addEventListener('pointerdown', event => {
    if (event.pointerType !== 'mouse' || event.button !== 0) return;
    dragState = {
      pointerId:event.pointerId,
      startX:event.clientX,
      startScrollLeft:tabs.scrollLeft,
      moved:false,
    };
  });

  tabs.addEventListener('pointermove', event => {
    if (!dragState || event.pointerId !== dragState.pointerId) return;
    const delta = event.clientX - dragState.startX;
    if (!dragState.moved && Math.abs(delta) < 6) return;
    if (!dragState.moved) {
      dragState.moved = true;
      try { tabs.setPointerCapture(event.pointerId); } catch (_) {}
      tabs.classList.add('is-dragging');
    }
    tabs.scrollLeft = dragState.startScrollLeft - delta;
    updateScrollAffordances();
  });

  const finishDrag = event => {
    if (!dragState || event.pointerId !== dragState.pointerId) return;
    if (dragState.moved) suppressClickUntil = performance.now() + 180;
    tabs.classList.remove('is-dragging');
    try {
      if (tabs.hasPointerCapture(event.pointerId)) tabs.releasePointerCapture(event.pointerId);
    } catch (_) {}
    dragState = null;
    updateScrollAffordances();
  };
  tabs.addEventListener('pointerup', finishDrag);
  tabs.addEventListener('pointercancel', finishDrag);

  tabs.addEventListener('click', event => {
    const button = event.target instanceof Element ? event.target.closest('[data-tournaments-game]') : null;
    if (!(button instanceof HTMLButtonElement)) return;
    if (performance.now() < suppressClickUntil) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }
    const nextGame = String(button.dataset.tournamentsGame || '').trim();
    if (!GAME_TYPES.includes(nextGame)) return;
    void activateGame(nextGame);
  });
}

function bindScrollButtons(screen){
  screen.querySelectorAll('[data-tournaments-scroll]').forEach(button => {
    button.addEventListener('click', () => {
      const tabs = document.getElementById('tournamentsLeaderboardTabs');
      if (!(tabs instanceof HTMLElement)) return;
      const direction = Number(button.dataset.tournamentsScroll || 0) < 0 ? -1 : 1;
      const amount = Math.max(180, Math.round(tabs.clientWidth * 0.72));
      tabs.scrollBy({ left:direction * amount, behavior:'smooth' });
    });
  });
}

async function activateGame(gameType){
  const next = GAME_TYPES.includes(gameType) ? gameType : DEFAULT_GAME;
  activeGame = next;
  syncActiveTab();

  const cached = currentCache(next);
  if (cached) {
    renderBoard(cached.board);
    void refreshArchiveForActiveGame();
  } else {
    const body = document.getElementById('tournamentsLeaderboardBody');
    if (body) body.innerHTML = loadingMarkup();
  }

  try {
    const board = await warmLeaderboard(next);
    if (activeGame !== next || currentScreen() !== 'tournaments') return;
    renderBoard(board);
    void refreshArchiveForActiveGame();
  } catch (error) {
    if (activeGame !== next || currentScreen() !== 'tournaments') return;
    const body = document.getElementById('tournamentsLeaderboardBody');
    if (body) body.innerHTML = `<div class="tournaments-v2-empty">${escapeHtml(error?.message || t('profile.leaderboard_error'))}</div>`;
  }
}

async function warmLeaderboard(gameType){
  const cached = currentCache(gameType);
  if (cached) return cached.board;
  if (inFlight.has(gameType)) return inFlight.get(gameType);

  const request = api.leaderboard(gameType)
    .then(result => {
      const board = result?.leaderboard && typeof result.leaderboard === 'object'
        ? result.leaderboard
        : {};
      cache.set(gameType, { board, loadedAt:Date.now() });
      return board;
    })
    .finally(() => {
      inFlight.delete(gameType);
    });

  inFlight.set(gameType, request);
  return request;
}

async function warmArchiveOverview(){
  if (archiveOverviewCache && Date.now() - archiveOverviewLoadedAt <= CACHE_TTL_MS * 2) return archiveOverviewCache;
  if (archiveOverviewPromise) return archiveOverviewPromise;
  archiveOverviewPromise = api.ratingArchiveOverview()
    .then(result => {
      const archive = result?.archive && typeof result.archive === 'object' ? result.archive : {};
      archiveOverviewCache = archive;
      archiveOverviewLoadedAt = Date.now();
      return archive;
    })
    .finally(() => { archiveOverviewPromise = null; });
  return archiveOverviewPromise;
}

async function loadArchiveOverview(){
  const body = document.getElementById('ratingArchiveBody');
  if (!(body instanceof HTMLElement)) return;
  try {
    const overview = await warmArchiveOverview();
    if (currentScreen() !== 'tournaments') return;
    const seasons = Array.isArray(overview?.seasons) ? overview.seasons : [];
    if (!seasons.length) {
      selectedArchiveSeasonId = '';
      body.innerHTML = `<div class="tournaments-v2-empty">${escapeHtml(t('shell.competition_archive_empty'))}</div>`;
      return;
    }
    if (!seasons.some(season => String(season?.season_id || '') === selectedArchiveSeasonId)) {
      selectedArchiveSeasonId = String(seasons[0]?.season_id || '');
    }
    body.innerHTML = archiveSeasonSelectorMarkup(seasons) + '<div id="ratingArchiveSeasonBoard"></div>';
    syncArchiveSeasonButtons();
    await loadArchiveSeason(selectedArchiveSeasonId, activeGame);
  } catch (error) {
    body.innerHTML = `<div class="tournaments-v2-empty">${escapeHtml(error?.message || t('profile.leaderboard_error'))}</div>`;
  }
}

async function loadArchiveSeason(seasonId, gameType){
  if (!seasonId) return;
  const target = document.getElementById('ratingArchiveSeasonBoard');
  if (!(target instanceof HTMLElement)) return;
  const key = seasonId + '|' + gameType;
  const cached = archiveSeasonCache.get(key);
  if (cached && Date.now() - Number(cached.loadedAt || 0) <= CACHE_TTL_MS * 2) {
    renderArchiveSeason(cached.archive);
    return;
  }
  target.innerHTML = loadingMarkup();
  try {
    const result = await api.ratingArchiveSeason(seasonId, gameType);
    const archive = result?.archive && typeof result.archive === 'object' ? result.archive : {};
    archiveSeasonCache.set(key, { archive, loadedAt:Date.now() });
    if (seasonId !== selectedArchiveSeasonId || gameType !== activeGame) return;
    renderArchiveSeason(archive);
  } catch (error) {
    if (seasonId !== selectedArchiveSeasonId || gameType !== activeGame) return;
    target.innerHTML = `<div class="tournaments-v2-empty">${escapeHtml(error?.message || t('profile.leaderboard_error'))}</div>`;
  }
}

async function refreshArchiveForActiveGame(){
  if (!selectedArchiveSeasonId || currentScreen() !== 'tournaments') return;
  const panel = document.querySelector('[data-rating-history-panel="seasons"]');
  if (!(panel instanceof HTMLElement) || panel.hidden) return;
  await loadArchiveSeason(selectedArchiveSeasonId, activeGame);
}

function archiveSeasonSelectorMarkup(seasons){
  return `<div class="tournaments-v2-archive-season-tabs" role="tablist" aria-label="${escapeHtml(t('shell.competition_archive_seasons'))}">
    ${seasons.map(season => {
      const seasonId = String(season?.season_id || '');
      const label = seasonLabel(season);
      const active = seasonId === selectedArchiveSeasonId;
      return `<button type="button" class="tournaments-v2-archive-season-tab${active ? ' active' : ''}" data-rating-archive-season="${escapeHtml(seasonId)}" role="tab" aria-selected="${active ? 'true' : 'false'}">${escapeHtml(label)}</button>`;
    }).join('')}
  </div>`;
}

function syncArchiveSeasonButtons(){
  document.querySelectorAll('[data-rating-archive-season]').forEach(button => {
    const active = String(button.dataset.ratingArchiveSeason || '') === selectedArchiveSeasonId;
    button.classList.toggle('active', active);
    button.setAttribute('aria-selected', active ? 'true' : 'false');
  });
}

function renderArchiveSeason(archive){
  const target = document.getElementById('ratingArchiveSeasonBoard');
  if (!(target instanceof HTMLElement)) return;
  const entries = Array.isArray(archive?.entries) ? archive.entries : [];
  const season = archive?.season && typeof archive.season === 'object' ? archive.season : {};
  const top3 = hallOfFameForArchive(String(season?.season_id || ''), activeGame, archive);
  target.innerHTML = `
    <div class="tournaments-v2-archive-season-head">
      <div><strong>${escapeHtml(seasonLabel(season))}</strong><span>${escapeHtml(gameName(activeGame))}</span></div>
      <small>${escapeHtml(t('shell.competition_archive_top100'))}</small>
    </div>
    ${top3.length ? `<div class="tournaments-v2-hof"><div class="tournaments-v2-hof-title">${escapeHtml(t('shell.competition_hall_of_fame'))}</div><div class="tournaments-v2-hof-grid">${top3.map(hallOfFameCard).join('')}</div></div>` : ''}
    <div class="tournaments-v2-table-head" aria-hidden="true"><span>№</span><span>Игрок</span><span>Очки</span></div>
    <div class="tournaments-v2-list">
      ${entries.length ? entries.map(leaderboardRow).join('') : `<div class="tournaments-v2-empty">${escapeHtml(t('profile.leaderboard_empty'))}</div>`}
    </div>
  `;
}

function hallOfFameForArchive(seasonId, gameType, archive){
  const overview = archiveOverviewCache && typeof archiveOverviewCache === 'object' ? archiveOverviewCache : {};
  const durable = Array.isArray(overview.hall_of_fame)
    ? overview.hall_of_fame.filter(item => String(item?.season_id || '') === seasonId && String(item?.game_type || '') === gameType)
    : [];
  if (durable.length) return durable.slice(0, 3);
  return Array.isArray(archive?.top3) ? archive.top3.slice(0, 3) : [];
}

function hallOfFameCard(entry){
  const rank = Math.max(1, Number(entry?.rank || 1));
  const nickname = String(entry?.nickname || t('profile.player')).trim() || t('profile.player');
  const avatar = String(entry?.avatar_item_id || 'starter-default-01').trim() || 'starter-default-01';
  return `<article class="tournaments-v2-hof-card top-${rank}"><b>#${escapeHtml(formatNumber(rank))}</b><span class="tournaments-v2-avatar" data-avatar-item-id="${escapeHtml(avatar)}" aria-hidden="true">MG</span><strong>${escapeHtml(nickname)}</strong></article>`;
}

function seasonLabel(season){
  const year = Math.max(0, Number(season?.calendar_year || 0));
  const quarter = Math.max(1, Number(season?.quarter || 1));
  return year > 0 ? `Q${quarter} · ${year}` : String(season?.season_id || '');
}

function currentCache(gameType){
  const item = cache.get(gameType);
  if (!item || Date.now() - Number(item.loadedAt || 0) > CACHE_TTL_MS) return null;
  return item;
}

function syncActiveTab(){
  document.querySelectorAll('#tournamentsLeaderboardTabs [data-tournaments-game]').forEach(button => {
    const active = String(button.dataset.tournamentsGame || '') === activeGame;
    button.classList.toggle('active', active);
    button.setAttribute('aria-selected', active ? 'true' : 'false');
    if (active) {
      button.scrollIntoView({ block:'nearest', inline:'nearest', behavior:'smooth' });
    }
  });
  window.requestAnimationFrame(updateScrollAffordances);
}

function updateScrollAffordances(){
  const tabs = document.getElementById('tournamentsLeaderboardTabs');
  const root = document.getElementById('tournamentsV2Root');
  if (!(tabs instanceof HTMLElement) || !(root instanceof HTMLElement)) return;

  const max = Math.max(0, tabs.scrollWidth - tabs.clientWidth);
  const left = tabs.scrollLeft > 3;
  const right = tabs.scrollLeft < max - 3;
  root.classList.toggle('can-scroll-left', left);
  root.classList.toggle('can-scroll-right', right);

  const leftButton = root.querySelector('[data-tournaments-scroll="-1"]');
  const rightButton = root.querySelector('[data-tournaments-scroll="1"]');
  if (leftButton instanceof HTMLButtonElement) leftButton.disabled = !left;
  if (rightButton instanceof HTMLButtonElement) rightButton.disabled = !right;
}

function renderBoard(board){
  const body = document.getElementById('tournamentsLeaderboardBody');
  if (!body) return;
  const data = board && typeof board === 'object' ? board : {};
  const entries = Array.isArray(data.entries) ? data.entries : [];
  const gameType = GAME_TYPES.includes(String(data.game_type || ''))
    ? String(data.game_type)
    : activeGame;

  body.innerHTML = `
    <div class="tournaments-v2-current-game">${escapeHtml(gameName(gameType))}</div>
    <div class="tournaments-v2-table-head" aria-hidden="true">
      <span>№</span>
      <span>Игрок</span>
      <span>Очки</span>
    </div>
    <div class="tournaments-v2-list">
      ${entries.length
        ? entries.map(leaderboardRow).join('')
        : `<div class="tournaments-v2-empty">${escapeHtml(t('profile.leaderboard_empty'))}</div>`}
    </div>
  `;
}

function leaderboardRow(entry){
  const rank = Math.max(1, Number(entry?.rank || 1));
  const nickname = String(entry?.nickname || t('profile.player')).trim() || t('profile.player');
  const avatar = String(entry?.avatar_item_id || 'starter-default-01').trim() || 'starter-default-01';
  const points = Math.max(0, Number(entry?.points || 0));
  const rankClass = rank <= 3 ? ` top-${rank}` : '';
  return `<div class="tournaments-v2-row${rankClass}">
    <b class="tournaments-v2-rank">${escapeHtml(formatNumber(rank))}</b>
    <span class="tournaments-v2-avatar" data-avatar-item-id="${escapeHtml(avatar)}" aria-hidden="true">MG</span>
    <strong class="tournaments-v2-name">${escapeHtml(nickname)}</strong>
    <span class="tournaments-v2-points" aria-label="${escapeHtml(t('profile.rating_points'))}: ${escapeHtml(formatNumber(points))}">${escapeHtml(formatNumber(points))}</span>
  </div>`;
}

function scrollIcon(direction){
  const path = direction === 'left' ? 'M9.5 3.5 5 8l4.5 4.5' : 'M6.5 3.5 11 8l-4.5 4.5';
  return `<svg class="tournaments-v2-scroll-icon" viewBox="0 0 16 16" aria-hidden="true"><path d="${path}"/></svg>`;
}

function tabMarkup(gameType, current){
  return `<button class="tournaments-v2-tab${gameType === current ? ' active' : ''}" type="button" role="tab" aria-selected="${gameType === current ? 'true' : 'false'}" data-tournaments-game="${escapeHtml(gameType)}">${escapeHtml(gameName(gameType))}</button>`;
}

function loadingMarkup(){
  return `<div class="tournaments-v2-empty">${escapeHtml(t('common.loading'))}</div>`;
}

function gameName(gameType){
  try { return t(`games.${gameType}.name`); } catch (_) { return gameType; }
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'",'&#39;');
}
