(() => {
  'use strict';

  const root = document.querySelector('[data-compensation-card]');
  const shell = document.querySelector('[data-compensation-api]');
  if (!root || !shell) return;

  const endpoint = String(shell.dataset.compensationApi || '');
  const telegram = window.Telegram?.WebApp || null;
  const operationInput = root.querySelector('[data-compensation-operation]');
  const lookupButton = root.querySelector('[data-compensation-lookup]');
  const lookupStatus = root.querySelector('[data-compensation-lookup-status]');
  const operationSummary = root.querySelector('[data-compensation-operation-summary]');
  const amountInput = root.querySelector('[data-compensation-amount]');
  const reasonInput = root.querySelector('[data-compensation-reason]');
  const requestButton = root.querySelector('[data-compensation-request]');
  const limitCopy = root.querySelector('[data-compensation-limit-copy]');
  const statusBox = root.querySelector('[data-compensation-status]');
  const confirmation = root.querySelector('[data-compensation-confirmation]');
  const confirmationCopy = root.querySelector('[data-compensation-confirmation-copy]');
  const confirmButton = root.querySelector('[data-compensation-confirm]');
  const history = root.querySelector('[data-compensation-history]');

  let busy = false;
  let currentOperation = null;
  let pendingCompensation = null;
  let activeRequestToken = '';
  let limits = {large_amount_threshold:50000,max_amount:250000,asset_code:'mgw_coin'};

  const coins = value => Number(value || 0).toLocaleString('ru-RU');
  const localTime = value => {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ', 'T') + (String(value).includes('Z') ? '' : 'Z'));
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('ru-RU');
  };

  const post = async payload => {
    const response = await fetch(endpoint, {
      method:'POST',
      cache:'no-store',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({...payload,initData:telegram?.initData || ''}),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.ok !== true) {
      throw new Error(String(data.error || 'Не удалось выполнить действие компенсации.'));
    }
    return data;
  };

  const setBox = (box, message, state = '') => {
    box.textContent = message;
    if (state) box.dataset.state = state;
    else delete box.dataset.state;
  };

  const setBusy = value => {
    busy = value;
    lookupButton.disabled = value;
    requestButton.disabled = value || !currentOperation || !!pendingCompensation;
    confirmButton.disabled = value || !pendingCompensation;
    operationInput.disabled = value;
    amountInput.disabled = value;
    reasonInput.disabled = value;
  };

  const metric = (label, value) => {
    const wrap = document.createElement('div');
    const span = document.createElement('span');
    const strong = document.createElement('strong');
    span.textContent = label;
    strong.textContent = value;
    wrap.append(span,strong);
    return wrap;
  };

  const renderOperation = operation => {
    currentOperation = operation || null;
    operationSummary.replaceChildren();
    if (!currentOperation) {
      operationSummary.hidden = true;
      requestButton.disabled = true;
      return;
    }
    const player = currentOperation.nickname
      ? `${currentOperation.nickname} · ${currentOperation.mgw_id || currentOperation.account_ref}`
      : (currentOperation.mgw_id || currentOperation.account_ref);
    operationSummary.append(
      metric('Игрок', player),
      metric('Операция', currentOperation.operation_key),
      metric('Изменение', `${Number(currentOperation.available_delta) > 0 ? '+' : ''}${coins(currentOperation.available_delta)}`),
      metric('Доступно сейчас', coins(currentOperation.current_available_amount))
    );
    operationSummary.hidden = false;
    requestButton.disabled = busy;
    setBox(
      lookupStatus,
      `Найдена запись ${currentOperation.entry_id}. Категория: ${currentOperation.category}. Уже компенсировано: ${coins(currentOperation.applied_compensation_total)}.`,
      'ok'
    );
  };

  const renderHistory = rows => {
    history.replaceChildren();
    if (!Array.isArray(rows) || rows.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'mgw-admin__history-empty';
      empty.textContent = 'Компенсаций пока нет.';
      history.append(empty);
      return;
    }

    rows.forEach(item => {
      const row = document.createElement('div');
      row.className = 'mgw-admin__history-item';
      const copy = document.createElement('div');
      copy.className = 'mgw-admin__history-copy';
      const title = document.createElement('strong');
      const meta = document.createElement('span');
      const player = item.nickname || item.mgw_id || item.account_ref || 'Игрок';
      const state = item.status === 'applied' ? 'Проведена' : 'Ждёт подтверждения';
      title.textContent = `${coins(item.amount)} · ${player} · ${state}`;
      meta.textContent = `${localTime(item.requested_at_utc)} · ${item.reason} · исходная: ${item.original_operation_key}`;
      copy.append(title,meta);

      const badge = document.createElement('button');
      badge.type = 'button';
      if (item.status === 'applied') {
        badge.disabled = true;
        badge.textContent = '✓ Ledger';
      } else {
        badge.textContent = 'Подтвердить';
        badge.addEventListener('click',() => {
          showPending(item);
          setBusy(false);
          confirmation.scrollIntoView({behavior:'smooth',block:'nearest'});
        });
      }
      row.append(copy,badge);
      history.append(row);
    });
  };

  const applyLimits = next => {
    if (next && typeof next === 'object') limits = {...limits,...next};
    amountInput.max = String(limits.max_amount || 250000);
    limitCopy.textContent = `Лимит: до ${coins(limits.max_amount)}. От ${coins(limits.large_amount_threshold)} требуется второе подтверждение.`;
  };

  const resetRequestToken = () => {
    if (!pendingCompensation) activeRequestToken = '';
  };

  const requestToken = () => {
    if (activeRequestToken) return activeRequestToken;
    const random = globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    activeRequestToken = `admin-compensation:${random}`;
    return activeRequestToken;
  };

  const showPending = item => {
    pendingCompensation = item;
    confirmationCopy.textContent = [
      `Игрок: ${item.nickname || item.mgw_id || item.account_ref}`,
      `Сумма: ${coins(item.amount)} MGW Coins`,
      `Причина: ${item.reason}`,
      `Исходная операция: ${item.original_operation_key}`,
      '',
      'Баланс ещё не изменён. Нажмите кнопку ниже для отдельного второго подтверждения.'
    ].join('\n');
    confirmation.hidden = false;
    requestButton.disabled = true;
    confirmButton.disabled = busy;
    setBox(statusBox,'Крупная компенсация сохранена и ждёт второго подтверждения. Баланс не изменён.','');
  };

  const clearPending = () => {
    pendingCompensation = null;
    confirmation.hidden = true;
    confirmationCopy.textContent = '—';
    activeRequestToken = '';
  };

  const refreshSnapshot = async () => {
    const data = await post({action:'snapshot'});
    applyLimits(data.limits);
    renderHistory(data.history || []);
  };

  const lookup = async () => {
    if (busy) return;
    const operationRef = operationInput.value.trim();
    if (!operationRef) {
      setBox(lookupStatus,'Укажите ID исходной операции.','error');
      operationInput.focus();
      return;
    }
    setBusy(true);
    setBox(lookupStatus,'Ищу операцию…');
    try {
      const data = await post({action:'lookup',operation_ref:operationRef});
      applyLimits(data.limits);
      renderOperation(data.operation);
      resetRequestToken();
    } catch (error) {
      renderOperation(null);
      setBox(lookupStatus,error.message || 'Операция не найдена.','error');
    } finally {
      setBusy(false);
    }
  };

  const createCompensation = async () => {
    if (busy || !currentOperation) return;
    const amount = Number(amountInput.value);
    const reason = reasonInput.value.trim();
    if (!Number.isInteger(amount) || amount < 1) {
      setBox(statusBox,'Укажите целую сумму компенсации.','error');
      amountInput.focus();
      return;
    }
    if (amount > Number(limits.max_amount || 250000)) {
      setBox(statusBox,`Сумма превышает лимит ${coins(limits.max_amount)}.`,'error');
      amountInput.focus();
      return;
    }
    if (!reason) {
      setBox(statusBox,'Укажите причину компенсации.','error');
      reasonInput.focus();
      return;
    }

    setBusy(true);
    setBox(statusBox,'Создаю компенсацию…');
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
        setBox(statusBox,`Компенсация проведена. Баланс: ${coins(item.available_before)} → ${coins(item.available_after)}.`,'ok');
        const lookupData = await post({action:'lookup',operation_ref:currentOperation.entry_id});
        renderOperation(lookupData.operation);
        amountInput.value = '';
        reasonInput.value = '';
      } else {
        setBox(statusBox,'Компенсация сохранена, но её состояние требует проверки.','error');
      }
    } catch (error) {
      setBox(statusBox,error.message || 'Не удалось создать компенсацию.','error');
    } finally {
      setBusy(false);
    }
  };

  const confirmCompensation = async () => {
    if (busy || !pendingCompensation) return;
    setBusy(true);
    setBox(statusBox,'Провожу подтверждённую компенсацию…');
    try {
      const data = await post({
        action:'confirm',
        compensation_id:pendingCompensation.compensation_id,
      });
      applyLimits(data.limits);
      renderHistory(data.history || []);
      const item = data.compensation;
      if (item?.status !== 'applied') throw new Error('Компенсация не была проведена.');
      clearPending();
      setBox(statusBox,`Компенсация проведена. Баланс: ${coins(item.available_before)} → ${coins(item.available_after)}.`,'ok');
      if (currentOperation) {
        const lookupData = await post({action:'lookup',operation_ref:currentOperation.entry_id});
        renderOperation(lookupData.operation);
      }
      amountInput.value = '';
      reasonInput.value = '';
    } catch (error) {
      setBox(statusBox,error.message || 'Не удалось подтвердить компенсацию.','error');
    } finally {
      setBusy(false);
    }
  };

  lookupButton.addEventListener('click',lookup);
  operationInput.addEventListener('keydown',event => {
    if (event.key === 'Enter') {
      event.preventDefault();
      void lookup();
    }
  });
  operationInput.addEventListener('input',() => {
    renderOperation(null);
    clearPending();
  });
  amountInput.addEventListener('input',resetRequestToken);
  reasonInput.addEventListener('input',resetRequestToken);
  requestButton.addEventListener('click',createCompensation);
  confirmButton.addEventListener('click',confirmCompensation);

  telegram?.ready?.();
  void refreshSnapshot().catch(error => {
    setBox(statusBox,error.message || 'Не удалось загрузить историю компенсаций.','error');
    history.replaceChildren();
    const empty = document.createElement('div');
    empty.className = 'mgw-admin__history-empty';
    empty.textContent = 'История недоступна.';
    history.append(empty);
  });
})();
