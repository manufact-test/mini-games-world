(() => {
  'use strict';

  const root = document.querySelector('[data-compensation-card]');
  const shell = document.querySelector('[data-compensation-api]');
  if (!root || !shell) return;

  const endpoint = String(shell.dataset.compensationApi || '');
  const telegram = window.Telegram?.WebApp || null;

  const picker = root.querySelector('[data-compensation-operation-picker]');
  const pickerTrigger = root.querySelector('[data-compensation-operation-trigger]');
  const pickerSelected = root.querySelector('[data-compensation-operation-selected]');
  const pickerList = root.querySelector('[data-compensation-operation-list]');
  const selectedBox = root.querySelector('[data-compensation-selected]');
  const selectedPlayer = root.querySelector('[data-compensation-selected-player]');
  const selectedAmount = root.querySelector('[data-compensation-selected-amount]');
  const selectedTime = root.querySelector('[data-compensation-selected-time]');
  const selectedBalance = root.querySelector('[data-compensation-selected-balance]');
  const selectedTech = root.querySelector('[data-compensation-selected-tech]');

  const browserQuery = root.querySelector('[data-compensation-browser-query]');
  const browserButton = root.querySelector('[data-compensation-browser-search]');
  const operationInput = root.querySelector('[data-compensation-operation]');
  const lookupButton = root.querySelector('[data-compensation-lookup]');
  const lookupStatus = root.querySelector('[data-compensation-lookup-status]');

  const amountInput = root.querySelector('[data-compensation-amount]');
  const reasonInput = root.querySelector('[data-compensation-reason]');
  const requestButton = root.querySelector('[data-compensation-request]');
  const limitCopy = root.querySelector('[data-compensation-limit-copy]');
  const statusBox = root.querySelector('[data-compensation-status]');

  const confirmation = root.querySelector('[data-compensation-confirmation]');
  const confirmationCopy = root.querySelector('[data-compensation-confirmation-copy]');
  const confirmButton = root.querySelector('[data-compensation-confirm]');

  const history = root.querySelector('[data-compensation-history]');
  const historyCount = root.querySelector('[data-compensation-history-count]');
  const historyPagination = root.querySelector('[data-compensation-pagination]');

  let busy = false;
  let currentOperation = null;
  let operationRows = [];
  let pendingCompensation = null;
  let activeRequestToken = '';
  let historyRows = [];
  let historyPage = 1;
  const historyPerPage = 8;
  let limits = {
    large_amount_threshold:50000,
    max_amount:250000,
    asset_code:'mgw_coin',
  };

  const coins = value => Number(value || 0).toLocaleString('ru-RU');

  const localTime = value => {
    if (!value) return '—';
    const raw = String(value);
    const date = new Date(raw.includes('T') ? raw : raw.replace(' ', 'T') + 'Z');
    if (Number.isNaN(date.getTime())) return raw;
    return date.toLocaleString('ru-RU', {
      day:'2-digit',
      month:'2-digit',
      year:'2-digit',
      hour:'2-digit',
      minute:'2-digit',
    });
  };

  const playerName = item =>
    String(item?.nickname || item?.mgw_id || item?.account_ref || 'Игрок');

  const categoryLabel = value => {
    const labels = {
      store_purchase:'Покупка',
      tournament_entry:'Взнос в турнир',
      tournament_registration:'Взнос в турнир',
      game_stake:'Ставка в игре',
      match_stake:'Ставка в матче',
      purchase:'Покупка',
    };
    return labels[String(value || '')] || 'Списание';
  };

  const post = async payload => {
    const response = await fetch(endpoint, {
      method:'POST',
      cache:'no-store',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({
        ...payload,
        initData:telegram?.initData || '',
      }),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.ok !== true) {
      throw new Error(String(data.error || 'Не удалось выполнить действие.'));
    }
    return data;
  };

  const setMessage = (box, message = '', state = '') => {
    if (!message) {
      box.hidden = true;
      box.textContent = '';
      delete box.dataset.state;
      return;
    }
    box.hidden = false;
    box.textContent = message;
    if (state) box.dataset.state = state;
    else delete box.dataset.state;
  };

  const canCompensate = operation =>
    !!operation && Number(operation.available_delta || 0) < 0;

  const setBusy = value => {
    busy = value;
    pickerTrigger.disabled = value || operationRows.length === 0;
    pickerList.querySelectorAll('button').forEach(button => { button.disabled = value; });
    historyPagination?.querySelectorAll('button').forEach(button => { button.disabled = value; });
    browserButton.disabled = value;
    lookupButton.disabled = value;
    operationInput.disabled = value;
    browserQuery.disabled = value;
    amountInput.disabled = value;
    reasonInput.disabled = value;
    requestButton.disabled =
      value ||
      !canCompensate(currentOperation) ||
      !!pendingCompensation;
    confirmButton.disabled = value || !pendingCompensation;
  };

  const resetRequestToken = () => {
    if (!pendingCompensation) activeRequestToken = '';
  };

  const requestToken = () => {
    if (activeRequestToken) return activeRequestToken;
    const random =
      globalThis.crypto?.randomUUID?.() ||
      `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    activeRequestToken = `admin-compensation:${random}`;
    return activeRequestToken;
  };

  const applyLimits = next => {
    if (next && typeof next === 'object') {
      limits = {...limits,...next};
    }
    amountInput.max = String(limits.max_amount || 250000);
    limitCopy.textContent =
      `До ${coins(limits.max_amount)} коинов за одну компенсацию. ` +
      `От ${coins(limits.large_amount_threshold)} потребуется второе подтверждение.`;
  };

  const optionLabel = operation => {
    const delta = Math.abs(Number(operation.available_delta || 0));
    return `−${coins(delta)} · ${playerName(operation)} · ${localTime(operation.created_at_utc)}`;
  };

  const closePicker = () => {
    pickerList.hidden = true;
    pickerTrigger.setAttribute('aria-expanded','false');
  };

  const renderPicker = rows => {
    operationRows = Array.isArray(rows) ? rows.filter(canCompensate) : [];
    pickerList.replaceChildren();

    pickerSelected.textContent = operationRows.length
      ? (currentOperation && canCompensate(currentOperation) ? optionLabel(currentOperation) : 'Выберите списание…')
      : 'Подходящих списаний не найдено';
    pickerTrigger.disabled = busy || operationRows.length === 0;

    if (!operationRows.length) {
      const empty = document.createElement('div');
      empty.className = 'mgw-admin__operation-picker-empty';
      empty.textContent = 'Подходящих списаний не найдено.';
      pickerList.append(empty);
      closePicker();
      return;
    }

    operationRows.forEach(operation => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'mgw-admin__operation-picker-item';
      button.dataset.operationId = String(operation.entry_id || '');
      button.setAttribute('role','option');
      button.setAttribute('aria-selected', currentOperation && String(currentOperation.entry_id) === String(operation.entry_id) ? 'true' : 'false');

      const title = document.createElement('strong');
      title.textContent = `−${coins(Math.abs(Number(operation.available_delta || 0)))} коинов · ${playerName(operation)}`;
      const meta = document.createElement('span');
      meta.textContent = `${localTime(operation.created_at_utc)} · ${categoryLabel(operation.category)}`;
      const id = document.createElement('small');
      id.textContent = String(operation.entry_id || '');
      button.append(title,meta,id);
      button.addEventListener('click', () => {
        renderSelected(operation);
        pickerSelected.textContent = optionLabel(operation);
        pickerList.querySelectorAll('[data-operation-id]').forEach(node => {
          node.setAttribute('aria-selected', String(node.dataset.operationId || '') === String(operation.entry_id || '') ? 'true' : 'false');
        });
        closePicker();
      });
      pickerList.append(button);
    });
  };
  const renderSelected = operation => {
    currentOperation = operation || null;

    if (!currentOperation) {
      selectedBox.hidden = true;
      requestButton.disabled = true;
      pickerSelected.textContent = operationRows.length ? 'Выберите списание…' : 'Подходящих списаний не найдено';
      pickerList.querySelectorAll('[data-operation-id]').forEach(node => node.setAttribute('aria-selected','false'));
      return;
    }

    const delta = Number(currentOperation.available_delta || 0);
    selectedPlayer.textContent = playerName(currentOperation);
    selectedAmount.textContent =
      delta < 0
        ? `${coins(Math.abs(delta))} коинов`
        : `+${coins(delta)} коинов`;
    selectedTime.textContent = localTime(currentOperation.created_at_utc);
    selectedBalance.textContent =
      `${coins(currentOperation.current_available_amount)} коинов`;

    selectedTech.replaceChildren();
    const techRows = [
      ['ID операции', currentOperation.entry_id],
      ['Ключ операции', currentOperation.operation_key],
      ['Тип', categoryLabel(currentOperation.category)],
      ['Техническая категория', currentOperation.category],
      ['Источник', currentOperation.source_ref || '—'],
      ['Уже компенсировано', `${coins(currentOperation.applied_compensation_total)} коинов`],
    ];
    techRows.forEach(([label,value]) => {
      const row = document.createElement('div');
      const key = document.createElement('span');
      const val = document.createElement('code');
      key.textContent = label;
      val.textContent = String(value ?? '—');
      row.append(key,val);
      selectedTech.append(row);
    });

    selectedBox.hidden = false;

    if (canCompensate(currentOperation)) {
      setMessage(lookupStatus);
      const suggested = Math.abs(delta);
      if (suggested > 0 && suggested <= Number(limits.max_amount || 250000)) {
        amountInput.value = String(suggested);
      }
      requestButton.disabled = busy || !!pendingCompensation;
    } else {
      setMessage(
        lookupStatus,
        'Эта операция не является списанием. Для компенсации выберите операцию со списанием коинов.',
        'error'
      );
      requestButton.disabled = true;
    }

    resetRequestToken();
  };

  const renderHistoryPage = () => {
    history.replaceChildren();
    const pageData = window.MGWAdminUX?.paginate(historyRows, historyPage, historyPerPage) || {
      rows:historyRows, page:1, total:historyRows.length, totalPages:1, from:historyRows.length ? 1 : 0, to:historyRows.length
    };
    historyPage = pageData.page;

    if (!pageData.total) {
      const empty = document.createElement('div');
      empty.className = 'mgw-admin__history-empty';
      empty.textContent = 'Компенсаций пока нет.';
      history.append(empty);
      window.MGWAdminUX?.renderPager(historyPagination, {page:1,total_pages:1,total:0,from:0,to:0}, () => {});
      return;
    }

    pageData.rows.forEach(item => {
      const details = document.createElement('details');
      details.className = 'mgw-admin__compensation-history-item';

      const summary = document.createElement('summary');
      const main = document.createElement('span');
      const state = document.createElement('strong');
      main.textContent = `${coins(item.amount)} коинов · ${playerName(item)}`;
      state.textContent = item.status === 'applied' ? 'Проведена' : 'Ждёт подтверждения';
      summary.append(main,state);

      const body = document.createElement('div');
      body.className = 'mgw-admin__compensation-history-body';
      const reason = document.createElement('p');
      reason.textContent = `Причина: ${item.reason}`;
      const time = document.createElement('p');
      time.textContent = `Создана: ${localTime(item.requested_at_utc)}`;
      body.append(reason,time);

      if (item.status !== 'applied') {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = 'Продолжить подтверждение';
        button.addEventListener('click',() => {
          showPending(item);
          confirmation.scrollIntoView({behavior:'smooth',block:'nearest'});
        });
        body.append(button);
      } else if (item.original_entry_id) {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = 'Показать исходное списание';
        button.addEventListener('click',() => { void openOperation(item.original_entry_id); });
        body.append(button);
      }

      details.append(summary,body);
      history.append(details);
    });

    window.MGWAdminUX?.renderPager(historyPagination, {
      page:pageData.page,
      total_pages:pageData.totalPages,
      total:pageData.total,
      from:pageData.from,
      to:pageData.to,
    }, nextPage => {
      historyPage = nextPage;
      renderHistoryPage();
    });
  };

  const renderHistory = rows => {
    historyRows = Array.isArray(rows) ? rows : [];
    historyCount.textContent = String(historyRows.length);
    historyPage = 1;
    renderHistoryPage();
  };
  const showPending = item => {
    pendingCompensation = item;
    confirmationCopy.replaceChildren();

    const lines = [
      ['Игрок', playerName(item)],
      ['Сумма', `${coins(item.amount)} коинов`],
      ['Причина', item.reason],
    ];
    lines.forEach(([label,value]) => {
      const row = document.createElement('div');
      const key = document.createElement('span');
      const val = document.createElement('strong');
      key.textContent = label;
      val.textContent = String(value || '—');
      row.append(key,val);
      confirmationCopy.append(row);
    });

    confirmation.hidden = false;
    requestButton.disabled = true;
    confirmButton.disabled = busy;
    setMessage(
      statusBox,
      'Компенсация подготовлена. Коины ещё не начислены — требуется второе подтверждение.'
    );
  };

  const clearPending = () => {
    pendingCompensation = null;
    confirmation.hidden = true;
    confirmationCopy.replaceChildren();
    confirmButton.disabled = true;
    requestButton.disabled = busy || !canCompensate(currentOperation);
    activeRequestToken = '';
  };

  const refreshSnapshot = async () => {
    const data = await post({action:'snapshot'});
    applyLimits(data.limits);
    renderPicker(data.operations || []);
    renderHistory(data.history || []);
  };

  const browseOperations = async () => {
    if (busy) return;
    setBusy(true);
    setMessage(lookupStatus,'Ищу списания…');

    try {
      const data = await post({
        action:'operations',
        query:browserQuery.value.trim(),
      });
      applyLimits(data.limits);
      renderPicker(data.operations || []);
      renderSelected(null);

      if ((data.operations || []).length === 0) {
        setMessage(
          lookupStatus,
          'Подходящих списаний по этому запросу не найдено.',
          'error'
        );
      } else {
        setMessage(
          lookupStatus,
          `Найдено списаний: ${(data.operations || []).length}. Выберите нужное в списке выше.`,
          'ok'
        );
      }
    } catch (error) {
      setMessage(
        lookupStatus,
        error.message || 'Не удалось найти списания.',
        'error'
      );
    } finally {
      setBusy(false);
    }
  };

  const openOperation = async operationRef => {
    if (busy || !operationRef) return;
    setBusy(true);
    setMessage(lookupStatus,'Открываю операцию…');

    try {
      const data = await post({
        action:'lookup',
        operation_ref:String(operationRef),
      });
      applyLimits(data.limits);
      operationInput.value = String(data.operation?.entry_id || operationRef);
      renderSelected(data.operation);

      if (canCompensate(data.operation)) {
        const exists = operationRows.some(
          item => String(item.entry_id) === String(data.operation.entry_id)
        );
        if (!exists) {
          renderPicker([data.operation,...operationRows]);
        }
        pickerSelected.textContent = optionLabel(data.operation);
        pickerList.querySelectorAll('[data-operation-id]').forEach(node => {
          node.setAttribute('aria-selected', String(node.dataset.operationId || '') === String(data.operation.entry_id || '') ? 'true' : 'false');
        });
      }
    } catch (error) {
      setMessage(
        lookupStatus,
        error.message || 'Операция не найдена.',
        'error'
      );
    } finally {
      setBusy(false);
    }
  };

  const lookup = async () => {
    const operationRef = operationInput.value.trim();
    if (!operationRef) {
      setMessage(lookupStatus,'Введите точный ID операции.','error');
      operationInput.focus();
      return;
    }
    await openOperation(operationRef);
  };

  const createCompensation = async () => {
    if (busy || !canCompensate(currentOperation)) return;

    const amount = Number(amountInput.value);
    const reason = reasonInput.value.trim();

    if (!Number.isInteger(amount) || amount < 1) {
      setMessage(statusBox,'Укажите целую сумму компенсации.','error');
      amountInput.focus();
      return;
    }

    if (amount > Number(limits.max_amount || 250000)) {
      setMessage(
        statusBox,
        `Сумма превышает лимит ${coins(limits.max_amount)} коинов.`,
        'error'
      );
      amountInput.focus();
      return;
    }

    if (!reason) {
      setMessage(statusBox,'Укажите причину компенсации.','error');
      reasonInput.focus();
      return;
    }

    setBusy(true);
    setMessage(statusBox,'Создаю компенсацию…');

    try {
      const data = await post({
        action:'request',
        operation_ref:currentOperation.entry_id,
        amount,
        reason,
        request_token:requestToken(),
      });

      applyLimits(data.limits);
      renderHistory(data.history || []);

      const item = data.compensation;
      if (item?.status === 'pending_confirmation') {
        showPending(item);
      } else if (item?.status === 'applied') {
        clearPending();
        setMessage(
          statusBox,
          `Готово. Начислено ${coins(item.amount)} коинов. Баланс: ${coins(item.available_before)} → ${coins(item.available_after)}.`,
          'ok'
        );
        amountInput.value = '';
        reasonInput.value = '';

        const lookupData = await post({
          action:'lookup',
          operation_ref:currentOperation.entry_id,
        });
        renderSelected(lookupData.operation);
      } else {
        setMessage(
          statusBox,
          'Компенсация сохранена, но её состояние требует проверки.',
          'error'
        );
      }
    } catch (error) {
      setMessage(
        statusBox,
        error.message || 'Не удалось создать компенсацию.',
        'error'
      );
    } finally {
      setBusy(false);
    }
  };

  const confirmCompensation = async () => {
    if (busy || !pendingCompensation) return;

    setBusy(true);
    setMessage(statusBox,'Подтверждаю компенсацию…');

    try {
      const data = await post({
        action:'confirm',
        compensation_id:pendingCompensation.compensation_id,
      });

      applyLimits(data.limits);
      renderHistory(data.history || []);

      const item = data.compensation;
      if (item?.status !== 'applied') {
        throw new Error('Компенсация не была проведена.');
      }

      clearPending();
      setMessage(
        statusBox,
        `Готово. Начислено ${coins(item.amount)} коинов. Баланс: ${coins(item.available_before)} → ${coins(item.available_after)}.`,
        'ok'
      );
      amountInput.value = '';
      reasonInput.value = '';

      if (currentOperation) {
        const lookupData = await post({
          action:'lookup',
          operation_ref:currentOperation.entry_id,
        });
        renderSelected(lookupData.operation);
      }
    } catch (error) {
      setMessage(
        statusBox,
        error.message || 'Не удалось подтвердить компенсацию.',
        'error'
      );
    } finally {
      setBusy(false);
    }
  };

  pickerTrigger.addEventListener('click', event => {
    event.stopPropagation();
    if (busy || operationRows.length === 0) return;
    pickerList.hidden = !pickerList.hidden;
    pickerTrigger.setAttribute('aria-expanded', pickerList.hidden ? 'false' : 'true');
  });
  pickerList.addEventListener('click', event => event.stopPropagation());
  document.addEventListener('click', event => {
    if (!picker.contains(event.target)) closePicker();
  });

  browserButton.addEventListener('click',browseOperations);
  browserQuery.addEventListener('keydown',event => {
    if (event.key === 'Enter') {
      event.preventDefault();
      void browseOperations();
    }
  });

  lookupButton.addEventListener('click',lookup);
  operationInput.addEventListener('keydown',event => {
    if (event.key === 'Enter') {
      event.preventDefault();
      void lookup();
    }
  });

  amountInput.addEventListener('input',resetRequestToken);
  reasonInput.addEventListener('input',resetRequestToken);
  requestButton.addEventListener('click',createCompensation);
  confirmButton.addEventListener('click',confirmCompensation);

  telegram?.ready?.();

  void refreshSnapshot().catch(error => {
    pickerList.replaceChildren();
    const emptyPicker = document.createElement('div');
    emptyPicker.className = 'mgw-admin__operation-picker-empty';
    emptyPicker.textContent = 'Не удалось загрузить списания';
    pickerList.append(emptyPicker);
    pickerSelected.textContent = 'Не удалось загрузить списания';
    pickerTrigger.disabled = true;
    closePicker();

    setMessage(
      statusBox,
      error.message || 'Не удалось загрузить компенсации.',
      'error'
    );

    history.replaceChildren();
    const empty = document.createElement('div');
    empty.className = 'mgw-admin__history-empty';
    empty.textContent = 'История недоступна.';
    history.append(empty);
  });
})();
