import { api } from '../api/client.js?v=47';
import { currentScreen, onScreenEnter } from '../router.js?v=27';
import { t, formatNumber } from '@mgw/i18n';

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

export function initTournamentsScreen(){
  if (initialized) return;
  const screen = document.getElementById('screen-tournaments');
  const content = screen?.querySelector('.content');
  if (!(screen instanceof HTMLElement) || !(content instanceof HTMLElement)) return;

  initialized = true;
  screen.dataset.mgwTournaments = 'leaderboards-v2 rating-archive-v1 official-tournament-registration-v1';
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
            <div>
              <h2>Официальный турнир</h2>
              <p>Регистрация, зарезервированный взнос и текущий состав турнира.</p>
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
      return tournamentSnapshot;
    })
    .finally(() => { tournamentRequest = null; });
  return tournamentRequest;
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
    const fee = formatNumber(Math.max(0, Number(tournament?.entry_fee?.amount || 50000)));
    if (!window.confirm(`Зарезервировать ${fee} коинов и зарегистрироваться на официальный турнир?`)) return;
  } else if (action === 'leave') {
    if (!window.confirm('Отменить регистрацию? Зарезервированные 50 000 коинов вернутся в доступный баланс.')) return;
  }

  tournamentBusy = true;
  renderTournamentSnapshot();
  try {
    const result = action === 'register'
      ? await api.tournamentRegister()
      : await api.tournamentLeave();
    tournamentSnapshot = result?.snapshot && typeof result.snapshot === 'object' ? result.snapshot : {};
    renderTournamentSnapshot();
  } catch (error) {
    renderTournamentSnapshot(error?.message || 'Не удалось изменить регистрацию.');
  } finally {
    tournamentBusy = false;
    renderTournamentSnapshot();
  }
}

function renderTournamentSnapshot(errorMessage = ''){
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
  const full = tournament.is_full === true;
  const capacity = Math.max(1, Number(tournament.capacity || 0));
  const count = Math.max(0, Number(tournament.registered_count || 0));
  const pct = Math.max(0, Math.min(100, Math.round((count / capacity) * 100)));
  const fee = Math.max(0, Number(tournament?.entry_fee?.amount || 50000));
  const available = Math.max(0, Number(snapshot?.balance?.available_amount || 0));
  const reserved = Math.max(0, Number(snapshot?.balance?.reserved_amount || 0));
  const rewards = tournament.reward_snapshot && typeof tournament.reward_snapshot === 'object'
    ? tournament.reward_snapshot
    : {};
  const first = rewards?.placements?.['1'] || {};
  const second = rewards?.placements?.['2'] || {};
  const third = rewards?.placements?.['3'] || {};

  let action = '';
  if (open && !registered && !full) {
    action = `<button type="button" class="tournaments-v2-tournament-action" data-tournament-action="register"${tournamentBusy ? ' disabled' : ''}>Зарегистрироваться · ${escapeHtml(formatNumber(fee))}</button>`;
  } else if (open && registered && !full) {
    action = `<button type="button" class="tournaments-v2-tournament-action tournaments-v2-tournament-action--secondary" data-tournament-action="leave"${tournamentBusy ? ' disabled' : ''}>Отменить регистрацию</button>`;
  }

  const statusText = state === 'draft'
    ? 'Турнир готовится · регистрация ещё не открыта'
    : full
      ? 'Состав заполнен'
      : 'Регистрация открыта';

  const ownStatus = registered
    ? (full
      ? 'Вы в составе. Турнир заполнен — место зафиксировано.'
      : `Вы зарегистрированы. ${formatNumber(fee)} коинов зарезервировано, но не списано.`)
    : full
      ? 'Свободных мест больше нет.'
      : open
        ? 'Взнос резервируется до дальнейшего этапа турнира.'
        : 'Ожидайте открытия регистрации.';

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

    <div class="tournaments-v2-tournament-progress">
      <div><span>Участники</span><strong>${escapeHtml(formatNumber(count))} / ${escapeHtml(formatNumber(capacity))}</strong></div>
      <div class="tournaments-v2-tournament-progress-track"><i style="width:${pct}%"></i></div>
    </div>

    <div class="tournaments-v2-tournament-prizes">
      <div><b>1 место</b><strong>${escapeHtml(formatNumber(Number(first.total || 200000)))}</strong><span>+ Golden Ticket</span></div>
      <div><b>2 место</b><strong>${escapeHtml(formatNumber(Number(second.total || 80000)))}</strong><span>серебряная награда</span></div>
      <div><b>3 место</b><strong>${escapeHtml(formatNumber(Number(third.total || 50000)))}</strong><span>возврат взноса</span></div>
    </div>

    <div class="tournaments-v2-tournament-own${registered ? ' is-registered' : ''}">
      <strong>${escapeHtml(ownStatus)}</strong>
      <span>Доступно: ${escapeHtml(formatNumber(available))} · Зарезервировано: ${escapeHtml(formatNumber(reserved))}</span>
    </div>
    ${action}
  `;
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
