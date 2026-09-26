(() => {
  'use strict';

  const root = document.querySelector('[data-system-api]');
  const card = root?.querySelector('[data-admin-system]');
  if (!root || !card) return;

  const endpoint = String(root.dataset.systemApi || '');
  const telegram = window.Telegram?.WebApp || null;
  const status = card.querySelector('[data-system-status]');
  const userCount = card.querySelector('[data-system-user-count]');
  const threshold = card.querySelector('[data-system-user-threshold]');
  const competition = card.querySelector('[data-system-competition]');
  const readiness = card.querySelector('[data-system-readiness]');
  const readinessAlert = card.querySelector('[data-system-readiness-alert]');
  const readinessReason = card.querySelector('[data-system-readiness-reason]');
  const ackReadiness = card.querySelector('[data-system-ack-readiness]');
  const maintenance = card.querySelector('[data-system-maintenance]');
  const maintenanceMessage = card.querySelector('[data-system-maintenance-message]');
  const financialReadOnly = card.querySelector('[data-system-financial-read-only]');
  const featureInputs = Array.from(card.querySelectorAll('[data-system-feature]'));
  const gameInputs = Array.from(card.querySelectorAll('[data-system-game]'));
  const flagReason = card.querySelector('[data-system-flag-reason]');
  const saveFlags = card.querySelector('[data-system-save-flags]');
  const refresh = card.querySelector('[data-system-refresh]');
  const rehearsal = card.querySelector('[data-system-rehearsal]');
  const rehearsalReason = card.querySelector('[data-system-rehearsal-reason]');
  const startRehearsal = card.querySelector('[data-system-start-rehearsal]');
  const stopRehearsal = card.querySelector('[data-system-stop-rehearsal]');
  const checklistInputs = Array.from(card.querySelectorAll('[data-system-check]'));
  const stagingSha = card.querySelector('[data-system-staging-sha]');
  const stagingNotes = card.querySelector('[data-system-staging-notes]');
  const acceptStaging = card.querySelector('[data-system-accept-staging]');
  const productionActivation = card.querySelector('[data-system-production-activation]');
  const activationReason = card.querySelector('[data-system-activation-reason]');
  const activationConfirm = card.querySelector('[data-system-activation-confirm]');
  const activateProduction = card.querySelector('[data-system-activate-production]');
  const audit = card.querySelector('[data-system-audit]');
  let busy = false;
  let current = null;

  const allButtons = () => Array.from(card.querySelectorAll('button'));

  const setStatus = (message, state = '') => {
    status.textContent = message;
    if (state) status.dataset.state = state;
    else delete status.dataset.state;
  };

  const setBusy = value => {
    busy = value;
    allButtons().forEach(button => { button.disabled = value; });
    if (!value) refreshActionState();
  };

  const post = async payload => {
    if (!telegram?.initData) throw new Error('Откройте панель администратора из Telegram.');
    const response = await fetch(endpoint, {
      method:'POST',
      cache:'no-store',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({...payload, initData:telegram.initData})
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.ok !== true) {
      throw new Error(String(data.error || 'Не удалось выполнить системное действие.'));
    }
    return data;
  };

  const competitionLabel = value => ({
    off:'Выключено',
    preseason:'Предсезон',
    active:'Активно',
  })[String(value || '').toLowerCase()] || String(value || '—');

  const formatDate = value => {
    if (!value) return '—';
    const raw = String(value);
    const date = new Date(raw.includes('T') ? raw : raw.replace(' ', 'T') + 'Z');
    return Number.isNaN(date.getTime()) ? raw : date.toLocaleString('ru-RU');
  };

  const auditLabel = code => ({
    readiness_threshold_reached:'Достигнут порог готовности',
    readiness_acknowledged:'Порог готовности подтверждён',
    feature_flags_updated:'Изменены системные переключатели',
    staging_rehearsal_started:'Начата репетиция официального сезона',
    staging_rehearsal_stopped:'Тестовая среда возвращена в «Предсезон»',
    staging_acceptance_recorded:'Зафиксирована ручная приёмка тестовой среды',
    official_competition_activated:'Запущен официальный рейтинговый сезон',
    activation_announcement_sent:'Отправлено объявление о запуске сезона',
  })[String(code || '')] || 'Системное действие';

  const renderAudit = rows => {
    audit.replaceChildren();
    const items = Array.isArray(rows) ? rows : [];
    if (!items.length) {
      const empty = document.createElement('div');
      empty.className = 'mgw-admin__history-empty';
      empty.textContent = 'Системных действий пока нет.';
      audit.append(empty);
      return;
    }
    items.forEach(row => {
      const item = document.createElement('div');
      item.className = 'mgw-admin__history-item';
      const copy = document.createElement('div');
      copy.className = 'mgw-admin__history-copy';
      const title = document.createElement('strong');
      const details = document.createElement('span');
      title.textContent = auditLabel(row.action_code);
      details.textContent = formatDate(row.created_at_utc) + ' · ' + (row.actor_ref || '—')
        + (row.reason_text ? ' · ' + row.reason_text : '');
      copy.append(title, details);
      item.append(copy);
      audit.append(item);
    });
  };

  const renderFlags = flags => {
    const source = flags && typeof flags === 'object' ? flags : {};
    maintenance.checked = source.maintenance_mode === true;
    maintenanceMessage.value = String(source.maintenance_message || '');
    financialReadOnly.checked = source.financial_read_only === true;
    featureInputs.forEach(input => {
      input.checked = source.features?.[input.dataset.systemFeature] === true;
    });
    gameInputs.forEach(input => {
      input.checked = source.games?.[input.dataset.systemGame] === true;
    });
  };

  const render = data => {
    current = data;
    const system = data.system || {};
    const users = system.users || {};
    const ready = system.readiness || {};
    const staging = system.staging_acceptance || {};
    const rehearsalState = system.staging_rehearsal || {};
    const comp = system.competition || {};
    const environment = String(system.environment || data.runtime?.environment || 'production').toLowerCase();

    userCount.textContent = Number(users.canonical_count || 0).toLocaleString('ru-RU');
    threshold.textContent = Number(users.threshold || 500).toLocaleString('ru-RU');
    competition.textContent = competitionLabel(comp.competition_state);
    readiness.textContent = ready.reached
      ? (ready.acknowledged ? 'Порог подтверждён' : 'Нужно подтвердить')
      : Number(users.canonical_count || 0) + ' / ' + Number(users.threshold || 500);

    readinessAlert.hidden = ready.alert_visible !== true;
    if (!readinessAlert.hidden) {
      readinessAlert.querySelector('span').textContent =
        'Зафиксировано: ' + formatDate(ready.reached_at) + '. Автоматического запуска нет.';
    }

    rehearsal.hidden = rehearsalState.available !== true;
    productionActivation.hidden = environment !== 'production';

    const acceptedChecklist = staging.checklist || {};
    checklistInputs.forEach(input => {
      input.checked = acceptedChecklist[input.dataset.systemCheck] === true;
    });
    if (staging.sha) stagingSha.value = String(staging.sha);
    if (staging.notes) stagingNotes.value = String(staging.notes);

    renderFlags(data.persisted_flags || {});
    renderAudit(system.recent_audit || []);

    const runtimeAlerts = Array.isArray(data.runtime?.alerts) ? data.runtime.alerts : [];
    const runtimeLabel = runtimeAlerts.length
      ? 'Система: предупреждений ' + runtimeAlerts.length
      : 'Система: ограничений не обнаружено';
    const acceptanceLabel = staging.accepted
      ? ' · тестовая среда принята, версия ' + String(staging.sha || '').slice(0, 8)
      : ' · ручная приёмка тестовой среды не зафиксирована';
    setStatus(runtimeLabel + acceptanceLabel, runtimeAlerts.length ? 'warn' : 'ok');

    window.dispatchEvent(new CustomEvent('mgw:admin-system-summary', {
      detail:{
        environment,
        competition_state:comp.competition_state,
        readiness_alert:ready.alert_visible === true,
        canonical_user_count:Number(users.canonical_count || 0)
      }
    }));

    refreshActionState();
  };

  const refreshActionState = () => {
    if (busy || !current) return;
    const system = current.system || {};
    const ready = system.readiness || {};
    const rehearsalState = system.staging_rehearsal || {};
    const compState = String(system.competition?.competition_state || '').toLowerCase();
    const activation = system.activation || {};
    const environment = String(system.environment || '').toLowerCase();

    ackReadiness.disabled = ready.alert_visible !== true;
    startRehearsal.disabled = environment === 'production'
      || rehearsalState.active === true
      || compState !== 'preseason';
    stopRehearsal.disabled = environment === 'production'
      || rehearsalState.active !== true;
    activateProduction.disabled = !(
      environment === 'production'
      && activation.production_action_available === true
      && activationConfirm.checked
    );
    saveFlags.disabled = false;
    refresh.disabled = false;
    acceptStaging.disabled = false;
  };

  const load = async (message = 'Обновляю состояние системы…') => {
    if (busy) return;
    setBusy(true);
    setStatus(message);
    try {
      render(await post({action:'snapshot'}));
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось загрузить состояние системы.', 'error');
    } finally {
      setBusy(false);
    }
  };

  const collectFlags = () => ({
    maintenance_mode:maintenance.checked,
    maintenance_message:maintenanceMessage.value.trim(),
    financial_read_only:financialReadOnly.checked,
    features:Object.fromEntries(featureInputs.map(input => [input.dataset.systemFeature, input.checked])),
    games:Object.fromEntries(gameInputs.map(input => [input.dataset.systemGame, input.checked])),
  });

  const updateFlags = async () => {
    if (busy) return;
    const reason = flagReason.value.trim();
    if (reason.length < 3) {
      setStatus('Укажите причину изменения системных переключателей.', 'error');
      flagReason.focus();
      return;
    }
    if (!window.confirm('Сохранить эти системные переключатели? Изменения начнут действовать для новых запросов.')) return;

    setBusy(true);
    setStatus('Сохраняю системные переключатели…');
    try {
      const data = await post({action:'update_flags', flags:collectFlags(), reason});
      const changed = data.operation?.changed === true;
      const refreshed = await post({action:'snapshot'});
      flagReason.value = '';
      render(refreshed);
      setStatus(changed ? 'Переключатели сохранены и записаны в аудит.' : 'Изменений в переключателях нет.', 'ok');
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось сохранить переключатели.', 'error');
    } finally {
      setBusy(false);
    }
  };

  const acknowledge = async () => {
    if (busy) return;
    const reason = readinessReason.value.trim();
    if (reason.length < 3) {
      setStatus('Укажите причину подтверждения уведомления о готовности.', 'error');
      readinessReason.focus();
      return;
    }
    setBusy(true);
    try {
      const data = await post({action:'acknowledge_readiness', reason});
      readinessReason.value = '';
      render(data);
      setStatus('Уведомление о готовности подтверждено. Официальный сезон не запускался.', 'ok');
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось подтвердить уведомление о готовности.', 'error');
    } finally {
      setBusy(false);
    }
  };

  const runRehearsal = async action => {
    if (busy) return;
    const reason = rehearsalReason.value.trim();
    if (reason.length < 3) {
      setStatus('Укажите причину репетиции на тестовой среде.', 'error');
      rehearsalReason.focus();
      return;
    }
    const start = action === 'start_staging_rehearsal';
    const copy = start
      ? 'Начать репетицию? Только тестовая среда будет временно переведена из «Предсезона» в состояние «Активно».'
      : 'Завершить репетицию и вернуть тестовую среду в «Предсезон»? Журнал действий сохранится.';
    if (!window.confirm(copy)) return;

    setBusy(true);
    setStatus(start ? 'Запускаю репетицию на тестовой среде…' : 'Возвращаю тестовую среду в «Предсезон»…');
    try {
      const data = await post({action, reason});
      rehearsalReason.value = '';
      render(data);
      setStatus(start ? 'Репетиция на тестовой среде активна.' : 'Тестовая среда возвращена в «Предсезон». Журнал действий сохранён.', 'ok');
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось изменить состояние репетиции.', 'error');
    } finally {
      setBusy(false);
    }
  };

  const acceptStagingChecklist = async () => {
    if (busy) return;
    const sha = stagingSha.value.trim().toLowerCase();
    if (!/^[a-f0-9]{40}$/.test(sha)) {
      setStatus('Укажите полный 40-символьный SHA тестовой среды.', 'error');
      stagingSha.focus();
      return;
    }
    const checklist = Object.fromEntries(
      checklistInputs.map(input => [input.dataset.systemCheck, input.checked])
    );
    const missing = checklistInputs.filter(input => !input.checked);
    if (missing.length) {
      setStatus('Проверка не завершена: осталось пунктов — ' + missing.length + '.', 'error');
      missing[0].focus();
      return;
    }
    if (!window.confirm('Зафиксировать ручную приёмку тестовой среды для версии ' + sha.slice(0, 12) + '?')) return;

    setBusy(true);
    try {
      const data = await post({
        action:'accept_staging',
        staging_sha:sha,
        checklist,
        notes:stagingNotes.value.trim()
      });
      render(data);
      setStatus('Ручная приёмка тестовой среды зафиксирована.', 'ok');
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось сохранить ручную приёмку тестовой среды.', 'error');
    } finally {
      setBusy(false);
    }
  };

  const activateOfficial = async () => {
    if (busy || !activationConfirm.checked) return;
    const reason = activationReason.value.trim();
    if (reason.length < 3) {
      setStatus('Укажите причину запуска официальных сезонов.', 'error');
      activationReason.focus();
      return;
    }
    if (!window.confirm('Запустить официальный рейтинговый сезон на боевом сервере и один раз отправить всем пользователям объявление?')) return;

    setBusy(true);
    setStatus('Запускаю официальный рейтинговый сезон…');
    try {
      const data = await post({action:'activate_official_competition', reason});
      activationConfirm.checked = false;
      activationReason.value = '';
      render(data);
      setStatus('Официальный рейтинговый сезон запущен. Объявление пользователям отправлено через общую систему уведомлений.', 'ok');
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось запустить официальный сезон.', 'error');
    } finally {
      setBusy(false);
    }
  };

  saveFlags.addEventListener('click', updateFlags);
  refresh.addEventListener('click', () => load());
  ackReadiness.addEventListener('click', acknowledge);
  startRehearsal.addEventListener('click', () => runRehearsal('start_staging_rehearsal'));
  stopRehearsal.addEventListener('click', () => runRehearsal('stop_staging_rehearsal'));
  acceptStaging.addEventListener('click', acceptStagingChecklist);
  activationConfirm.addEventListener('change', refreshActionState);
  activateProduction.addEventListener('click', activateOfficial);

  load('Загружаю состояние системы и переключатели…');
})();
