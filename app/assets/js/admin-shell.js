(() => {
  'use strict';

  const root = document.querySelector('[data-admin-api]');
  if (!root) return;

  const status = root.querySelector('[data-admin-status]');
  const refresh = root.querySelector('[data-admin-refresh]');
  const meta = root.querySelector('[data-admin-meta]');
  const content = root.querySelector('[data-admin-content]');
  const environment = root.querySelector('[data-admin-environment]');
  const build = root.querySelector('[data-admin-build]');
  const generated = root.querySelector('[data-admin-generated]');
  const dashboard = root.querySelector('[data-admin-dashboard]');
  const systemCheck = root.querySelector('[data-admin-system-check]');
  const economyVersion = root.querySelector('[data-economy-version]');
  const economySha = root.querySelector('[data-economy-sha]');
  const economyConfig = root.querySelector('[data-economy-config]');
  const economyReason = root.querySelector('[data-economy-reason]');
  const economySave = root.querySelector('[data-economy-save]');
  const economySimulation = root.querySelector('[data-economy-simulation]');
  const economyHistory = root.querySelector('[data-economy-history]');
  const replayMatchId = root.querySelector('[data-replay-match-id]');
  const replayLoad = root.querySelector('[data-replay-load]');
  const replayStatus = root.querySelector('[data-replay-status]');
  const replayOutput = root.querySelector('[data-replay-output]');
  const replaySummary = root.querySelector('[data-replay-summary]');
  const replayTimeline = root.querySelector('[data-replay-timeline]');
  const replayFrames = root.querySelector('[data-replay-frames]');
  const endpoint = String(root.dataset.adminApi || '');
  const economyEndpoint = String(root.dataset.economyApi || '');
  const replayEndpoint = String(root.dataset.replayApi || '');
  const telegram = window.Telegram?.WebApp || null;
  let requestInFlight = false;
  let currentEconomyVersion = 0;

  const setStatus = (message, state = '') => {
    status.textContent = message;
    if (state) status.dataset.state = state;
    else delete status.dataset.state;
  };

  const setBusy = (busy) => {
    requestInFlight = busy;
    refresh.disabled = busy;
    economySave.disabled = busy;
    replayLoad.disabled = busy;
    economyHistory.querySelectorAll('button').forEach(button => {
      button.disabled = busy;
    });
  };

  const post = async (url, payload) => {
    const response = await fetch(url, {
      method: 'POST',
      cache: 'no-store',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({...payload, initData: telegram.initData})
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.ok !== true) {
      throw new Error(String(data.error || 'Не удалось выполнить запрос панели.'));
    }
    return data;
  };

  const renderBase = (data) => {
    environment.textContent = String(data.environment || '—');
    build.textContent = String(data.build || '—');
    generated.textContent = data.generated_at
      ? new Date(data.generated_at).toLocaleString('ru-RU')
      : '—';
    dashboard.textContent = String(data.dashboard || '—');
    systemCheck.textContent = String(data.system_check || '—');
    meta.hidden = false;
    content.hidden = false;
  };

  const historyLabel = (entry) => {
    const type = entry.change_type === 'rollback'
      ? `rollback к v${entry.source_version}`
      : entry.change_type === 'seed' ? 'начальная версия' : 'изменение';
    return `v${entry.version} · ${type}`;
  };

  const renderEconomyHistory = (history) => {
    economyHistory.replaceChildren();
    if (!Array.isArray(history) || history.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'mgw-admin__history-empty';
      empty.textContent = 'История пока пуста.';
      economyHistory.append(empty);
      return;
    }

    history.forEach(entry => {
      const item = document.createElement('div');
      item.className = 'mgw-admin__history-item';

      const copy = document.createElement('div');
      copy.className = 'mgw-admin__history-copy';
      const title = document.createElement('strong');
      title.textContent = historyLabel(entry);
      const details = document.createElement('span');
      const when = entry.created_at_utc ? `${entry.created_at_utc} UTC` : 'время неизвестно';
      details.textContent = `${when} · ${entry.actor_ref || '—'} · ${entry.reason || '—'}`;
      copy.append(title, details);
      item.append(copy);

      if (Number(entry.version) !== currentEconomyVersion) {
        const rollback = document.createElement('button');
        rollback.type = 'button';
        rollback.textContent = `Вернуть v${entry.version}`;
        rollback.addEventListener('click', () => rollbackEconomy(Number(entry.version)));
        item.append(rollback);
      }

      economyHistory.append(item);
    });
  };

  const renderEconomy = (data) => {
    const current = data.current || {};
    currentEconomyVersion = Number(current.version || 0);
    economyVersion.textContent = currentEconomyVersion > 0 ? `v${currentEconomyVersion}` : '—';
    economySha.textContent = String(current.config_sha256 || '—');
    economyConfig.value = JSON.stringify(current.config || {}, null, 2);
    economySimulation.textContent = JSON.stringify(current.simulation || {}, null, 2);
    renderEconomyHistory(data.history || []);
  };

  const addReplaySummary = (label, value) => {
    const item = document.createElement('div');
    const key = document.createElement('span');
    const strong = document.createElement('strong');
    key.textContent = label;
    strong.textContent = String(value ?? '—');
    item.append(key, strong);
    replaySummary.append(item);
  };

  const replayDetails = (titleText, metaText, payload) => {
    const details = document.createElement('details');
    details.className = 'mgw-admin__replay-item';
    const summary = document.createElement('summary');
    const title = document.createElement('strong');
    const metaTextNode = document.createElement('span');
    title.textContent = titleText;
    metaTextNode.textContent = metaText;
    summary.append(title, metaTextNode);
    const pre = document.createElement('pre');
    pre.textContent = JSON.stringify(payload, null, 2);
    details.append(summary, pre);
    return details;
  };

  const renderReplay = (data) => {
    const replay = data.replay || {};
    const match = replay.match || {};
    const diagnostics = replay.diagnostics || {};
    const players = Array.isArray(replay.players) ? replay.players : [];
    const timeline = Array.isArray(replay.timeline) ? replay.timeline : [];
    const frames = Array.isArray(replay.frames) ? replay.frames : [];

    replaySummary.replaceChildren();
    replayTimeline.replaceChildren();
    replayFrames.replaceChildren();
    addReplaySummary('Match', match.match_id || '—');
    addReplaySummary('Игра', match.game_type || '—');
    addReplaySummary('Статус', match.status || '—');
    addReplaySummary('State version', match.state_version || '—');
    addReplaySummary('События', diagnostics.event_count ?? timeline.length);
    addReplaySummary('Snapshots', diagnostics.snapshot_count ?? frames.length);
    addReplaySummary('Игроки', players.map(player => player.display_name || player.player_ref).join(' / ') || '—');
    addReplaySummary('Replayable', diagnostics.replayable === true ? 'YES' : 'NO');

    timeline.forEach(event => {
      const actor = event.actor_user_id ? ` · actor ${event.actor_user_id}` : '';
      replayTimeline.append(replayDetails(
        `${event.event_type || 'event'} · rev ${event.primary_revision}.${event.event_ordinal}`,
        `${event.occurred_at_utc || '—'} · snapshot v${event.snapshot_state_version || '—'}${actor}`,
        event
      ));
    });
    if (timeline.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'mgw-admin__history-empty';
      empty.textContent = 'События для этого матча не сохранены.';
      replayTimeline.append(empty);
    }

    frames.forEach(frame => {
      replayFrames.append(replayDetails(
        `Snapshot v${frame.state_version}`,
        `${frame.created_at_utc || '—'} · events ${Array.isArray(frame.events) ? frame.events.length : 0}`,
        frame
      ));
    });

    const missing = Array.isArray(diagnostics.missing_snapshot_versions)
      ? diagnostics.missing_snapshot_versions.join(', ')
      : '';
    replayStatus.textContent = diagnostics.replayable === true
      ? 'Replay chain целостна: durable events связаны с immutable snapshots.'
      : `Replay chain неполна${missing ? `; отсутствуют snapshots: ${missing}` : '.'}`;
    replayStatus.dataset.state = diagnostics.replayable === true ? 'ok' : 'error';
    replayOutput.hidden = false;
  };

  const loadReplay = async () => {
    if (requestInFlight) return;
    const matchId = replayMatchId.value.trim();
    if (!matchId) {
      replayStatus.textContent = 'Укажите Match ID.';
      replayStatus.dataset.state = 'error';
      replayMatchId.focus();
      return;
    }

    setBusy(true);
    replayStatus.textContent = 'Читаю durable event log и snapshots…';
    delete replayStatus.dataset.state;
    replayOutput.hidden = true;
    try {
      const data = await post(replayEndpoint, {action: 'match_replay', matchId});
      renderReplay(data);
    } catch (error) {
      replayStatus.textContent = error instanceof Error ? error.message : 'Не удалось загрузить replay.';
      replayStatus.dataset.state = 'error';
    } finally {
      setBusy(false);
    }
  };

  const load = async () => {
    if (requestInFlight) return;
    if (!telegram || !telegram.initData) {
      setStatus('Откройте Web Admin кнопкой из админ-панели бота в Telegram.', 'error');
      return;
    }

    setBusy(true);
    setStatus('Загружаю актуальное состояние…');

    try {
      const [baseData, economyData] = await Promise.all([
        post(endpoint, {action: 'snapshot'}),
        post(economyEndpoint, {action: 'snapshot'})
      ]);
      renderBase(baseData);
      renderEconomy(economyData);
      setStatus('Данные загружены. Обновление выполняется только вручную.', 'ok');
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось загрузить панель.', 'error');
    } finally {
      setBusy(false);
    }
  };

  const saveEconomy = async () => {
    if (requestInFlight) return;
    const reason = economyReason.value.trim();
    if (reason.length < 3) {
      setStatus('Укажите причину изменения экономики.', 'error');
      economyReason.focus();
      return;
    }

    let config;
    try {
      config = JSON.parse(economyConfig.value);
    } catch (error) {
      setStatus('Конфигурация экономики содержит некорректный JSON.', 'error');
      economyConfig.focus();
      return;
    }

    setBusy(true);
    setStatus('Сохраняю новую версию экономики…');
    try {
      const data = await post(economyEndpoint, {action: 'update', config, reason});
      renderEconomy(data);
      economyReason.value = '';
      setStatus(`Конфигурация сохранена как v${data.current.version}. Балансы пользователей не изменялись.`, 'ok');
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось сохранить конфигурацию.', 'error');
    } finally {
      setBusy(false);
    }
  };

  const rollbackEconomy = async (version) => {
    if (requestInFlight) return;
    const reason = economyReason.value.trim();
    if (reason.length < 3) {
      setStatus('Для rollback укажите причину изменения.', 'error');
      economyReason.focus();
      return;
    }
    if (!window.confirm(`Создать новую версию экономики на основе v${version}?`)) return;

    setBusy(true);
    setStatus(`Создаю rollback-версию из v${version}…`);
    try {
      const data = await post(economyEndpoint, {action: 'rollback', version, reason});
      renderEconomy(data);
      economyReason.value = '';
      setStatus(`Rollback сохранён как новая v${data.current.version}. История не переписывалась.`, 'ok');
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось выполнить rollback.', 'error');
    } finally {
      setBusy(false);
    }
  };

  if (telegram) {
    telegram.ready();
    telegram.expand();
  }

  refresh.addEventListener('click', load);
  replayLoad.addEventListener('click', loadReplay);
  replayMatchId.addEventListener('keydown', event => {
    if (event.key === 'Enter') loadReplay();
  });
  economySave.addEventListener('click', saveEconomy);
  load();
})();