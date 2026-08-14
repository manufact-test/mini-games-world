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
  const endpoint = String(root.dataset.adminApi || '');
  const telegram = window.Telegram?.WebApp || null;
  let requestInFlight = false;

  const setStatus = (message, state = '') => {
    status.textContent = message;
    if (state) status.dataset.state = state;
    else delete status.dataset.state;
  };

  const render = (data) => {
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

  const load = async () => {
    if (requestInFlight) return;
    if (!telegram || !telegram.initData) {
      setStatus('Откройте Web Admin кнопкой из админ-панели бота в Telegram.', 'error');
      return;
    }

    requestInFlight = true;
    refresh.disabled = true;
    setStatus('Загружаю актуальное состояние…');

    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        cache: 'no-store',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          action: 'snapshot',
          initData: telegram.initData
        })
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || data.ok !== true) {
        throw new Error(String(data.error || 'Не удалось загрузить панель.'));
      }

      render(data);
      setStatus('Данные загружены. Обновление выполняется только вручную.', 'ok');
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось загрузить панель.', 'error');
    } finally {
      requestInFlight = false;
      refresh.disabled = false;
    }
  };

  if (telegram) {
    telegram.ready();
    telegram.expand();
  }

  refresh.addEventListener('click', load);
  load();
})();
