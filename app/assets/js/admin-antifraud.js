(() => {
  'use strict';

  const root = document.querySelector('[data-admin-antifraud]');
  const shell = document.querySelector('[data-replay-api]');
  if (!root || !shell) return;

  const endpoint = String(shell.dataset.replayApi || '');
  const telegram = window.Telegram?.WebApp || null;

  const status = root.querySelector('[data-af-status]');
  const modeButtons = Array.from(root.querySelectorAll('[data-af-mode]'));
  const queryInput = root.querySelector('[data-af-query]');
  const refreshButton = root.querySelector('[data-af-refresh]');
  const queue = root.querySelector('[data-af-queue]');
  const queueTitle = root.querySelector('[data-af-queue-title]');
  const recentMatch = root.querySelector('[data-af-recent-match]');
  const matchInput = root.querySelector('[data-af-match-id]');
  const matchLoad = root.querySelector('[data-af-match-load]');
  const reviewBox = root.querySelector('[data-af-review]');
  const matchSummary = root.querySelector('[data-af-match-summary]');
  const policy = root.querySelector('[data-af-policy]');
  const signals = root.querySelector('[data-af-signals]');
  const pairHistory = root.querySelector('[data-af-pair-history]');
  const deviceSession = root.querySelector('[data-af-device-session]');
  const caseBox = root.querySelector('[data-af-case]');
  const caseSummary = root.querySelector('[data-af-case-summary]');
  const createCaseButton = root.querySelector('[data-af-create-case]');
  const takeCaseButton = root.querySelector('[data-af-take-case]');
  const decisionBox = root.querySelector('[data-af-decisions]');
  const decisionNote = root.querySelector('[data-af-decision-note]');
  const decisionButtons = Array.from(root.querySelectorAll('[data-af-decision]'));
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

  let mode = 'active';
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
      throw new Error(String(data.error || 'Не удалось выполнить anti-fraud запрос.'));
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
    root.querySelectorAll('button,input,select,textarea').forEach(node => {
      if (node.matches('[data-af-query],[data-af-mode]')) {
        node.disabled = value;
        return;
      }
      node.disabled = value;
    });
    if (!value) renderCaseActions();
  };

  const statusLabel = value => ({
    open:'Новый',
    reviewing:'На проверке',
    closed:'Обработан',
  })[String(value || '')] || String(value || '—');

  const decisionLabel = value => ({
    pending:'Решение не принято',
    cleared:'Нарушений не найдено',
    monitor:'Оставить под наблюдением',
    moderation_review:'Передать на модерацию',
    rating_review:'Передать на проверку рейтинга',
  })[String(value || '')] || String(value || '—');

  const priorityLabel = value => ({
    high:'Высокий',
    normal:'Обычный',
    low:'Низкий',
  })[String(value || '')] || String(value || '—');

  const severityLabel = value => ({
    high:'Высокий сигнал',
    medium:'Средний сигнал',
    low:'Информационный сигнал',
  })[String(value || '')] || String(value || 'Сигнал');

  const localTime = value => {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ', 'T') + (String(value).includes('Z') || /[+-]\d\d:\d\d$/.test(String(value)) ? '' : 'Z'));
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('ru-RU');
  };

  const renderRecentMatches = matches => {
    if (!recentMatch) return;
    const selected = String(recentMatch.value || '');
    recentMatch.replaceChildren();
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Выберите недавний матч';
    recentMatch.append(placeholder);

    (Array.isArray(matches) ? matches : []).forEach(match => {
      const option = document.createElement('option');
      option.value = String(match.match_id || '');
      const names = (match.players || [])
        .map(player => player.display_name || player.mgw_id || 'Игрок')
        .filter(Boolean)
        .join(' — ');
      option.textContent = `${match.game_type || 'game'} · ${names || 'участники'} · ${localTime(match.finished_at)}`;
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
      empty.className = 'mgw-admin__history-empty';
      empty.textContent = mode === 'closed'
        ? 'Обработанных anti-fraud кейсов пока нет.'
        : 'Активных anti-fraud кейсов пока нет.';
      queue.append(empty);
      return;
    }

    cases.forEach(item => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'mgw-admin__af-case-card';
      button.dataset.caseId = String(item.case_id || '');
      button.innerHTML = `
        <span class="mgw-admin__af-case-top">
          <strong>${escapeHtml(item.case_id || 'Кейс')}</strong>
          <em data-tone="${escapeHtml(item.priority || 'normal')}">${escapeHtml(priorityLabel(item.priority))}</em>
        </span>
        <span class="mgw-admin__af-case-match">${escapeHtml(item.game_type || 'игра')} · ${escapeHtml(item.match_id || '—')}</span>
        <span class="mgw-admin__af-case-meta">${escapeHtml(statusLabel(item.status))} · сигналов: ${Number(item.signal_count || 0)}</span>
      `;
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
      ['Матч', match.match_id || '—'],
      ['Игра', match.game_type || '—'],
      ['Статус', match.status || '—'],
      ['Игроки', players.map(p => p.display_name || p.mgw_id || p.player_ref).join(' / ') || '—'],
      ['События', diagnostics.event_count ?? 0],
      ['Снимки', diagnostics.snapshot_count ?? 0],
      ['Replay', diagnostics.replayable === true ? 'Цепочка целостна' : 'Цепочка неполна'],
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
  };

  const renderSignals = review => {
    signals.replaceChildren();
    const list = Array.isArray(review?.signals) ? review.signals : [];
    if (list.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'mgw-admin__history-empty';
      empty.textContent = 'Автоматические сигналы не обнаружены. Матч всё равно можно открыть на ручную проверку.';
      signals.append(empty);
      return;
    }
    list.forEach(signal => {
      const item = document.createElement('div');
      item.className = 'mgw-admin__af-signal';
      item.dataset.severity = String(signal.severity || 'low');
      const head = document.createElement('div');
      const title = document.createElement('strong');
      const badge = document.createElement('span');
      title.textContent = String(signal.label || signal.code || 'Сигнал');
      badge.textContent = severityLabel(signal.severity);
      head.append(title,badge);
      const pre = document.createElement('pre');
      pre.textContent = JSON.stringify(signal.details || {}, null, 2);
      item.append(head,pre);
      signals.append(item);
    });
  };

  const renderPairHistory = review => {
    pairHistory.replaceChildren();
    const list = Array.isArray(review?.pair_history) ? review.pair_history : [];
    if (list.length === 0) {
      pairHistory.innerHTML = '<div class="mgw-admin__history-empty">История этой пары недоступна или матч не содержит двух канонических MGW-ID.</div>';
      return;
    }
    list.forEach(item => {
      const row = document.createElement('div');
      row.className = 'mgw-admin__af-history-row';
      const winner = item.winner_player_ref === item.first_player_ref
        ? 'первый игрок'
        : item.winner_player_ref === item.second_player_ref ? 'второй игрок' : '—';
      row.innerHTML = `
        <strong>${escapeHtml(item.match_id || '—')}</strong>
        <span>${escapeHtml(item.game_type || '—')} · ${escapeHtml(item.finish_reason || item.status || '—')}</span>
        <span>Победитель: ${escapeHtml(winner)}</span>
        <em>${escapeHtml(localTime(item.finished_at_utc || item.started_at_utc || item.created_at_utc))}</em>
      `;
      pairHistory.append(row);
    });
  };

  const renderDeviceSession = review => {
    deviceSession.replaceChildren();
    const data = review?.device_session || {};
    const shared = Array.isArray(data.shared_device_hash_prefixes) ? data.shared_device_hash_prefixes : [];
    const sharedWindow = Array.isArray(data.shared_match_window_device_hash_prefixes)
      ? data.shared_match_window_device_hash_prefixes : [];

    const summary = document.createElement('div');
    summary.className = 'mgw-admin__af-device-summary';
    summary.innerHTML = `
      <div><span>Общие device-key в истории</span><strong>${shared.length}</strong></div>
      <div><span>Общие device-key в окне матча</span><strong>${sharedWindow.length}</strong></div>
    `;
    deviceSession.append(summary);

    Object.entries(data.players || {}).forEach(([mgwId,player]) => {
      const details = document.createElement('details');
      details.className = 'mgw-admin__af-session-player';
      const head = document.createElement('summary');
      head.textContent = `${mgwId} · сессий: ${Array.isArray(player.sessions) ? player.sessions.length : 0}`;
      details.append(head);
      (player.sessions || []).forEach(session => {
        const row = document.createElement('div');
        row.className = 'mgw-admin__af-session-row';
        row.innerHTML = `
          <strong>${escapeHtml(session.provider || 'session')} · ${escapeHtml(session.session_prefix || '—')}</strong>
          <span>device: ${escapeHtml(session.device_hash_prefix || '—')}</span>
          <span>${session.overlaps_match ? 'Пересекается со временем матча' : 'Вне окна матча'}</span>
          <em>${escapeHtml(localTime(session.issued_at))} → ${escapeHtml(localTime(session.expires_at))}</em>
        `;
        details.append(row);
      });
      deviceSession.append(details);
    });
  };

  const stopPlayer = () => {
    playing = false;
    if (timer) window.clearTimeout(timer);
    timer = 0;
    playButton.textContent = '▶';
    playButton.setAttribute('aria-label','Воспроизвести');
  };

  const frameDelay = (from, to) => {
    const speed = Math.max(0.25, Number(speedSelect.value || 1));
    const a = Date.parse(String(from?.created_at_utc || '').replace(' ', 'T') + 'Z');
    const b = Date.parse(String(to?.created_at_utc || '').replace(' ', 'T') + 'Z');
    const delta = Number.isFinite(a) && Number.isFinite(b) && b > a ? b - a : 1000;
    return Math.max(250, Math.min(2500, delta / speed));
  };

  const scheduleNext = () => {
    if (!playing) return;
    if (frameIndex >= frames.length - 1) {
      stopPlayer();
      return;
    }
    timer = window.setTimeout(() => {
      frameIndex += 1;
      renderFrame();
      scheduleNext();
    }, frameDelay(frames[frameIndex], frames[frameIndex + 1]));
  };

  const renderFrame = () => {
    if (frames.length === 0) {
      player.hidden = true;
      return;
    }
    player.hidden = false;
    frameIndex = Math.max(0, Math.min(frames.length - 1, frameIndex));
    const frame = frames[frameIndex] || {};
    progress.max = String(Math.max(0, frames.length - 1));
    progress.value = String(frameIndex);
    prevButton.disabled = busy || frameIndex <= 0;
    nextButton.disabled = busy || frameIndex >= frames.length - 1;
    playerMeta.textContent = `Шаг ${frameIndex + 1} из ${frames.length} · state v${frame.state_version || '—'} · ${localTime(frame.created_at_utc)}`;
    playerState.textContent = JSON.stringify({
      public_state:frame.public_state ?? null,
      server_state:frame.server_state ?? null,
      private_states:frame.private_states ?? {},
    }, null, 2);
    const events = Array.isArray(frame.events) ? frame.events : [];
    playerEvents.textContent = events.length
      ? events.map(event => `${event.event_type || 'event'} · ${event.actor_user_id || 'system'} · ${event.occurred_at_utc || '—'}`).join('\n')
      : 'На этом шаге отдельного события нет.';
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
      `${event.event_type || 'event'} · rev ${event.primary_revision}.${event.event_ordinal}`,
      `${event.occurred_at_utc || '—'} · v${event.snapshot_state_version || '—'}`,
      event
    ));
    replayFrames.forEach(frame => add(
      rawFrames,
      `Снимок v${frame.state_version || '—'}`,
      `${frame.created_at_utc || '—'} · событий ${Array.isArray(frame.events) ? frame.events.length : 0}`,
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

    if (!item) {
      caseSummary.textContent = 'Кейс ещё не создан. Сигналы не применяют санкции автоматически.';
      return;
    }
    caseSummary.textContent = `${item.case_id} · ${statusLabel(item.status)} · ${decisionLabel(item.decision)}${item.owner_ref ? ` · ${item.owner_ref}` : ''}`;
  };

  const renderReview = review => {
    currentReview = review || null;
    currentCase = review?.case || currentCase;
    reviewBox.hidden = !currentReview;
    if (!currentReview) return;

    renderMatchSummary(currentReview);
    renderSignals(currentReview);
    renderPairHistory(currentReview);
    renderDeviceSession(currentReview);
    policy.textContent = String(currentReview.policy?.copy || 'Сигналы предназначены только для ручной проверки.');
    policy.dataset.state = 'ok';

    frames = Array.isArray(currentReview.replay?.frames) ? currentReview.replay.frames : [];
    frameIndex = 0;
    stopPlayer();
    renderFrame();
    renderRawReplay(currentReview);
    renderCaseActions();
  };

  const loadSnapshot = async () => {
    if (busy) return;
    if (!telegram?.initData) {
      setStatus('Откройте Web Admin из Telegram.', 'error');
      return;
    }
    setBusy(true);
    setStatus('Загружаю anti-fraud кейсы…');
    try {
      const data = await post({action:'snapshot', filters:filters()});
      renderQueue(data.cases || []);
      renderRecentMatches(data.recent_matches || []);
      setStatus(mode === 'closed' ? 'Обработанные кейсы загружены.' : 'Активные кейсы загружены.', 'ok');
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось загрузить anti-fraud кейсы.', 'error');
    } finally {
      setBusy(false);
    }
  };

  const reviewMatch = async () => {
    if (busy) return;
    const matchId = String(matchInput.value || '').trim();
    if (!matchId) {
      setStatus('Укажите ID матча.', 'error');
      matchInput.focus();
      return;
    }
    setBusy(true);
    setStatus('Собираю replay, историю пары и сигналы…');
    try {
      const data = await post({action:'match_review', match_id:matchId});
      currentCase = data.review?.case || null;
      renderReview(data.review || null);
      setStatus('Матч загружен для ручной проверки.', 'ok');
    } catch (error) {
      reviewBox.hidden = true;
      setStatus(error instanceof Error ? error.message : 'Не удалось проверить матч.', 'error');
    } finally {
      setBusy(false);
    }
  };

  const openCase = async caseId => {
    if (busy || !caseId) return;
    setBusy(true);
    setStatus(`Открываю ${caseId}…`);
    try {
      const data = await post({action:'case', case_id:caseId, filters:filters()});
      currentCase = data.case || null;
      renderQueue(data.cases || []);
      renderRecentMatches(data.recent_matches || []);
      renderReview(data.review || null);
      setStatus(`${caseId}: данные проверки загружены.`, 'ok');
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
      return data;
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось сохранить anti-fraud решение.', 'error');
      return null;
    } finally {
      setBusy(false);
    }
  };

  modeButtons.forEach(button => button.addEventListener('click', () => {
    mode = String(button.dataset.afMode || 'active');
    modeButtons.forEach(node => node.classList.toggle('is-active', node === button));
    void loadSnapshot();
  }));
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
      if (data) setStatus(`${data.case?.case_id || 'Кейс'} создан. Санкции автоматически не применялись.`, 'ok');
    });
  });
  takeCaseButton.addEventListener('click', () => {
    if (!currentCase?.case_id) return;
    void mutateCase({action:'take_case', case_id:currentCase.case_id}).then(data => {
      if (data) setStatus(`${data.case?.case_id || 'Кейс'} взят в работу.`, 'ok');
    });
  });
  decisionButtons.forEach(button => button.addEventListener('click', () => {
    if (!currentCase?.case_id) return;
    const note = String(decisionNote.value || '').trim();
    if (!note) {
      setStatus('Добавьте короткий комментарий к итогу проверки.', 'error');
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
      setStatus(`${data.case?.case_id || 'Кейс'} обработан: ${decisionLabel(data.case?.decision)}.`, 'ok');
    });
  }));

  prevButton.addEventListener('click', () => {
    stopPlayer();
    frameIndex -= 1;
    renderFrame();
  });
  nextButton.addEventListener('click', () => {
    stopPlayer();
    frameIndex += 1;
    renderFrame();
  });
  playButton.addEventListener('click', () => {
    if (playing) {
      stopPlayer();
      return;
    }
    if (frames.length === 0) return;
    if (frameIndex >= frames.length - 1) frameIndex = 0;
    playing = true;
    playButton.textContent = '⏸';
    playButton.setAttribute('aria-label','Пауза');
    renderFrame();
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
    void loadSnapshot();
  }
})();