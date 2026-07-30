import { applyServerProjection } from './server-projection.js';

const POLL_INTERVAL_MS = 500;

export function createMatchOwner({ root, api, store, router, requestContext }){
  if (!(root instanceof HTMLElement)) throw new TypeError('Clean match root is required.');
  if (!api || typeof api.syncMatch !== 'function') throw new TypeError('Clean match API is required.');
  if (!store || typeof store.setState !== 'function') throw new TypeError('Clean match store is required.');
  if (!router || typeof router.show !== 'function') throw new TypeError('Clean match router is required.');
  if (typeof requestContext !== 'function') throw new TypeError('Clean match request context is required.');

  let started = false;
  let inFlight = null;
  let pollTimer = 0;
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
    stopPolling();
  }

  async function onClick(event){
    const target = event.target instanceof Element ? event.target.closest('[data-match-action]') : null;
    if (!(target instanceof HTMLElement) || !root.contains(target)) return;
    const action = target.dataset.matchAction || '';
    if (!action || inFlight || store.getState().session?.locked) return;

    if (action === 'start-search') {
      await run(() => api.startSearch(requestContext(), commandId()));
      return;
    }
    if (action === 'cancel-search') {
      await run(() => api.cancelSearch(requestContext(), commandId()));
      return;
    }
    if (action === 'surrender') {
      const gameId = String(store.getState().activeMatch?.id || '');
      if (gameId) await run(() => api.surrender(requestContext(), gameId, commandId()));
      return;
    }
    if (action === 'dismiss-result') {
      await run(() => api.dismissResult(requestContext(), commandId()));
      return;
    }
    if (action === 'new-search') {
      await run(async () => {
        const context = requestContext();
        await api.dismissResult(context, commandId());
        return api.startSearch(context, commandId());
      });
      return;
    }
    if (action === 'move') {
      const game = store.getState().activeMatch;
      const cell = Number(target.dataset.cell);
      if (!game?.id || !Number.isInteger(cell)) return;
      await run(() => api.makeMove(requestContext(), game.id, cell, commandId()));
    }
  }

  async function run(operation, { busy = true, reportError = true } = {}){
    if (inFlight) return inFlight;
    if (busy) setBusy(true);
    inFlight = Promise.resolve()
      .then(operation)
      .then(applyProjection)
      .catch(error => reportError ? showTransientError(error) : null)
      .finally(() => {
        inFlight = null;
        if (busy) setBusy(false);
      });
    return inFlight;
  }

  async function poll(){
    if (inFlight) return;
    const state = store.getState();
    if (!state.matchmaking && !state.activeMatch) return;
    await run(() => api.syncMatch(requestContext()), { busy:false, reportError:false });
  }

  function applyProjection(result){
    applyServerProjection(store, result);
    return result;
  }

  function render(state){
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
    setText('[data-match-balance]', String(state.balances?.match ?? '—'));
    const button = root.querySelector('[data-match-action="start-search"]');
    if (button instanceof HTMLButtonElement) {
      button.disabled = Boolean(state.session?.locked) || Number(state.balances?.match ?? 0) < 10;
    }
  }

  function renderSearch(state){
    const matchmaking = state.matchmaking || {};
    setText('[data-search-bet]', String(matchmaking.bet ?? 10));
    setText('[data-search-balance]', String(state.balances?.match ?? '—'));
    const cancel = root.querySelector('[data-match-action="cancel-search"]');
    if (cancel instanceof HTMLButtonElement) cancel.disabled = Boolean(state.session?.locked) || inFlight !== null;
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
        || inFlight !== null
        || value !== '-'
        || match.turn !== match.viewer_id
        || !match.legal_actions?.includes('move');
    }
    const surrender = root.querySelector('[data-match-action="surrender"]');
    if (surrender instanceof HTMLButtonElement) surrender.disabled = locked || inFlight !== null;
  }

  function renderResult(state){
    const result = state.matchResult || {};
    const titles = { win:'Победа', loss:'Поражение', draw:'Ничья' };
    setText('[data-result-title]', titles[result.outcome] || 'Матч завершён');
    setText('[data-result-reason]', reasonLabel(result.finish_reason));
    setText('[data-result-balance]', String(state.balances?.match ?? '—'));
    setText('[data-result-payout]', String(result.outcome === 'loss' ? 0 : result.payout ?? 0));
    for (const button of root.querySelectorAll('[data-screen="result"] [data-match-action]')) {
      if (button instanceof HTMLButtonElement) button.disabled = Boolean(state.session?.locked) || inFlight !== null;
    }
  }

  function startPolling(){
    if (pollTimer) return;
    pollTimer = window.setInterval(() => void poll(), POLL_INTERVAL_MS);
  }

  function stopPolling(){
    if (!pollTimer) return;
    window.clearInterval(pollTimer);
    pollTimer = 0;
  }

  function setBusy(busy){
    root.toggleAttribute('data-match-busy', busy);
    for (const button of root.querySelectorAll('[data-match-action]')) {
      if (button instanceof HTMLButtonElement && busy) button.disabled = true;
    }
    if (!busy) render(store.getState());
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
