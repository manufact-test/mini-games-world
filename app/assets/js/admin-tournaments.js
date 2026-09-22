(() => {
  'use strict';

  const root = document.querySelector('.mgw-admin');
  const card = document.querySelector('[data-tournament-admin]');
  if (!root || !card) return;

  const endpoint = String(root.dataset.tournamentApi || '');
  const telegram = window.Telegram?.WebApp || null;
  const status = card.querySelector('[data-tournament-admin-status]');
  const summary = card.querySelector('[data-tournament-admin-summary]');
  const current = card.querySelector('[data-tournament-current]');
  const rewards = card.querySelector('[data-tournament-rewards]');
  const rules = card.querySelector('[data-tournament-rules]');
  const title = card.querySelector('[data-tournament-title]');
  const game = card.querySelector('[data-tournament-game]');
  const capacity = card.querySelector('[data-tournament-capacity]');
  const create = card.querySelector('[data-tournament-create]');
  const open = card.querySelector('[data-tournament-open]');
  const refresh = card.querySelector('[data-tournament-refresh]');
  const schedulePanel = card.querySelector('[data-tournament-schedule-panel]');
  const scheduleStart = card.querySelector('[data-tournament-start]');
  const assignDate = card.querySelector('[data-tournament-assign-date]');
  const scheduleInfo = card.querySelector('[data-tournament-schedule-info]');
  const manualPanel = card.querySelector('[data-tournament-manual-panel]');
  const manualInfo = card.querySelector('[data-tournament-manual-info]');
  const prepareManual = card.querySelector('[data-tournament-prepare-manual]');
  const manualNote = manualPanel?.querySelector('small') || null;
  const progressionPanel = card.querySelector('[data-tournament-progression-panel]');
  const progressionInfo = card.querySelector('[data-tournament-progression-info]');
  const completeFixtures = card.querySelector('[data-tournament-complete-fixtures]');
  const resetPanel = card.querySelector('[data-tournament-reset-panel]');
  const resetInfo = card.querySelector('[data-tournament-reset-info]');
  const resetManual = card.querySelector('[data-tournament-reset-manual]');
  let busy = false;
  let snapshot = null;
  let manualAcceptance = null;
  let manualReset = null;
  let manualProgression = null;
  let resetConfirmUntil = 0;
  let resetConfirmTimer = null;

  const format = value => new Intl.NumberFormat('ru-RU').format(Number(value || 0));
  const stateLabel = value => ({
    draft:'черновик',
    registration_open:'регистрация открыта',
    waiting_for_date:'состав набран · ожидает дату',
    scheduled:'дата назначена',
  })[String(value || '')] || String(value || '—');
  const parseUtc = value => {
    const raw = String(value || '').trim();
    if (!raw) return null;
    let normalized = raw.replace(' ', 'T');
    normalized = normalized.replace(/(\.\d{3})\d+/, '$1');
    if (!/[zZ]|[+-]\d{2}:?\d{2}$/.test(normalized)) normalized += 'Z';
    const date = new Date(normalized);
    return Number.isNaN(date.getTime()) ? null : date;
  };
  const formatDateTime = value => {
    const date = value instanceof Date ? value : parseUtc(value);
    if (!date) return '—';
    return new Intl.DateTimeFormat('ru-RU', {
      day:'2-digit',
      month:'2-digit',
      year:'numeric',
      hour:'2-digit',
      minute:'2-digit',
    }).format(date);
  };

  const gameLabel = value => ({
    tictactoe:'Крестики-нолики',
    four_in_a_row:'Четыре в ряд',
    battleship:'Морской бой',
    checkers:'Русские шашки',
    reversi:'Реверси',
    chess:'Шахматы',
    go:'Го',
    domino:'Домино',
  })[String(value || '')] || String(value || '—');
  const rewardSummary = snapshot => {
    const placements = snapshot?.placements && typeof snapshot.placements === 'object'
      ? snapshot.placements
      : {};
    const first = placements['1'] || {};
    const second = placements['2'] || {};
    const third = placements['3'] || {};
    const entry = Number(snapshot?.entry?.amount || 50000);
    return [
      `Взнос: ${format(entry)} коинов — при регистрации только резервируется.`,
      '',
      `1 место: ${format(first.total || 200000)} коинов · Золотой билет · корона чемпиона на 30 дней · постоянный значок победителя · эксклюзивная косметика · Зал славы · золотой кубок.`,
      `2 место: ${format(second.total || 80000)} коинов · серебряная рамка на 30 дней · постоянный результат финалиста · серебряный кубок.`,
      `3 место: ${format(third.total || 50000)} коинов · возврат взноса · бронзовая отметка на 30 дней · постоянный результат третьего места · бронзовый кубок.`,
      '',
      'Золотой билет нельзя продать или передать другому игроку.',
    ].join('\n');
  };

  const rulesSummary = rulesProjection => {
    const snapshot = rulesProjection?.snapshot && typeof rulesProjection.snapshot === 'object'
      ? rulesProjection.snapshot
      : {};
    const sections = Array.isArray(snapshot.sections) ? snapshot.sections : [];
    if (!rulesProjection?.version || !rulesProjection?.language || !rulesProjection?.sha256 || !sections.length) {
      return 'Правила турнира ещё не подготовлены.';
    }
    const lines = [
      `Версия: ${rulesProjection.version}`,
      `Язык: ${String(rulesProjection.language).toUpperCase()}`,
      `SHA-256: ${rulesProjection.sha256}`,
      '',
    ];
    sections.forEach(section => {
      lines.push(String(section?.title || 'Раздел'));
      (Array.isArray(section?.items) ? section.items : []).forEach(item => lines.push(`• ${String(item || '')}`));
      lines.push('');
    });
    lines.push('Правила являются снимком этого турнира. Существенная правка требует отмены и нового турнира, а не редактирования текущего.');
    return lines.join('\n').trim();
  };

  const setBusy = (value) => {
    busy = value;
    card.querySelectorAll('button, input, select').forEach(control => {
      if (value) {
        // Draft form controls must remain editable even while background admin
        // reads/resets are running. Disabling a focused Telegram WebView input
        // can strand the soft keyboard until the app is backgrounded.
        if (control === title || control === game || control === capacity) return;
        control.disabled = true;
        return;
      }
      if (control === open) {
        control.disabled = open.dataset.available !== '1';
        return;
      }
      if (control === assignDate) {
        control.disabled = assignDate.dataset.available !== '1';
        return;
      }
      if (control === scheduleStart) {
        control.disabled = scheduleStart.dataset.locked === '1';
        return;
      }
      if (control === prepareManual) {
        control.disabled = prepareManual.dataset.available !== '1';
        return;
      }
      if (control === completeFixtures) {
        control.disabled = completeFixtures.dataset.available !== '1';
        return;
      }
      if (control === resetManual) {
        control.disabled = resetManual.dataset.available !== '1';
        return;
      }
      control.disabled = false;
    });
  };

  const setStatus = (message, state = '') => {
    status.textContent = message;
    if (state) status.dataset.state = state;
    else delete status.dataset.state;
  };

  const restoreDraftControls = () => {
    for (const control of [title, game, capacity]) {
      if (!(control instanceof HTMLElement)) continue;
      control.removeAttribute('disabled');
      control.removeAttribute('readonly');
    }
  };

  const releaseFocusBeforeHide = panel => {
    if (!(panel instanceof HTMLElement)) return;
    const active = document.activeElement;
    if (active instanceof HTMLElement && panel.contains(active)) {
      active.blur();
    }
  };

  const disarmResetConfirmation = () => {
    resetConfirmUntil = 0;
    if (resetConfirmTimer) window.clearTimeout(resetConfirmTimer);
    resetConfirmTimer = null;
    if (resetManual instanceof HTMLButtonElement) {
      resetManual.textContent = 'Сбросить staging-турнир';
    }
  };

  const armResetConfirmation = warning => {
    resetConfirmUntil = Date.now() + 8000;
    if (resetManual instanceof HTMLButtonElement) {
      resetManual.textContent = 'Подтвердить сброс';
    }
    setStatus(warning, 'error');
    if (resetConfirmTimer) window.clearTimeout(resetConfirmTimer);
    resetConfirmTimer = window.setTimeout(() => {
      disarmResetConfirmation();
      if (!busy) setStatus('Сброс staging-турнира не подтверждён.');
    }, 8000);
  };

  const post = async payload => {
    if (!telegram?.initData) throw new Error('Откройте Web Admin из Telegram.');
    const headers = {'Content-Type':'application/json'};
    const requestedLiveSeats = Number(payload?.live_seats || 0);
    if ([1,2].includes(requestedLiveSeats)) {
      headers['X-MGW-Manual-Live-Seats'] = String(requestedLiveSeats);
    }
    const response = await fetch(endpoint, {
      method:'POST',
      cache:'no-store',
      credentials:'same-origin',
      headers,
      body:JSON.stringify({...payload, initData:telegram.initData})
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.ok !== true) {
      throw new Error(String(data.error || 'Не удалось выполнить запрос управления турниром.'));
    }
    return data;
  };

  const summaryCard = (label, value) => {
    const node = document.createElement('div');
    const span = document.createElement('span');
    const strong = document.createElement('strong');
    span.textContent = label;
    strong.textContent = value;
    node.append(span, strong);
    return node;
  };

  const render = value => {
    snapshot = value && typeof value === 'object' ? value : {};
    const tournament = snapshot.tournament && typeof snapshot.tournament === 'object'
      ? snapshot.tournament
      : null;

    summary.replaceChildren();
    if (!tournament) {
      // The successful reset is triggered by the reset button itself. Telegram
      // WebView can strand the focused element when its parent is hidden in the
      // same render pass, which makes the next text input appear frozen until
      // the Mini App is backgrounded. Hand focus back before hiding that panel.
      releaseFocusBeforeHide(resetPanel);
      restoreDraftControls();
      summary.append(
        summaryCard('Статус', 'нет активного турнира'),
        summaryCard('Взнос', '50 000'),
        summaryCard('Участники', '8 / 16 / 32 / 64 / 128')
      );
      current.textContent = 'Официальный турнир ещё не создан.';
      rules.textContent = 'Снимок правил появится после создания черновика.';
      rewards.textContent = 'Снимок наград появится после создания черновика.';
      create.disabled = busy;
      open.disabled = true;
      open.dataset.available = '0';
      if (schedulePanel instanceof HTMLElement) schedulePanel.hidden = true;
      if (assignDate instanceof HTMLButtonElement) {
        assignDate.disabled = true;
        assignDate.dataset.available = '0';
      }
      if (scheduleStart instanceof HTMLInputElement) {
        scheduleStart.value = '';
        scheduleStart.disabled = false;
        scheduleStart.dataset.locked = '0';
      }
      if (scheduleInfo instanceof HTMLElement) scheduleInfo.textContent = 'Дата ещё не назначена.';
      if (manualPanel instanceof HTMLElement) manualPanel.hidden = true;
      if (prepareManual instanceof HTMLButtonElement) {
        prepareManual.disabled = true;
        prepareManual.dataset.available = '0';
      }
      if (manualInfo instanceof HTMLElement) manualInfo.textContent = 'Ручная проверка staging недоступна.';
      if (progressionPanel instanceof HTMLElement) progressionPanel.hidden = true;
      if (completeFixtures instanceof HTMLButtonElement) {
        completeFixtures.disabled = true;
        completeFixtures.dataset.available = '0';
      }
      if (progressionInfo instanceof HTMLElement) progressionInfo.textContent = 'Fixture-only пары пока не требуют завершения.';
      if (resetPanel instanceof HTMLElement) resetPanel.hidden = true;
      if (resetManual instanceof HTMLButtonElement) {
        resetManual.disabled = true;
        resetManual.dataset.available = '0';
      }
      if (resetInfo instanceof HTMLElement) resetInfo.textContent = 'Сброс staging-турнира недоступен.';
      return;
    }

    const state = String(tournament.state || '—');
    const count = Number(tournament.registered_count || 0);
    const cap = Number(tournament.capacity || 0);
    const fee = Number(tournament?.entry_fee?.amount || 50000);

    summary.append(
      summaryCard('Статус', stateLabel(state)),
      summaryCard('Игра', gameLabel(tournament.game_type)),
      summaryCard('Участники', `${format(count)} / ${format(cap)}`),
      summaryCard('Взнос', format(fee))
    );

    current.textContent = `${tournament.title || 'Официальный турнир'} · ${gameLabel(tournament.game_type)} · ${format(count)}/${format(cap)}`;
    rules.textContent = rulesSummary(tournament.rules || {});
    rewards.textContent = rewardSummary(tournament.reward_snapshot || {});

    create.disabled = true;
    const canOpen = state === 'draft';
    open.dataset.available = canOpen ? '1' : '0';
    open.disabled = busy || !canOpen;

    const waitingForDate = state === 'waiting_for_date';
    const scheduled = state === 'scheduled' && Boolean(tournament.scheduled_start_at_utc);
    if (schedulePanel instanceof HTMLElement) schedulePanel.hidden = !(waitingForDate || scheduled);
    if (scheduleStart instanceof HTMLInputElement) {
      scheduleStart.dataset.locked = scheduled ? '1' : '0';
      scheduleStart.disabled = busy || scheduled;
      if (scheduled) {
        const startDate = parseUtc(tournament.scheduled_start_at_utc);
        if (startDate) {
          const offset = startDate.getTimezoneOffset() * 60000;
          scheduleStart.value = new Date(startDate.getTime() - offset).toISOString().slice(0,16);
        }
      }
    }
    if (assignDate instanceof HTMLButtonElement) {
      assignDate.dataset.available = waitingForDate ? '1' : '0';
      assignDate.disabled = busy || !waitingForDate;
    }
    if (scheduleInfo instanceof HTMLElement) {
      scheduleInfo.textContent = scheduled
        ? `Начало: ${formatDateTime(tournament.scheduled_start_at_utc)} по времени этого устройства. Дата зафиксирована.`
        : waitingForDate
          ? 'Состав набран. Назначьте финальную дату и время начала турнира.'
          : 'Дата ещё не назначена.';
    }

    const manual = manualAcceptance && typeof manualAcceptance === 'object'
      ? manualAcceptance
      : {};
    const modes = manual.modes && typeof manual.modes === 'object' ? manual.modes : {};
    const selectedLiveSeats = 2;
    const selectedMode = modes[String(selectedLiveSeats)] || {};
    const manualVisible = state === 'registration_open'
      && (Object.keys(modes).length > 0 || manual.available === true);
    if (manualPanel instanceof HTMLElement) manualPanel.hidden = !manualVisible;
    if (manualNote instanceof HTMLElement) {
      manualNote.textContent = 'Только staging: для ручной проверки MVP-21.5 добавляются 6 синтетических участников, а два места остаются двум живым аккаунтам. После заполнения 8/8 эти два живых аккаунта гарантированно попадут в одну пару первого раунда.';
    }
    if (prepareManual instanceof HTMLButtonElement) {
      const canPrepare = selectedMode.available === true;
      prepareManual.dataset.available = canPrepare ? '1' : '0';
      prepareManual.disabled = busy || !canPrepare;
      const target = Number(selectedMode.target_registered_count || Math.max(0, cap - selectedLiveSeats));
      prepareManual.textContent = selectedLiveSeats === 2
        ? `Подготовить ${format(target)}/${format(cap)} для двух живых аккаунтов`
        : `Подготовить ${format(target)}/${format(cap)} для одного живого аккаунта`;
    }
    if (manualInfo instanceof HTMLElement) {
      const target = Number(selectedMode.target_registered_count || Math.max(0, cap - selectedLiveSeats));
      if (selectedMode.ready === true) {
        manualInfo.textContent = selectedLiveSeats === 2
          ? `Готово: ${format(count)}/${format(cap)}. Оставлены два живых места — зарегистрируйтесь двумя обычными аккаунтами.`
          : `Готово: ${format(count)}/${format(cap)}. Осталось одно живое место — зарегистрируйтесь обычным аккаунтом.`;
      } else if (selectedMode.available === true) {
        const fixtureCount = Number(selectedMode.remaining_fixture_slots || 0);
        manualInfo.textContent = `Staging: будет добавлено ${format(fixtureCount)} тестовых участников; живых мест останется — ${format(selectedLiveSeats)}.`;
      } else if (count > target && state === 'registration_open') {
        manualInfo.textContent = 'Для выбранного режима уже занято слишком много мест. Используйте другой режим или сбросьте staging-турнир.';
      } else {
        manualInfo.textContent = 'Ручная проверка staging недоступна.';
      }
    }

    const progression = manualProgression && typeof manualProgression === 'object'
      ? manualProgression
      : {};
    const progressionReason = String(progression.reason || '');
    const progressionVisible = progressionReason !== '' && progressionReason !== 'staging_only';
    const canCompleteFixtures = progression.available === true;
    if (progressionPanel instanceof HTMLElement) progressionPanel.hidden = !progressionVisible;
    if (completeFixtures instanceof HTMLButtonElement) {
      completeFixtures.dataset.available = canCompleteFixtures ? '1' : '0';
      completeFixtures.disabled = busy || !canCompleteFixtures;
    }
    if (progressionInfo instanceof HTMLElement) {
      const fixturePairs = Number(progression.fixture_pair_count || 0);
      const roundNo = Number(progression.round_no || 0);
      if (canCompleteFixtures) {
        progressionInfo.textContent = `Раунд ${format(roundNo)}: fixture-only пар для staging-проверки — ${format(fixturePairs)}.`;
      } else if (progressionReason === 'no_fixture_only_pairs') {
        progressionInfo.textContent = 'В текущем раунде нет fixture-only пар. Реальную пару нужно доиграть в клиентах.';
      } else if (progressionReason === 'no_unresolved_pairs') {
        progressionInfo.textContent = 'Текущий раунд уже завершён. Обновите турнир, чтобы увидеть следующий этап.';
      } else {
        progressionInfo.textContent = 'Fixture-only пары пока не требуют завершения.';
      }
    }

    const reset = manualReset && typeof manualReset === 'object' ? manualReset : {};
    const canReset = reset.available === true;
    if (resetPanel instanceof HTMLElement) resetPanel.hidden = !canReset;
    if (resetManual instanceof HTMLButtonElement) {
      resetManual.dataset.available = canReset ? '1' : '0';
      resetManual.disabled = busy || !canReset;
    }
    if (resetInfo instanceof HTMLElement) {
      resetInfo.textContent = canReset
        ? `Staging cleanup: освободить все активные резервы и снять текущий турнир «${tournament.title || 'Официальный турнир'}» с active slot.`
        : 'Сброс staging-турнира недоступен.';
    }
  };

  const withBusy = async (message, action) => {
    if (busy) return null;
    setBusy(true);
    setStatus(message);
    try {
      const data = await action();
      manualAcceptance = data?.manual_acceptance && typeof data.manual_acceptance === 'object'
        ? data.manual_acceptance
        : manualAcceptance;
      manualReset = data?.manual_reset && typeof data.manual_reset === 'object'
        ? data.manual_reset
        : manualReset;
      manualProgression = data?.manual_progression && typeof data.manual_progression === 'object'
        ? data.manual_progression
        : manualProgression;
      render(data.snapshot || {});
      return data;
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Не удалось выполнить операцию с турниром.', 'error');
      throw error;
    } finally {
      busy = false;
      setBusy(false);
      render(snapshot || {});
    }
  };

  const load = async () => {
    try {
      await withBusy(
        'Загружаю управление турниром…',
        () => post({action:'snapshot'})
      );
      setStatus('Управление турниром загружено.', 'ok');
    } catch (_) {}
  };

  const createDraft = async () => {
    const selectedGame = String(game.value || '').trim();
    const selectedCapacity = Number(capacity.value || 0);
    const selectedTitle = String(title.value || '').trim() || 'Официальный турнир';
    if (!selectedGame || ![8,16,32,64,128].includes(selectedCapacity)) {
      setStatus('Выберите игру и допустимый размер турнира.', 'error');
      return;
    }
    if (!window.confirm(`Создать черновик официального турнира на ${selectedCapacity} участников? Взнос 50 000, правила и снимок наград будут зафиксированы.`)) return;

    try {
      await withBusy('Создаю черновик турнира…', () => post({
        action:'create_draft',
        game_type:selectedGame,
        capacity:selectedCapacity,
        title:selectedTitle,
      }));
      setStatus('Черновик турнира создан. Проверьте правила и снимок наград, затем откройте регистрацию.', 'ok');
    } catch (_) {}
  };

  const openRegistration = async () => {
    const tournamentId = String(snapshot?.tournament?.tournament_id || '');
    if (!tournamentId) return;
    if (!window.confirm('Открыть регистрацию? Игроки смогут резервировать 50 000 коинов и занимать места.')) return;

    try {
      await withBusy('Открываю регистрацию…', () => post({
        action:'open_registration',
        tournament_id:tournamentId,
      }));
      setStatus('Регистрация официального турнира открыта.', 'ok');
    } catch (_) {}
  };

  const prepareManualAcceptance = async () => {
    const tournament = snapshot?.tournament;
    const count = Number(tournament?.registered_count || 0);
    const cap = Number(tournament?.capacity || 0);
    const liveSeats = 2;
    const mode = manualAcceptance?.modes?.[String(liveSeats)] || {};
    const target = Number(mode.target_registered_count || Math.max(0, cap - liveSeats));
    if (!tournament || cap < 2 || target <= count) return;
    if (!window.confirm(`Только staging: добавить тестовых участников до ${target}/${cap} и оставить живых мест — ${liveSeats}?`)) return;

    try {
      const data = await withBusy('Готовлю турнир для ручной проверки…', () => post({
        action:'prepare_manual_acceptance',
        live_seats:liveSeats,
      }));
      const fixture = data?.manual_acceptance_fixture || {};
      setStatus(
        `Готово: ${format(fixture.registered_count || target)}/${format(fixture.capacity || cap)}. Живых мест осталось — ${format(fixture.manual_seats_left || liveSeats)}.`,
        'ok'
      );
    } catch (_) {}
  };

  const completeFixturePairs = async () => {
    if (manualProgression?.available !== true) return;
    const fixturePairs = Number(manualProgression.fixture_pair_count || 0);
    const roundNo = Number(manualProgression.round_no || 0);
    if (!window.confirm(`Только staging: канонически завершить fixture-only пары раунда ${roundNo} (пар: ${fixturePairs})? Реальные пары не затрагиваются.`)) return;
    try {
      const data = await withBusy('Завершаю fixture-only пары через турнирный progression owner…', () => post({
        action:'complete_fixture_pairs',
      }));
      const completed = data?.manual_progression_result || {};
      setStatus(
        `Fixture-only пары завершены: ${format(completed.completed_pairs || 0)}. Обновите два живых клиента и проверьте переход турнира.`,
        'ok'
      );
    } catch (_) {}
  };

  const resetManualAcceptance = async () => {
    const tournament = snapshot?.tournament;
    const tournamentId = String(tournament?.tournament_id || '');
    if (!tournamentId || manualReset?.available !== true) return;

    const count = Number(tournament?.registered_count || 0);
    const cap = Number(tournament?.capacity || 0);
    const warning = `Сбросить ТОЛЬКО staging-турнир «${tournament.title || 'Официальный турнир'}» (${format(count)}/${format(cap)})? Нажмите «Подтвердить сброс» ещё раз в течение 8 секунд.`;

    if (Date.now() > resetConfirmUntil) {
      armResetConfirmation(warning);
      return;
    }
    disarmResetConfirmation();

    // The second confirmation click leaves the reset button focused. Telegram
    // WebView can freeze the next text input if that focused button is disabled
    // by withBusy() before focus is released. Blur it synchronously, before any
    // busy-state mutation or panel rerender.
    releaseFocusBeforeHide(resetPanel);
    restoreDraftControls();

    try {
      const data = await withBusy('Безопасно сбрасываю staging-турнир и освобождаю резервы…', () => post({
        action:'reset_manual_acceptance',
      }));
      const reset = data?.manual_reset_result || {};
      restoreDraftControls();
      setStatus(
        `Staging-турнир сброшен: освобождено резервов — ${format(reset.released_reservations || 0)}, fixture accounts retired — ${format(reset.fixture_accounts_retired || 0)}. Можно сразу создать новый турнир.`,
        'ok'
      );
    } catch (_) {
      restoreDraftControls();
    }
  };

  const assignFinalDate = async () => {
    const tournamentId = String(snapshot?.tournament?.tournament_id || '');
    if (!tournamentId || !(scheduleStart instanceof HTMLInputElement)) return;
    const localValue = String(scheduleStart.value || '').trim();
    if (!localValue) {
      setStatus('Укажите финальную дату и время начала турнира.', 'error');
      return;
    }
    const start = new Date(localValue);
    if (Number.isNaN(start.getTime()) || start.getTime() <= Date.now()) {
      setStatus('Дата начала турнира должна быть в будущем.', 'error');
      return;
    }
    const label = formatDateTime(start);
    if (!window.confirm(`Назначить старт турнира на ${label}? После сохранения перенести дату в MVP-21.3 нельзя.`)) return;

    try {
      const data = await withBusy('Назначаю финальную дату и создаю напоминания…', () => post({
        action:'assign_date',
        tournament_id:tournamentId,
        start_at_utc:start.toISOString(),
      }));
      if (data?.notifications?.ok === false) {
        setStatus(String(data.notifications.warning || 'Дата сохранена, но напоминания требуют повторной синхронизации.'), 'error');
      } else {
        setStatus('Дата турнира назначена. Участникам подготовлены напоминания за день, час и 15 минут.', 'ok');
      }
    } catch (_) {}
  };

  refresh?.addEventListener('click', load);
  create?.addEventListener('click', createDraft);
  open?.addEventListener('click', openRegistration);
  prepareManual?.addEventListener('click', prepareManualAcceptance);
  completeFixtures?.addEventListener('click', completeFixturePairs);
  resetManual?.addEventListener('click', resetManualAcceptance);
  assignDate?.addEventListener('click', assignFinalDate);

  if (telegram?.initData) {
    window.setTimeout(load, 180);
  }
})();
