(() => {
  'use strict';

  const root = document.querySelector('[data-admin-analytics]');
  const adminRoot = document.querySelector('.mgw-admin');
  if (!root || !adminRoot) return;

  const endpoint = String(adminRoot.dataset.analyticsApi || '');
  const telegram = window.Telegram?.WebApp || null;
  const status = root.querySelector('[data-analytics-status]');
  const refresh = root.querySelector('[data-analytics-refresh]');
  const updated = root.querySelector('[data-analytics-updated]');
  const usersBox = root.querySelector('[data-analytics-users]');
  const retentionBox = root.querySelector('[data-analytics-retention]');
  const retentionNote = root.querySelector('[data-analytics-retention-note]');
  const gamesSummary = root.querySelector('[data-analytics-games-summary]');
  const gamesBox = root.querySelector('[data-analytics-games]');
  const matchmakingBox = root.querySelector('[data-analytics-matchmaking]');
  const matchmakingNote = root.querySelector('[data-analytics-matchmaking-note]');
  const purchasesBox = root.querySelector('[data-analytics-purchases]');
  const offersBox = root.querySelector('[data-analytics-offers]');
  const adsBox = root.querySelector('[data-analytics-ads]');
  const tournamentsBox = root.querySelector('[data-analytics-tournaments]');
  const currentTournamentBox = root.querySelector('[data-analytics-current-tournament]');
  const coinsBox = root.querySelector('[data-analytics-coins]');
  const coinCategories = root.querySelector('[data-analytics-coin-categories]');
  const reconciliationBox = root.querySelector('[data-analytics-reconciliation]');
  const coverageBox = root.querySelector('[data-analytics-coverage]');
  let loading = false;

  const gameLabels = {
    tictactoe:'Крестики-нолики',
    four_in_a_row:'Четыре в ряд',
    battleship:'Морской бой',
    checkers:'Русские шашки',
    reversi:'Реверси',
    chess:'Шахматы',
    go:'Го',
    domino:'Домино',
  };

  const tournamentStates = {
    draft:'Черновик',
    registration_open:'Регистрация открыта',
    waiting_for_date:'Ожидает даты',
    scheduled:'Запланирован',
    cancelled:'Отменён',
    emergency_stopped:'Аварийно остановлен',
    in_progress:'Идёт',
    completed:'Завершён',
  };

  const categoryLabels = {
    opening_balance:'Стартовый баланс',
    balance_migration:'Миграция баланса',
    unified_balance_migration:'Объединение балансов',
    runtime_balance_sync:'Синхронизация баланса',
    match_entry:'Взнос за матч',
    match_entry_reserve:'Резерв взноса за матч',
    match_entry_release:'Возврат резерва матча',
    match_settlement:'Расчёт матча',
    match_reward:'Награда за матч',
    weekly_bonus:'Недельный бонус',
    cosmetic_purchase:'Покупка косметики',
    cosmetic_purchase_refund:'Возврат за косметику',
    tournament_entry:'Турнирный взнос',
    tournament_entry_reserve:'Резерв турнирного взноса',
    tournament_entry_release:'Возврат турнирного резерва',
    tournament_settlement:'Расчёт турнира',
    tournament_reward:'Турнирная награда',
    compensation:'Компенсация',
    admin_compensation:'Компенсация администратора',
    test_grant:'Тестовое начисление',
  };

  const blockerLabels = new Map([
    ['Canonical runtime balance differs from mgw_coin and requires synchronization.','Текущий баланс приложения отличается от канонического баланса MGW и требует синхронизации.'],
    ['Immutable ledger integrity verification failed for one or more balances.','Проверка целостности журнала операций не прошла для одного или нескольких балансов.'],
    ['Active economy reservations remain during frozen reconciliation.','Во время проверки остались активные финансовые резервы.'],
    ['Frozen JSON economy still has unapplied database balance deltas.','Есть изменения старого баланса, которые ещё не отражены в базе данных.'],
    ['Current JSON economy shadow differs from the database shadow.','Текущая копия экономики приложения отличается от её записи в базе данных.'],
  ]);

  const num = value => Number(value || 0).toLocaleString('ru-RU');
  const signed = value => {
    const n = Number(value || 0);
    return (n > 0 ? '+' : '') + n.toLocaleString('ru-RU');
  };
  const percent = value => value === null || value === undefined ? '—' : Number(value).toLocaleString('ru-RU', {maximumFractionDigits:1}) + '%';
  const duration = value => {
    const ms = Number(value);
    if (!Number.isFinite(ms) || ms < 0) return '—';
    if (ms < 1000) return Math.round(ms) + ' мс';
    if (ms < 60000) return (ms / 1000).toLocaleString('ru-RU', {maximumFractionDigits:1}) + ' с';
    return (ms / 60000).toLocaleString('ru-RU', {maximumFractionDigits:1}) + ' мин';
  };
  const dateTime = value => {
    const date = new Date(String(value || ''));
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('ru-RU');
  };

  const clear = node => node?.replaceChildren();

  const metric = (label, value, note = '') => {
    const card = document.createElement('div');
    const title = document.createElement('span');
    const strong = document.createElement('strong');
    title.textContent = label;
    strong.textContent = value;
    card.append(title, strong);
    if (note) {
      const small = document.createElement('small');
      small.textContent = note;
      card.append(small);
    }
    return card;
  };

  const renderMetrics = (container, rows) => {
    clear(container);
    rows.forEach(row => container.append(metric(row[0], row[1], row[2] || '')));
  };

  const setKpi = (name, value) => {
    const node = root.querySelector('[data-analytics-kpi="' + name + '"]');
    if (node) node.textContent = value;
  };

  const renderUsers = data => {
    setKpi('users', num(data.real_total));
    setKpi('active30', num(data.active_30d));
    renderMetrics(usersBox, [
      ['Новые за 24 часа', num(data.new_24h)],
      ['Новые за 7 дней', num(data.new_7d)],
      ['Новые за 30 дней', num(data.new_30d)],
      ['Активны за 24 часа', num(data.active_24h)],
      ['Активны за 7 дней', num(data.active_7d)],
      ['Активны за 30 дней', num(data.active_30d)],
    ]);
  };

  const renderRetention = (data, note) => {
    const d1 = data.return_after_1d || {};
    const d7 = data.return_after_7d || {};
    renderMetrics(retentionBox, [
      ['Вернулись спустя ≥ 1 день', percent(d1.percent), num(d1.returned_accounts) + ' из ' + num(d1.eligible_accounts)],
      ['Вернулись спустя ≥ 7 дней', percent(d7.percent), num(d7.returned_accounts) + ' из ' + num(d7.eligible_accounts)],
    ]);
    retentionNote.textContent = String(note || '');
  };

  const renderGames = data => {
    setKpi('games30', num(data.finished_30d));
    renderMetrics(gamesSummary, [
      ['За всё время', num(data.finished_all_time)],
      ['За 7 дней', num(data.finished_7d)],
      ['За 30 дней', num(data.finished_30d)],
      ['Игрок против игрока · 30 дней', num(data.pvp_30d)],
    ]);
    clear(gamesBox);
    const values = Object.entries(data.by_game_30d || {});
    const max = Math.max(1, ...values.map(([,value]) => Number(value || 0)));
    values.forEach(([game, value]) => {
      const row = document.createElement('div');
      row.className = 'mgw-admin__analytics-bar';
      const head = document.createElement('div');
      const label = document.createElement('span');
      const amount = document.createElement('strong');
      label.textContent = gameLabels[game] || game;
      amount.textContent = num(value);
      head.append(label, amount);
      const track = document.createElement('i');
      const fill = document.createElement('b');
      fill.style.width = Math.max(0, Math.min(100, Number(value || 0) / max * 100)) + '%';
      track.append(fill);
      row.append(head, track);
      gamesBox.append(row);
    });
    if (Number(data.vs_bot_30d || 0) > 0) {
      const note = document.createElement('p');
      note.className = 'mgw-admin__analytics-note';
      note.textContent = 'Матчей с ботом за 30 дней: ' + num(data.vs_bot_30d) + '.';
      gamesBox.append(note);
    }
  };

  const renderMatchmaking = (data, note) => {
    renderMetrics(matchmakingBox, [
      ['Сейчас в очереди', num(data.queue_depth)],
      ['Последнее ожидание', duration(data.last_wait_ms)],
      ['Среднее ожидание по подбору рейтинга', duration(data.skill_wait_avg_ms)],
      ['Максимальное ожидание по подбору рейтинга', duration(data.skill_wait_max_ms)],
      ['Совпадений человек-человек', num(data.human_match_total)],
      ['Совпадений с ботом', num(data.bot_match_total)],
      ['Расширений диапазона', num(data.skill_widened_match_total)],
      ['Предотвращено дублей матчей', num(data.duplicate_prevented_total)],
    ]);
    matchmakingNote.textContent = String(note || '');
  };

  const renderPurchases = data => {
    renderMetrics(purchasesBox, [
      ['Покупки за всё время', num(data.completed_all_time)],
      ['Покупки за 7 дней', num(data.completed_7d)],
      ['Покупки за 30 дней', num(data.completed_30d)],
      ['Покупателей за 30 дней', num(data.unique_buyers_30d)],
      ['Потрачено коинов за 30 дней', num(data.coins_spent_30d)],
    ]);
    clear(offersBox);
    const rows = Array.isArray(data.top_offers_30d) ? data.top_offers_30d : [];
    if (!rows.length) {
      offersBox.append(metric('Популярные предложения', 'Пока нет покупок'));
      return;
    }
    rows.forEach(row => {
      const item = document.createElement('div');
      const title = document.createElement('strong');
      const meta = document.createElement('span');
      title.textContent = String(row.offer_id || 'Предложение');
      meta.textContent = num(row.purchases) + ' покупок · ' + num(row.coins_spent) + ' коинов';
      item.append(title, meta);
      offersBox.append(item);
    });
  };

  const renderAds = (data, note) => {
    clear(adsBox);
    const strong = document.createElement('strong');
    const text = document.createElement('p');
    strong.textContent = data.available ? 'Данные доступны' : 'Телеметрия пока не собирается';
    text.textContent = data.available
      ? 'События рекламы поступают в аналитику.'
      : String(note || 'Исторических данных по рекламе нет.');
    adsBox.append(strong, text);
  };

  const renderTournaments = data => {
    renderMetrics(tournamentsBox, [
      ['С реальными участниками', num(data.with_real_participants)],
      ['Завершены с реальными участниками', num(data.completed_with_real_participants)],
      ['Реальных регистраций за всё время', num(data.real_registrations_all_time)],
      ['Реальных регистраций за 30 дней', num(data.real_registrations_30d)],
      ['Уникальных реальных участников', num(data.unique_real_participants)],
    ]);
    clear(currentTournamentBox);
    if (!data.current) {
      currentTournamentBox.textContent = 'Сейчас активного официального турнира нет.';
      return;
    }
    const title = document.createElement('strong');
    const meta = document.createElement('span');
    title.textContent = data.current.title || 'Текущий турнир';
    meta.textContent = (gameLabels[data.current.game_type] || data.current.game_type || 'Игра не указана')
      + ' · ' + (tournamentStates[data.current.state] || data.current.state || 'Статус не указан')
      + ' · реальных участников ' + num(data.current.real_registered_count) + ' из ' + num(data.current.capacity);
    currentTournamentBox.append(title, meta);
  };

  const renderCoins = data => {
    setKpi('net30', signed(data.net_30d));
    renderMetrics(coinsBox, [
      ['Создано за 7 дней', '+' + num(data.sources_7d)],
      ['Сожжено за 7 дней', '−' + num(data.sinks_7d)],
      ['Баланс за 30 дней', signed(data.net_30d)],
      ['Создано за 30 дней', '+' + num(data.sources_30d)],
      ['Сожжено за 30 дней', '−' + num(data.sinks_30d)],
      ['Доступно сейчас', num(data.available_now)],
      ['В резерве сейчас', num(data.reserved_now)],
    ]);
    clear(coinCategories);
    const rows = Array.isArray(data.by_category_30d) ? data.by_category_30d : [];
    if (!rows.length) {
      coinCategories.append(metric('Категории операций', 'За 30 дней движений нет'));
      return;
    }
    rows.forEach(row => {
      const item = document.createElement('div');
      const title = document.createElement('strong');
      const meta = document.createElement('span');
      title.textContent = categoryLabels[row.category] || String(row.category || 'Другая операция').replaceAll('_', ' ');
      meta.textContent = 'создано ' + num(row.sources)
        + ' · сожжено ' + num(row.sinks)
        + ' · итог ' + signed(row.net)
        + ' · операций ' + num(row.entries);
      item.append(title, meta);
      coinCategories.append(item);
    });
  };

  const translateBlocker = value => blockerLabels.get(String(value || '')) || String(value || '');

  const renderReconciliation = data => {
    clear(reconciliationBox);
    const hero = document.createElement('div');
    hero.className = data.ok ? 'is-ok' : 'is-warning';
    const strong = document.createElement('strong');
    const text = document.createElement('span');
    strong.textContent = data.ok ? 'Экономика согласована' : 'Требует внимания: ' + num(data.warning_count);
    text.textContent = data.ok
      ? 'Каноническая проверка балансов и журнала операций не сообщает о блокирующих расхождениях.'
      : 'Есть предупреждения канонической проверки. Аналитика ничего не исправляет автоматически.';
    hero.append(strong, text);
    reconciliationBox.append(hero);

    const metrics = document.createElement('div');
    metrics.className = 'mgw-admin__analytics-metrics mgw-admin__analytics-metrics--4';
    reconciliationBox.append(metrics);
    renderMetrics(metrics, [
      ['Ожидают синхронизации', num(data.planned_delta_count)],
      ['Ошибки целостности журнала', num(data.integrity_failure_count)],
      ['Активные резервы', num(data.active_reservation_count)],
      ['Записей в журнале', num(data.ledger_entry_count)],
    ]);

    const blockers = Array.isArray(data.blockers) ? data.blockers : [];
    if (blockers.length) {
      const list = document.createElement('ul');
      blockers.forEach(item => {
        const li = document.createElement('li');
        li.textContent = translateBlocker(item);
        list.append(li);
      });
      reconciliationBox.append(list);
    }
  };

  const renderCoverage = data => {
    clear(coverageBox);
    [
      ['Реальные аккаунты', data.real_accounts],
      ['Возврат пользователей', data.retention],
      ['Подбор соперников', data.matchmaking],
      ['Реклама', data.ads],
    ].forEach(([label, value]) => {
      const item = document.createElement('div');
      const strong = document.createElement('strong');
      const text = document.createElement('p');
      strong.textContent = label;
      text.textContent = String(value || 'Нет дополнительного пояснения.');
      item.append(strong, text);
      coverageBox.append(item);
    });
  };

  const post = async () => {
    const response = await fetch(endpoint, {
      method:'POST',
      cache:'no-store',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({
        action:'snapshot',
        initData:String(telegram?.initData || ''),
      }),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload?.ok !== true) {
      throw new Error(String(payload?.error || ('Ошибка HTTP ' + response.status)));
    }
    return payload;
  };

  const load = async () => {
    if (loading || !endpoint) return;
    loading = true;
    refresh.disabled = true;
    status.textContent = 'Обновляю продуктовые и экономические показатели…';
    delete status.dataset.state;
    try {
      const payload = await post();
      const data = payload.analytics || {};
      const coverage = data.coverage || {};
      renderUsers(data.users || {});
      renderRetention(data.retention || {}, coverage.retention);
      renderGames(data.games || {});
      renderMatchmaking(data.matchmaking || {}, coverage.matchmaking);
      renderPurchases(data.purchases || {});
      renderAds(data.ads || {}, coverage.ads);
      renderTournaments(data.tournaments || {});
      renderCoins(data.coin_flow || {});
      renderReconciliation(data.reconciliation || {});
      renderCoverage(coverage);
      updated.textContent = 'Обновлено: ' + dateTime(data.generated_at_utc);
      status.textContent = data.reconciliation?.ok === false
        ? 'Данные актуальны. В экономике есть предупреждения — они показаны ниже.'
        : 'Данные актуальны.';
      status.dataset.state = data.reconciliation?.ok === false ? 'warning' : 'ok';
    } catch (error) {
      status.textContent = error instanceof Error ? error.message : 'Не удалось загрузить аналитику.';
      status.dataset.state = 'error';
    } finally {
      loading = false;
      refresh.disabled = false;
    }
  };

  refresh.addEventListener('click', load);
  window.addEventListener('mgw:admin-refresh', load);
  load();
})();