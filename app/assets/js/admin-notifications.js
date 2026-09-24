(() => {
  'use strict';

  const root = document.querySelector('[data-admin-notifications]');
  const shell = document.querySelector('[data-notifications-api]');
  if (!root || !shell) return;

  const endpoint = String(shell.dataset.notificationsApi || '');
  const telegram = window.Telegram?.WebApp || null;
  const status = root.querySelector('[data-notification-event-status]');
  const list = root.querySelector('[data-notification-event-list]');
  const refresh = root.querySelector('[data-notification-event-refresh]');
  const send = root.querySelector('[data-notification-event-send]');
  const sourceType = root.querySelector('[data-notification-source-type]');
  const audienceType = root.querySelector('[data-notification-audience-type]');
  const audienceRef = root.querySelector('[data-notification-audience-ref]');
  const targetMgwId = root.querySelector('[data-notification-target-mgw-id]');
  const recipients = root.querySelector('[data-notification-recipient-mgw-ids]');
  const platform = root.querySelector('[data-notification-platform]');
  const title = root.querySelector('[data-notification-title]');
  const text = root.querySelector('[data-notification-text]');
  const deepLink = root.querySelector('[data-notification-deep-link]');
  const scheduledAt = root.querySelector('[data-notification-scheduled-at]');
  const expiresAt = root.querySelector('[data-notification-expires-at]');
  const audienceHint = root.querySelector('[data-notification-audience-hint]');
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
    if (!response.ok || data.ok !== true) throw new Error(String(data.error || 'Не удалось обработать уведомление.'));
    return data;
  };

  const requestId = () => {
    if (window.crypto?.randomUUID) return `admin:${window.crypto.randomUUID()}`;
    return `admin:${Date.now()}:${Math.random().toString(16).slice(2)}`;
  };

  const asIso = (value) => {
    const raw = String(value || '').trim();
    if (!raw) return '';
    const date = new Date(raw);
    return Number.isNaN(date.getTime()) ? raw : date.toISOString();
  };

  const sourceLabel = value => ({admin:'Администратор',system:'Система',support:'Поддержка'})[String(value || '')] || String(value || '—');
  const audienceLabel = value => ({
    all:'Все игроки', one:'Один игрок', segment:'Сегмент', platform:'Платформа',
    tournament:'Участники турнира', support:'Участник обращения'
  })[String(value || '')] || String(value || '—');

  const recipientIds = () => String(recipients.value || '')
    .split(/[\s,;]+/)
    .map(value => value.trim())
    .filter(Boolean);

  const syncAudience = () => {
    const type = String(audienceType.value || 'all');
    targetMgwId.closest('label').hidden = type !== 'one';
    platform.closest('label').hidden = type !== 'platform';
    audienceRef.closest('label').hidden = !['segment','tournament','support'].includes(type);
    recipients.closest('label').hidden = !['segment','tournament','support'].includes(type);
    audienceHint.textContent = ({
      all:'Все текущие MGW-аккаунты.',
      one:'Один точный MGW-ID.',
      platform:'Все аккаунты выбранной платформы.',
      segment:'Явный снимок MGW-ID + идентификатор сегмента.',
      tournament:'Зафиксированный список участников выбранного турнира.',
      support:'Получатель, связанный с выбранным обращением.',
    })[type] || '';
  };

  const render = (events) => {
    list.replaceChildren();
    if (!Array.isArray(events) || events.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'mgw-admin__history-empty';
      empty.textContent = 'Уведомлений пока нет.';
      list.append(empty);
      return;
    }

    events.forEach(event => {
      const item = document.createElement('div');
      item.className = 'mgw-admin__history-item';
      const copy = document.createElement('div');
      copy.className = 'mgw-admin__history-copy';
      const head = document.createElement('strong');
      head.textContent = `${event.title || 'Уведомление'} · ${sourceLabel(event.source_type)} / ${audienceLabel(event.audience_type)}`;
      const body = document.createElement('span');
      body.textContent = String(event.text || '');
      const lifecycle = document.createElement('span');
      lifecycle.textContent = `Получателей: ${event.recipient_count || 0} · доставлено: ${event.delivered_count || 0} · прочитано: ${event.read_count || 0}`;
      const timing = document.createElement('span');
      timing.textContent = `Отправка: ${event.scheduled_at || 'сразу'} · срок действия: ${event.expires_at || 'не ограничен'}${event.expired ? ' · истёк' : ''}`;
      const meta = document.createElement('span');
      meta.textContent = `${event.event_id || '—'} · ${event.audience_ref || '—'}${event.deep_link ? ` · → ${event.deep_link}` : ''}`;
      copy.append(head, body, lifecycle, timing, meta);
      item.append(copy);
      list.append(item);
    });
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
    send.disabled = true;
    status.textContent = 'Загружаю уведомления…';
    delete status.dataset.state;
    try {
      const data = await post({action:'snapshot'});
      render(data.events || []);
      status.textContent = 'Центр уведомлений готов.';
      status.dataset.state = 'ok';
    } catch (error) {
      status.textContent = error instanceof Error ? error.message : 'Не удалось загрузить уведомления.';
      status.dataset.state = 'error';
    } finally {
      busy = false;
      refresh.disabled = false;
      send.disabled = false;
    }
  };

  const create = async () => {
    if (busy) return;
    if (!telegram?.initData) {
      status.textContent = 'Откройте Web Admin из Telegram.';
      status.dataset.state = 'error';
      return;
    }

    busy = true;
    refresh.disabled = true;
    send.disabled = true;
    status.textContent = 'Создаю уведомление…';
    delete status.dataset.state;
    try {
      const event = {
        request_id:requestId(),
        source_type:String(sourceType.value || 'admin'),
        audience_type:String(audienceType.value || 'all'),
        audience_ref:String(audienceRef.value || '').trim(),
        target_mgw_id:String(targetMgwId.value || '').trim(),
        recipient_mgw_ids:recipientIds(),
        platform:String(platform.value || '').trim(),
        title:String(title.value || '').trim(),
        text:String(text.value || '').trim(),
        deep_link:String(deepLink.value || '').trim(),
        scheduled_at:asIso(scheduledAt.value),
        expires_at:asIso(expiresAt.value),
      };
      const data = await post({action:'create', event});
      render(data.events || []);
      status.textContent = `Уведомление создано: ${data.created?.recipient_count || 0} получателей.`;
      status.dataset.state = 'ok';
    } catch (error) {
      status.textContent = error instanceof Error ? error.message : 'Не удалось создать уведомление.';
      status.dataset.state = 'error';
    } finally {
      busy = false;
      refresh.disabled = false;
      send.disabled = false;
    }
  };

  audienceType.addEventListener('change', syncAudience);
  refresh.addEventListener('click', () => void load());
  send.addEventListener('click', () => void create());
  syncAudience();
  load();
})();
