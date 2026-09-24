(() => {
  'use strict';

  const root = document.querySelector('[data-admin-support]');
  const shell = document.querySelector('[data-support-api]');
  if (!root || !shell) return;

  const endpoint = String(shell.dataset.supportApi || '');
  const telegram = window.Telegram?.WebApp || null;
  const statusBox = root.querySelector('[data-support-status]');
  const metricsBox = root.querySelector('[data-support-metrics]');
  const queue = root.querySelector('[data-support-queue]');
  const detail = root.querySelector('[data-support-detail]');
  const refresh = root.querySelector('[data-support-refresh]');
  const queryInput = root.querySelector('[data-support-filter-query]');
  const statusFilter = root.querySelector('[data-support-filter-status]');
  const priorityFilter = root.querySelector('[data-support-filter-priority]');
  const categoryFilter = root.querySelector('[data-support-filter-category]');
  const platformFilter = root.querySelector('[data-support-filter-platform]');
  const requestedTicket = new URLSearchParams(window.location.search).get('ticket') || '';
  let currentTicket = null;
  let currentAdminRef = '';
  let busy = false;

  const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({
    '&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'
  })[char]);

  const post = async payload => {
    const response = await fetch(endpoint, {
      method:'POST',
      cache:'no-store',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({...payload, initData:telegram?.initData || ''}),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.ok !== true) throw new Error(String(data.error || 'Не удалось выполнить действие поддержки.'));
    return data;
  };

  const filters = () => ({
    query:queryInput.value.trim(),
    status:statusFilter.value,
    priority:priorityFilter.value,
    category:categoryFilter.value,
    platform:platformFilter.value,
  });

  const setBusy = value => {
    busy = value;
    root.querySelectorAll('button,select,input,textarea').forEach(node => {
      if (node.matches('[data-support-filter-query],[data-support-filter-status],[data-support-filter-priority],[data-support-filter-category],[data-support-filter-platform]')) {
        node.disabled = value;
        return;
      }
      if (node.type !== 'file') node.disabled = value;
    });
  };

  const setStatus = (message, state = '') => {
    statusBox.textContent = message;
    if (state) statusBox.dataset.state = state;
    else delete statusBox.dataset.state;
  };

  const fillSelect = (select, labels, emptyLabel) => {
    const value = select.value;
    select.replaceChildren();
    const empty = document.createElement('option');
    empty.value = '';
    empty.textContent = emptyLabel;
    select.append(empty);
    Object.entries(labels || {}).forEach(([key, label]) => {
      const option = document.createElement('option');
      option.value = key;
      option.textContent = label;
      select.append(option);
    });
    if (Array.from(select.options).some(option => option.value === value)) select.value = value;
  };

  const priorityClass = value => ['low','normal','high','critical'].includes(value) ? `priority-${value}` : 'priority-normal';

  const renderMetrics = metrics => {
    metricsBox.innerHTML = [
      ['Открытых', metrics?.open_total ?? 0],
      ['Критических', metrics?.critical_open ?? 0],
      ['Без владельца', metrics?.unowned_open ?? 0],
    ].map(([label, value]) => `<div><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong></div>`).join('');
  };

  const queueItem = ticket => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `mgw-admin__support-ticket ${priorityClass(ticket.priority)}`;
    button.dataset.ticketNumber = String(ticket.ticket_number || '');
    button.innerHTML = `
      <span class="mgw-admin__support-ticket-top">
        <strong>${escapeHtml(ticket.ticket_number || '—')}</strong>
        <span class="mgw-admin__support-priority ${priorityClass(ticket.priority)}">${escapeHtml(ticket.priority_label || ticket.priority || '—')}</span>
      </span>
      <span class="mgw-admin__support-ticket-subject">${escapeHtml(ticket.subject || ticket.category_label || 'Обращение')}</span>
      <span class="mgw-admin__support-ticket-meta">${escapeHtml(ticket.category_label || '—')} · ${escapeHtml(ticket.platform_label || '—')}</span>
      <span class="mgw-admin__support-ticket-meta">${escapeHtml(ticket.status_label || ticket.status || '—')} · ${escapeHtml(ticket.owner_ref || 'Без владельца')}</span>
      <span class="mgw-admin__support-ticket-time">${escapeHtml(ticket.updated_at || '')} UTC</span>
    `;
    button.addEventListener('click', () => void openTicket(ticket.ticket_number));
    return button;
  };

  const renderQueue = tickets => {
    queue.replaceChildren();
    if (!Array.isArray(tickets) || tickets.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'mgw-admin__history-empty';
      empty.textContent = 'По выбранным фильтрам обращений нет.';
      queue.append(empty);
      return;
    }
    tickets.forEach(ticket => queue.append(queueItem(ticket)));
  };

  const attachmentButton = attachment => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'mgw-admin__support-attachment';
    button.textContent = `📎 ${attachment.file_name || 'Вложение'} · ${Math.max(1, Math.ceil(Number(attachment.size_bytes || 0) / 1024))} КБ`;
    button.addEventListener('click', () => void openAttachment(String(attachment.attachment_id || '')));
    return button;
  };

  const renderThread = ticket => {
    const thread = detail.querySelector('[data-support-thread]');
    thread.replaceChildren();
    (ticket.messages || []).forEach(message => {
      const item = document.createElement('div');
      item.className = `mgw-admin__support-message is-${message.actor_type === 'admin' ? 'admin' : 'user'}`;
      const head = document.createElement('div');
      head.className = 'mgw-admin__support-message-head';
      const who = document.createElement('strong');
      who.textContent = message.actor_type === 'admin' ? `Admin · ${message.actor_ref || '—'}` : `Игрок · ${message.actor_ref || '—'}`;
      const when = document.createElement('span');
      when.textContent = `${message.created_at_utc || '—'} UTC`;
      head.append(who, when);
      const body = document.createElement('div');
      body.className = 'mgw-admin__support-message-body';
      body.textContent = String(message.body || '');
      item.append(head, body);
      if (Array.isArray(message.attachments) && message.attachments.length) {
        const files = document.createElement('div');
        files.className = 'mgw-admin__support-attachments';
        message.attachments.forEach(file => files.append(attachmentButton(file)));
        item.append(files);
      }
      thread.append(item);
    });
  };

  const renderHistory = ticket => {
    const history = detail.querySelector('[data-support-history]');
    history.replaceChildren();
    (ticket.history || []).forEach(event => {
      const row = document.createElement('div');
      row.className = 'mgw-admin__support-history-row';
      const value = [event.previous_value, event.next_value].filter(value => value !== null && value !== '').join(' → ');
      row.innerHTML = `<strong>${escapeHtml(event.event_type || 'event')}</strong><span>${escapeHtml(value)}</span><em>${escapeHtml(event.actor_ref || '—')} · ${escapeHtml(event.created_at_utc || '—')} UTC</em>`;
      history.append(row);
    });
  };

  const renderDetail = ticket => {
    currentTicket = ticket;
    detail.hidden = false;
    detail.querySelector('[data-support-detail-number]').textContent = String(ticket.ticket_number || '—');
    detail.querySelector('[data-support-detail-subject]').textContent = String(ticket.subject || 'Обращение');
    detail.querySelector('[data-support-detail-player]').textContent = String(ticket.requester_mgw_id || '—');
    detail.querySelector('[data-support-detail-platform]').textContent = String(ticket.platform_label || '—');
    detail.querySelector('[data-support-detail-category]').textContent = String(ticket.category_label || '—');
    detail.querySelector('[data-support-detail-owner]').textContent = String(ticket.owner_ref || 'Без владельца');

    const statusSelect = detail.querySelector('[data-support-detail-status]');
    const prioritySelect = detail.querySelector('[data-support-detail-priority]');
    if (Array.from(statusSelect.options).some(option => option.value === ticket.status)) statusSelect.value = ticket.status;
    if (Array.from(prioritySelect.options).some(option => option.value === ticket.priority)) prioritySelect.value = ticket.priority;
    prioritySelect.className = `mgw-admin__support-select ${priorityClass(ticket.priority)}`;

    const related = ticket.related || {};
    detail.querySelector('[data-support-related-game]').value = related.game_id || '';
    detail.querySelector('[data-support-related-payment]').value = related.payment_id || '';
    detail.querySelector('[data-support-related-tournament]').value = related.tournament_id || '';
    detail.querySelector('[data-support-related-operation]').value = related.operation_id || '';

    renderThread(ticket);
    renderHistory(ticket);
    detail.scrollIntoView({block:'start', behavior:'smooth'});
  };

  const renderSnapshot = data => {
    currentAdminRef = String(data.admin_ref || currentAdminRef || '');
    fillSelect(statusFilter, data.statuses || {}, 'Все статусы');
    fillSelect(priorityFilter, data.priorities || {}, 'Все приоритеты');
    fillSelect(categoryFilter, data.categories || {}, 'Все категории');
    fillSelect(platformFilter, data.platforms || {}, 'Все платформы');
    const detailStatus = detail.querySelector('[data-support-detail-status]');
    const detailPriority = detail.querySelector('[data-support-detail-priority]');
    fillSelect(detailStatus, data.statuses || {}, 'Статус');
    fillSelect(detailPriority, data.priorities || {}, 'Приоритет');
    if (currentTicket) {
      detailStatus.value = currentTicket.status || '';
      detailPriority.value = currentTicket.priority || '';
    }
    renderMetrics(data.metrics || {});
    renderQueue(data.tickets || []);
    if (data.ticket) renderDetail(data.ticket);
  };

  const load = async () => {
    if (busy) return;
    if (!telegram?.initData) {
      setStatus('Откройте Web Admin из Telegram.', 'error');
      return;
    }
    setBusy(true);
    setStatus('Загружаю обращения…');
    try {
      const data = await post({action:'snapshot', filters:filters()});
      renderSnapshot(data);
      setStatus('Очередь поддержки загружена.', 'ok');
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось загрузить поддержку.', 'error');
    } finally {
      setBusy(false);
    }
    if (requestedTicket && !currentTicket) void openTicket(requestedTicket, false);
  };

  const openTicket = async (ticketNumber, scroll = true) => {
    if (busy || !ticketNumber) return;
    setBusy(true);
    setStatus(`Открываю ${ticketNumber}…`);
    try {
      const data = await post({action:'ticket', ticket:ticketNumber, filters:filters()});
      renderSnapshot(data);
      if (!scroll) detail.scrollIntoView({block:'start'});
      setStatus(`${ticketNumber}: карточка и история загружены.`, 'ok');
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось открыть обращение.', 'error');
    } finally {
      setBusy(false);
    }
  };

  const mutate = async payload => {
    if (busy || !currentTicket?.ticket_number) return;
    setBusy(true);
    try {
      const data = await post({...payload, ticket:currentTicket.ticket_number, filters:filters()});
      renderSnapshot(data);
      setStatus(`${currentTicket.ticket_number}: изменения сохранены в истории.`, 'ok');
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось сохранить изменение.', 'error');
    } finally {
      setBusy(false);
    }
  };

  const filesToPayload = async input => {
    const files = Array.from(input?.files || []).slice(0, 3);
    return Promise.all(files.map(file => new Promise((resolve, reject) => {
      if (file.size > 2_000_000) return reject(new Error(`${file.name}: файл больше 2 МБ.`));
      const reader = new FileReader();
      reader.onerror = () => reject(new Error(`${file.name}: не удалось прочитать файл.`));
      reader.onload = () => {
        const value = String(reader.result || '');
        const mime = String(file.type || '').toLowerCase();
        const allowed = ['image/jpeg','image/png','image/webp','image/gif','application/pdf','text/plain'];
        if (!allowed.includes(mime)) return reject(new Error(`${file.name}: поддерживаются изображения, PDF и TXT.`));
        resolve({file_name:file.name, mime_type:mime, content_base64:value.split(',').pop() || ''});
      };
      reader.readAsDataURL(file);
    })));
  };

  const sendReply = async () => {
    const message = detail.querySelector('[data-support-reply]').value.trim();
    const fileInput = detail.querySelector('[data-support-reply-files]');
    if (!message) {
      setStatus('Напишите ответ пользователю.', 'error');
      return;
    }
    try {
      const attachments = await filesToPayload(fileInput);
      await mutate({action:'reply', message, attachments});
      detail.querySelector('[data-support-reply]').value = '';
      fileInput.value = '';
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось подготовить вложение.', 'error');
    }
  };

  const saveRelated = () => void mutate({
    action:'update_related',
    related:{
      game_id:detail.querySelector('[data-support-related-game]').value.trim(),
      payment_id:detail.querySelector('[data-support-related-payment]').value.trim(),
      tournament_id:detail.querySelector('[data-support-related-tournament]').value.trim(),
      operation_id:detail.querySelector('[data-support-related-operation]').value.trim(),
    },
  });

  const openAttachment = async attachmentId => {
    if (!attachmentId) return;
    try {
      const data = await post({action:'attachment', attachment_id:attachmentId});
      const attachment = data.attachment || {};
      const binary = atob(String(attachment.content_base64 || ''));
      const bytes = new Uint8Array(binary.length);
      for (let index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index);
      const blob = new Blob([bytes], {type:String(attachment.mime_type || 'application/octet-stream')});
      const url = URL.createObjectURL(blob);
      window.open(url, '_blank', 'noopener,noreferrer');
      window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось открыть вложение.', 'error');
    }
  };

  refresh.addEventListener('click', () => void load());
  [statusFilter, priorityFilter, categoryFilter, platformFilter].forEach(select => {
    select.addEventListener('change', () => void load());
  });
  queryInput.addEventListener('keydown', event => {
    if (event.key === 'Enter') void load();
  });
  detail.querySelector('[data-support-detail-status]').addEventListener('change', event => {
    if (currentTicket) void mutate({action:'set_status', status:event.target.value});
  });
  detail.querySelector('[data-support-detail-priority]').addEventListener('change', event => {
    if (currentTicket) void mutate({action:'set_priority', priority:event.target.value});
  });
  detail.querySelector('[data-support-assign-self]').addEventListener('click', () => void mutate({action:'assign_self'}));
  detail.querySelector('[data-support-unassign]').addEventListener('click', () => void mutate({action:'unassign'}));
  detail.querySelector('[data-support-related-save]').addEventListener('click', saveRelated);
  detail.querySelector('[data-support-reply-send]').addEventListener('click', () => void sendReply());

  load();
})();