(() => {
  'use strict';

  const root = document.querySelector('[data-admin-antifraud]');
  const shell = document.querySelector('[data-replay-api]');
  if (!root || !shell) return;

  const endpoint = String(shell.dataset.replayApi || '');
  const telegram = window.Telegram?.WebApp || null;

  const status = root.querySelector('[data-af-status]');
  const home = root.querySelector('[data-af-home]');
  const homeButtons = Array.from(root.querySelectorAll('[data-af-home-mode]'));
  const homePanels = Array.from(root.querySelectorAll('[data-af-home-panel]'));
  const modeButtons = Array.from(root.querySelectorAll('[data-af-mode]'));
  const queryInput = root.querySelector('[data-af-query]');
  const refreshButton = root.querySelector('[data-af-refresh]');
  const queue = root.querySelector('[data-af-queue]');
  const queueTitle = root.querySelector('[data-af-queue-title]');
  const recentMatch = root.querySelector('[data-af-recent-match]');
  const matchInput = root.querySelector('[data-af-match-id]');
  const matchLoad = root.querySelector('[data-af-match-load]');

  const reviewBox = root.querySelector('[data-af-review]');
  const reviewSubtitle = root.querySelector('[data-af-review-subtitle]');
  const backButton = root.querySelector('[data-af-back]');
  const reviewTabs = Array.from(root.querySelectorAll('[data-af-review-tab]'));
  const reviewPanels = Array.from(root.querySelectorAll('[data-af-review-panel]'));
  const matchSummary = root.querySelector('[data-af-match-summary]');
  const policy = root.querySelector('[data-af-policy]');
  const signals = root.querySelector('[data-af-signals]');

  const pairHistory = root.querySelector('[data-af-pair-history]');
  const deviceSession = root.querySelector('[data-af-device-session]');

  const replayStatus = root.querySelector('[data-af-replay-status]');
  const player = root.querySelector('[data-af-player]');
  const prevButton = root.querySelector('[data-af-prev]');
  const playButton = root.querySelector('[data-af-play]');
  const nextButton = root.querySelector('[data-af-next]');
  const speedSelect = root.querySelector('[data-af-speed]');
  const progress = root.querySelector('[data-af-progress]');
  const playerMeta = root.querySelector('[data-af-player-meta]');
  const playerState = root.querySelector('[data-af-player-state]');
  const playerEvents = root.querySelector('[data-af-player-events]');
  const rawTimeline = root.querySelector('[data-af-raw-timeline]');
  const rawFrames = root.querySelector('[data-af-raw-frames]');

  const caseBox = root.querySelector('[data-af-case]');
  const caseSummary = root.querySelector('[data-af-case-summary]');
  const createCaseButton = root.querySelector('[data-af-create-case]');
  const takeCaseButton = root.querySelector('[data-af-take-case]');
  const decisionBox = root.querySelector('[data-af-decisions]');
  const decisionNote = root.querySelector('[data-af-decision-note]');
  const decisionButtons = Array.from(root.querySelectorAll('[data-af-decision]'));
  const openProcessedButton = root.querySelector('[data-af-open-processed]');

  let mode = 'active';
  let homeMode = 'match';
  let reviewTab = 'overview';
  let busy = false;
  let currentReview = null;
  let currentCase = null;
  let frames = [];
  let frameIndex = 0;
  let playing = false;
  let timer = 0;

  const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, ch => ({
    '&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'
  })[ch]);

  const post = async payload => {
    const response = await fetch(endpoint, {
      method:'POST',
      cache:'no-store',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({...payload, initData:telegram?.initData || ''}),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.ok !== true) {
      throw new Error(String(data.error || 'Не удалось выполнить запрос проверки.'));
    }
    return data;
  };

  const filters = () => ({
    mode,
    query:String(queryInput?.value || '').trim(),
  });

  const setStatus = (message, state = '') => {
    status.textContent = message;
    if (state) status.dataset.state = state;
    else delete status.dataset.state;
  };

  const setBusy = value => {
    busy = value;
    root.setAttribute('aria-busy', value ? 'true' : 'false');
    [
      refreshButton, matchLoad, recentMatch, matchInput, queryInput,
      createCaseButton, takeCaseButton, decisionNote, speedSelect,
      prevButton, playButton, nextButton, progress
    ].filter(Boolean).forEach(node => {
      node.disabled = value;
    });
    decisionButtons.forEach(node => { node.disabled = value; });
    modeButtons.forEach(node => { node.disabled = value; });
    homeButtons.forEach(node => { node.disabled = value; });
    reviewTabs.forEach(node => { node.disabled = value; });
    if (!value) {
      renderCaseActions();
      renderFrame();
    }
  };

  const statusLabel = value => ({
    open:'Новый',
    reviewing:'На проверке',
    closed:'Обработан',
    finished:'Завершён',
    active:'Идёт',
    cancelled:'Отменён',
  })[String(value || '')] || String(value || '—');

  const decisionLabel = value => ({
    pending:'Решение не принято',
    cleared:'Нарушений не найдено',
    monitor:'Оставлен под наблюдением',
    moderation_review:'Передан в модерацию',
    rating_review:'Передан на проверку рейтинга',
  })[String(value || '')] || String(value || '—');

  const priorityLabel = value => ({
    high:'Высокий',
    normal:'Обычный',
    low:'Низкий',
  })[String(value || '')] || String(value || '—');

  const severityLabel = value => ({
    high:'Высокий',
    medium:'Средний',
    low:'Информация',
  })[String(value || '')] || 'Информация';

  const gameLabel = value => ({
    tictactoe:'Крестики-нолики',
    tic_tac_toe:'Крестики-нолики',
    four_in_a_row:'Четыре в ряд',
    battleship:'Морской бой',
    checkers:'Русские шашки',
    reversi:'Реверси',
    chess:'Шахматы',
    go:'Го',
    domino:'Домино',
  })[String(value || '')] || String(value || 'Игра');

  const eventLabel = value => ({
    match_started:'Начало матча',
    move:'Ход',
    action:'Действие',
    result:'Результат',
    match_finished:'Завершение матча',
    timeout:'Тайм-аут',
    reconnect:'Переподключение',
    disconnect:'Отключение',
  })[String(value || '')] || 'Событие матча';

  const finishLabel = value => ({
    normal_win:'Обычная победа',
    draw:'Ничья',
    timeout:'Победа по времени',
    forfeit:'Технический результат',
    cancelled:'Отменён',
  })[String(value || '')] || String(value || '—');

  const localTime = value => {
    if (!value) return '—';
    const raw = String(value);
    const normalized = raw.replace(' ', 'T') + (raw.includes('Z') || /[+-]\d\d:\d\d$/.test(raw) ? '' : 'Z');
    const date = new Date(normalized);
    return Number.isNaN(date.getTime()) ? raw : date.toLocaleString('ru-RU');
  };

  const playerName = ref => {
    const players = Array.isArray(currentReview?.replay?.players) ? currentReview.replay.players : [];
    const value = String(ref || '');
    const player = players.find(item =>
      String(item.player_ref || '') === value ||
      String(item.mgw_id || '') === value
    );
    return player ? String(player.display_name || player.mgw_id || player.player_ref || 'Игрок') : (value || 'Система');
  };

  const signalExplanation = signal => {
    const code = String(signal?.code || '');
    const details = signal?.details || {};
    if (code === 'shared_device_match_window') return 'У игроков найден общий идентификатор устройства в период этого матча. Это повод проверить матч вручную, но не доказательство нарушения.';
    if (code === 'shared_device_history') return 'У игроков найден общий идентификатор устройства в истории. Проверьте контекст предыдущих матчей.';
    if (code === 'repeat_pair_24h') return 'Эта пара играла друг с другом много раз за короткий период. Сравните историю пары и результат матча.';
    if (code === 'rating_antifarming_limited') return 'Система рейтинга уже ограничила начисление очков из-за повторных побед над тем же соперником.';
    if (code === 'replay_integrity_gap') return 'В сохранённой цепочке повтора есть пропуски. Пошаговый повтор может быть неполным.';
    if (code === 'manual_review') return 'Кейс создан администратором вручную без автоматического сигнала.';
    const reason = String(details.reason || details.message || '').trim();
    return reason || 'Сигнал требует ручной проверки администратором.';
  };

  const showHome = nextMode => {
    stopPlayer();
    currentReview = null;
    reviewBox.hidden = true;
    home.hidden = false;
    homeMode = nextMode || 'match';
    if (homeMode === 'active' || homeMode === 'closed') mode = homeMode;

    homeButtons.forEach(button => {
      button.classList.toggle('is-active', String(button.dataset.afHomeMode || '') === homeMode);
    });
    homePanels.forEach(panel => {
      const panelMode = String(panel.dataset.afHomePanel || '');
      panel.hidden = homeMode === 'match' ? panelMode !== 'match' : panelMode !== 'cases';
    });

    if (homeMode !== 'match') {
      void loadSnapshot();
    } else {
      setStatus('Выберите недавний матч или вставьте его ID.', 'ok');
    }
    root.scrollIntoView({block:'start', behavior:'smooth'});
  };

  const showReviewTab = name => {
    reviewTab = name || 'overview';
    reviewTabs.forEach(button => {
      button.classList.toggle('is-active', String(button.dataset.afReviewTab || '') === reviewTab);
    });
    reviewPanels.forEach(panel => {
      panel.hidden = String(panel.dataset.afReviewPanel || '') !== reviewTab;
    });
    if (reviewTab === 'replay') renderFrame();
  };

  const openReviewWorkspace = preferredTab => {
    home.hidden = true;
    reviewBox.hidden = false;
    showReviewTab(preferredTab || 'overview');
    reviewBox.scrollIntoView({block:'start', behavior:'smooth'});
  };

  const renderRecentMatches = matches => {
    if (!recentMatch) return;
    const selected = String(recentMatch.value || '');
    recentMatch.replaceChildren();
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Выберите матч из списка';
    recentMatch.append(placeholder);

    (Array.isArray(matches) ? matches : []).forEach(match => {
      const option = document.createElement('option');
      option.value = String(match.match_id || '');
      const names = (match.players || [])
        .map(player => player.display_name || player.mgw_id || 'Игрок')
        .filter(Boolean)
        .join(' — ');
      option.textContent = gameLabel(match.game_type) + ' · ' + (names || 'участники') + ' · ' + localTime(match.finished_at);
      recentMatch.append(option);
    });
    if (Array.from(recentMatch.options).some(option => option.value === selected)) {
      recentMatch.value = selected;
    }
  };

  const renderQueue = cases => {
    queue.replaceChildren();
    queueTitle.textContent = mode === 'closed' ? 'Обработанные кейсы' : 'Активные кейсы';

    if (!Array.isArray(cases) || cases.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'mgw-admin__af-empty';
      empty.textContent = mode === 'closed'
        ? 'Обработанных кейсов пока нет.'
        : 'Активных кейсов пока нет.';
      queue.append(empty);
      return;
    }

    cases.forEach(item => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'mgw-admin__af-case-card';
      button.dataset.caseId = String(item.case_id || '');
      button.innerHTML =
        '<span class="mgw-admin__af-case-top">' +
          '<strong>' + escapeHtml(item.case_id || 'Кейс') + '</strong>' +
          '<em data-tone="' + escapeHtml(item.priority || 'normal') + '">' + escapeHtml(priorityLabel(item.priority)) + '</em>' +
        '</span>' +
        '<span class="mgw-admin__af-case-match">' + escapeHtml(gameLabel(item.game_type)) + ' · ' + escapeHtml(item.match_id || '—') + '</span>' +
        '<span class="mgw-admin__af-case-meta">' + escapeHtml(statusLabel(item.status)) + ' · сигналов: ' + Number(item.signal_count || 0) + '</span>';
      if (currentCase && String(currentCase.case_id || '') === String(item.case_id || '')) {
        button.setAttribute('aria-current','true');
      }
      button.addEventListener('click', () => void openCase(String(item.case_id || '')));
      queue.append(button);
    });
  };

  const renderMatchSummary = review => {
    matchSummary.replaceChildren();
    const replay = review?.replay || {};
    const match = replay.match || {};
    const players = Array.isArray(replay.players) ? replay.players : [];
    const diagnostics = replay.diagnostics || {};
    const rows = [
      ['Игра', gameLabel(match.game_type)],
      ['Игроки', players.map(p => p.display_name || p.mgw_id || p.player_ref).join(' — ') || '—'],
      ['Статус', statusLabel(match.status)],
      ['Сигналы', String(Array.isArray(review?.signals) ? review.signals.length : 0)],
      ['Повтор', diagnostics.replayable === true && (replay.frames || []).length > 1 ? 'Доступен' : 'Ограничен'],
      ['Матч', match.match_id || '—'],
    ];

    rows.forEach(([label,value]) => {
      const row = document.createElement('div');
      const key = document.createElement('span');
      const strong = document.createElement('strong');
      key.textContent = label;
      strong.textContent = String(value);
      row.append(key,strong);
      matchSummary.append(row);
    });

    const names = players.map(p => p.display_name || p.mgw_id || p.player_ref).filter(Boolean).join(' — ');
    reviewSubtitle.textContent = gameLabel(match.game_type) + (names ? ' · ' + names : '') + ' · ' + String(match.match_id || '');
  };

  const renderSignals = review => {
    signals.replaceChildren();
    const list = Array.isArray(review?.signals) ? review.signals : [];

    if (list.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'mgw-admin__af-empty mgw-admin__af-empty--ok';
      empty.innerHTML = '<strong>Автоматических сигналов нет</strong><span>Это не означает автоматическое одобрение: при необходимости всё равно можно посмотреть повтор и историю пары.</span>';
      signals.append(empty);
      return;
    }

    list.forEach(signal => {
      const item = document.createElement('article');
      item.className = 'mgw-admin__af-signal';
      item.dataset.severity = String(signal.severity || 'low');

      const head = document.createElement('div');
      const title = document.createElement('strong');
      const badge = document.createElement('span');
      title.textContent = String(signal.label || 'Сигнал');
      badge.textContent = severityLabel(signal.severity);
      head.append(title,badge);

      const copy = document.createElement('p');
      copy.textContent = signalExplanation(signal);
      item.append(head,copy);
      signals.append(item);
    });
  };

  const renderPairHistory = review => {
    pairHistory.replaceChildren();
    const list = Array.isArray(review?.pair_history) ? review.pair_history : [];

    if (list.length === 0) {
      pairHistory.innerHTML = '<div class="mgw-admin__af-empty"><strong>История пары недоступна</strong><span>Для этого матча нет двух канонических MGW-ID или предыдущих матчей пары.</span></div>';
      return;
    }

    list.forEach(item => {
      const row = document.createElement('div');
      row.className = 'mgw-admin__af-history-row';
      const winner = item.winner_player_ref
        ? playerName(item.winner_player_ref)
        : 'Не определён';
      row.innerHTML =
        '<strong>' + escapeHtml(gameLabel(item.game_type)) + '</strong>' +
        '<span>' + escapeHtml(finishLabel(item.finish_reason || item.status)) + '</span>' +
        '<span>Победитель: ' + escapeHtml(winner) + '</span>' +
        '<em>' + escapeHtml(localTime(item.finished_at_utc || item.started_at_utc || item.created_at_utc)) + '</em>' +
        '<small>' + escapeHtml(item.match_id || '—') + '</small>';
      pairHistory.append(row);
    });
  };

  const renderDeviceSession = review => {
    deviceSession.replaceChildren();
    const data = review?.device_session || {};
    const shared = Array.isArray(data.shared_device_hash_prefixes) ? data.shared_device_hash_prefixes : [];
    const sharedWindow = Array.isArray(data.shared_match_window_device_hash_prefixes)
      ? data.shared_match_window_device_hash_prefixes : [];

    const verdict = document.createElement('div');
    verdict.className = 'mgw-admin__af-device-verdict';
    if (sharedWindow.length > 0) {
      verdict.dataset.tone = 'warn';
      verdict.innerHTML = '<strong>Есть совпадение устройства во время матча</strong><span>Нужно проверить контекст. Совпадение само по себе не является санкцией.</span>';
    } else if (shared.length > 0) {
      verdict.dataset.tone = 'note';
      verdict.innerHTML = '<strong>Есть совпадение устройства в истории</strong><span>Во время этого матча прямое совпадение не зафиксировано.</span>';
    } else {
      verdict.dataset.tone = 'ok';
      verdict.innerHTML = '<strong>Явных совпадений устройств не найдено</strong><span>Доступные device/session данные не показывают общего device-key.</span>';
    }
    deviceSession.append(verdict);

    const summary = document.createElement('div');
    summary.className = 'mgw-admin__af-device-summary';
    summary.innerHTML =
      '<div><span>Совпадений в истории</span><strong>' + shared.length + '</strong></div>' +
      '<div><span>Совпадений во время матча</span><strong>' + sharedWindow.length + '</strong></div>';
    deviceSession.append(summary);

    const players = data.players || {};
    if (Object.keys(players).length === 0) {
      const empty = document.createElement('div');
      empty.className = 'mgw-admin__af-empty';
      empty.textContent = 'Подробные device/session данные для этого матча недоступны.';
      deviceSession.append(empty);
      return;
    }

    Object.entries(players).forEach(([mgwId,player]) => {
      const details = document.createElement('details');
      details.className = 'mgw-admin__af-session-player';
      const head = document.createElement('summary');
      const sessions = Array.isArray(player.sessions) ? player.sessions : [];
      head.textContent = mgwId + ' · сессий: ' + sessions.length;
      details.append(head);

      sessions.forEach(session => {
        const row = document.createElement('div');
        row.className = 'mgw-admin__af-session-row';
        row.innerHTML =
          '<strong>' + escapeHtml(session.provider || 'Сессия') + '</strong>' +
          '<span>Устройство: ' + escapeHtml(session.device_hash_prefix || '—') + '</span>' +
          '<span>' + (session.overlaps_match ? 'Активна во время матча' : 'Вне времени матча') + '</span>' +
          '<em>' + escapeHtml(localTime(session.issued_at)) + ' → ' + escapeHtml(localTime(session.expires_at)) + '</em>';
        details.append(row);
      });
      deviceSession.append(details);
    });
  };

  const stopPlayer = () => {
    playing = false;
    if (timer) window.clearTimeout(timer);
    timer = 0;
    playButton.textContent = '▶ Воспроизвести';
    playButton.setAttribute('aria-label','Воспроизвести пошаговый повтор');
  };

  const frameDelay = (from, to) => {
    const speed = Math.max(0.25, Number(speedSelect.value || 1));
    const a = Date.parse(String(from?.created_at_utc || '').replace(' ', 'T') + 'Z');
    const b = Date.parse(String(to?.created_at_utc || '').replace(' ', 'T') + 'Z');
    const delta = Number.isFinite(a) && Number.isFinite(b) && b > a ? b - a : 1000;
    return Math.max(500, Math.min(2200, delta / speed));
  };

  const scheduleNext = () => {
    if (!playing) return;
    if (frameIndex >= frames.length - 1) {
      stopPlayer();
      setStatus('Повтор завершён. Можно вернуться к любому шагу.', 'ok');
      return;
    }
    timer = window.setTimeout(() => {
      frameIndex += 1;
      renderFrame();
      scheduleNext();
    }, frameDelay(frames[frameIndex], frames[frameIndex + 1]));
  };

  const stateSummary = frame => {
    const publicState = frame?.public_state && typeof frame.public_state === 'object' ? frame.public_state : {};
    const serverState = frame?.server_state && typeof frame.server_state === 'object' ? frame.server_state : {};
    const phase = publicState.phase || serverState.phase || publicState.status || serverState.status || '';
    const turn = publicState.turn || serverState.turn || publicState.turn_player_ref || serverState.turn_player_ref || '';
    const winner = publicState.winner || serverState.winner || publicState.winner_player_ref || serverState.winner_player_ref || '';

    const parts = [];
    if (phase) parts.push('<strong>Этап:</strong> ' + escapeHtml(statusLabel(phase)));
    if (turn) parts.push('<strong>Ход:</strong> ' + escapeHtml(playerName(turn)));
    if (winner) parts.push('<strong>Победитель:</strong> ' + escapeHtml(playerName(winner)));
    if (parts.length === 0) {
      parts.push('<span>Состояние сохранено. Полный снимок доступен ниже в «Технических данных».</span>');
    }
    return parts.join('<br>');
  };

  const renderReplayAvailability = review => {
    const replay = review?.replay || {};
    const diagnostics = replay.diagnostics || {};
    frames = Array.isArray(replay.frames) ? replay.frames : [];
    frameIndex = 0;
    stopPlayer();

    const missing = Array.isArray(diagnostics.missing_snapshot_versions) ? diagnostics.missing_snapshot_versions : [];

    if (frames.length === 0) {
      player.hidden = true;
      replayStatus.dataset.state = 'error';
      replayStatus.innerHTML = '<strong>Пошаговый повтор недоступен</strong><span>Для этого матча не сохранены состояния, из которых можно собрать повтор.</span>';
      return;
    }

    if (frames.length === 1) {
      player.hidden = true;
      replayStatus.dataset.state = 'warn';
      replayStatus.innerHTML = '<strong>Пошаговый повтор недоступен</strong><span>Сохранён только один снимок состояния. Кнопки воспроизведения поэтому не используются. Технические события можно открыть ниже.</span>';
      return;
    }

    player.hidden = false;
    if (diagnostics.replayable !== true) {
      replayStatus.dataset.state = 'warn';
      replayStatus.innerHTML = '<strong>Повтор доступен частично</strong><span>В цепочке есть пропуски' + (missing.length ? ': ' + escapeHtml(missing.join(', ')) : '') + '. Смотрите шаги как диагностическую информацию.</span>';
    } else {
      replayStatus.dataset.state = 'ok';
      replayStatus.innerHTML = '<strong>Повтор готов</strong><span>Доступно шагов: ' + frames.length + '. Нажмите «Воспроизвести» или переходите по шагам вручную.</span>';
    }
    renderFrame();
  };

  const renderFrame = () => {
    if (!player || player.hidden || frames.length === 0) return;
    frameIndex = Math.max(0, Math.min(frames.length - 1, frameIndex));
    const frame = frames[frameIndex] || {};
    const events = Array.isArray(frame.events) ? frame.events : [];

    progress.max = String(Math.max(0, frames.length - 1));
    progress.value = String(frameIndex);
    prevButton.disabled = busy || frameIndex <= 0;
    nextButton.disabled = busy || frameIndex >= frames.length - 1;
    playButton.disabled = busy || frames.length < 2;

    playerMeta.textContent = 'Шаг ' + (frameIndex + 1) + ' из ' + frames.length + ' · ' + localTime(frame.created_at_utc);

    if (events.length === 0) {
      playerEvents.innerHTML = '<strong>Сохранено состояние матча</strong><span>Отдельного события на этом шаге нет.</span>';
    } else {
      playerEvents.innerHTML = events.map(event =>
        '<div class="mgw-admin__af-step-event">' +
          '<strong>' + escapeHtml(eventLabel(event.event_type)) + '</strong>' +
          '<span>' + escapeHtml(playerName(event.actor_user_id)) + '</span>' +
          '<em>' + escapeHtml(localTime(event.occurred_at_utc)) + '</em>' +
        '</div>'
      ).join('');
    }

    playerState.innerHTML = stateSummary(frame);
  };

  const renderRawReplay = review => {
    rawTimeline.replaceChildren();
    rawFrames.replaceChildren();
    const replay = review?.replay || {};
    const timeline = Array.isArray(replay.timeline) ? replay.timeline : [];
    const replayFrames = Array.isArray(replay.frames) ? replay.frames : [];

    const add = (target, title, meta, payload) => {
      const details = document.createElement('details');
      details.className = 'mgw-admin__replay-item';
      const summary = document.createElement('summary');
      const strong = document.createElement('strong');
      const span = document.createElement('span');
      strong.textContent = title;
      span.textContent = meta;
      summary.append(strong,span);
      const pre = document.createElement('pre');
      pre.textContent = JSON.stringify(payload, null, 2);
      details.append(summary,pre);
      target.append(details);
    };

    timeline.forEach(event => add(
      rawTimeline,
      eventLabel(event.event_type) + ' · rev ' + event.primary_revision + '.' + event.event_ordinal,
      localTime(event.occurred_at_utc) + ' · state v' + (event.snapshot_state_version || '—'),
      event
    ));
    replayFrames.forEach(frame => add(
      rawFrames,
      'Снимок состояния v' + (frame.state_version || '—'),
      localTime(frame.created_at_utc) + ' · событий ' + (Array.isArray(frame.events) ? frame.events.length : 0),
      frame
    ));
  };

  const renderCaseActions = () => {
    const hasReview = currentReview && currentReview.replay?.match?.match_id;
    caseBox.hidden = !hasReview;
    if (!hasReview) return;

    const item = currentCase || currentReview.case || null;
    currentCase = item;

    createCaseButton.hidden = item !== null;
    takeCaseButton.hidden = !item || item.status !== 'open';
    decisionBox.hidden = !item || item.status !== 'reviewing';
    openProcessedButton.hidden = !item || item.status !== 'closed';

    if (!item) {
      caseSummary.innerHTML = '<strong>Кейс не создан</strong><span>Если матч требует отдельного решения, создайте кейс. Само создание ничего не блокирует.</span>';
      return;
    }

    if (item.status === 'open') {
      caseSummary.innerHTML = '<strong>' + escapeHtml(item.case_id) + ' · Новый</strong><span>Кейс создан. Нажмите «Взять в работу», чтобы назначить себя и перейти к решению.</span>';
      return;
    }

    if (item.status === 'reviewing') {
      caseSummary.innerHTML = '<strong>' + escapeHtml(item.case_id) + ' · На проверке</strong><span>Добавьте короткий комментарий и выберите итог проверки ниже.</span>';
      return;
    }

    caseSummary.innerHTML = '<strong>' + escapeHtml(item.case_id) + ' · Проверка завершена</strong><span>Итог: ' + escapeHtml(decisionLabel(item.decision)) + '.</span>';
  };

  const renderReview = review => {
    currentReview = review || null;
    currentCase = review?.case || currentCase;
    if (!currentReview) {
      reviewBox.hidden = true;
      return;
    }

    renderMatchSummary(currentReview);
    renderSignals(currentReview);
    renderPairHistory(currentReview);
    renderDeviceSession(currentReview);
    renderReplayAvailability(currentReview);
    renderRawReplay(currentReview);
    renderCaseActions();

    policy.textContent = String(currentReview.policy?.copy || 'Сигналы только помогают ручной проверке. Один сигнал не блокирует игрока автоматически.');
    policy.dataset.state = 'ok';
  };

  const loadSnapshot = async () => {
    if (busy) return;
    if (!telegram?.initData) {
      setStatus('Откройте Web Admin из Telegram.', 'error');
      return;
    }
    setBusy(true);
    setStatus(mode === 'closed' ? 'Загружаю обработанные кейсы…' : 'Загружаю активные кейсы…');
    try {
      const data = await post({action:'snapshot', filters:filters()});
      renderQueue(data.cases || []);
      renderRecentMatches(data.recent_matches || []);
      setStatus(mode === 'closed' ? 'Обработанные кейсы загружены.' : 'Активные кейсы загружены.', 'ok');
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось загрузить кейсы.', 'error');
    } finally {
      setBusy(false);
    }
  };

  const reviewMatch = async () => {
    if (busy) return;
    const matchId = String(matchInput.value || recentMatch?.value || '').trim();
    if (!matchId) {
      setStatus('Сначала выберите матч из списка или вставьте его ID.', 'error');
      matchInput.focus();
      return;
    }

    setBusy(true);
    setStatus('Открываю проверку матча…');
    try {
      const data = await post({action:'match_review', match_id:matchId});
      currentCase = data.review?.case || null;
      renderReview(data.review || null);
      openReviewWorkspace('overview');
      setStatus('Матч открыт. Начните с вкладки «Обзор».', 'ok');
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось открыть проверку матча.', 'error');
    } finally {
      setBusy(false);
    }
  };

  const openCase = async caseId => {
    if (busy || !caseId) return;
    setBusy(true);
    setStatus('Открываю кейс ' + caseId + '…');
    try {
      const data = await post({action:'case', case_id:caseId, filters:filters()});
      currentCase = data.case || null;
      renderQueue(data.cases || []);
      renderRecentMatches(data.recent_matches || []);
      renderReview(data.review || null);
      openReviewWorkspace('case');
      setStatus(caseId + ': кейс открыт.', 'ok');
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось открыть кейс.', 'error');
    } finally {
      setBusy(false);
    }
  };

  const mutateCase = async payload => {
    if (busy) return null;
    setBusy(true);
    try {
      const data = await post({...payload, filters:filters()});
      currentCase = data.case || null;
      renderQueue(data.cases || []);
      renderRecentMatches(data.recent_matches || []);
      renderReview(data.review || null);
      openReviewWorkspace('case');
      return data;
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось сохранить решение.', 'error');
      return null;
    } finally {
      setBusy(false);
    }
  };

  homeButtons.forEach(button => button.addEventListener('click', () => {
    const target = String(button.dataset.afHomeMode || 'match');
    showHome(target);
  }));

  modeButtons.forEach(button => button.addEventListener('click', () => {
    mode = String(button.dataset.afMode || 'active');
  }));

  reviewTabs.forEach(button => button.addEventListener('click', () => {
    showReviewTab(String(button.dataset.afReviewTab || 'overview'));
  }));

  backButton.addEventListener('click', () => {
    showHome(currentCase?.status === 'closed' ? 'closed' : 'match');
  });

  refreshButton.addEventListener('click', () => void loadSnapshot());
  queryInput.addEventListener('keydown', event => {
    if (event.key === 'Enter') void loadSnapshot();
  });

  recentMatch?.addEventListener('change', () => {
    const matchId = String(recentMatch.value || '');
    if (matchId) matchInput.value = matchId;
  });

  matchLoad.addEventListener('click', () => void reviewMatch());
  matchInput.addEventListener('keydown', event => {
    if (event.key === 'Enter') void reviewMatch();
  });

  createCaseButton.addEventListener('click', () => {
    const matchId = String(currentReview?.replay?.match?.match_id || '');
    if (!matchId) return;
    void mutateCase({action:'create_case', match_id:matchId}).then(data => {
      if (!data) return;
      showReviewTab('case');
      setStatus((data.case?.case_id || 'Кейс') + ' создан. Игрок автоматически не блокировался.', 'ok');
    });
  });

  takeCaseButton.addEventListener('click', () => {
    if (!currentCase?.case_id) return;
    void mutateCase({action:'take_case', case_id:currentCase.case_id}).then(data => {
      if (!data) return;
      showReviewTab('case');
      decisionNote.focus();
      setStatus((data.case?.case_id || 'Кейс') + ' взят в работу. Теперь добавьте комментарий и выберите итог.', 'ok');
    });
  });

  decisionButtons.forEach(button => button.addEventListener('click', () => {
    if (!currentCase?.case_id) return;
    const note = String(decisionNote.value || '').trim();
    if (!note) {
      setStatus('Сначала добавьте короткий комментарий к решению.', 'error');
      decisionNote.focus();
      return;
    }

    const decision = String(button.dataset.afDecision || '');
    void mutateCase({
      action:'resolve_case',
      case_id:currentCase.case_id,
      decision,
      note,
    }).then(data => {
      if (!data) return;
      decisionNote.value = '';
      showReviewTab('case');
      setStatus((data.case?.case_id || 'Кейс') + ' обработан. Итог: ' + decisionLabel(data.case?.decision) + '.', 'ok');
    });
  }));

  openProcessedButton.addEventListener('click', () => showHome('closed'));

  prevButton.addEventListener('click', () => {
    if (frames.length < 2) return;
    stopPlayer();
    frameIndex -= 1;
    renderFrame();
  });

  nextButton.addEventListener('click', () => {
    if (frames.length < 2) return;
    stopPlayer();
    frameIndex += 1;
    renderFrame();
  });

  playButton.addEventListener('click', () => {
    if (playing) {
      stopPlayer();
      setStatus('Повтор поставлен на паузу.', 'ok');
      return;
    }
    if (frames.length < 2) {
      setStatus('Пошаговый повтор для этого матча недоступен. Причина указана над кнопками.', 'error');
      return;
    }
    if (frameIndex >= frames.length - 1) frameIndex = 0;
    playing = true;
    playButton.textContent = '⏸ Пауза';
    playButton.setAttribute('aria-label','Поставить повтор на паузу');
    renderFrame();
    setStatus('Воспроизвожу пошаговый повтор матча.', 'ok');
    scheduleNext();
  });

  speedSelect.addEventListener('change', () => {
    if (!playing) return;
    if (timer) window.clearTimeout(timer);
    timer = 0;
    scheduleNext();
  });

  progress.addEventListener('input', () => {
    stopPlayer();
    frameIndex = Number(progress.value || 0);
    renderFrame();
  });

  const requestedCase = new URLSearchParams(window.location.search).get('afcase') || '';
  if (requestedCase) {
    void openCase(requestedCase);
  } else {
    showHome('match');
    void loadSnapshot();
  }
})();