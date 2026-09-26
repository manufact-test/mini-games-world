(() => {
  'use strict';

  const root = document.querySelector('[data-incident-api]');
  const card = root?.querySelector('[data-admin-incident]');
  if (!root || !card) return;

  const endpoint = String(root.dataset.incidentApi || '');
  const telegram = window.Telegram?.WebApp || null;
  const q = selector => card.querySelector(selector);
  const qa = selector => Array.from(card.querySelectorAll(selector));

  const status = q('[data-incident-status]');
  const environment = q('[data-incident-environment]');
  const build = q('[data-incident-build]');
  const schema = q('[data-incident-schema]');
  const sessions = q('[data-incident-sessions]');
  const security = q('[data-incident-security]');
  const emptyState = q('[data-incident-empty]');
  const activeState = q('[data-incident-active]');
  const activeTitle = q('[data-incident-active-title]');
  const activeMeta = q('[data-incident-active-meta]');
  const activeSummary = q('[data-incident-active-summary]');
  const activeStatus = q('[data-incident-active-status]');
  const createTitle = q('[data-incident-create-title]');
  const createSummary = q('[data-incident-create-summary]');
  const createButton = q('[data-incident-create]');
  const lifecycleStatus = q('[data-incident-lifecycle-status]');
  const lifecycleReason = q('[data-incident-lifecycle-reason]');
  const lifecycleSave = q('[data-incident-lifecycle-save]');
  const riskReason = q('[data-incident-risk-reason]');
  const riskActions = qa('[data-incident-request-risk]');
  const pendingActions = q('[data-incident-actions]');
  const keyRows = qa('[data-incident-key-row]');
  const evidenceType = q('[data-incident-evidence-type]');
  const evidenceLabel = q('[data-incident-evidence-label]');
  const evidenceReference = q('[data-incident-evidence-reference]');
  const evidenceFingerprint = q('[data-incident-evidence-fingerprint]');
  const evidenceAdd = q('[data-incident-add-evidence]');
  const evidenceList = q('[data-incident-evidence-list]');
  const restoreStatus = q('[data-incident-restore-status]');
  const restoreBackup = q('[data-incident-restore-backup]');
  const restoreRollback = q('[data-incident-restore-rollback]');
  const restoreSha = q('[data-incident-restore-sha]');
  const restoreNotes = q('[data-incident-restore-notes]');
  const restoreSave = q('[data-incident-save-restore]');
  const audit = q('[data-incident-audit]');
  const refreshButtons = qa('[data-incident-refresh]');\n  const incidentArchive = q('[data-incident-archive]');
  const rehearsal = q('[data-incident-rehearsal]');
  const rehearsalRun = q('[data-incident-run-rehearsal]');

  let current = null;
  let busy = false;

  const incidentStatusLabel = value => ({
    open:'Открыт',
    mitigating:'Локализация',
    recovering:'Восстановление',
    resolved:'Завершён',
  })[String(value || '').toLowerCase()] || String(value || '—');

  const actionLabel = value => ({
    enable_security_mode:'Включить режим безопасности',
    disable_security_mode:'Выключить режим безопасности',
    revoke_all_sessions:'Отозвать все активные сессии',
  })[String(value || '')] || 'Опасное действие';

  const actionStatusLabel = value => ({
    pending_second_review:'Ожидает второго администратора',
    executing:'Выполняется',
    completed:'Выполнено',
    rejected:'Отклонено',
    failed:'Ошибка выполнения',
  })[String(value || '')] || String(value || '—');

  const keyLabel = value => ({
    telegram_bot_token:'Telegram Bot Token',
    database_credentials:'Доступ к базе данных',
    account_data_hook_secret:'Ключ запроса данных аккаунта',
  })[String(value || '')] || String(value || '—');

  const keyStatusLabel = value => ({
    pending:'Нужно проверить',
    rotated:'Заменён',
    verified:'Проверен',
    not_applicable:'Не требуется',
  })[String(value || '')] || String(value || '—');

  const restoreStatusLabel = value => ({
    not_started:'Не начато',
    preparing:'Подготовка',
    ready:'Готово к проверке',
    verified:'Проверено',
    blocked:'Заблокировано',
  })[String(value || '')] || String(value || '—');

  const auditLabel = value => ({
    incident_opened:'Инцидент открыт',
    incident_status_changed:'Изменён этап инцидента',
    key_check_updated:'Обновлена проверка ключей',
    evidence_preserved:'Сохранено доказательство',
    restore_status_updated:'Обновлён статус восстановления',
    high_risk_action_requested:'Запрошено опасное действие',
    high_risk_action_confirmed:'Опасное действие подтверждено вторым администратором',
    high_risk_action_rejected:'Опасное действие отклонено',
    high_risk_action_completed:'Опасное действие выполнено',
    high_risk_action_failed:'Ошибка опасного действия',
  })[String(value || '')] || 'Действие по инциденту';

  const formatDate = value => {
    if (!value) return '—';
    const raw = String(value);
    const date = new Date(raw.includes('T') ? raw : raw.replace(' ', 'T') + 'Z');
    return Number.isNaN(date.getTime()) ? raw : date.toLocaleString('ru-RU');
  };

  const setStatus = (message, state = '') => {
    status.textContent = message;
    if (state) status.dataset.state = state;
    else delete status.dataset.state;
  };

  const allButtons = () => qa('button');

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
      throw new Error(String(data.error || 'Не удалось выполнить действие по инциденту.'));
    }
    return data;
  };

  const run = async (payload, successMessage) => {
    if (busy) return;
    setBusy(true);
    setStatus('Выполняю действие…');
    try {
      const data = await post(payload);
      render(data);
      setStatus(successMessage, 'success');
    } catch (error) {
      setStatus(error?.message || 'Не удалось выполнить действие.', 'error');
    } finally {
      setBusy(false);
    }
  };

  const renderHistory = (node, rows, labelFn) => {
    node.replaceChildren();
    const items = Array.isArray(rows) ? rows : [];
    if (!items.length) {
      const empty = document.createElement('div');
      empty.className = 'mgw-admin__incident-empty-list';
      empty.textContent = 'Записей пока нет.';
      node.append(empty);
      return;
    }
    items.forEach(row => {
      const item = document.createElement('div');
      item.className = 'mgw-admin__incident-history-item';
      const title = document.createElement('strong');
      title.textContent = labelFn(row);
      const meta = document.createElement('span');
      meta.textContent = [
        formatDate(row.created_at_utc || row.requested_at_utc),
        row.actor_ref || row.requested_by_ref || row.captured_by_ref || '',
      ].filter(Boolean).join(' · ');
      const note = document.createElement('small');
      note.textContent = String(row.reason_text || row.reference_text || row.summary_text || '');
      if (!note.textContent) note.hidden = true;
      item.append(title, meta, note);
      node.append(item);
    });
  };

  const renderPendingActions = rows => {
    pendingActions.replaceChildren();
    const items = Array.isArray(rows) ? rows : [];
    if (!items.length) {
      const empty = document.createElement('div');
      empty.className = 'mgw-admin__incident-empty-list';
      empty.textContent = 'Опасных действий по этому инциденту пока нет.';
      pendingActions.append(empty);
      return;
    }

    items.forEach(row => {
      const item = document.createElement('div');
      item.className = 'mgw-admin__incident-action';
      if (row.status_code === 'pending_second_review') item.classList.add('is-pending');

      const head = document.createElement('div');
      head.className = 'mgw-admin__incident-action-head';
      const title = document.createElement('strong');
      title.textContent = actionLabel(row.action_code);
      const badge = document.createElement('span');
      badge.textContent = actionStatusLabel(row.status_code);
      head.append(title, badge);

      const reason = document.createElement('p');
      reason.textContent = row.reason_text || 'Причина не указана.';
      const meta = document.createElement('small');
      meta.textContent = formatDate(row.requested_at_utc) + ' · ' + (row.requested_by_ref || '—');

      item.append(head, reason, meta);

      if (row.status_code === 'pending_second_review') {
        const controls = document.createElement('div');
        controls.className = 'mgw-admin__incident-review';
        const note = document.createElement('input');
        note.type = 'text';
        note.maxLength = 800;
        note.placeholder = 'Комментарий второго администратора';
        note.autocomplete = 'off';

        const buttons = document.createElement('div');
        buttons.className = 'mgw-admin__incident-review-buttons';
        const approve = document.createElement('button');
        approve.type = 'button';
        approve.textContent = 'Подтвердить';
        approve.className = 'mgw-admin__incident-danger';
        const reject = document.createElement('button');
        reject.type = 'button';
        reject.textContent = 'Отклонить';

        approve.addEventListener('click', () => {
          const reviewNote = note.value.trim();
          if (!reviewNote) {
            setStatus('Добавьте комментарий второго администратора.', 'error');
            note.focus();
            return;
          }
          if (!window.confirm('Подтвердить опасное действие? Сервер дополнительно проверит, что вы не автор запроса.')) return;
          run({
            action:'review_high_risk_action',
            action_id:row.action_id,
            decision:'approve',
            review_note:reviewNote,
          }, 'Опасное действие подтверждено и обработано.');
        });
        reject.addEventListener('click', () => {
          const reviewNote = note.value.trim();
          if (!reviewNote) {
            setStatus('Добавьте комментарий второго администратора.', 'error');
            note.focus();
            return;
          }
          run({
            action:'review_high_risk_action',
            action_id:row.action_id,
            decision:'reject',
            review_note:reviewNote,
          }, 'Опасное действие отклонено.');
        });

        buttons.append(approve, reject);
        controls.append(note, buttons);
        item.append(controls);
      }

      pendingActions.append(item);
    });
  };

  const renderKeyChecks = rows => {
    const byCode = new Map((Array.isArray(rows) ? rows : []).map(row => [String(row.key_code), row]));
    keyRows.forEach(row => {
      const code = String(row.dataset.incidentKeyRow || '');
      const data = byCode.get(code);
      const title = row.querySelector('[data-incident-key-name]');
      const select = row.querySelector('[data-incident-key-status]');
      const note = row.querySelector('[data-incident-key-note]');
      const meta = row.querySelector('[data-incident-key-meta]');
      if (title) title.textContent = keyLabel(code);
      if (select) select.value = String(data?.status_code || 'pending');
      if (note) note.value = String(data?.note_text || '');
      if (meta) meta.textContent = data
        ? keyStatusLabel(data.status_code) + ' · ' + formatDate(data.updated_at_utc)
        : 'Нужно проверить';
    });
  };

  const renderRestore = row => {
    const data = row && typeof row === 'object' ? row : {};
    restoreStatus.value = String(data.status_code || 'not_started');
    restoreBackup.value = String(data.backup_reference || '');
    restoreRollback.value = String(data.rollback_reference || '');
    restoreSha.value = String(data.restore_sha || '');
    restoreNotes.value = String(data.notes_text || '');
  };

  const render = data => {
    current = data;
    const health = data.health || {};
    const snapshot = data.incidents || {};
    const active = snapshot.active_incident || null;
    const env = String(data.environment || '—');

    environment.textContent = env.toUpperCase();
    build.textContent = String(data.runtime_build || '—');
    schema.textContent = health.schema_current === true ? 'Актуальна' : 'Нужна миграция';
    schema.dataset.state = health.schema_current === true ? 'good' : 'bad';
    sessions.textContent = Number(health.active_sessions || 0).toLocaleString('ru-RU');
    security.textContent = health.security_mode === true ? 'Включён' : 'Обычный режим';
    security.dataset.state = health.security_mode === true ? 'warn' : 'good';

    emptyState.hidden = !!active;
    activeState.hidden = !active;
    rehearsal.hidden = !['staging','local'].includes(env.toLowerCase());

    if (!active) {
      activeTitle.textContent = '—';
      activeMeta.textContent = '';
      activeSummary.textContent = '';
      renderPendingActions([]);
      renderKeyChecks([]);
      renderRestore(null);
      renderHistory(evidenceList, [], row => row.label_text || 'Доказательство');
      renderHistory(audit, [], row => auditLabel(row.action_code));
      setStatus('Активных инцидентов нет.', 'success');
      refreshActionState();
      return;
    }

    activeTitle.textContent = active.title || active.incident_id || 'Инцидент';
    activeMeta.textContent = [
      incidentStatusLabel(active.incident_status),
      active.incident_id,
      formatDate(active.opened_at_utc),
    ].filter(Boolean).join(' · ');
    activeSummary.textContent = active.summary_text || 'Описание не добавлено.';
    activeStatus.value = String(active.incident_status || 'open');
    lifecycleStatus.value = String(active.incident_status || 'open');

    renderPendingActions(snapshot.pending_actions || []);
    renderKeyChecks(snapshot.key_checks || []);
    renderRestore(snapshot.restore_status || null);
    renderHistory(
      evidenceList,
      snapshot.evidence || [],
      row => row.label_text || 'Доказательство'
    );
    renderHistory(
      audit,
      snapshot.recent_audit || [],
      row => auditLabel(row.action_code)
    );

    if (health.security_mode === true) {
      setStatus('Активен режим безопасности. Опасные действия требуют второго администратора.', 'warning');
    } else {
      setStatus('Консоль восстановления готова.', 'success');
    }
    refreshActionState();
  };

  const refreshActionState = () => {
    if (busy) return;
    const active = current?.incidents?.active_incident || null;
    const resolved = String(active?.incident_status || '') === 'resolved';
    allButtons().forEach(button => { button.disabled = false; });
    lifecycleSave.disabled = !active || resolved;
    restoreSave.disabled = !active || resolved;
    evidenceAdd.disabled = !active || resolved;
    riskActions.forEach(button => {
      const code = String(button.dataset.incidentRequestRisk || '');
      const isSecurity = current?.health?.security_mode === true;
      button.disabled = !active || resolved
        || (code === 'enable_security_mode' && isSecurity)
        || (code === 'disable_security_mode' && !isSecurity);
    });
    keyRows.forEach(row => {
      const save = row.querySelector('[data-incident-save-key]');
      if (save) save.disabled = !active || resolved;
    });
  };

  createButton?.addEventListener('click', () => {
    const title = createTitle.value.trim();
    if (!title) {
      setStatus('Введите название инцидента.', 'error');
      createTitle.focus();
      return;
    }
    run({
      action:'create_incident',
      title,
      summary:createSummary.value.trim(),
    }, 'Инцидент открыт.');
  });

  lifecycleSave?.addEventListener('click', () => {
    const active = current?.incidents?.active_incident;
    if (!active) return;
    const reason = lifecycleReason.value.trim();
    if (!reason) {
      setStatus('Укажите причину изменения этапа.', 'error');
      lifecycleReason.focus();
      return;
    }
    const target = lifecycleStatus.value;
    if (target === 'resolved' && !window.confirm('Завершить инцидент? После этого карточка станет доступна только для чтения.')) return;
    run({
      action:'set_incident_status',
      incident_id:active.incident_id,
      status:target,
      reason,
    }, 'Этап инцидента обновлён.');
  });

  riskActions.forEach(button => {
    button.addEventListener('click', () => {
      const active = current?.incidents?.active_incident;
      if (!active) return;
      const reason = riskReason.value.trim();
      if (!reason) {
        setStatus('Укажите причину опасного действия.', 'error');
        riskReason.focus();
        return;
      }
      const actionCode = String(button.dataset.incidentRequestRisk || '');
      if (!window.confirm('Создать запрос «' + actionLabel(actionCode) + '»? Само действие выполнится только после подтверждения другим администратором.')) return;
      run({
        action:'request_high_risk_action',
        incident_id:active.incident_id,
        action_code:actionCode,
        reason,
      }, 'Запрос создан. Требуется подтверждение другого администратора.');
    });
  });

  keyRows.forEach(row => {
    const save = row.querySelector('[data-incident-save-key]');
    save?.addEventListener('click', () => {
      const active = current?.incidents?.active_incident;
      if (!active) return;
      run({
        action:'update_key_check',
        incident_id:active.incident_id,
        key_code:String(row.dataset.incidentKeyRow || ''),
        status:String(row.querySelector('[data-incident-key-status]')?.value || 'pending'),
        note:String(row.querySelector('[data-incident-key-note]')?.value || '').trim(),
      }, 'Проверка ключа обновлена.');
    });
  });

  evidenceAdd?.addEventListener('click', () => {
    const active = current?.incidents?.active_incident;
    if (!active) return;
    run({
      action:'add_evidence',
      incident_id:active.incident_id,
      evidence_type:evidenceType.value,
      label:evidenceLabel.value.trim(),
      reference:evidenceReference.value.trim(),
      fingerprint_sha256:evidenceFingerprint.value.trim(),
    }, 'Доказательство сохранено.');
  });

  restoreSave?.addEventListener('click', () => {
    const active = current?.incidents?.active_incident;
    if (!active) return;
    run({
      action:'update_restore_status',
      incident_id:active.incident_id,
      status:restoreStatus.value,
      backup_reference:restoreBackup.value.trim(),
      rollback_reference:restoreRollback.value.trim(),
      restore_sha:restoreSha.value.trim(),
      notes:restoreNotes.value.trim(),
    }, 'Статус восстановления обновлён.');
  });

  refreshButtons.forEach(button => button.addEventListener('click', () => run({action:'snapshot'}, 'Состояние обновлено.')));

  rehearsalRun?.addEventListener('click', () => {
    if (!window.confirm('Запустить безопасную учебную симуляцию? Она не включает режим безопасности, не отзывает сессии и не меняет production.')) return;
    run({action:'run_staging_rehearsal'}, 'Учебная симуляция завершена без изменения production.');
  });

  const bootstrap = async () => {
    setBusy(true);
    setStatus('Загружаю консоль восстановления…');
    try {
      render(await post({action:'snapshot'}));
    } catch (error) {
      setStatus(error?.message || 'Не удалось загрузить консоль восстановления.', 'error');
    } finally {
      setBusy(false);
    }
  };

  bootstrap();
})();
