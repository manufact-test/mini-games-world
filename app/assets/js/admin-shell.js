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
  const environmentBadge = root.querySelector('[data-admin-environment-badge]');
  const nav = root.querySelector('[data-admin-nav]');
  const navButtons = Array.from(root.querySelectorAll('[data-admin-nav-target]'));
  const sectionTitle = root.querySelector('[data-admin-section-title]');
  const backOverview = root.querySelector('[data-admin-back-overview]');
  const sectionDescription = root.querySelector('[data-admin-section-description]');
  const dashboard = root.querySelector('[data-admin-dashboard]');
  const overviewSystem = root.querySelector('[data-overview-system]');
  const overviewSystemNote = root.querySelector('[data-overview-system-note]');
  const overviewSupport = root.querySelector('[data-overview-support]');
  const overviewSupportNote = root.querySelector('[data-overview-support-note]');
  const overviewTournament = root.querySelector('[data-overview-tournament]');
  const overviewTournamentNote = root.querySelector('[data-overview-tournament-note]');
  const overviewSeason = root.querySelector('[data-overview-season]');
  const overviewSeasonNote = root.querySelector('[data-overview-season-note]');
  const systemCheck = root.querySelector('[data-admin-system-check]');
  const economyVersion = root.querySelector('[data-economy-version]');
  const economySha = root.querySelector('[data-economy-sha]');
  const economyConfig = root.querySelector('[data-economy-config]');
  const economyReason = root.querySelector('[data-economy-reason]');
  const economySave = root.querySelector('[data-economy-save]');
  const economySimulation = root.querySelector('[data-economy-simulation]');
  const economyHistory = root.querySelector('[data-economy-history]');
  const testCoinsPlayer = root.querySelector('[data-test-coins-player]');
  const testCoinsAmount = root.querySelector('[data-test-coins-amount]');
  const testCoinsReason = root.querySelector('[data-test-coins-reason]');
  const testCoinsGrant = root.querySelector('[data-test-coins-grant]');
  const testCoinsStatus = root.querySelector('[data-test-coins-status]');
  const endpoint = String(root.dataset.adminApi || '');
  const economyEndpoint = String(root.dataset.economyApi || '');
  const testCoinsEndpoint = String(root.dataset.testCoinsApi || '');
  const telegram = window.Telegram?.WebApp || null;
  let requestInFlight = false;
  let currentEconomyVersion = 0;
  const initialParams = new URLSearchParams(window.location.search);
  let activeSection = initialParams.has('ticket')
    ? 'support'
    : initialParams.has('report')
      ? 'users'
      : initialParams.has('afcase')
        ? 'antifraud'
        : (window.location.hash || '#overview').slice(1);

  const sections = {
    overview:['Обзор','Ключевое состояние продукта и быстрый контроль.'],
    users:['Пользователи','Жалобы и действия, связанные с игроками.'],
    antifraud:['Проверка игр','Anti-fraud кейсы, сигналы и воспроизведение матчей.'],
    support:['Поддержка','Очередь обращений и рабочее место по выбранному тикету.'],
    tournaments:['Турниры и сезоны','Официальные турниры, рейтинг и сезонные проверки.'],
    economy:['Экономика','Версионные настройки экономики и аудит изменений.'],
    notifications:['Уведомления','Сообщения игрокам через единый центр уведомлений.'],
    system:['Система','Состояние runtime и диагностическая сводка.'],
    tests:['Тесты','Инструменты тестовой среды и диагностические сценарии.'],
  };

  const renderDashboard = raw => {
    dashboard.replaceChildren();
    let group = null;
    String(raw || '—').split(/\r?\n/).map(line => line.trim()).filter(Boolean).forEach(line => {
      const isHeading = /^[🛠📊💰🎁📩🎮🧾]/u.test(line) && !line.includes(':');
      if (isHeading) {
        group = document.createElement('section');
        group.className = 'mgw-admin__overview-group';
        const heading = document.createElement('h3');
        heading.textContent = line.replace(/^[^\p{L}\p{N}]+/u, '');
        group.append(heading);
        dashboard.append(group);
        return;
      }
      if (!group) {
        group = document.createElement('section');
        group.className = 'mgw-admin__overview-group';
        dashboard.append(group);
      }
      const separator = line.indexOf(':');
      if (separator > 0) {
        const row = document.createElement('div');
        row.className = 'mgw-admin__overview-row';
        const label = document.createElement('span');
        const value = document.createElement('strong');
        label.textContent = line.slice(0, separator).trim().replace('Dev-записей','Тестовых записей');
        value.textContent = line.slice(separator + 1).trim();
        row.append(label, value);
        group.append(row);
      } else {
        const note = document.createElement('p');
        note.textContent = line;
        group.append(note);
      }
    });
  };

  const applySection = (section, updateHash = true) => {
    if (!sections[section]) section = 'overview';
    const testsButton = navButtons.find(button => button.dataset.adminNavTarget === 'tests');
    if (section === 'tests' && testsButton?.hidden) section = 'overview';
    activeSection = section;
    root.querySelectorAll('[data-admin-section]').forEach(card => {
      card.hidden = card.dataset.adminSection !== section;
    });
    navButtons.forEach(button => {
      const selected = button.dataset.adminNavTarget === section;
      button.classList.toggle('is-active', selected);
      button.setAttribute('aria-current', selected ? 'page' : 'false');
      if (selected && window.innerWidth > 720) {
        button.scrollIntoView({behavior:'smooth', block:'nearest', inline:'center'});
      }
    });
    if (backOverview) backOverview.hidden = section === 'overview';
    const meta = sections[section];
    sectionTitle.textContent = meta[0];
    sectionDescription.textContent = meta[1];
    if (updateHash) history.replaceState(null, '', window.location.pathname + window.location.search + '#' + section);
    if (window.scrollY > 140) root.scrollIntoView({block:'start', behavior:'smooth'});
  };

  navButtons.forEach(button => {
    button.addEventListener('click', () => applySection(String(button.dataset.adminNavTarget || 'overview')));
  });
  backOverview?.addEventListener('click', () => applySection('overview'));
  root.querySelectorAll('[data-admin-shortcut]').forEach(button => {
    button.addEventListener('click', () => applySection(String(button.dataset.adminShortcut || 'overview')));
  });

  window.addEventListener('mgw:admin-support-summary', event => {
    const metrics = event.detail || {};
    const open = Number(metrics.open || 0);
    const critical = Number(metrics.critical || 0);
    overviewSupport.textContent = open.toLocaleString('ru-RU');
    overviewSupportNote.textContent = critical > 0
      ? `Критических: ${critical}`
      : 'Открытых обращений';
    overviewSupport.closest('button')?.toggleAttribute('data-alert', critical > 0);
  });

  window.addEventListener('mgw:admin-tournament-summary', event => {
    const info = event.detail || {};
    overviewTournament.textContent = String(info.label || 'Нет активного');
    overviewTournamentNote.textContent = info.participants
      ? `Участники: ${info.participants}`
      : 'Официальный турнир не создан';
  });

  window.addEventListener('mgw:admin-rating-summary', event => {
    const info = event.detail || {};
    overviewSeason.textContent = String(info.season || '—');
    overviewSeasonNote.textContent = String(info.stateLabel || 'Состояние не загружено');
  });

  const setStatus = (message, state = '') => {
    status.textContent = message;
    if (state) status.dataset.state = state;
    else delete status.dataset.state;
  };

  const setBusy = (busy) => {
    requestInFlight = busy;
    refresh.disabled = busy;
    economySave.disabled = busy;
    testCoinsGrant.disabled = busy;
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
      throw new Error(String(data.error || 'Не удалось выполнить запрос панели администратора.'));
    }
    return data;
  };

  const renderBase = (data) => {
    const rawEnvironment = String(data.environment || 'production').toLowerCase();
    const environmentLabel = rawEnvironment === 'staging'
      ? 'ТЕСТОВАЯ СРЕДА'
      : rawEnvironment === 'production' ? 'РАБОЧАЯ СРЕДА' : 'НЕИЗВЕСТНАЯ СРЕДА';
    environment.textContent = environmentLabel;
    environmentBadge.textContent = environmentLabel;
    environmentBadge.dataset.environment = rawEnvironment;
    build.textContent = String(data.build || '—');
    generated.textContent = data.generated_at
      ? new Date(data.generated_at).toLocaleString('ru-RU')
      : '—';
    renderDashboard(String(data.dashboard || '—'));
    const runtime = data.runtime && typeof data.runtime === 'object' ? data.runtime : {};
    const alerts = Array.isArray(runtime.alerts) ? runtime.alerts : [];
    const maintenance = runtime.maintenance?.enabled === true;
    overviewSystem.textContent = maintenance ? 'Техработы' : alerts.length ? 'Есть предупреждения' : 'Работает';
    overviewSystemNote.textContent = alerts.length
      ? `Предупреждений: ${alerts.length}`
      : 'Ограничений не обнаружено';
    overviewSystem.closest('button')?.toggleAttribute('data-alert', alerts.length > 0 || maintenance);
    systemCheck.textContent = String(data.system_check || '—')
      .replaceAll('Dev-записей', 'Тестовых записей')
      .replaceAll('Структура: OK', 'Структура: исправна');
    const testsButton = navButtons.find(button => button.dataset.adminNavTarget === 'tests');
    if (testsButton) testsButton.hidden = rawEnvironment === 'production';
    root.classList.toggle('is-production', rawEnvironment === 'production');
    meta.hidden = false;
    content.hidden = false;
    applySection(activeSection, false);
  };

  const historyLabel = (entry) => {
    const type = entry.change_type === 'rollback'
      ? `откат к v${entry.source_version}`
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

  const load = async () => {
    if (requestInFlight) return;
    if (!telegram || !telegram.initData) {
      setStatus('Откройте панель администратора кнопкой из админ-панели бота в Telegram.', 'error');
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
      setStatus('Данные актуальны.', 'ok');
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
    setStatus(`Создаю версию отката из v${version}…`);
    try {
      const data = await post(economyEndpoint, {action: 'rollback', version, reason});
      renderEconomy(data);
      economyReason.value = '';
      setStatus(`Откат сохранён как новая v${data.current.version}. История не переписывалась.`, 'ok');
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось выполнить откат.', 'error');
    } finally {
      setBusy(false);
    }
  };

  const grantTestCoins = async () => {
    if (requestInFlight) return;
    const player = testCoinsPlayer.value.trim();
    const amount = Number(testCoinsAmount.value);
    const reason = testCoinsReason.value.trim();
    if (!Number.isSafeInteger(amount) || amount < 1 || amount > 250000) {
      testCoinsStatus.textContent = 'Укажите целое количество от 1 до 250 000.';
      testCoinsStatus.dataset.state = 'error';
      testCoinsAmount.focus();
      return;
    }
    if (reason.length < 3) {
      testCoinsStatus.textContent = 'Укажите причину начисления.';
      testCoinsStatus.dataset.state = 'error';
      testCoinsReason.focus();
      return;
    }
    const target = player || 'себе';
    if (!window.confirm(`Начислить ${amount.toLocaleString('ru-RU')} тестовых коинов ${target}?`)) return;

    setBusy(true);
    testCoinsStatus.textContent = 'Начисляю тестовые коины…';
    delete testCoinsStatus.dataset.state;
    try {
      const requestToken = `admin-test-coins:${Date.now()}:${String(telegram.initDataUnsafe?.user?.id || 'admin')}`;
      const data = await post(testCoinsEndpoint, {
        action: 'grant_test_coins',
        player,
        amount,
        reason,
        request_token: requestToken
      });
      const grant = data.grant || {};
      const syncNote = data.economy_sync === 'pending' ? ' Синхронизация ledger будет повторена магазином.' : '';
      testCoinsStatus.textContent = `${grant.player || target}: +${Number(grant.amount || amount).toLocaleString('ru-RU')}. Баланс: ${Number(grant.balance_after || 0).toLocaleString('ru-RU')}.${syncNote}`;
      testCoinsStatus.dataset.state = 'ok';
    } catch (error) {
      testCoinsStatus.textContent = error instanceof Error ? error.message : 'Не удалось начислить тестовые коины.';
      testCoinsStatus.dataset.state = 'error';
    } finally {
      setBusy(false);
    }
  };

  if (telegram) {
    telegram.ready();
    telegram.expand();
  }

  refresh.addEventListener('click', load);
  economySave.addEventListener('click', saveEconomy);
  testCoinsGrant.addEventListener('click', grantTestCoins);
  load();
})();
