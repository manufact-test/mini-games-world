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
  const queuePanel = root.querySelector('[data-support-queue-panel]');
  const queueTitle = root.querySelector('[data-support-queue-title]');
  const modeButtons = Array.from(root.querySelectorAll('[data-support-mode]'));
  const detail = root.querySelector('[data-support-detail]');
  const back = root.querySelector('[data-support-back]');
  const relatedSummary = root.querySelector('[data-support-related-summary]');
  const refresh = root.querySelector('[data-support-refresh]');
  const queryInput = root.querySelector('[data-support-filter-query]');
  const statusFilter = root.querySelector('[data-support-filter-status]');
  const priorityFilter = root.querySelector('[data-support-filter-priority]');
  const categoryFilter = root.querySelector('[data-support-filter-category]');
  const platformFilter = root.querySelector('[data-support-filter-platform]');
  const fileSummary = root.querySelector('[data-support-file-summary]');
  const replyFileList = root.querySelector('[data-support-reply-file-list]');
  const replyFileInput = root.querySelector('[data-support-reply-files]');
  const requestedTicket = new URLSearchParams(window.location.search).get('ticket') || '';
  const replyFileTypes = new Set(['image/jpeg','image/png','image/webp','image/gif','application/pdf','text/plain']);
  let selectedReplyFiles = [];
  let currentTicket = null;
  let currentAdminRef = '';
  let queueMode = requestedTicket ? 'all' : 'active';
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
    mode:queueMode,
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
    if (!value && currentTicket?.status === 'closed') {
      const closeButton = detail.querySelector('[data-support-close]');
      if (closeButton) closeButton.disabled = true;
    }
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
  const historyLabel = value => ({
    created:'Создано обращение',
    user_reply:'Сообщение игрока',
    admin_reply:'Ответ администратора',
    status_changed:'Изменён статус',
    priority_changed:'Изменён приоритет',
    owner_changed:'Изменён ответственный',
    related_ids_changed:'Обновлены технические связи',
  })[String(value || '')] || 'Изменение';
  const humanValue = value => ({
    open:'Открыто', in_progress:'В работе', waiting_user:'Ожидает игрока', resolved:'Решено', closed:'Закрыто',
    low:'Низкий', normal:'Обычный', high:'Высокий', critical:'Критический',
  })[String(value || '')] || String(value ?? '');

  const renderMetrics = metrics => {
    window.dispatchEvent(new CustomEvent('mgw:admin-support-summary', {
      detail:{
        open:Number(metrics?.open_total || 0),
        critical:Number(metrics?.critical_open || 0),
        unowned:Number(metrics?.unowned_open || 0),
      }
    }));
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
      <span class="mgw-admin__support-ticket-state">${escapeHtml(ticket.status_label || ticket.status || '—')}</span>
    `;
    button.addEventListener('click', () => void openTicket(ticket.ticket_number));
    return button;
  };

  const renderQueue = tickets => {
    queue.replaceChildren();
    if (!Array.isArray(tickets) || tickets.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'mgw-admin__history-empty';
      empty.textContent = queueMode === 'processed' ? 'Обработанных обращений пока нет.' : 'Активных обращений по выбранным фильтрам нет.';
      queue.append(empty);
      return;
    }
    tickets.forEach(ticket => {
      const item = queueItem(ticket);
      if (currentTicket && String(ticket.ticket_number || '') === String(currentTicket.ticket_number || '')) item.setAttribute('aria-current','true');
      queue.append(item);
    });
  };

  const attachmentButton = attachment => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'mgw-admin__support-attachment';
    button.textContent = `📎 ${attachment.file_name || 'Вложение'} · ${Math.max(1, Math.ceil(Number(attachment.size_bytes || 0) / 1024))} КБ`;
    button.addEventListener('click', () => void openAttachment(String(attachment.attachment_id || ''), button));
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
      who.textContent = message.actor_type === 'admin' ? `Администратор · ${message.actor_ref || '—'}` : `Игрок · ${message.actor_ref || '—'}`;
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
      const value = [event.previous_value, event.next_value]
        .filter(value => value !== null && value !== '')
        .map(humanValue)
        .join(' → ');
      row.innerHTML = `<strong>${escapeHtml(historyLabel(event.event_type))}</strong><span>${escapeHtml(value)}</span><em>${escapeHtml(event.actor_ref || '—')} · ${escapeHtml(event.created_at_utc || '—')} UTC</em>`;
      history.append(row);
    });
  };

  const renderDetail = ticket => {
    currentTicket = ticket;
    const terminal = ['resolved','closed'].includes(String(ticket.status || ''));
    detail.hidden = false;
    detail.dataset.ticketTerminal = terminal ? '1' : '0';
    detail.querySelector('[data-support-detail-number]').textContent = String(ticket.ticket_number || '—');
    detail.querySelector('[data-support-detail-subject]').textContent = String(ticket.subject || 'Обращение');
    detail.querySelector('[data-support-detail-player]').textContent = String(ticket.requester_mgw_id || '—');
    detail.querySelector('[data-support-detail-platform]').textContent = String(ticket.platform_label || '—');
    detail.querySelector('[data-support-detail-category]').textContent = String(ticket.category_label || '—');
    detail.querySelector('[data-support-detail-owner]').textContent = ticket.owner_ref
      ? `Ответственный: ${String(ticket.owner_ref)}`
      : 'Ответственный не назначен';

    const statusValue = detail.querySelector('[data-support-detail-status]');
    if (statusValue) statusValue.textContent = humanValue(ticket.status || ticket.status_label || '—');

    const prioritySelect = detail.querySelector('[data-support-detail-priority]');
    if (Array.from(prioritySelect.options).some(option => option.value === ticket.priority)) prioritySelect.value = ticket.priority;
    prioritySelect.className = `mgw-admin__support-select ${priorityClass(ticket.priority)}`;
    prioritySelect.disabled = terminal;

    const takeButton = detail.querySelector('[data-support-assign-self]');
    const closeButton = detail.querySelector('[data-support-close]');
    if (takeButton) takeButton.hidden = String(ticket.status || '') !== 'open';
    if (closeButton) closeButton.hidden = !['in_progress','waiting_user'].includes(String(ticket.status || ''));

    const related = ticket.related || {};
    const relatedInputs = [
      detail.querySelector('[data-support-related-game]'),
      detail.querySelector('[data-support-related-payment]'),
      detail.querySelector('[data-support-related-tournament]'),
      detail.querySelector('[data-support-related-operation]'),
    ];
    relatedInputs[0].value = related.game_id || '';
    relatedInputs[1].value = related.payment_id || '';
    relatedInputs[2].value = related.tournament_id || '';
    relatedInputs[3].value = related.operation_id || '';
    relatedInputs.forEach(input => { input.disabled = terminal; });
    const relatedSave = detail.querySelector('[data-support-related-save]');
    if (relatedSave) relatedSave.hidden = terminal;

    const linked = [
      related.game_id ? 'матч' : '',
      related.payment_id ? 'пополнение' : '',
      related.tournament_id ? 'турнир' : '',
      related.operation_id ? 'операция' : '',
    ].filter(Boolean);
    relatedSummary.textContent = linked.length
      ? `Связанные данные: ${linked.join(', ')}`
      : 'Связанные данные: нет';

    const reply = detail.querySelector('.mgw-admin__support-reply');
    if (reply) reply.hidden = terminal;

    renderThread(ticket);
    renderHistory(ticket);
    root.classList.add('is-ticket-open');
    queue.querySelectorAll('[data-ticket-number]').forEach(button => {
      button.setAttribute('aria-current', button.dataset.ticketNumber === String(ticket.ticket_number || '') ? 'true' : 'false');
    });
  };

  const renderSnapshot = data => {
    currentAdminRef = String(data.admin_ref || currentAdminRef || '');
    fillSelect(statusFilter, data.statuses || {}, 'Все статусы');
    fillSelect(priorityFilter, data.priorities || {}, 'Все приоритеты');
    fillSelect(categoryFilter, data.categories || {}, 'Все категории');
    fillSelect(platformFilter, data.platforms || {}, 'Все платформы');
    const detailPriority = detail.querySelector('[data-support-detail-priority]');
    fillSelect(detailPriority, data.priorities || {}, 'Приоритет');
    if (currentTicket) detailPriority.value = currentTicket.priority || '';
    renderMetrics(data.metrics || {});
    renderQueue(data.tickets || []);
    if (data.ticket) renderDetail(data.ticket);
  };

  const load = async () => {
    if (busy) return;
    if (!telegram?.initData) {
      setStatus('Откройте панель администратора из Telegram.', 'error');
      return;
    }
    setBusy(true);
    setStatus('Загружаю обращения…');
    try {
      const data = await post({action:'snapshot', filters:filters()});
      renderSnapshot(data);
      setStatus(queueMode === 'processed' ? 'Обработанные обращения загружены.' : 'Активные обращения загружены.', 'ok');
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось загрузить поддержку.', 'error');
    } finally {
      setBusy(false);
    }
    if (requestedTicket && !currentTicket) void openTicket(requestedTicket, false);
  };

  const openTicket = async (ticketNumber, scroll = true) => {
    if (busy || !ticketNumber) return;
    if (currentTicket && String(currentTicket.ticket_number || '') !== String(ticketNumber)) clearReplyFiles();
    setBusy(true);
    setStatus(`Открываю ${ticketNumber}…`);
    try {
      const data = await post({action:'ticket', ticket:ticketNumber, filters:filters()});
      renderSnapshot(data);
      if (window.matchMedia('(max-width: 980px)').matches) {
        window.requestAnimationFrame(() => detail.scrollIntoView({
          block:'start',
          behavior:scroll ? 'smooth' : 'auto'
        }));
      }
      setStatus(`${ticketNumber}: карточка и история загружены.`, 'ok');
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось открыть обращение.', 'error');
    } finally {
      setBusy(false);
    }
  };

  const mutate = async payload => {
    if (busy || !currentTicket?.ticket_number) return null;
    const ticketNumber = currentTicket.ticket_number;
    setBusy(true);
    try {
      const data = await post({...payload, ticket:ticketNumber, filters:filters()});
      const closedNow = payload.action === 'set_status' && payload.status === 'closed' && String(data.ticket?.status || '') === 'closed';
      if (closedNow) {
        currentTicket = null;
        clearReplyFiles();
        detail.hidden = true;
        root.classList.remove('is-ticket-open');
        renderSnapshot({...data, ticket:null});
        setStatus(`${ticketNumber}: обращение закрыто и перенесено в «Обработанные».`, 'ok');
        return data;
      }
      renderSnapshot(data);
      if (payload.action !== 'reply') {
        setStatus(`${ticketNumber}: изменения сохранены.`, 'ok');
      }
      return data;
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось сохранить изменение.', 'error');
      return null;
    } finally {
      setBusy(false);
    }
  };

  const formatFileSize = bytes => {
    const size = Number(bytes || 0);
    if (size < 1024) return `${size} Б`;
    if (size < 1024 * 1024) return `${Math.max(1, Math.round(size / 1024))} КБ`;
    return `${(size / (1024 * 1024)).toFixed(1).replace('.0','')} МБ`;
  };

  const renderReplyFiles = () => {
    if (fileSummary) {
      fileSummary.textContent = selectedReplyFiles.length
        ? `Выбрано файлов: ${selectedReplyFiles.length}/3`
        : 'Файлы не выбраны';
    }
    if (!replyFileList) return;
    replyFileList.replaceChildren();
    selectedReplyFiles.forEach((file, index) => {
      const row = document.createElement('div');
      row.className = 'mgw-admin__reply-file';

      const copy = document.createElement('div');
      const name = document.createElement('strong');
      const meta = document.createElement('span');
      name.textContent = file.name;
      meta.textContent = formatFileSize(file.size);
      copy.append(name, meta);

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'mgw-admin__reply-file-remove';
      remove.setAttribute('aria-label', `Удалить ${file.name}`);
      remove.textContent = '×';
      remove.addEventListener('click', () => {
        selectedReplyFiles.splice(index, 1);
        renderReplyFiles();
      });

      row.append(copy, remove);
      replyFileList.append(row);
    });
  };

  const clearReplyFiles = () => {
    selectedReplyFiles = [];
    if (replyFileInput) replyFileInput.value = '';
    renderReplyFiles();
  };

  const addReplyFiles = files => {
    const errors = [];
    Array.from(files || []).forEach(file => {
      if (selectedReplyFiles.length >= 3) {
        errors.push('Можно выбрать не больше 3 файлов.');
        return;
      }
      const mime = String(file.type || '').toLowerCase();
      if (!replyFileTypes.has(mime)) {
        errors.push(`${file.name}: поддерживаются изображения, PDF и TXT.`);
        return;
      }
      if (Number(file.size || 0) > 2_000_000) {
        errors.push(`${file.name}: файл больше 2 МБ.`);
        return;
      }
      selectedReplyFiles.push(file);
    });
    if (replyFileInput) replyFileInput.value = '';
    renderReplyFiles();
    if (errors.length) {
      setStatus(errors[0], 'error');
    } else if (selectedReplyFiles.length) {
      setStatus('Вложения готовы к отправке.', 'ok');
    }
  };

  const filesToPayload = async files => Promise.all(Array.from(files || []).map(file => new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(new Error(`${file.name}: не удалось прочитать файл.`));
    reader.onload = () => {
      const value = String(reader.result || '');
      resolve({
        file_name:file.name,
        mime_type:String(file.type || '').toLowerCase(),
        content_base64:value.split(',').pop() || ''
      });
    };
    reader.readAsDataURL(file);
  })));

  const sendReply = async () => {
    const message = detail.querySelector('[data-support-reply]').value.trim();
    if (!message) {
      setStatus('Напишите ответ пользователю.', 'error');
      return;
    }
    try {
      const attachments = await filesToPayload(selectedReplyFiles);
      const result = await mutate({action:'reply', message, attachments});
      if (!result) return;
      detail.querySelector('[data-support-reply]').value = '';
      clearReplyFiles();
      if (result.notification?.ok && result.notification?.feed_verified && Number(result.notification?.delivered_count || 0) > 0) {
        setStatus('Ответ отправлен. Уведомление создано в колокольчике пользователя.', 'ok');
      } else {
        setStatus(result.notification?.error || 'Ответ сохранён, но уведомление не подтверждено в колокольчике пользователя.', 'error');
      }
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось отправить ответ.', 'error');
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

  let attachmentViewerUrl = '';

  const closeAttachmentViewer = () => {
    document.querySelector('[data-support-attachment-viewer]')?.remove();
    if (attachmentViewerUrl) {
      URL.revokeObjectURL(attachmentViewerUrl);
      attachmentViewerUrl = '';
    }
  };

  const showAttachmentViewer = attachment => {
    closeAttachmentViewer();

    const mime = String(attachment.mime_type || 'application/octet-stream').toLowerCase();
    const fileName = String(attachment.file_name || 'Вложение');
    const base64 = String(attachment.content_base64 || '');
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);
    for (let index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index);

    const overlay = document.createElement('div');
    overlay.className = 'mgw-admin__attachment-viewer';
    overlay.dataset.supportAttachmentViewer = '';
    overlay.tabIndex = -1;

    const card = document.createElement('div');
    card.className = 'mgw-admin__attachment-viewer-card';

    const head = document.createElement('div');
    head.className = 'mgw-admin__attachment-viewer-head';

    const copy = document.createElement('div');
    const title = document.createElement('strong');
    const meta = document.createElement('span');
    title.textContent = fileName;
    meta.textContent = `${mime || 'файл'} · ${Math.max(1, Math.ceil(bytes.length / 1024))} КБ`;
    copy.append(title, meta);

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'mgw-admin__attachment-viewer-close';
    close.setAttribute('aria-label', 'Закрыть просмотр вложения');
    close.textContent = '×';
    close.addEventListener('click', closeAttachmentViewer);
    head.append(copy, close);

    const preview = document.createElement('div');
    preview.className = 'mgw-admin__attachment-viewer-preview';

    if (mime.startsWith('image/')) {
      const image = document.createElement('img');
      image.alt = fileName;
      image.src = `data:${mime};base64,${base64}`;
      preview.append(image);
    } else if (mime === 'text/plain') {
      const pre = document.createElement('pre');
      pre.textContent = new TextDecoder('utf-8').decode(bytes);
      preview.append(pre);
    } else {
      const note = document.createElement('div');
      note.className = 'mgw-admin__attachment-viewer-note';
      note.textContent = mime === 'application/pdf'
        ? 'PDF можно скачать на устройство.'
        : 'Файл можно скачать на устройство.';
      preview.append(note);
    }

    const blob = new Blob([bytes], {type:mime || 'application/octet-stream'});
    attachmentViewerUrl = URL.createObjectURL(blob);

    const actions = document.createElement('div');
    actions.className = 'mgw-admin__attachment-viewer-actions';

    const download = document.createElement('a');
    download.href = attachmentViewerUrl;
    download.download = fileName;
    download.textContent = mime === 'application/pdf' ? 'Скачать PDF' : 'Скачать';

    actions.append(download);
    card.append(head, preview, actions);
    overlay.append(card);

    overlay.addEventListener('click', event => {
      if (event.target === overlay) closeAttachmentViewer();
    });
    overlay.addEventListener('keydown', event => {
      if (event.key === 'Escape') closeAttachmentViewer();
    });

    document.body.append(overlay);
    overlay.focus({preventScroll:true});
  };

  const openAttachment = async (attachmentId, sourceButton = null) => {
    if (!attachmentId) return;
    const originalText = sourceButton?.textContent || '';
    if (sourceButton) {
      sourceButton.disabled = true;
      sourceButton.textContent = 'Открываю…';
    }
    try {
      const data = await post({action:'attachment', attachment_id:attachmentId});
      showAttachmentViewer(data.attachment || {});
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось открыть вложение.', 'error');
    } finally {
      if (sourceButton?.isConnected) {
        sourceButton.disabled = false;
        sourceButton.textContent = originalText;
      }
    }
  };

  back.addEventListener('click', () => {
    clearReplyFiles();
    currentTicket = null;
    detail.hidden = true;
    root.classList.remove('is-ticket-open');
    if (window.matchMedia('(max-width: 980px)').matches) queuePanel.scrollIntoView({block:'start', behavior:'smooth'});
  });
  refresh.addEventListener('click', () => void load());
  modeButtons.forEach(button => button.addEventListener('click', () => {
    queueMode = String(button.dataset.supportMode || 'active');
    statusFilter.value = '';
    currentTicket = null;
    detail.hidden = true;
    root.classList.remove('is-ticket-open');
    modeButtons.forEach(node => node.classList.toggle('is-active', node === button));
    if (queueTitle) queueTitle.textContent = queueMode === 'processed' ? 'Обработанные обращения' : 'Активные обращения';
    void load();
  }));
  [statusFilter, priorityFilter, categoryFilter, platformFilter].forEach(select => {
    select.addEventListener('change', () => void load());
  });
  queryInput.addEventListener('keydown', event => {
    if (event.key === 'Enter') void load();
  });
  detail.querySelector('[data-support-detail-priority]').addEventListener('change', event => {
    if (currentTicket && !['resolved','closed'].includes(String(currentTicket.status || ''))) {
      void mutate({action:'set_priority', priority:event.target.value});
    }
  });
  detail.querySelector('[data-support-assign-self]').addEventListener('click', () => void mutate({action:'assign_self'}));
  detail.querySelector('[data-support-close]')?.addEventListener('click', () => {
    if (!currentTicket || !['in_progress','waiting_user'].includes(String(currentTicket.status || ''))) return;
    void mutate({action:'set_status', status:'closed'});
  });
  detail.querySelector('[data-support-related-save]').addEventListener('click', saveRelated);
  detail.querySelector('[data-support-reply-send]').addEventListener('click', () => void sendReply());
  replyFileInput?.addEventListener('change', event => {
    addReplyFiles(event.target.files);
  });

  if (queueTitle) queueTitle.textContent = queueMode === 'processed' ? 'Обработанные обращения' : 'Активные обращения';
  if (requestedTicket) modeButtons.forEach(button => button.classList.remove('is-active'));
  renderReplyFiles();
  load();
})();