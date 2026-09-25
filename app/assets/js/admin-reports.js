(() => {
  'use strict';

  const root = document.querySelector('[data-admin-reports]');
  const shell = document.querySelector('[data-reports-api]');
  if (!root || !shell) return;

  const endpoint = String(shell.dataset.reportsApi || '');
  const telegram = window.Telegram?.WebApp || null;
  const status = root.querySelector('[data-report-queue-status]');
  const list = root.querySelector('[data-report-queue-list]');
  const refresh = root.querySelector('[data-report-queue-refresh]');
  const modeButtons = Array.from(root.querySelectorAll('[data-report-mode]'));
  const queryInput = root.querySelector('[data-report-filter-query]');
  const dateFromInput = root.querySelector('[data-report-filter-from]');
  const dateToInput = root.querySelector('[data-report-filter-to]');
  const requestedCase = new URLSearchParams(window.location.search).get('report') || '';
  let queueMode = requestedCase ? 'all' : 'active';
  const reportFilters = () => ({
    mode:queueMode,
    query:String(queryInput?.value || '').trim(),
    date_from:String(dateFromInput?.value || ''),
    date_to:String(dateToInput?.value || ''),
  });
  let busy = false;
  let adminRef = '';
  let moderationOptions = { restriction_scopes:{}, restriction_durations:[] };

  const post = async (payload) => {
    const response = await fetch(endpoint, {
      method:'POST',
      cache:'no-store',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({...payload, filters:payload.filters || reportFilters(), initData:telegram?.initData || ''}),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.ok !== true) throw new Error(String(data.error || 'Не удалось загрузить очередь жалоб.'));
    return data;
  };

  const labelStatus = (value) => ({ open:'Новая', reviewing:'В работе', closed:'Рассмотрена' })[value] || value;
  const statusTone = (value) => ({ open:'new', reviewing:'reviewing', closed:'closed' })[value] || 'neutral';

  const actionButton = (reportId, nextStatus, label) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'mgw-admin__report-action';
    button.textContent = label;
    button.dataset.reportId = reportId;
    button.dataset.reportStatus = nextStatus;
    button.dataset.reportActionTone = nextStatus === 'closed' ? 'complete' : (nextStatus === 'reviewing' ? 'primary' : 'secondary');
    button.addEventListener('click', () => void changeStatus(reportId, nextStatus));
    return button;
  };

  const localTime = value => {
    if (!value) return '—';
    const raw = String(value);
    const date = new Date(raw.includes('T') ? raw : raw.replace(' ', 'T') + 'Z');
    return Number.isNaN(date.getTime()) ? raw : date.toLocaleString('ru-RU');
  };

  const moderationButton = (label, handler) => {
    const node = document.createElement('button');
    node.type = 'button';
    node.textContent = label;
    node.addEventListener('click', handler);
    return node;
  };

  const moderationTextarea = placeholder => {
    const node = document.createElement('textarea');
    node.rows = 3;
    node.maxLength = 800;
    node.placeholder = placeholder;
    return node;
  };

  const moderationField = (label, control) => {
    const wrap = document.createElement('label');
    wrap.className = 'mgw-admin__field';
    const span = document.createElement('span');
    span.textContent = label;
    wrap.append(span, control);
    return wrap;
  };

  const moderationSelect = entries => {
    const node = document.createElement('select');
    entries.forEach(entry => {
      const option = document.createElement('option');
      option.value = String(entry[0]);
      option.textContent = String(entry[1]);
      node.append(option);
    });
    return node;
  };

  const moderationActionLabel = value => ({
    warning:'Предупреждение',
    restriction:'Ограничение',
    permanent_ban:'Постоянная блокировка',
  })[value] || value;

  const moderationStatusLabel = value => ({
    active:'Активно',
    pending_second_review:'Ждёт второго администратора',
    confirmed:'Подтверждено',
    rejected:'Отклонено',
    revoked:'Отменено',
    expired:'Истекло',
  })[value] || value;

  const appealStatusLabel = value => ({
    open:'Новая',
    reviewing:'В работе',
    accepted:'Удовлетворена',
    rejected:'Отклонена',
  })[value] || value;

  const applySnapshot = data => {
    adminRef = String(data.admin_ref || adminRef || '');
    moderationOptions = Object.assign({}, moderationOptions, data.moderation_options || {});
    render(data.reports || []);
  };

  const mutateModeration = async (payload, successMessage) => {
    if (busy) return;
    busy = true;
    refresh.disabled = true;
    list.querySelectorAll('button,select,textarea,input').forEach(node => { node.disabled = true; });
    try {
      const data = await post(payload);
      applySnapshot(data);
      status.textContent = successMessage;
      status.dataset.state = 'ok';
    } catch (error) {
      status.textContent = error instanceof Error ? error.message : 'Не удалось выполнить действие модерации.';
      status.dataset.state = 'error';
    } finally {
      busy = false;
      refresh.disabled = false;
      list.querySelectorAll('button,select,textarea,input').forEach(node => { node.disabled = false; });
    }
  };

  const moderationPanel = report => {
    const rootPanel = document.createElement('details');
    rootPanel.className = 'mgw-admin__moderation-panel';
    const summary = document.createElement('summary');
    summary.textContent = 'Решение и история модерации';
    rootPanel.append(summary);

    const body = document.createElement('div');
    body.className = 'mgw-admin__moderation-panel-body';

    const note = moderationTextarea('Что проверено и почему применяется действие');
    const scope = moderationSelect(Object.entries(moderationOptions.restriction_scopes || {}));
    const duration = moderationSelect((moderationOptions.restriction_durations || []).map(item => [item.seconds, item.label]));

    const grid = document.createElement('div');
    grid.className = 'mgw-admin__moderation-grid';
    grid.append(
      moderationField('Область ограничения', scope),
      moderationField('Срок', duration)
    );

    const actions = document.createElement('div');
    actions.className = 'mgw-admin__economy-actions';
    actions.append(
      moderationButton('Предупреждение', () => void mutateModeration({
        action:'warning',
        report_id:report.report_id,
        note:note.value.trim(),
      }, 'Предупреждение сохранено.')),
      moderationButton('Ограничить', () => void mutateModeration({
        action:'restrict',
        report_id:report.report_id,
        scope:scope.value,
        duration_seconds:Number(duration.value),
        note:note.value.trim(),
      }, 'Ограничение применено.')),
      moderationButton('На постоянную блокировку', () => void mutateModeration({
        action:'recommend_ban',
        report_id:report.report_id,
        note:note.value.trim(),
      }, 'Рекомендация отправлена на вторую проверку.'))
    );

    body.append(moderationField('Основание решения', note), grid, actions);

    const moderation = report.moderation || {};
    const actionRows = Array.isArray(moderation.actions) ? moderation.actions : [];
    if (actionRows.length) {
      const historyTitle = document.createElement('h4');
      historyTitle.textContent = 'История решений';
      body.append(historyTitle);

      actionRows.forEach(item => {
        const row = document.createElement('div');
        row.className = 'mgw-admin__moderation-event';
        const strong = document.createElement('strong');
        strong.textContent = moderationActionLabel(item.action_type) + ' · ' + moderationStatusLabel(item.status);
        const meta = document.createElement('span');
        const metaParts = [];
        if (item.scope_label) metaParts.push(item.scope_label);
        if (item.expires_at_utc) metaParts.push('до ' + localTime(item.expires_at_utc));
        if (item.created_by_admin_ref) metaParts.push(item.created_by_admin_ref);
        meta.textContent = metaParts.join(' · ');
        const p = document.createElement('p');
        p.textContent = item.note || 'Без комментария.';
        row.append(strong, meta, p);
        body.append(row);

        if (item.action_type === 'permanent_ban' && item.status === 'pending_second_review') {
          const review = document.createElement('div');
          review.className = 'mgw-admin__moderation-second-review';
          const reviewTitle = document.createElement('strong');
          reviewTitle.textContent = 'Вторая проверка постоянной блокировки';
          review.append(reviewTitle);

          if (String(item.created_by_admin_ref || '') === adminRef) {
            const warning = document.createElement('p');
            warning.textContent = 'Подтверждение должен выполнить другой администратор.';
            review.append(warning);
          } else {
            const reviewNote = moderationTextarea('Комментарий второго администратора');
            const reviewActions = document.createElement('div');
            reviewActions.className = 'mgw-admin__economy-actions';
            reviewActions.append(
              moderationButton('Подтвердить блокировку', () => void mutateModeration({
                action:'review_ban',
                report_id:report.report_id,
                moderation_action_id:item.action_id,
                decision:'approve',
                note:reviewNote.value.trim(),
              }, 'Постоянная блокировка подтверждена.')),
              moderationButton('Отклонить', () => void mutateModeration({
                action:'review_ban',
                report_id:report.report_id,
                moderation_action_id:item.action_id,
                decision:'reject',
                note:reviewNote.value.trim(),
              }, 'Рекомендация отклонена.'))
            );
            review.append(moderationField('Комментарий второй проверки', reviewNote), reviewActions);
          }
          body.append(review);
        }
      });
    }

    const appeals = Array.isArray(moderation.appeals) ? moderation.appeals : [];
    if (appeals.length) {
      const appealsTitle = document.createElement('h4');
      appealsTitle.textContent = 'Апелляции';
      body.append(appealsTitle);
      appeals.forEach(appeal => {
        const card = document.createElement('div');
        card.className = 'mgw-admin__moderation-appeal';
        const title = document.createElement('strong');
        title.textContent = 'Апелляция · ' + appealStatusLabel(appeal.status);
        const meta = document.createElement('span');
        meta.textContent = String(appeal.appeal_id || '') + ' · ' + localTime(appeal.created_at_utc);
        const text = document.createElement('p');
        text.textContent = appeal.message || 'Без текста.';
        card.append(title, meta, text);

        if (appeal.status === 'open' || appeal.status === 'reviewing') {
          const reviewNote = moderationTextarea('Комментарий администратора по апелляции');
          const reviewActions = document.createElement('div');
          reviewActions.className = 'mgw-admin__economy-actions';
          reviewActions.append(
            moderationButton('Удовлетворить', () => void mutateModeration({
              action:'review_appeal',
              report_id:report.report_id,
              appeal_id:appeal.appeal_id,
              decision:'accept',
              note:reviewNote.value.trim(),
            }, 'Апелляция удовлетворена.')),
            moderationButton('Отклонить', () => void mutateModeration({
              action:'review_appeal',
              report_id:report.report_id,
              appeal_id:appeal.appeal_id,
              decision:'reject',
              note:reviewNote.value.trim(),
            }, 'Апелляция отклонена.'))
          );
          card.append(moderationField('Решение по апелляции', reviewNote), reviewActions);
        } else if (appeal.review_note) {
          const result = document.createElement('p');
          result.textContent = 'Решение: ' + appeal.review_note;
          card.append(result);
        }
        body.append(card);
      });
    }

    rootPanel.append(body);
    return rootPanel;
  };

  const render = (reports) => {
    list.replaceChildren();
    if (!Array.isArray(reports) || reports.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'mgw-admin__history-empty';
      empty.textContent = 'Жалоб пока нет.';
      list.append(empty);
      return;
    }

    reports.forEach(report => {
      const item = document.createElement('article');
      item.className = 'mgw-admin__report-card';
      item.dataset.reportCase = String(report.report_id || '');
      item.dataset.reportStatus = String(report.status || 'open');

      const head = document.createElement('div');
      head.className = 'mgw-admin__report-head';

      const identity = document.createElement('div');
      identity.className = 'mgw-admin__report-identity';
      const eyebrow = document.createElement('span');
      eyebrow.textContent = 'Жалоба игрока';
      const caseLink = document.createElement('a');
      caseLink.className = 'mgw-admin__report-id';
      caseLink.href = String(report.case_link || `./admin.php?report=${encodeURIComponent(report.report_id || '')}`);
      caseLink.textContent = String(report.report_id || 'Жалоба');
      identity.append(eyebrow, caseLink);

      const badge = document.createElement('span');
      badge.className = 'mgw-admin__report-status';
      badge.dataset.tone = statusTone(String(report.status || 'open'));
      badge.textContent = labelStatus(String(report.status || 'open'));
      head.append(identity, badge);

      const reason = document.createElement('div');
      reason.className = 'mgw-admin__report-reason';
      const reasonLabel = document.createElement('span');
      reasonLabel.textContent = 'Причина';
      const reasonValue = document.createElement('strong');
      reasonValue.textContent = String(report.reason_label || report.reason || '—');
      reason.append(reasonLabel, reasonValue);

      const people = document.createElement('div');
      people.className = 'mgw-admin__report-people';

      const reporter = document.createElement('div');
      reporter.innerHTML = '<span>Отправитель</span>';
      const reporterName = document.createElement('strong');
      reporterName.textContent = String(report.reporter_nickname || 'Игрок');
      const reporterId = document.createElement('small');
      reporterId.textContent = String(report.reporter_public_mgw_id || '—');
      reporter.append(reporterName, reporterId);

      const target = document.createElement('div');
      target.innerHTML = '<span>Жалоба на игрока</span>';
      const targetName = document.createElement('strong');
      targetName.textContent = String(report.target_nickname || 'Игрок');
      const targetId = document.createElement('small');
      targetId.textContent = String(report.target_public_mgw_id || '—');
      target.append(targetName, targetId);
      people.append(reporter, target);

      const message = document.createElement('div');
      message.className = 'mgw-admin__report-message';
      const messageLabel = document.createElement('span');
      messageLabel.textContent = 'Комментарий игрока';
      const messageText = document.createElement('p');
      messageText.textContent = report.details ? String(report.details) : 'Комментарий не добавлен.';
      message.append(messageLabel, messageText);

      const actions = document.createElement('div');
      actions.className = 'mgw-admin__report-actions';
      if (report.status !== 'open') actions.append(actionButton(report.report_id, 'open', 'Вернуть в новые'));
      if (report.status !== 'reviewing') actions.append(actionButton(report.report_id, 'reviewing', 'Взять в работу'));
      if (report.status !== 'closed') actions.append(actionButton(report.report_id, 'closed', 'Завершить рассмотрение'));

      const technical = document.createElement('details');
      technical.className = 'mgw-admin__report-tech';
      const technicalSummary = document.createElement('summary');
      technicalSummary.textContent = 'Технические данные';
      const technicalGrid = document.createElement('div');
      technicalGrid.className = 'mgw-admin__report-tech-grid';
      const technicalRows = [
        ['ID жалобы', report.report_id || '—'],
        ['Создана', localTime(report.created_at)],
        ['Обновлена', localTime(report.updated_at)],
        ['Рассмотрена', report.resolved_at ? localTime(report.resolved_at) : '—'],
        ['Связанный матч', report.related_match_id || '—'],
        ['Последний администратор', report.last_admin_ref || '—'],
      ];
      technicalRows.forEach(([label,value]) => {
        const row = document.createElement('div');
        const key = document.createElement('span');
        key.textContent = String(label);
        const val = document.createElement('strong');
        val.textContent = String(value);
        row.append(key, val);
        technicalGrid.append(row);
      });
      technical.append(technicalSummary, technicalGrid);

      item.append(head, reason, people, message, actions, technical, moderationPanel(report));
      list.append(item);
    });

    if (requestedCase) {
      const target = Array.from(list.querySelectorAll('[data-report-case]'))
        .find(node => node.dataset.reportCase === requestedCase);
      target?.scrollIntoView({block:'center'});
    }
  };

  const load = async () => {
    if (busy) return;
    if (!telegram?.initData) {
      status.textContent = 'Откройте панель администратора из Telegram.';
      status.dataset.state = 'error';
      return;
    }
    busy = true;
    refresh.disabled = true;
    status.textContent = 'Загружаю очередь жалоб…';
    delete status.dataset.state;
    try {
      const data = await post({action:'snapshot'});
      applySnapshot(data);
      status.textContent = queueMode === 'closed'
        ? 'Архив закрытых жалоб загружен. Используйте поиск или даты, чтобы найти нужную запись.'
        : 'Активная очередь загружена. Закрытые жалобы находятся в отдельном архиве.';
      status.dataset.state = 'ok';
    } catch (error) {
      status.textContent = error instanceof Error ? error.message : 'Не удалось загрузить очередь жалоб.';
      status.dataset.state = 'error';
    } finally {
      busy = false;
      refresh.disabled = false;
    }
  };

  const changeStatus = async (reportId, nextStatus) => {
    if (busy || !reportId) return;
    busy = true;
    refresh.disabled = true;
    list.querySelectorAll('button').forEach(button => { button.disabled = true; });
    try {
      const data = await post({action:'set_status', report_id:reportId, status:nextStatus});
      applySnapshot(data);
      status.textContent = `Жалоба ${reportId}: ${labelStatus(nextStatus)}.`;
      status.dataset.state = 'ok';
    } catch (error) {
      status.textContent = error instanceof Error ? error.message : 'Не удалось изменить статус жалобы.';
      status.dataset.state = 'error';
    } finally {
      busy = false;
      refresh.disabled = false;
      list.querySelectorAll('button').forEach(button => { button.disabled = false; });
    }
  };

  refresh.addEventListener('click', () => void load());
  modeButtons.forEach(button => button.addEventListener('click', () => {
    queueMode = String(button.dataset.reportMode || 'active');
    modeButtons.forEach(node => node.classList.toggle('is-active', node === button));
    void load();
  }));
  queryInput?.addEventListener('keydown', event => {
    if (event.key === 'Enter') void load();
  });
  dateFromInput?.addEventListener('change', () => void load());
  dateToInput?.addEventListener('change', () => void load());
  if (requestedCase) {
    modeButtons.forEach(node => node.classList.remove('is-active'));
  }
  load();
})();
