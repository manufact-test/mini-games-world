export function applyServerProjection(store, result){
  if (!store || typeof store.getState !== 'function' || typeof store.setState !== 'function') {
    throw new TypeError('Clean runtime store is required.');
  }
  if (!result || typeof result !== 'object') {
    throw new TypeError('Clean server projection is required.');
  }

  const currentRevision = revisionOf(store.getState().storage);
  const nextRevision = revisionOf(result.storage);
  if (nextRevision >= 0 && currentRevision >= 0 && nextRevision < currentRevision) {
    return false;
  }

  store.setState({
    account:result.account,
    session:result.session,
    presence:result.presence,
    storage:result.storage,
    matchmaking:result.matchmaking,
    activeMatch:result.active_match,
    matchResult:result.match_result,
    balances:result.balances,
    error:null,
  });
  return true;
}

function revisionOf(storage){
  const value = Number(storage?.revision);
  return Number.isInteger(value) && value >= 0 ? value : -1;
}
