(() => {
  'use strict';

  const root = document.querySelector('[data-compensation-card]');
  const shell = document.querySelector('[data-compensation-api]');
  if (!root || !shell) return;

  const endpoint = String(shell.dataset.compensationApi || '');
  const telegram = window.Telegram?.WebApp || null;

  const picker = root.querySelector('[data-compensation-operation-picker]');
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

  let busy = false;
  let currentOperation = null;
  let operationRows = [];
  let pendingCompensation = null;
  let activeRequestToken = '';
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
    picker.disabled = value || operationRows.length === 0;
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

  const renderPicker = rows => {
    operationRows = Array.isArray(rows) ? rows.filter(canCompensate) : [];
    picker.replaceChildren();

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = operationRows.length
      ? 'Выберите списание…'
      : 'Подходящих списаний не найдено';
    picker.append(placeholder);

    operationRows.forEach(operation => {
      const option = document.createElement('option');
      option.value = String(operation.entry_id || '');
      option.textContent = optionLabel(operation);
      picker.append(option);
    });

    picker.disabled = busy || operationRows.length === 0;

    if (currentOperation && canCompensate(currentOperation)) {
      const exists = operationRows.some(
        item => String(item.entry_id) === String(currentOperation.entry_id)
      );
      if (exists) picker.value = String(currentOperation.entry_id);
    }
  };

  const renderSelected = operation => {
    currentOperation = operation || null;

    if (!currentOperation) {
      selectedBox.hidden = true;
      requestButton.disabled = true;
      picker.value = '';
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

  const chooseFromPicker = () => {
    const entryId = picker.value;
    if (!entryId) {
      renderSelected(null);
      return;
    }
    const operation = operationRows.find(
      item => String(item.entry_id) === String(entryId)
    );
    renderSelected(operation || null);
  };

  const renderHistory = rows => {
    const items = Array.isArray(rows) ? rows : [];
    historyCount.textContent = String(items.length);
    history.replaceChildren();

    if (items.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'mgw-admin__history-empty';
      empty.textContent = 'Компенсаций пока нет.';
      history.append(empty);
      return;
    }

    items.forEach(item => {
      const details = document.createElement('details');
      details.className = 'mgw-admin__compensation-history-item';

      const summary = document.createElement('summary');
      const main = document.createElement('span');
      const state = document.createElement('strong');
      main.textContent =
        `${coins(item.amount)} коинов · ${playerName(item)}`;
      state.textContent =
        item.status === 'applied' ? 'Проведена' : 'Ждёт подтверждения';
      summary.append(main,state);

      const body = document.createElement('div');
      body.className = 'mgw-admin__compensation-history-body';

      const reason = document.createElement('p');
      reason.textContent = `Причина: ${item.reason}`;

      const time = document.createElement('p');
      time.textContent = `Создана: ${localTime(item.requested_at_utc)}`;

      body.append(reason,time);

      if (item.status === 'pending_confirmation') {
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
        button.addEventListener('click',() => {
          void openOperation(item.original_entry_id);
        });
        body.append(button);
      }

      details.append(summary,body);
      history.append(details);
    });
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
      'Компенсация подготовлена. Коins ещё не начислены — требуется второе подтверждение.'
        .replace('Коins','Коины')
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
        picker.value = String(data.operation.entry_id);
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

  picker.addEventListener('change',chooseFromPicker);

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
    picker.replaceChildren();
    const option = document.createElement('option');
    option.value = '';
    option.textContent = 'Не удалось загрузить списания';
    picker.append(option);
    picker.disabled = true;

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
