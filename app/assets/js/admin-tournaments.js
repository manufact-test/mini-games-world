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
  const scheduleDate = card.querySelector('[data-tournament-start-date]');
  const scheduleTime = card.querySelector('[data-tournament-start-time]');
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
  const cancelPanel = card.querySelector('[data-tournament-cancel-panel]');
  const cancelInfo = card.querySelector('[data-tournament-cancel-info]');
  const cancelReason = card.querySelector('[data-tournament-cancel-reason]');
  const cancelTournament = card.querySelector('[data-tournament-cancel]');
  const emergencyStop = card.querySelector('[data-tournament-emergency]');
  const reviewPanel = card.querySelector('[data-tournament-review-panel]');
  const reviewInfo = card.querySelector('[data-tournament-review-info]');
  const reviewMgwId = card.querySelector('[data-tournament-review-mgw-id]');
  const reviewSignal = card.querySelector('[data-tournament-review-signal]');
  const reviewGameId = card.querySelector('[data-tournament-review-game-id]');
  const reviewNote = card.querySelector('[data-tournament-review-note]');
  const reviewFlag = card.querySelector('[data-tournament-review-flag]');
  const reviewList = card.querySelector('[data-tournament-review-list]');
  let busy = false;
  let snapshot = null;
  let manualAcceptance = null;
  let manualReset = null;
  let manualProgression = null;
  let cancellation = null;
  let prizeReview = null;
  let resetConfirmUntil = 0;
  let resetConfirmTimer = null;
  let cancelConfirmUntil = 0;
  let cancelConfirmKind = '';
  let cancelConfirmTimer = null;
  let passiveRefreshTimer = null;
  let passiveRefreshInFlight = false;
  const PASSIVE_REFRESH_MS = 8000;

  const format = value => new Intl.NumberFormat('ru-RU').format(Number(value || 0));
  const confirmAction = message => new Promise(resolve => {
    if (telegram && typeof telegram.showConfirm === 'function') {
      try {
        telegram.showConfirm(message, confirmed => resolve(confirmed === true));
        return;
      } catch (_) {}
    }
    resolve(window.confirm(message));
  });
  const stateLabel = value => ({
    draft:'черновик',
    registration_open:'регистрация открыта',
    waiting_for_date:'состав набран · ожидает дату',
    scheduled:'дата назначена',
    cancelled:'отменён',
    emergency_stopped:'аварийно остановлен',
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
    card.querySelectorAll('button, input, select, textarea').forEach(control => {
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
      if (control === scheduleDate || control === scheduleTime) {
        control.disabled = control.dataset.locked === '1';
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
      if (control === cancelTournament) {
        control.disabled = cancelTournament.dataset.available !== '1';
        return;
      }
      if (control === emergencyStop) {
        control.disabled = emergencyStop.dataset.available !== '1';
        return;
      }
      if (control === reviewFlag) {
        control.disabled = reviewFlag.dataset.available !== '1';
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

  const disarmCancellationConfirmation = () => {
    cancelConfirmUntil = 0;
    cancelConfirmKind = '';
    if (cancelConfirmTimer) window.clearTimeout(cancelConfirmTimer);
    cancelConfirmTimer = null;
    if (cancelTournament instanceof HTMLButtonElement) cancelTournament.textContent = 'Отменить турнир';
    if (emergencyStop instanceof HTMLButtonElement) emergencyStop.textContent = 'Аварийная остановка';
  };

  const armCancellationConfirmation = (kind, warning) => {
    cancelConfirmUntil = Date.now() + 8000;
    cancelConfirmKind = kind;
    if (kind === 'emergency' && emergencyStop instanceof HTMLButtonElement) {
      emergencyStop.textContent = 'Подтвердить аварийную остановку';
    } else if (kind === 'cancel' && cancelTournament instanceof HTMLButtonElement) {
      cancelTournament.textContent = 'Подтвердить отмену';
    }
    setStatus(warning, 'error');
    if (cancelConfirmTimer) window.clearTimeout(cancelConfirmTimer);
    cancelConfirmTimer = window.setTimeout(() => {
      disarmCancellationConfirmation();
      if (!busy) setStatus('Отмена турнира не подтверждена.');
    }, 8000);
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

  const reviewStateLabel = value => ({
    pending:'Ожидает решения',
    released:'Проверка пройдена',
    disqualified:'Дисквалифицирован',
  })[String(value || '')] || String(value || '—');

  const renderPrizeReview = tournament => {
    if (!(reviewPanel instanceof HTMLElement)) return;
    const review = prizeReview && typeof prizeReview === 'object' ? prizeReview : {};
    const scheduled = String(tournament?.state || '') === 'scheduled';
    const available = review.available === true && scheduled;
    reviewPanel.hidden = !available;

    if (reviewFlag instanceof HTMLButtonElement) {
      reviewFlag.dataset.available = available ? '1' : '0';
      reviewFlag.disabled = busy || !available;
    }
    if (!available) return;

    const settlement = review.settlement && typeof review.settlement === 'object'
      ? review.settlement
      : {};
    const held = Array.isArray(settlement.held_mgw_ids) ? settlement.held_mgw_ids : [];
    const top3 = Array.isArray(review.top3) ? review.top3 : [];
    if (reviewInfo instanceof HTMLElement) {
      const topCopy = top3.length
        ? 'Top-3: ' + top3.map(item => `#${item.canonical_placement} ${item.nickname || item.public_mgw_id || item.mgw_id}`).join(' · ')
        : 'Top-3 появится после завершения финала и матча за 3-е место.';
      reviewInfo.textContent = settlement.hold === true
        ? `ПРИЗОВАЯ ВЕТКА УДЕРЖИВАЕТСЯ: ${held.length} участн. ждут Admin review. ${topCopy}`
        : `Серьёзных сигналов, удерживающих призовую ветку, нет. ${topCopy}`;
    }

    if (!(reviewList instanceof HTMLElement)) return;
    reviewList.replaceChildren();
    const reviews = Array.isArray(review.reviews) ? review.reviews : [];
    if (!reviews.length) {
      const empty = document.createElement('div');
      empty.className = 'mgw-admin__history-empty';
      empty.textContent = 'Активных или завершённых prize-review cases пока нет.';
      reviewList.append(empty);
      return;
    }

    reviews.forEach(item => {
      const row = document.createElement('div');
      row.className = 'mgw-admin__history-item';
      row.dataset.tournamentPrizeReview = String(item.mgw_id || '');

      const copy = document.createElement('div');
      copy.className = 'mgw-admin__history-copy';
      const title = document.createElement('strong');
      title.textContent = `${item.nickname || 'Игрок'} · ${item.public_mgw_id || item.mgw_id || '—'} · ${reviewStateLabel(item.review_state)}`;
      const signal = document.createElement('span');
      signal.textContent = `Сигнал: ${item.signal_code || '—'}${item.related_game_id ? ` · Game ${item.related_game_id}` : ''}`;
      const note = document.createElement('span');
      note.textContent = item.signal_note || 'Без описания.';
      const resolution = document.createElement('span');
      resolution.textContent = item.resolution_note
        ? `Решение: ${item.resolution_note}`
        : `Создан: ${formatDateTime(item.signaled_at_utc)}`;
      copy.append(title, signal, note, resolution);

      const actions = document.createElement('div');
      actions.className = 'mgw-admin__tournament-actions';
      if (String(item.review_state || '') === 'pending') {
        const release = document.createElement('button');
        release.type = 'button';
        release.textContent = 'Разрешить выплату';
        release.addEventListener('click', () => { void resolvePrizeReview(item, 'release'); });

        const disqualify = document.createElement('button');
        disqualify.type = 'button';
        disqualify.textContent = 'Дисквалифицировать';
        disqualify.dataset.danger = '1';
        disqualify.addEventListener('click', () => { void resolvePrizeReview(item, 'disqualify'); });
        actions.append(release, disqualify);
      }

      row.append(copy, actions);
      reviewList.append(row);
    });
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
      for (const control of [scheduleDate, scheduleTime]) {
        if (!(control instanceof HTMLInputElement)) continue;
        control.value = '';
        control.disabled = false;
        control.dataset.locked = '0';
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
      releaseFocusBeforeHide(cancelPanel);
      disarmCancellationConfirmation();
      if (cancelPanel instanceof HTMLElement) cancelPanel.hidden = true;
      if (cancelTournament instanceof HTMLButtonElement) {
        cancelTournament.disabled = true;
        cancelTournament.dataset.available = '0';
      }
      if (emergencyStop instanceof HTMLButtonElement) {
        emergencyStop.disabled = true;
        emergencyStop.dataset.available = '0';
      }
      if (reviewPanel instanceof HTMLElement) reviewPanel.hidden = true;
      if (reviewFlag instanceof HTMLButtonElement) {
        reviewFlag.disabled = true;
        reviewFlag.dataset.available = '0';
      }
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
    for (const control of [scheduleDate, scheduleTime]) {
      if (!(control instanceof HTMLInputElement)) continue;
      control.dataset.locked = scheduled ? '1' : '0';
      control.disabled = busy || scheduled;
    }
    if (scheduled) {
      const startDate = parseUtc(tournament.scheduled_start_at_utc);
      if (startDate) {
        const yyyy = String(startDate.getFullYear()).padStart(4, '0');
        const mm = String(startDate.getMonth() + 1).padStart(2, '0');
        const dd = String(startDate.getDate()).padStart(2, '0');
        const hh = String(startDate.getHours()).padStart(2, '0');
        const mi = String(startDate.getMinutes()).padStart(2, '0');
        if (scheduleDate instanceof HTMLInputElement) scheduleDate.value = `${yyyy}-${mm}-${dd}`;
        if (scheduleTime instanceof HTMLInputElement) scheduleTime.value = `${hh}:${mi}`;
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

    const cancellationState = cancellation && typeof cancellation === 'object' ? cancellation : {};
    const canCancel = cancellationState.normal_cancel_available === true;
    const canEmergency = cancellationState.emergency_stop_available === true;
    if (cancelPanel instanceof HTMLElement) cancelPanel.hidden = !(canCancel || canEmergency);
    if (cancelTournament instanceof HTMLButtonElement) {
      cancelTournament.dataset.available = canCancel ? '1' : '0';
      cancelTournament.disabled = busy || !canCancel;
    }
    if (emergencyStop instanceof HTMLButtonElement) {
      emergencyStop.dataset.available = canEmergency ? '1' : '0';
      emergencyStop.disabled = busy || !canEmergency;
    }
    if (cancelInfo instanceof HTMLElement) {
      cancelInfo.textContent = cancellationState.technical_cancel_required === true
        ? 'Технический перезапуск исчерпан. Турнир требует аварийной остановки: укажите причину и подтвердите действие дважды.'
        : 'Отмена возвращает каждому зарегистрированному участнику полный взнос 50 000 и аннулирует турнирные результаты.';
    }

    renderPrizeReview(tournament);
  };

  const applyResponseState = data => {
    manualAcceptance = data?.manual_acceptance && typeof data.manual_acceptance === 'object'
      ? data.manual_acceptance
      : manualAcceptance;
    manualReset = data?.manual_reset && typeof data.manual_reset === 'object'
      ? data.manual_reset
      : manualReset;
    manualProgression = data?.manual_progression && typeof data.manual_progression === 'object'
      ? data.manual_progression
      : manualProgression;
    cancellation = data?.cancellation && typeof data.cancellation === 'object'
      ? data.cancellation
      : cancellation;
    prizeReview = data?.prize_review && typeof data.prize_review === 'object'
      ? data.prize_review
      : prizeReview;
    render(data?.snapshot || {});
  };

  const withBusy = async (message, action) => {
    if (busy) return null;
    setBusy(true);
    setStatus(message);
    try {
      const data = await action();
      applyResponseState(data);
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

  const passiveRefresh = async () => {
    if (busy || passiveRefreshInFlight || document.hidden || !telegram?.initData) return;
    passiveRefreshInFlight = true;
    try {
      const data = await post({action:'snapshot', passive_refresh:true});
      applyResponseState(data);
    } catch (error) {
      const message = error instanceof Error ? error.message : '';
      if (/сессия панели устарела/i.test(message)) {
        setStatus(message, 'error');
      }
    } finally {
      passiveRefreshInFlight = false;
    }
  };

  const schedulePassiveRefresh = () => {
    if (passiveRefreshTimer) window.clearTimeout(passiveRefreshTimer);
    passiveRefreshTimer = window.setTimeout(async () => {
      await passiveRefresh();
      schedulePassiveRefresh();
    }, PASSIVE_REFRESH_MS);
  };

  const createDraft = async () => {
    const selectedGame = String(game.value || '').trim();
    const selectedCapacity = Number(capacity.value || 0);
    const selectedTitle = String(title.value || '').trim() || 'Официальный турнир';
    if (!selectedGame || ![8,16,32,64,128].includes(selectedCapacity)) {
      setStatus('Выберите игру и допустимый размер турнира.', 'error');
      return;
    }
    if (!(await confirmAction(`Создать черновик официального турнира на ${selectedCapacity} участников? Взнос 50 000, правила и снимок наград будут зафиксированы.`))) return;

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
    if (!(await confirmAction('Открыть регистрацию? Игроки смогут резервировать 50 000 коинов и занимать места.'))) return;

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
    if (!(await confirmAction(`Только staging: добавить тестовых участников до ${target}/${cap} и оставить живых мест — ${liveSeats}?`))) return;

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
    if (!(await confirmAction(`Только staging: канонически завершить fixture-only пары раунда ${roundNo} (пар: ${fixturePairs})? Реальные пары не затрагиваются.`))) return;
    try {
      const data = await withBusy('Завершаю fixture-only пары через турнирный progression owner…', () => post({
        action:'complete_fixture_pairs',
      }));
      const completed = data?.manual_progression_result || {};
      const nextRound = Number(completed.next_round_no || 0);
      const completedRound = Number(completed.round_no || 0);
      const message = nextRound > completedRound
        ? `Fixture-only пары завершены: ${format(completed.completed_pairs || 0)}. Раунд ${format(completedRound)} закрыт, создан раунд ${format(nextRound)}.`
        : `Fixture-only пары завершены: ${format(completed.completed_pairs || 0)}. Турнирное состояние обновлено.`;
      setStatus(message, 'ok');
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

  const executeTournamentCancellation = async kind => {
    const tournament = snapshot?.tournament;
    const tournamentId = String(tournament?.tournament_id || '');
    if (!tournamentId || !['cancel','emergency'].includes(kind)) return;

    const reason = String(cancelReason?.value || '').trim();
    if (kind === 'emergency' && !reason) {
      setStatus('Для аварийной остановки обязательно укажите причину.', 'error');
      cancelReason?.focus();
      return;
    }

    const titleText = String(tournament?.title || 'Официальный турнир');
    const warning = kind === 'emergency'
      ? `Аварийно остановить «${titleText}»? Всем участникам будет возвращён полный взнос, результаты будут аннулированы. Нажмите подтверждение ещё раз в течение 8 секунд.`
      : `Отменить «${titleText}»? Всем участникам будет возвращён полный взнос, результаты будут аннулированы. Нажмите подтверждение ещё раз в течение 8 секунд.`;

    if (cancelConfirmKind !== kind || Date.now() > cancelConfirmUntil) {
      disarmCancellationConfirmation();
      armCancellationConfirmation(kind, warning);
      return;
    }

    disarmCancellationConfirmation();
    releaseFocusBeforeHide(cancelPanel);
    restoreDraftControls();

    try {
      const data = await withBusy(
        kind === 'emergency' ? 'Аварийно останавливаю турнир…' : 'Отменяю турнир…',
        () => post({
          action:kind === 'emergency' ? 'emergency_stop' : 'cancel_tournament',
          tournament_id:tournamentId,
          reason,
          confirmation:{
            confirmed:true,
            mode:'double_confirm',
            tournament_id:tournamentId,
            kind,
          },
        })
      );
      const result = data?.cancellation_result || {};
      setStatus(
        `${kind === 'emergency' ? 'Турнир аварийно остановлен' : 'Турнир отменён'}: полный возврат получили ${format(result.refunded_count || 0)} участн.; возвращено ${format(result.refund_amount || 0)} коинов. Результаты аннулированы.`,
        'ok'
      );
    } catch (_) {}
  };

  const flagPrizeReview = async () => {
    const tournamentId = String(snapshot?.tournament?.tournament_id || '');
    const mgwId = String(reviewMgwId?.value || '').trim();
    const signalCode = String(reviewSignal?.value || '').trim();
    const relatedGameId = String(reviewGameId?.value || '').trim();
    const note = String(reviewNote?.value || '').trim();
    if (!tournamentId || !mgwId || !signalCode || !note) {
      setStatus('Для серьёзного сигнала укажите MGW-ID, тип сигнала и основание.', 'error');
      return;
    }
    if (!window.confirm('Зафиксировать серьёзный сигнал? Если игрок окажется в затронутой призовой ветке, выплата будет удержана до Admin review.')) return;

    try {
      await withBusy('Фиксирую серьёзный сигнал призового пути…', () => post({
        action:'prize_review_flag',
        tournament_id:tournamentId,
        mgw_id:mgwId,
        signal_code:signalCode,
        related_game_id:relatedGameId,
        note,
      }));
      if (reviewNote instanceof HTMLInputElement) reviewNote.value = '';
      setStatus('Серьёзный сигнал зафиксирован. Выплата удерживается только если этот сигнал затрагивает призовую ветку.', 'ok');
    } catch (_) {}
  };

  const resolvePrizeReview = async (item, decision) => {
    const tournamentId = String(snapshot?.tournament?.tournament_id || '');
    const mgwId = String(item?.mgw_id || '');
    if (!tournamentId || !mgwId || !['release','disqualify'].includes(decision)) return;
    const note = window.prompt(
      decision === 'disqualify'
        ? 'Причина дисквалификации (обязательно):'
        : 'Комментарий проверки перед разрешением выплаты (обязательно):',
      ''
    );
    if (note === null || !String(note).trim()) {
      setStatus('Решение prize review требует комментария.', 'error');
      return;
    }
    if (decision === 'disqualify'
        && !(await confirmAction('Дисквалифицировать игрока? Призовые места ниже будут сдвинуты каноническим settlement owner.'))) return;

    try {
      const data = await withBusy(
        decision === 'disqualify' ? 'Фиксирую дисквалификацию и пересчитываю призовую ветку…' : 'Разрешаю призовую выплату…',
        () => post({
          action:decision === 'disqualify' ? 'prize_review_disqualify' : 'prize_review_release',
          tournament_id:tournamentId,
          mgw_id:mgwId,
          note:String(note).trim(),
        })
      );
      const settlement = data?.prize_review_settlement || {};
      if (settlement.status === 'review_hold') {
        setStatus('Решение сохранено. В призовой ветке остаётся другой серьёзный сигнал — часть выплат всё ещё удерживается.', 'ok');
      } else if (settlement.status === 'settled') {
        setStatus('Review завершён. Канонический settlement выполнен без повторных выплат.', 'ok');
      } else {
        setStatus('Review завершён. Турнир ещё не дошёл до terminal settlement.', 'ok');
      }
    } catch (_) {}
  };

  const assignFinalDate = async () => {
    const tournamentId = String(snapshot?.tournament?.tournament_id || '');
    if (!tournamentId
        || !(scheduleDate instanceof HTMLInputElement)
        || !(scheduleTime instanceof HTMLInputElement)) return;
    const localDate = String(scheduleDate.value || '').trim();
    const localTime = String(scheduleTime.value || '').trim();
    if (!localDate || !localTime) {
      setStatus('Укажите дату и время начала турнира.', 'error');
      return;
    }
    const start = new Date(`${localDate}T${localTime}:00`);
    if (Number.isNaN(start.getTime()) || start.getTime() <= Date.now()) {
      setStatus('Дата начала турнира должна быть в будущем.', 'error');
      return;
    }
    const label = formatDateTime(start);
    if (!(await confirmAction(`Назначить старт турнира на ${label}? После сохранения перенести дату в MVP-21.3 нельзя.`))) return;

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
    } catch (error) {
      if (scheduleInfo instanceof HTMLElement) {
        scheduleInfo.textContent = error instanceof Error
          ? error.message
          : 'Не удалось назначить дату турнира.';
      }
    }
  };

  refresh?.addEventListener('click', load);
  create?.addEventListener('click', createDraft);
  open?.addEventListener('click', openRegistration);
  prepareManual?.addEventListener('click', prepareManualAcceptance);
  completeFixtures?.addEventListener('click', completeFixturePairs);
  resetManual?.addEventListener('click', resetManualAcceptance);
  cancelTournament?.addEventListener('click', () => { void executeTournamentCancellation('cancel'); });
  emergencyStop?.addEventListener('click', () => { void executeTournamentCancellation('emergency'); });
  reviewFlag?.addEventListener('click', () => { void flagPrizeReview(); });
  assignDate?.addEventListener('click', assignFinalDate);

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      if (passiveRefreshTimer) window.clearTimeout(passiveRefreshTimer);
      passiveRefreshTimer = null;
      return;
    }
    void passiveRefresh();
    schedulePassiveRefresh();
  });

  window.addEventListener('focus', () => {
    void passiveRefresh();
  });

  if (telegram?.initData) {
    window.setTimeout(load, 180);
    window.setTimeout(schedulePassiveRefresh, 1200);
  }
})();
