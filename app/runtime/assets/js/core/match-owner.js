import { applyServerProjection } from './server-projection.js';

const POLL_INTERVAL_MS = 500;
const WINNING_LINES = Object.freeze([
  [0, 1, 2], [3, 4, 5], [6, 7, 8],
  [0, 3, 6], [1, 4, 7], [2, 5, 8],
  [0, 4, 8], [2, 4, 6],
]);

export function createMatchOwner({ root, api, store, router, requestContext }){
  if (!(root instanceof HTMLElement)) throw new TypeError('Clean match root is required.');
  if (!api || typeof api.syncMatch !== 'function') throw new TypeError('Clean match API is required.');
  if (!store || typeof store.setState !== 'function') throw new TypeError('Clean match store is required.');
  if (!router || typeof router.show !== 'function') throw new TypeError('Clean match router is required.');
  if (typeof requestContext !== 'function') throw new TypeError('Clean match request context is required.');

  let started = false;
  let commandInFlight = null;
  let commandAbortController = null;
  let pollInFlight = null;
  let pollAbortController = null;
  let pollTimer = 0;
  let pendingResultTitle = '';
  let unsubscribe = null;

  function start(){
    if (started) return;
    started = true;
    root.addEventListener('click', onClick);
    unsubscribe = store.subscribe(render);
    render(store.getState());
  }

  function stop(){
    if (!started) return;
    started = false;
    root.removeEventListener('click', onClick);
    unsubscribe?.();
    unsubscribe = null;
    pendingResultTitle = '';
    stopPolling();
    commandAbortController?.abort();
    commandAbortController = null;
  }

  async function onClick(event){
    const target = event.target instanceof Element ? event.target.closest('[data-match-action]') : null;
    if (!(target instanceof HTMLElement) || !root.contains(target)) return;

    const action = target.dataset.matchAction || '';
    const state = store.getState();
    if (!action || state.session?.locked) return;

    const transition = state.matchTransition;
    if (transition?.type === 'surrendering') {
      if (action === 'start-search' && transition.next !== 'start-search') {
        store.setState({
          matchTransition:{ ...transition, next:'start-search' },
        });
      }
      return;
    }

    if (commandInFlight) return;
    pausePolling();

    if (action === 'start-search') {
      await runCommand(signal => api.startSearch(requestContext(), commandId(), { signal }));
      return;
    }
    if (action === 'cancel-search') {
      await runCommand(signal => api.cancelSearch(requestContext(), commandId(), { signal }));
      return;
    }
    if (action === 'surrender') {
      const gameId = String(state.activeMatch?.id || '');
      if (gameId) await runSurrenderTransition(gameId);
      return;
    }
    if (action === 'dismiss-result') {
      await runCommand(signal => api.dismissResult(requestContext(), commandId(), { signal }));
      return;
    }
    if (action === 'new-search') {
      await runCommand(async signal => {
        const context = requestContext();
        await api.dismissResult(context, commandId(), { signal });
        return api.startSearch(context, commandId(), { signal });
      });
      return;
    }
    if (action === 'move') {
      const game = state.activeMatch;
      const cell = Number(target.dataset.cell);
      if (!game?.id || !Number.isInteger(cell)) return;
      const pendingTitle = pendingMoveTitle(game, cell);
      if (pendingTitle) showPendingResult(pendingTitle, state);
      await runCommand(signal => api.makeMove(requestContext(), game.id, cell, commandId(), { signal }));
    }
  }

  async function runCommand(operation){
    if (commandInFlight) return commandInFlight;
    pausePolling();
    const controller = new AbortController();
    commandAbortController = controller;
    setBusy(true);
    commandInFlight = Promise.resolve()
      .then(() => operation(controller.signal))
      .then(result => {
        pendingResultTitle = '';
        return applyProjection(result);
      })
      .catch(error => {
        pendingResultTitle = '';
        return isAbortError(error) ? null : showTransientError(error);
      })
      .finally(() => {
        if (commandAbortController === controller) commandAbortController = null;
        commandInFlight = null;
        setBusy(false);
        startPolling();
      });
    return commandInFlight;
  }

  async function runSurrenderTransition(gameId){
    if (commandInFlight) return commandInFlight;
    pausePolling();

    const controller = new AbortController();
    const context = requestContext();
    commandAbortController = controller;
    store.setState({
      matchTransition:{
        type:'surrendering',
        next:null,
        started_at:Date.now(),
      },
    });

    commandInFlight = (async () => {
      let surrenderProjection = null;
      let dismissedProjection = null;

      try {
        surrenderProjection = await api.surrender(context, gameId, commandId(), { signal:controller.signal });
        dismissedProjection = await api.dismissResult(context, commandId(), { signal:controller.signal });

        const latestTransition = store.getState().matchTransition;
        let finalProjection = dismissedProjection;
        if (latestTransition?.type === 'surrendering' && latestTransition.next === 'start-search') {
          finalProjection = await api.startSearch(context, commandId(), { signal:controller.signal });
        }

        applyProjection(finalProjection);
        store.setState({ matchTransition:null });
        return finalProjection;
      } catch (error) {
        store.setState({ matchTransition:null });
        if (surrenderProjection) {
          applyProjection(surrenderProjection);
        } else {
          await restoreAuthoritativeMatch();
        }
        if (!isAbortError(error)) showTransientError(error);
        return null;
      } finally {
        if (commandAbortController === controller) commandAbortController = null;
        commandInFlight = null;
        render(store.getState());
        startPolling();
      }
    })();

    return commandInFlight;
  }

  async function restoreAuthoritativeMatch(){
    try {
      const result = await api.syncMatch(requestContext());
      applyProjection(result);
    } catch {
      // The visible error from the original command is sufficient.
    }
  }

  async function poll(){
    if (!started || commandInFlight || pollInFlight) return pollInFlight;
    const state = store.getState();
    if (!state.matchmaking && !state.activeMatch) return null;

    const controller = new AbortController();
    pollAbortController = controller;
    pollInFlight = api.syncMatch(requestContext(), { signal:controller.signal })
      .then(applyProjection)
      .catch(() => null)
      .finally(() => {
        if (pollAbortController === controller) pollAbortController = null;
        pollInFlight = null;
        startPolling();
      });
    return pollInFlight;
  }

  function applyProjection(result){
    applyServerProjection(store, result);
    return result;
  }

  function render(state){
    if (state.matchTransition?.type === 'surrendering') {
      renderHome(state);
      router.show('home');
      stopPolling();
      return;
    }
    if (pendingResultTitle && commandInFlight) {
      renderPendingResult(pendingResultTitle, state);
      router.show('result');
      return;
    }
    if (state.matchResult) {
      renderResult(state);
      router.show('result');
      stopPolling();
      return;
    }
    if (state.activeMatch) {
      renderMatch(state);
      router.show('match');
      startPolling();
      return;
    }
    if (state.matchmaking) {
      renderSearch(state);
      router.show('search');
      startPolling();
      return;
    }
    renderHome(state);
    router.show('home');
    stopPolling();
  }

  function renderHome(state){
    const transition = state.matchTransition;
    const queuedSearch = transition?.type === 'surrendering' && transition.next === 'start-search';
    const balance = Number(state.balances?.match ?? 0);
    setText('[data-match-balance]', String(state.balances?.match ?? '—'));

    const button = root.querySelector('[data-match-action="start-search"]');
    if (button instanceof HTMLButtonElement) {
      button.textContent = queuedSearch ? 'Запускаем поиск…' : 'Найти соперника';
      button.disabled = Boolean(state.session?.locked)
        || balance < 10
        || (transition?.type === 'surrendering' ? queuedSearch : commandInFlight !== null);
    }
  }

  function renderSearch(state){
    const matchmaking = state.matchmaking || {};
    setText('[data-search-bet]', String(matchmaking.bet ?? 10));
    setText('[data-search-balance]', String(state.balances?.match ?? '—'));
    const cancel = root.querySelector('[data-match-action="cancel-search"]');
    if (cancel instanceof HTMLButtonElement) {
      cancel.disabled = Boolean(state.session?.locked) || commandInFlight !== null;
    }
  }

  function renderMatch(state){
    const match = state.activeMatch || {};
    const viewer = match.players?.find(player => player.id === match.viewer_id);
    const opponent = match.players?.find(player => player.id !== match.viewer_id);
    const locked = Boolean(state.session?.locked);
    setText('[data-match-title]', opponent ? `Матч против ${opponent.name}` : 'Тестовый матч');
    setText('[data-match-status]', locked ? 'Матч открыт на другом устройстве' : match.turn === match.viewer_id ? 'Ваш ход' : 'Ход соперника');
    setText('[data-match-symbol]', viewer?.symbol || match.viewer_symbol || '—');
    setText('[data-game-balance]', String(state.balances?.match ?? '—'));
    setText('[data-match-time]', String(match.time_left ?? '—'));

    const board = String(match.board || '---------');
    for (const button of root.querySelectorAll('[data-match-action="move"]')) {
      if (!(button instanceof HTMLButtonElement)) continue;
      const cell = Number(button.dataset.cell);
      const value = board[cell] || '-';
      button.textContent = value === '-' ? '' : value;
      button.disabled = locked
        || commandInFlight !== null
        || value !== '-'
        || match.turn !== match.viewer_id
        || !match.legal_actions?.includes('move');
    }
    const surrender = root.querySelector('[data-match-action="surrender"]');
    if (surrender instanceof HTMLButtonElement) {
      surrender.disabled = locked || commandInFlight !== null;
    }
  }

  function renderResult(state){
    const result = state.matchResult || {};
    const titles = { win:'Победа', loss:'Поражение', draw:'Ничья' };
    setText('[data-result-title]', titles[result.outcome] || 'Матч завершён');
    setText('[data-result-reason]', reasonLabel(result.finish_reason));
    setText('[data-result-balance]', String(state.balances?.match ?? '—'));
    setText('[data-result-payout]', String(result.outcome === 'loss' ? 0 : result.payout ?? 0));
    for (const button of root.querySelectorAll('[data-screen="result"] [data-match-action]')) {
      if (button instanceof HTMLButtonElement) {
        button.disabled = Boolean(state.session?.locked) || commandInFlight !== null;
      }
    }
  }

  function showPendingResult(title, state){
    pendingResultTitle = title;
    renderPendingResult(title, state);
    router.show('result');
  }

  function renderPendingResult(title, state){
    setText('[data-result-title]', title);
    setText('[data-result-reason]', 'Подтверждаем результат на сервере…');
    setText('[data-result-balance]', String(state.balances?.match ?? '—'));
    setText('[data-result-payout]', '—');
    for (const button of root.querySelectorAll('[data-screen="result"] [data-match-action]')) {
      if (button instanceof HTMLButtonElement) button.disabled = true;
    }
  }

  function startPolling(){
    if (!started || pollTimer || pollInFlight || commandInFlight) return;
    const state = store.getState();
    if (!state.matchmaking && !state.activeMatch) return;
    pollTimer = window.setTimeout(() => {
      pollTimer = 0;
      void poll();
    }, POLL_INTERVAL_MS);
  }

  function pausePolling(){
    if (pollTimer) window.clearTimeout(pollTimer);
    pollTimer = 0;
    pollAbortController?.abort();
    pollAbortController = null;
  }

  function stopPolling(){
    pausePolling();
  }

  function setBusy(busy){
    root.toggleAttribute('data-match-busy', busy);
    if (busy) {
      for (const button of root.querySelectorAll('[data-match-action]')) {
        if (button instanceof HTMLButtonElement) button.disabled = true;
      }
      return;
    }
    render(store.getState());
  }

  function showTransientError(error){
    const message = String(error?.message || error || 'Не удалось выполнить действие.');
    store.setState({ error:message });
    setText('[data-match-error]', message);
    window.setTimeout(() => {
      if (store.getState().error === message) store.setState({ error:null });
      setText('[data-match-error]', '');
    }, 4000);
    return null;
  }

  function setText(selector, value){
    for (const element of root.querySelectorAll(selector)) {
      if (element instanceof HTMLElement) element.textContent = value;
    }
  }

  return Object.freeze({ start, stop, sync:poll });
}

function pendingMoveTitle(match, cell){
  const board = String(match?.board || '---------').split('');
  const symbol = String(match?.viewer_symbol || match?.players?.find(player => player.id === match.viewer_id)?.symbol || '');
  if (!Number.isInteger(cell) || cell < 0 || cell >= 9 || board[cell] !== '-' || !['X', 'O'].includes(symbol)) {
    return '';
  }
  board[cell] = symbol;
  if (WINNING_LINES.some(line => line.every(index => board[index] === symbol))) {
    return 'Проверяем победу';
  }
  if (board.every(value => value !== '-')) {
    return 'Проверяем ничью';
  }
  return '';
}

function isAbortError(error){
  return String(error?.name || '') === 'AbortError';
}

function commandId(){
  if (typeof crypto?.randomUUID === 'function') {
    return `cmd_${crypto.randomUUID().replaceAll('-', '')}`;
  }
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  return `cmd_${Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('')}`;
}

function reasonLabel(reason){
  const labels = {
    normal_win:'Линия из трёх символов',
    draw:'Поле заполнено',
    surrender:'Игрок завершил матч',
    timeout:'Время хода истекло',
  };
  return labels[String(reason || '')] || 'Матч завершён';
}
