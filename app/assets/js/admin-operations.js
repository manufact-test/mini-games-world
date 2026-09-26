(() => {
  'use strict';

  const root = document.querySelector('[data-admin-operations]');
  const adminRoot = document.querySelector('.mgw-admin');
  if (!root || !adminRoot) return;

  const endpoint = String(adminRoot.dataset.operationsApi || '');
  const telegram = window.Telegram?.WebApp || null;
  const $ = selector => root.querySelector(selector);
  const status = $('[data-operations-status]');
  const refreshButtons = [...root.querySelectorAll('[data-operations-refresh]')];
  const tasksBox = $('[data-operations-tasks]');
  const closedTasksBox = $('[data-operations-closed-tasks]');
  const plansBox = $('[data-operations-plans]');
  const releasesBox = $('[data-operations-releases]');
  const auditBox = $('[data-operations-audit]');
  const seasonStatus = $('[data-operations-season-status]');
  const seasonMeta = $('[data-operations-season-meta]');
  const seasonReminders = $('[data-operations-season-reminders]');
  const seasonChecklist = $('[data-operations-season-checklist]');
  const seasonActions = $('[data-operations-season-actions]');
  const runtimeBuild = $('[data-operations-runtime-build]');
  const releaseCoverage = $('[data-operations-release-coverage]');
  const taskFeedback = $('[data-operations-task-feedback]');
  const planFeedback = $('[data-operations-plan-feedback]');
  const releaseFeedback = $('[data-operations-release-feedback]');
  const listPages = {tasks:1, closed:1, plans:1, releases:1};
  const actionLabels = {
    created:'Создано',
    updated:'Обновлено',
    recurrence_created:'Создан следующий повтор',
    season_reminder_materialized:'Создана сезонная задача',
    readiness_updated:'Готовность обновлена',
    due_reminder_sent:'Напоминание отправлено',
  };
  const entityLabels = {
    task:'Задача',
    future_plan:'План',
    release:'Релиз',
    season_package:'Пакет сезона',
  };
  let snapshot = null;
  let loading = false;
  let toastTimer = 0;

  const recurrenceLabels = {
    once:'Один раз',
    daily:'Каждый день',
    weekly:'Каждую неделю',
    monthly:'Каждый месяц',
    quarterly:'Каждый квартал',
    season_checkpoint:'Контрольная точка сезона',
  };
  const taskStatusLabels = {open:'Открыта',in_progress:'В работе',done:'Готово',skipped:'Пропущена'};
  const planStatusLabels = {idea:'Идея',planned:'Запланировано',in_progress:'В работе',blocked:'Заблокировано',done:'Готово',cancelled:'Отменено'};
  const categoryLabels = {
    season:'Сезон',product:'Продукт',engineering:'Разработка',operations:'Операции',
    support:'Поддержка',content:'Контент',growth:'Рост',other:'Другое',
  };
  const readinessLabels = {pending:'Ожидает',ready:'Готово',not_required:'Не требуется'};
  const environmentLabels = {staging:'Тестовая',production:'Рабочая'};

  const clear = node => node?.replaceChildren();

  const feedback = (node, message = '', state = '') => {
    if (!node) return;
    node.textContent = message;
    node.hidden = message === '';
    if (state) node.dataset.state = state;
    else delete node.dataset.state;
  };

  const notify = (message, state = 'ok') => {
    let toast = root.querySelector('[data-operations-toast]');
    if (!toast) {
      toast = document.createElement('div');
      toast.className = 'mgw-admin__operations-toast';
      toast.dataset.operationsToast = '';
      toast.setAttribute('role', 'status');
      toast.setAttribute('aria-live', 'polite');
      root.append(toast);
    }
    toast.textContent = message;
    toast.dataset.state = state;
    toast.hidden = false;
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(() => { toast.hidden = true; }, state === 'error' ? 5200 : 3000);
  };

  const focusInvalid = (node, message, feedbackNode) => {
    if (!node) return false;
    node.setAttribute('aria-invalid', 'true');
    feedback(feedbackNode, message, 'error');
    notify(message, 'error');
    node.scrollIntoView({behavior:'smooth', block:'center'});
    window.setTimeout(() => {
      try { node.focus({preventScroll:true}); } catch (_) { node.focus(); }
    }, 260);
    return false;
  };

  const requireValue = (node, label, feedbackNode) => {
    if (String(node?.value || '').trim() !== '') {
      node?.removeAttribute('aria-invalid');
      return true;
    }
    return focusInvalid(node, 'Заполните поле «' + label + '».', feedbackNode);
  };

  const num = value => Number(value || 0).toLocaleString('ru-RU');
  const dateTime = value => {
    const date = new Date(String(value || ''));
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('ru-RU');
  };
  const dateOnly = value => {
    const date = new Date(String(value || ''));
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('ru-RU');
  };
  const toIso = value => {
    if (!value) return '';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '' : date.toISOString();
  };
  const localInputNow = () => {
    const date = new Date();
    const pad = value => String(value).padStart(2, '0');
    return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate())
      + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
  };
  const isOverdue = row => {
    if (!row?.due_at_utc || !['open','in_progress'].includes(String(row.task_status || ''))) return false;
    const due = new Date(String(row.due_at_utc)).getTime();
    return Number.isFinite(due) && due < Date.now();
  };

  const button = (label, handler, className = '') => {
    const node = document.createElement('button');
    node.type = 'button';
    node.textContent = label;
    if (className) node.className = className;
    node.addEventListener('click', handler);
    return node;
  };

  const field = (label, control) => {
    const wrap = document.createElement('label');
    wrap.className = 'mgw-admin__field';
    const span = document.createElement('span');
    span.textContent = label;
    wrap.append(span, control);
    return wrap;
  };

  const select = (options, value) => {
    const node = document.createElement('select');
    Object.entries(options).forEach(([key, label]) => {
      const option = document.createElement('option');
      option.value = key;
      option.textContent = label;
      node.append(option);
    });
    node.value = String(value || '');
    return node;
  };

  const textInput = value => {
    const node = document.createElement('input');
    node.type = 'text';
    node.value = String(value || '');
    node.maxLength = 191;
    node.autocomplete = 'off';
    return node;
  };

  const textarea = (value, max = 3000) => {
    const node = document.createElement('textarea');
    node.rows = 3;
    node.maxLength = max;
    node.value = String(value || '');
    return node;
  };

  const badge = (text, state = '') => {
    const node = document.createElement('span');
    node.className = 'mgw-admin__operations-badge';
    if (state) node.dataset.state = state;
    node.textContent = text;
    return node;
  };

  const post = async (action, extra = {}) => {
    const response = await fetch(endpoint, {
      method:'POST',
      cache:'no-store',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({action, initData:String(telegram?.initData || ''), ...extra}),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload?.ok !== true) {
      throw new Error(String(payload?.error || ('Ошибка HTTP ' + response.status)));
    }
    return payload;
  };

  const run = async (buttonNode, action, extra, successText, feedbackNode = null) => {
    buttonNode.disabled = true;
    try {
      const payload = await post(action, extra);
      snapshot = payload;
      render(payload);
      status.textContent = successText;
      status.dataset.state = 'ok';
      feedback(feedbackNode, successText, 'ok');
      notify(successText, 'ok');
      return true;
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Не удалось выполнить действие.';
      status.textContent = message;
      status.dataset.state = 'error';
      feedback(feedbackNode, message, 'error');
      notify(message, 'error');
      return false;
    } finally {
      buttonNode.disabled = false;
    }
  };

  const renderPagedList = (box, rows, key, pageSize, renderRow, emptyText) => {
    clear(box);
    if (!rows.length) {
      const empty = document.createElement('p');
      empty.className = 'mgw-admin__operations-empty';
      empty.textContent = emptyText;
      box.append(empty);
      listPages[key] = 1;
      return;
    }

    const ux = window.MGWAdminUX;
    const meta = ux?.paginate
      ? ux.paginate(rows, listPages[key], pageSize)
      : {
          rows:rows.slice(0, pageSize),
          page:1,
          totalPages:Math.max(1, Math.ceil(rows.length / pageSize)),
          total:rows.length,
          from:1,
          to:Math.min(rows.length, pageSize),
        };
    listPages[key] = Number(meta.page || 1);
    meta.rows.forEach(row => box.append(renderRow(row)));

    if (Number(meta.totalPages || 1) <= 1) return;
    const pager = document.createElement('div');
    pager.className = 'mgw-admin__pager mgw-admin__operations-pager';
    box.append(pager);
    if (ux?.renderPager) {
      ux.renderPager(pager, meta, target => {
        listPages[key] = target;
        renderPagedList(box, rows, key, pageSize, renderRow, emptyText);
      });
    }
  };

  const renderTask = (row, editable) => {
    const card = document.createElement(editable ? 'details' : 'article');
    card.className = editable
      ? 'mgw-admin__operations-item mgw-admin__operations-item--details'
      : 'mgw-admin__operations-item';
    if (isOverdue(row)) card.dataset.overdue = 'true';

    const head = document.createElement('div');
    head.className = 'mgw-admin__operations-item-head';
    const title = document.createElement('strong');
    title.textContent = String(row.title || 'Задача');
    const badges = document.createElement('div');
    badges.append(
      badge(categoryLabels[row.category] || 'Задача'),
      badge(taskStatusLabels[row.task_status] || 'Неизвестный статус', row.task_status || ''),
    );
    if (row.recurrence_code && row.recurrence_code !== 'once') {
      badges.append(badge(recurrenceLabels[row.recurrence_code] || 'Повторяется'));
    }
    head.append(title, badges);

    const meta = document.createElement('div');
    meta.className = 'mgw-admin__operations-item-meta';
    const dueText = row.due_at_utc
      ? (isOverdue(row) ? 'Просрочено · ' : 'Срок · ') + dateTime(row.due_at_utc)
      : 'Без срока';
    const ownerText = row.owner_ref ? 'Ответственный · ' + row.owner_ref : 'Ответственный не назначен';
    [dueText, ownerText].forEach(value => {
      const span = document.createElement('span');
      span.textContent = value;
      meta.append(span);
    });

    if (editable) {
      const summary = document.createElement('summary');
      summary.className = 'mgw-admin__operations-summary';
      summary.append(head, meta);
      card.append(summary);
    } else {
      card.append(head, meta);
    }

    if (!editable) {
      if (row.result_text) {
        const result = document.createElement('p');
        result.className = 'mgw-admin__operations-result';
        result.textContent = 'Результат: ' + row.result_text;
        card.append(result);
      }
      return card;
    }

    const controls = document.createElement('div');
    controls.className = 'mgw-admin__operations-editor mgw-admin__operations-editor--details';
    const owner = textInput(row.owner_ref);
    const state = select(taskStatusLabels, row.task_status);
    const result = textarea(row.result_text);
    controls.append(field('Ответственный', owner), field('Статус', state), field('Результат / комментарий', result));
    const save = button('Сохранить изменения', () => {
      const closing = ['done','skipped'].includes(state.value);
      const successText = closing
        ? 'Задача сохранена и перенесена в завершённые.'
        : 'Задача сохранена.';
      run(save, 'update_task', {
        task_id:String(row.task_id || ''),
        changes:{owner_ref:owner.value, task_status:state.value, result_text:result.value},
      }, successText);
    });
    controls.append(save);
    card.append(controls);
    return card;
  };

  const renderTasks = operations => {
    const active = Array.isArray(operations.tasks?.active) ? operations.tasks.active : [];
    const closed = Array.isArray(operations.tasks?.recent_closed) ? operations.tasks.recent_closed : [];

    renderPagedList(
      tasksBox, active, 'tasks', 8,
      row => renderTask(row, true),
      'Активных задач сейчас нет.'
    );
    renderPagedList(
      closedTasksBox, closed, 'closed', 8,
      row => renderTask(row, false),
      'Завершённых задач пока нет.'
    );

    const overdue = active.filter(isOverdue).length;
    $('[data-operations-kpi="tasks"]').textContent = num(active.length);
    $('[data-operations-kpi="overdue"]').textContent = num(overdue);
  };

  const renderSeason = data => {
    clear(seasonStatus);
    clear(seasonMeta);
    clear(seasonReminders);

    const strong = document.createElement('strong');
    const text = document.createElement('span');
    if (data?.enabled !== true) {
      strong.textContent = 'Пока не активно';
      text.textContent = String(data?.message || 'Регулярная сезонная подготовка ещё не включена.');
      seasonStatus.append(strong, text);
      seasonStatus.dataset.state = 'idle';
      seasonChecklist.hidden = true;
      seasonActions.hidden = true;
      return;
    }

    strong.textContent = data.ready ? 'Пакет следующего сезона готов' : 'Подготовка требуется';
    text.textContent = 'Текущий сезон: ' + String(data.current_season_id || '—') + ' · следующий: ' + String(data.target_season_id || '—');
    seasonStatus.append(strong, text);
    seasonStatus.dataset.state = data.ready ? 'ready' : 'pending';

    [
      ['Следующий сезон', String(data.target_season_id || '—')],
      ['Начало', dateTime(data.target_start_at_utc)],
      ['Конец', dateTime(data.target_end_at_utc)],
      ['Часовой пояс календаря', String(data.target_timezone || '—')],
    ].forEach(([label, value]) => {
      const item = document.createElement('div');
      const a = document.createElement('span');
      const b = document.createElement('strong');
      a.textContent = label;
      b.textContent = value;
      item.append(a, b);
      seasonMeta.append(item);
    });

    const reminders = Array.isArray(data.reminders) ? data.reminders : [];
    reminders.forEach(row => {
      const item = document.createElement('div');
      const checkpoint = document.createElement('strong');
      const due = document.createElement('span');
      const state = document.createElement('span');
      checkpoint.textContent = 'T‑' + String(row.checkpoint_days || '?');
      due.textContent = dateTime(row.due_at_utc);
      const effectiveDue = new Date(String(row.due_at_utc || '')).getTime() <= Date.now() && !data.ready;
      state.textContent = data.ready ? 'Закрыто: готово' : (effectiveDue ? 'Требует внимания' : 'Ожидает срока');
      state.dataset.state = data.ready ? 'ready' : (effectiveDue ? 'due' : 'pending');
      item.append(checkpoint, due, state);
      seasonReminders.append(item);
    });

    const pkg = data.reward_package || {};
    root.querySelectorAll('[data-season-ready]').forEach(node => {
      const key = node.dataset.seasonReady;
      if (key && pkg[key]) node.value = String(pkg[key]);
      node.disabled = data.ready === true;
    });
    seasonChecklist.hidden = false;
    seasonActions.hidden = data.ready === true;
  };

  const renderPlan = row => {
    const card = document.createElement('details');
    card.className = 'mgw-admin__operations-item mgw-admin__operations-item--details';
    const summary = document.createElement('summary');
    summary.className = 'mgw-admin__operations-summary';

    const head = document.createElement('div');
    head.className = 'mgw-admin__operations-item-head';
    const title = document.createElement('strong');
    title.textContent = String(row.title || 'План');
    const badges = document.createElement('div');
    badges.append(
      badge(categoryLabels[row.category] || 'План'),
      badge(planStatusLabels[row.plan_status] || 'Неизвестный статус', row.plan_status || '')
    );
    head.append(title, badges);

    const meta = document.createElement('div');
    meta.className = 'mgw-admin__operations-item-meta';
    const period = document.createElement('span');
    period.textContent = row.target_period ? 'Период · ' + row.target_period : 'Период не указан';
    const ownerMeta = document.createElement('span');
    ownerMeta.textContent = row.owner_ref ? 'Ответственный · ' + row.owner_ref : 'Ответственный не назначен';
    meta.append(period, ownerMeta);
    summary.append(head, meta);
    card.append(summary);

    const controls = document.createElement('div');
    controls.className = 'mgw-admin__operations-editor mgw-admin__operations-editor--details';
    const state = select(planStatusLabels, row.plan_status);
    const owner = textInput(row.owner_ref);
    const targetPeriod = textInput(row.target_period);
    targetPeriod.maxLength = 120;
    const notes = textarea(row.notes, 5000);
    controls.append(
      field('Статус', state),
      field('Ответственный', owner),
      field('Период', targetPeriod),
      field('Заметки', notes)
    );
    const save = button('Сохранить изменения', () => run(save, 'update_plan', {
      plan_id:String(row.plan_id || ''),
      changes:{plan_status:state.value, owner_ref:owner.value, target_period:targetPeriod.value, notes:notes.value},
    }, 'План сохранён.'));
    controls.append(save);
    card.append(controls);
    return card;
  };

  const renderPlans = operations => {
    const rows = Array.isArray(operations.future_plans) ? operations.future_plans : [];
    const active = rows.filter(row => ['planned','in_progress','blocked'].includes(String(row.plan_status || ''))).length;
    $('[data-operations-kpi="plans"]').textContent = num(active);
    renderPagedList(
      plansBox, rows, 'plans', 8,
      renderPlan,
      'Планов на будущее пока нет.'
    );
  };

  const renderRelease = row => {
    const card = document.createElement('details');
    card.className = 'mgw-admin__operations-item mgw-admin__operations-item--details';
    const summaryNode = document.createElement('summary');
    summaryNode.className = 'mgw-admin__operations-summary';

    const head = document.createElement('div');
    head.className = 'mgw-admin__operations-item-head';
    const title = document.createElement('strong');
    title.textContent = String(row.version_label || 'Релиз');
    const badges = document.createElement('div');
    badges.append(badge(environmentLabels[row.environment] || 'Среда не указана'));
    head.append(title, badges);

    const meta = document.createElement('div');
    meta.className = 'mgw-admin__operations-item-meta';
    [dateTime(row.released_at_utc), String(row.release_sha || '')].forEach(value => {
      const span = document.createElement('span');
      span.textContent = value;
      meta.append(span);
    });
    summaryNode.append(head, meta);
    card.append(summaryNode);

    const controls = document.createElement('div');
    controls.className = 'mgw-admin__operations-editor mgw-admin__operations-editor--details';
    const summary = textarea(row.summary_text, 5000);
    const issues = textarea(row.known_issues_text, 5000);
    const rollback = textInput(row.rollback_link);
    rollback.maxLength = 500;
    controls.append(field('Что вошло', summary), field('Известные проблемы', issues), field('Ссылка отката', rollback));
    const save = button('Сохранить изменения', () => run(save, 'update_release', {
      release_id:String(row.release_id || ''),
      changes:{summary_text:summary.value, known_issues_text:issues.value, rollback_link:rollback.value},
    }, 'Запись релиза сохранена.'));
    controls.append(save);

    if (row.rollback_link) {
      const link = document.createElement('a');
      link.href = String(row.rollback_link);
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.textContent = 'Открыть инструкцию / точку отката';
      link.className = 'mgw-admin__operations-link';
      controls.append(link);
    }
    card.append(controls);
    return card;
  };

  const renderReleases = operations => {
    const rows = Array.isArray(operations.release_log) ? operations.release_log : [];
    $('[data-operations-kpi="releases"]').textContent = num(rows.length);
    renderPagedList(
      releasesBox, rows, 'releases', 6,
      renderRelease,
      'Записей релизов пока нет. История начинается с MVP-22.7.'
    );
  };

  const renderAudit = operations => {
    clear(auditBox);
    const rows = Array.isArray(operations.recent_audit) ? operations.recent_audit : [];
    if (!rows.length) {
      auditBox.textContent = 'Журнал пока пуст.';
      return;
    }
    rows.forEach(row => {
      const item = document.createElement('div');
      const strong = document.createElement('strong');
      const span = document.createElement('span');
      strong.textContent = (actionLabels[row.action_code] || 'Изменение')
        + ' · ' + (entityLabels[row.entity_type] || 'Запись');
      span.textContent = dateTime(row.created_at_utc) + ' · ' + String(row.actor_ref || '—');
      item.append(strong, span);
      auditBox.append(item);
    });
  };

  const render = payload => {
    const operations = payload?.operations || {};
    runtimeBuild.textContent = String(payload?.runtime_build || '—');
    const coverage = operations.coverage || {};
    if (coverage.release_history) releaseCoverage.textContent = String(coverage.release_history);
    renderTasks(operations);
    renderSeason(operations.season_preparation || {});
    renderPlans(operations);
    renderReleases(operations);
    renderAudit(operations);
  };

  const load = async (quiet = false) => {
    if (loading || !endpoint) return;
    loading = true;
    if (!quiet) {
      refreshButtons.forEach(node => { node.disabled = true; });
      status.textContent = 'Обновляю задачи, планы и журнал релизов…';
      delete status.dataset.state;
    }
    try {
      const payload = await post('snapshot');
      snapshot = payload;
      render(payload);
      const reminderCount = Number(payload?.task_reminders?.sent || 0);
      if (reminderCount > 0) {
        notify(reminderCount === 1
          ? 'Напоминание по задаче отправлено.'
          : 'Отправлено напоминаний по задачам: ' + reminderCount + '.');
      }
      if (!quiet) {
        status.textContent = 'Операционные данные актуальны.';
        status.dataset.state = 'ok';
      }
    } catch (error) {
      if (!quiet) {
        status.textContent = error instanceof Error ? error.message : 'Не удалось загрузить раздел.';
        status.dataset.state = 'error';
      }
    } finally {
      loading = false;
      if (!quiet) refreshButtons.forEach(node => { node.disabled = false; });
    }
  };

  root.querySelector('[data-operations-create-task]')?.addEventListener('click', event => {
    const node = event.currentTarget;
    const title = $('[data-operations-task-title]');
    const category = $('[data-operations-task-category]');
    const recurrence = $('[data-operations-task-recurrence]');
    const due = $('[data-operations-task-due]');
    const owner = $('[data-operations-task-owner]');

    feedback(taskFeedback);
    if (!requireValue(title, 'Задача', taskFeedback)) return;
    if (recurrence.value !== 'once' && !requireValue(due, 'Ближайший срок', taskFeedback)) return;

    listPages.tasks = 1;
    run(node, 'create_task', {
      task:{
        title:title.value,
        category:category.value,
        recurrence_code:recurrence.value,
        due_at_utc:toIso(due.value),
        owner_ref:owner.value,
      },
    }, 'Задача добавлена в рабочий список.', taskFeedback).then(success => {
      if (!success) return;
      title.value = '';
      title.removeAttribute('aria-invalid');
      due.removeAttribute('aria-invalid');
      if (recurrence.value === 'once') due.value = '';
    });
  });

  root.querySelector('[data-operations-create-plan]')?.addEventListener('click', event => {
    const node = event.currentTarget;
    const title = $('[data-operations-plan-title]');
    feedback(planFeedback);
    if (!requireValue(title, 'План', planFeedback)) return;

    listPages.plans = 1;
    run(node, 'create_plan', {
      plan:{
        title:title.value,
        category:$('[data-operations-plan-category]').value,
        plan_status:$('[data-operations-plan-status]').value,
        target_period:$('[data-operations-plan-period]').value,
        owner_ref:$('[data-operations-plan-owner]').value,
        notes:$('[data-operations-plan-notes]').value,
      },
    }, 'План добавлен.', planFeedback).then(success => {
      if (!success) return;
      title.value = '';
      title.removeAttribute('aria-invalid');
      $('[data-operations-plan-notes]').value = '';
    });
  });

  root.querySelector('[data-operations-create-release]')?.addEventListener('click', event => {
    const node = event.currentTarget;
    const version = $('[data-operations-release-version]');
    const sha = $('[data-operations-release-sha]');
    const date = $('[data-operations-release-date]');
    const summary = $('[data-operations-release-summary]');

    feedback(releaseFeedback);
    if (!requireValue(version, 'Версия', releaseFeedback)) return;
    if (!requireValue(sha, 'Полный SHA', releaseFeedback)) return;
    if (!requireValue(date, 'Дата релиза', releaseFeedback)) return;
    if (!requireValue(summary, 'Что вошло', releaseFeedback)) return;

    listPages.releases = 1;
    run(node, 'create_release', {
      release:{
        version_label:version.value,
        environment:$('[data-operations-release-environment]').value,
        release_sha:sha.value,
        released_at_utc:toIso(date.value),
        summary_text:summary.value,
        known_issues_text:$('[data-operations-release-issues]').value,
        rollback_link:$('[data-operations-release-rollback]').value,
      },
    }, 'Релиз добавлен в журнал.', releaseFeedback);
  });

  root.querySelector('[data-operations-save-season]')?.addEventListener('click', event => {
    const target = String(snapshot?.operations?.season_preparation?.target_season_id || '');
    const states = {};
    root.querySelectorAll('[data-season-ready]').forEach(node => {
      states[node.dataset.seasonReady] = node.value;
    });
    run(event.currentTarget, 'update_season_readiness', {
      target_season_id:target,
      states,
    }, 'Готовность пакета следующего сезона сохранена.');
  });

  refreshButtons.forEach(node => node.addEventListener('click', () => load(false)));
  window.addEventListener('mgw:admin-refresh', () => load(false));

  const releaseDate = $('[data-operations-release-date]');
  if (releaseDate && !releaseDate.value) releaseDate.value = localInputNow();

  window.setInterval(() => {
    if (document.visibilityState !== 'visible') return;
    if (root.getClientRects().length === 0) return;
    load(true);
  }, 20000);

  load(false);
})();