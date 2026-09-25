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

  const recentPicker = root.querySelector('[data-af-recent-picker]');
  const recentTrigger = root.querySelector('[data-af-recent-trigger]');
  const recentSelected = root.querySelector('[data-af-recent-selected]');
  const recentList = root.querySelector('[data-af-recent-list]');
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

  const timelineState = root.querySelector('[data-af-timeline-state]');
  const timelineBox = root.querySelector('[data-af-timeline]');
  const rawTimeline = root.querySelector('[data-af-raw-timeline]');
  const rawFrames = root.querySelector('[data-af-raw-frames]');

  const pairHistory = root.querySelector('[data-af-pair-history]');
  const deviceSession = root.querySelector('[data-af-device-session]');

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
  let recentMatches = [];
  let selectedRecentMatchId = '';

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
      refreshButton, matchLoad, recentTrigger, matchInput, queryInput,
      createCaseButton, takeCaseButton, decisionNote
    ].filter(Boolean).forEach(node => {
      node.disabled = value;
    });
    recentList?.querySelectorAll('button').forEach(node => { node.disabled = value; });
    decisionButtons.forEach(node => { node.disabled = value; });
    modeButtons.forEach(node => { node.disabled = value; });
    homeButtons.forEach(node => { node.disabled = value; });
    reviewTabs.forEach(node => { node.disabled = value; });
    if (!value) renderCaseActions();
  };

  const statusLabel = value => ({
    open:'Новый',
    reviewing:'В работе',
    monitoring:'Под наблюдением',
    closed:'Завершён',
    finished:'Завершён',
    active:'Идёт',
    cancelled:'Отменён',
  })[String(value || '')] || String(value || '—');

  const decisionLabel = value => ({
    pending:'Решение не принято',
    cleared:'Нарушений не найдено',
    monitor:'Под наблюдением',
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
    match_started:'Матч начался',
    move:'Ход игрока',
    action:'Действие игрока',
    result:'Результат матча',
    match_finished:'Матч завершён',
    timeout:'Тайм-аут',
    reconnect:'Переподключение',
    disconnect:'Отключение',
  })[String(value || '')] || 'Событие матча';

  const phaseLabel = value => ({
    start:'Начало',
    active:'Идёт',
    playing:'Идёт',
    finished:'Завершён',
    complete:'Завершён',
    completed:'Завершён',
  })[String(value || '')] || String(value || '');

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

  const players = () => Array.isArray(currentReview?.replay?.players) ? currentReview.replay.players : [];

  const playerName = ref => {
    const value = String(ref || '');
    const player = players().find(item =>
      String(item.player_ref || '') === value ||
      String(item.mgw_id || '') === value ||
      String(item.legacy_user_id || '') === value
    );
    return player ? String(player.display_name || player.mgw_id || player.player_ref || 'Игрок') : (value || 'Система');
  };

  const closeRecentList = () => {
    if (!recentList || !recentTrigger) return;
    recentList.hidden = true;
    recentTrigger.setAttribute('aria-expanded','false');
  };

  const toggleRecentList = () => {
    if (!recentList || !recentTrigger || busy) return;
    recentList.hidden = !recentList.hidden;
    recentTrigger.setAttribute('aria-expanded', recentList.hidden ? 'false' : 'true');
  };

  const recentMatchPrimary = match => {
    const names = (match.players || [])
      .map(player => player.display_name || player.mgw_id || 'Игрок')
      .filter(Boolean)
      .join(' — ');
    return gameLabel(match.game_type) + ' · ' + (names || 'участники');
  };

  const renderRecentMatches = matches => {
    recentMatches = Array.isArray(matches) ? matches : [];
    if (!recentList) return;

    recentList.replaceChildren();

    if (recentMatches.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'mgw-admin__af-recent-empty';
      empty.textContent = 'Недавних матчей пока нет. Можно вставить ID матча вручную.';
      recentList.append(empty);
      return;
    }

    recentMatches.forEach(match => {
      const matchId = String(match.match_id || '');
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'mgw-admin__af-recent-item';
      button.dataset.matchId = matchId;
      button.setAttribute('role','option');
      button.setAttribute('aria-selected', matchId === selectedRecentMatchId ? 'true' : 'false');

      const primary = document.createElement('strong');
      primary.textContent = recentMatchPrimary(match);

      const meta = document.createElement('span');
      meta.textContent = localTime(match.finished_at);

      const id = document.createElement('small');
      id.textContent = matchId;

      button.append(primary, meta, id);
      button.addEventListener('click', () => {
        selectedRecentMatchId = matchId;
        matchInput.value = matchId;
        recentSelected.textContent = recentMatchPrimary(match);
        recentList.querySelectorAll('[data-match-id]').forEach(node => {
          node.setAttribute('aria-selected', String(node.dataset.matchId || '') === matchId ? 'true' : 'false');
        });
        closeRecentList();
        setStatus('Матч выбран. Нажмите «Открыть проверку».', 'ok');
      });
      recentList.append(button);
    });
  };

  const signalExplanation = signal => {
    const code = String(signal?.code || '');
    const details = signal?.details || {};
    if (code === 'shared_device_match_window') return 'У игроков найден общий идентификатор устройства в период этого матча. Это повод проверить матч вручную, но не доказательство нарушения.';
    if (code === 'shared_device_history') return 'У игроков найден общий идентификатор устройства в истории. Проверьте контекст предыдущих матчей.';
    if (code === 'repeat_pair_24h') return 'Эта пара играла друг с другом много раз за короткий период. Сравните историю пары и результат матча.';
    if (code === 'rating_antifarming_limited') return 'Система рейтинга уже ограничила начисление очков из-за повторных побед над тем же соперником.';
    if (code === 'replay_integrity_gap') return 'В сохранённой истории матча есть пропуски. Хронология может быть неполной.';
    if (code === 'manual_review') return 'Кейс создан администратором вручную без автоматического сигнала.';
    const reason = String(details.reason || details.message || '').trim();
    return reason || 'Сигнал требует ручной проверки администратором.';
  };

  const showHome = nextMode => {
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

    closeRecentList();

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
  };

  const openReviewWorkspace = preferredTab => {
    closeRecentList();
    home.hidden = true;
    reviewBox.hidden = false;
    showReviewTab(preferredTab || 'overview');
    reviewBox.scrollIntoView({block:'start', behavior:'smooth'});
  };

  const renderQueue = cases => {
    queue.replaceChildren();
    queueTitle.textContent = mode === 'closed' ? 'Завершённые кейсы' : 'Активные кейсы';

    if (!Array.isArray(cases) || cases.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'mgw-admin__af-empty';
      empty.textContent = mode === 'closed'
        ? 'Завершённых кейсов пока нет.'
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
    const matchPlayers = Array.isArray(replay.players) ? replay.players : [];
    const diagnostics = replay.diagnostics || {};
    const timelineCount = Array.isArray(replay.timeline) ? replay.timeline.length : 0;
    const rows = [
      ['Игра', gameLabel(match.game_type)],
      ['Игроки', matchPlayers.map(p => p.display_name || p.mgw_id || p.player_ref).join(' — ') || '—'],
      ['Статус', statusLabel(match.status)],
      ['Сигналы', String(Array.isArray(review?.signals) ? review.signals.length : 0)],
      ['События', String(timelineCount)],
      ['Целостность', diagnostics.replayable === true ? 'Полная' : 'Есть пропуски'],
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

    const names = matchPlayers.map(p => p.display_name || p.mgw_id || p.player_ref).filter(Boolean).join(' — ');
    reviewSubtitle.textContent = gameLabel(match.game_type) + (names ? ' · ' + names : '') + ' · ' + String(match.match_id || '');
  };

  const renderSignals = review => {
    signals.replaceChildren();
    const list = Array.isArray(review?.signals) ? review.signals : [];

    if (list.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'mgw-admin__af-empty mgw-admin__af-empty--ok';
      empty.innerHTML = '<strong>Автоматических сигналов нет</strong><span>При необходимости всё равно можно посмотреть хронологию, историю пары и устройства.</span>';
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

  const stateSummary = frame => {
    if (!frame || typeof frame !== 'object') return '';
    const publicState = frame.public_state && typeof frame.public_state === 'object' ? frame.public_state : {};
    const serverState = frame.server_state && typeof frame.server_state === 'object' ? frame.server_state : {};
    const phase = publicState.phase || serverState.phase || publicState.status || serverState.status || '';
    const turn = publicState.turn || serverState.turn || publicState.turn_player_ref || serverState.turn_player_ref || '';
    const winner = publicState.winner || serverState.winner || publicState.winner_player_ref || serverState.winner_player_ref || '';

    const parts = [];
    if (phase) parts.push('Этап: ' + phaseLabel(phase));
    if (turn) parts.push('Ход: ' + playerName(turn));
    if (winner) parts.push('Победитель: ' + playerName(winner));
    return parts.join(' · ');
  };

  const timelineDescription = (event, frame) => {
    const type = String(event?.event_type || '');
    const actor = String(event?.actor_user_id || '');
    if (type === 'match_started') return 'Матч начался.';
    if (type === 'move') return actor ? playerName(actor) + ' сделал ход.' : 'Зафиксирован ход игрока.';
    if (type === 'action') return actor ? playerName(actor) + ' выполнил действие.' : 'Зафиксировано действие игрока.';
    if (type === 'result' || type === 'match_finished') {
      const state = stateSummary(frame);
      return state ? 'Зафиксирован результат. ' + state : 'Матч завершён, результат сохранён.';
    }
    if (type === 'timeout') return actor ? 'Зафиксирован тайм-аут: ' + playerName(actor) + '.' : 'Зафиксирован тайм-аут.';
    if (type === 'disconnect') return actor ? playerName(actor) + ' отключился.' : 'Зафиксировано отключение.';
    if (type === 'reconnect') return actor ? playerName(actor) + ' переподключился.' : 'Зафиксировано переподключение.';
    const state = stateSummary(frame);
    return state || 'Сервер сохранил событие матча.';
  };

  const renderTimeline = review => {
    timelineBox.replaceChildren();
    const replay = review?.replay || {};
    const events = Array.isArray(replay.timeline) ? replay.timeline : [];
    const frames = Array.isArray(replay.frames) ? replay.frames : [];
    const diagnostics = replay.diagnostics || {};
    const frameByVersion = new Map(frames.map(frame => [String(frame.state_version ?? ''), frame]));

    if (events.length === 0 && frames.length === 0) {
      timelineState.dataset.state = 'error';
      timelineState.innerHTML = '<strong>Хронология недоступна</strong><span>Для этого матча не сохранены события или состояния.</span>';
      return;
    }

    if (diagnostics.replayable === true) {
      timelineState.dataset.state = 'ok';
      timelineState.innerHTML = '<strong>Хронология собрана</strong><span>Событий: ' + events.length + '. Они показаны ниже в порядке записи сервером.</span>';
    } else {
      const missing = Array.isArray(diagnostics.missing_snapshot_versions) ? diagnostics.missing_snapshot_versions : [];
      timelineState.dataset.state = 'warn';
      timelineState.innerHTML = '<strong>Хронология может быть неполной</strong><span>В сохранённой цепочке есть пропуски' + (missing.length ? ': ' + escapeHtml(missing.join(', ')) : '') + '.</span>';
    }

    const items = events.length
      ? events.map((event,index) => ({
          index:index + 1,
          title:eventLabel(event.event_type),
          time:event.occurred_at_utc,
          actor:event.actor_user_id,
          frame:frameByVersion.get(String(event.snapshot_state_version ?? '')) || null,
          event,
        }))
      : frames.map((frame,index) => ({
          index:index + 1,
          title:'Сохранено состояние матча',
          time:frame.created_at_utc,
          actor:'',
          frame,
          event:null,
        }));

    items.forEach(item => {
      const article = document.createElement('article');
      article.className = 'mgw-admin__af-timeline-item';

      const number = document.createElement('span');
      number.className = 'mgw-admin__af-timeline-number';
      number.textContent = String(item.index);

      const body = document.createElement('div');
      body.className = 'mgw-admin__af-timeline-body';

      const head = document.createElement('div');
      head.className = 'mgw-admin__af-timeline-head';

      const title = document.createElement('strong');
      title.textContent = item.title;

      const time = document.createElement('time');
      time.textContent = localTime(item.time);
      head.append(title,time);

      const description = document.createElement('p');
      description.textContent = item.event
        ? timelineDescription(item.event,item.frame)
        : (stateSummary(item.frame) || 'Сервер сохранил состояние матча.');

      const state = stateSummary(item.frame);
      body.append(head,description);
      if (state && !(item.event && (String(item.event.event_type || '') === 'result' || String(item.event.event_type || '') === 'match_finished'))) {
        const meta = document.createElement('small');
        meta.textContent = state;
        body.append(meta);
      }

      article.append(number,body);
      timelineBox.append(article);
    });
  };

  const renderRawReplay = review => {
    rawTimeline.replaceChildren();
    rawFrames.replaceChildren();
    const replay = review?.replay || {};
    const events = Array.isArray(replay.timeline) ? replay.timeline : [];
    const frames = Array.isArray(replay.frames) ? replay.frames : [];

    const add = (target,title,meta,payload) => {
      const details = document.createElement('details');
      details.className = 'mgw-admin__replay-item';
      const summary = document.createElement('summary');
      const strong = document.createElement('strong');
      const span = document.createElement('span');
      strong.textContent = title;
      span.textContent = meta;
      summary.append(strong,span);
      const pre = document.createElement('pre');
      pre.textContent = JSON.stringify(payload,null,2);
      details.append(summary,pre);
      target.append(details);
    };

    events.forEach(event => add(
      rawTimeline,
      eventLabel(event.event_type) + ' · rev ' + event.primary_revision + '.' + event.event_ordinal,
      localTime(event.occurred_at_utc) + ' · state v' + (event.snapshot_state_version || '—'),
      event
    ));
    frames.forEach(frame => add(
      rawFrames,
      'Снимок состояния v' + (frame.state_version || '—'),
      localTime(frame.created_at_utc) + ' · событий ' + (Array.isArray(frame.events) ? frame.events.length : 0),
      frame
    ));
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
      const winner = item.winner_player_ref ? playerName(item.winner_player_ref) : 'Не определён';
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

    const devicePlayers = data.players || {};
    if (Object.keys(devicePlayers).length === 0) {
      const empty = document.createElement('div');
      empty.className = 'mgw-admin__af-empty';
      empty.textContent = 'Подробные device/session данные для этого матча недоступны.';
      deviceSession.append(empty);
      return;
    }

    Object.entries(devicePlayers).forEach(([mgwId,player]) => {
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

  const renderCaseActions = () => {
    const hasReview = currentReview && currentReview.replay?.match?.match_id;
    caseBox.hidden = !hasReview;
    if (!hasReview) return;

    const item = currentCase || currentReview.case || null;
    currentCase = item;

    createCaseButton.hidden = item !== null;
    takeCaseButton.hidden = !item || !['open','monitoring'].includes(String(item.status || ''));
    decisionBox.hidden = !item || item.status !== 'reviewing';
    openProcessedButton.hidden = !item || item.status !== 'closed';

    if (!item) {
      caseSummary.innerHTML = '<strong>Кейс не создан</strong><span>Обычный просмотр матча не требует кейса. Создавайте его только если ситуацию нужно отдельно зафиксировать и довести до решения.</span>';
      return;
    }

    if (item.status === 'open') {
      takeCaseButton.textContent = 'Взять в работу';
      caseSummary.innerHTML = '<strong>' + escapeHtml(item.case_id) + ' · Новый</strong><span>Кейс создан и ждёт администратора.</span>';
      return;
    }

    if (item.status === 'reviewing') {
      caseSummary.innerHTML = '<strong>' + escapeHtml(item.case_id) + ' · В работе</strong><span>Добавьте комментарий и выберите следующий итог.</span>';
      return;
    }

    if (item.status === 'monitoring') {
      takeCaseButton.textContent = 'Вернуть в работу';
      caseSummary.innerHTML = '<strong>' + escapeHtml(item.case_id) + ' · Под наблюдением</strong><span>Кейс остаётся активным. Когда появятся новые данные, верните его в работу и примите итоговое решение.</span>';
      return;
    }

    caseSummary.innerHTML = '<strong>' + escapeHtml(item.case_id) + ' · Завершён</strong><span>Итог: ' + escapeHtml(decisionLabel(item.decision)) + '.</span>';
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
    renderTimeline(currentReview);
    renderRawReplay(currentReview);
    renderPairHistory(currentReview);
    renderDeviceSession(currentReview);
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
    setStatus(mode === 'closed' ? 'Загружаю завершённые кейсы…' : 'Загружаю активные кейсы…');
    try {
      const data = await post({action:'snapshot', filters:filters()});
      renderQueue(data.cases || []);
      renderRecentMatches(data.recent_matches || []);
      setStatus(mode === 'closed' ? 'Завершённые кейсы загружены.' : 'Активные кейсы загружены.', 'ok');
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось загрузить кейсы.', 'error');
    } finally {
      setBusy(false);
    }
  };

  const reviewMatch = async () => {
    if (busy) return;
    const matchId = String(matchInput.value || selectedRecentMatchId || '').trim();
    if (!matchId) {
      setStatus('Сначала выберите матч из списка или вставьте его ID.', 'error');
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
    showHome(String(button.dataset.afHomeMode || 'match'));
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

  recentTrigger.addEventListener('click', event => {
    event.stopPropagation();
    toggleRecentList();
  });

  recentList.addEventListener('click', event => event.stopPropagation());
  document.addEventListener('click', event => {
    if (recentPicker && !recentPicker.contains(event.target)) closeRecentList();
  });

  refreshButton.addEventListener('click', () => void loadSnapshot());
  queryInput.addEventListener('keydown', event => {
    if (event.key === 'Enter') void loadSnapshot();
  });

  matchLoad.addEventListener('click', () => void reviewMatch());
  matchInput.addEventListener('input', () => {
    const typed = String(matchInput.value || '').trim();
    if (typed !== selectedRecentMatchId) {
      selectedRecentMatchId = '';
      recentSelected.textContent = 'Выбрать недавний матч';
      recentList.querySelectorAll('[data-match-id]').forEach(node => node.setAttribute('aria-selected','false'));
    }
  });
  matchInput.addEventListener('keydown', event => {
    if (event.key === 'Enter') void reviewMatch();
  });

  createCaseButton.addEventListener('click', () => {
    const matchId = String(currentReview?.replay?.match?.match_id || '');
    if (!matchId) return;
    void mutateCase({action:'create_case', match_id:matchId}).then(data => {
      if (!data) return;
      showReviewTab('case');
      setStatus((data.case?.case_id || 'Кейс') + ' создан и добавлен в активные.', 'ok');
    });
  });

  takeCaseButton.addEventListener('click', () => {
    if (!currentCase?.case_id) return;
    const wasMonitoring = currentCase.status === 'monitoring';
    void mutateCase({action:'take_case', case_id:currentCase.case_id}).then(data => {
      if (!data) return;
      showReviewTab('case');
      decisionNote.focus();
      setStatus(
        (data.case?.case_id || 'Кейс') +
        (wasMonitoring ? ' возвращён в работу.' : ' взят в работу.') +
        ' Добавьте комментарий и выберите итог.',
        'ok'
      );
    });
  });

  decisionButtons.forEach(button => button.addEventListener('click', () => {
    if (!currentCase?.case_id) return;
    const note = String(decisionNote.value || '').trim();
    if (!note) {
      setStatus('Сначала добавьте короткий комментарий.', 'error');
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
      if (data.case?.status === 'monitoring') {
        setStatus((data.case?.case_id || 'Кейс') + ' оставлен под наблюдением и остаётся в активных.', 'ok');
      } else {
        setStatus((data.case?.case_id || 'Кейс') + ' завершён. Итог: ' + decisionLabel(data.case?.decision) + '.', 'ok');
      }
    });
  }));

  openProcessedButton.addEventListener('click', () => showHome('closed'));

  const requestedCase = new URLSearchParams(window.location.search).get('afcase') || '';
  if (requestedCase) {
    void openCase(requestedCase);
  } else {
    showHome('match');
    void loadSnapshot();
  }
})();