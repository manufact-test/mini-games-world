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
  const requestedCase = new URLSearchParams(window.location.search).get('report') || '';
  let busy = false;

  const post = async (payload) => {
    const response = await fetch(endpoint, {
      method:'POST',
      cache:'no-store',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({...payload, initData:telegram?.initData || ''}),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.ok !== true) throw new Error(String(data.error || 'Не удалось загрузить очередь жалоб.'));
    return data;
  };

  const labelStatus = (value) => ({ open:'Новая', reviewing:'В работе', closed:'Закрыта' })[value] || value;

  const actionButton = (reportId, nextStatus, label) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = label;
    button.dataset.reportId = reportId;
    button.dataset.reportStatus = nextStatus;
    button.addEventListener('click', () => void changeStatus(reportId, nextStatus));
    return button;
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
      const item = document.createElement('div');
      item.className = 'mgw-admin__history-item';
      item.dataset.reportCase = String(report.report_id || '');

      const copy = document.createElement('div');
      copy.className = 'mgw-admin__history-copy';
      const title = document.createElement('strong');
      const caseLink = document.createElement('a');
      caseLink.href = String(report.case_link || `./admin.php?report=${encodeURIComponent(report.report_id || '')}`);
      caseLink.textContent = String(report.report_id || 'Case');
      title.append(caseLink, document.createTextNode(` · ${labelStatus(String(report.status || 'open'))}`));

      const people = document.createElement('span');
      people.textContent = `${report.reporter_nickname || 'Игрок'} (${report.reporter_public_mgw_id || '—'}) → ${report.target_nickname || 'Игрок'} (${report.target_public_mgw_id || '—'})`;
      const reason = document.createElement('span');
      reason.textContent = `Причина: ${report.reason_label || report.reason || '—'}${report.related_match_id ? ` · Match ${report.related_match_id}` : ''}`;
      const details = document.createElement('span');
      details.textContent = report.details ? String(report.details) : 'Комментарий не добавлен.';
      const time = document.createElement('span');
      time.textContent = `${report.created_at || '—'} UTC${report.last_admin_ref ? ` · ${report.last_admin_ref}` : ''}`;
      copy.append(title, people, reason, details, time);

      const actions = document.createElement('div');
      actions.className = 'mgw-admin__economy-actions';
      if (report.status !== 'open') actions.append(actionButton(report.report_id, 'open', 'Вернуть в новые'));
      if (report.status !== 'reviewing') actions.append(actionButton(report.report_id, 'reviewing', 'В работу'));
      if (report.status !== 'closed') actions.append(actionButton(report.report_id, 'closed', 'Закрыть'));

      item.append(copy, actions);
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
      status.textContent = 'Откройте Web Admin из Telegram.';
      status.dataset.state = 'error';
      return;
    }
    busy = true;
    refresh.disabled = true;
    status.textContent = 'Загружаю очередь жалоб…';
    delete status.dataset.state;
    try {
      const data = await post({action:'snapshot'});
      render(data.reports || []);
      status.textContent = 'Очередь загружена. Статусы меняются вручную; автоматических блокировок нет.';
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
      render(data.reports || []);
      status.textContent = `Case ${reportId}: ${labelStatus(nextStatus)}.`;
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
  load();
})();
