export function buildTicTacToeOptimistic(game, action, viewerId){
  const board = String(game?.board || '');
  const cell = Number(action?.cell);
  const symbol = playerSymbol(game, viewerId);
  if (String(action?.type || '') !== 'cell'
    || String(game?.status || '') !== 'active'
    || String(game?.turn || '') !== String(viewerId || '')
    || !Number.isInteger(cell)
    || cell < 0
    || cell >= board.length
    || board[cell] !== '-'
    || !['X','O'].includes(symbol)) {
    return null;
  }

  const next = clone(game);
  next.board = `${board.slice(0, cell)}${symbol}${board.slice(cell + 1)}`;
  next.turn = otherPlayerId(game, viewerId);
  next.last_move = { cell, player_id:String(viewerId), symbol };
  next.move_count = Number(game?.move_count || 0) + 1;
  next.time_left = Number(game?.move_timeout_sec || 60);
  return next;
}

export function validateBattleshipPlacement(game, action){
  if (String(action?.type || '') !== 'place_ship') return true;

  const size = Number(action?.size);
  const start = Number(action?.cell);
  const orientation = String(action?.orientation || 'h') === 'v' ? 'v' : 'h';
  if (![1,2,3,4].includes(size) || !Number.isInteger(start) || start < 0 || start >= 100) return false;

  const row = Math.floor(start / 10);
  const col = start % 10;
  const cells = [];
  for (let index = 0; index < size; index++) {
    const r = row + (orientation === 'v' ? index : 0);
    const c = col + (orientation === 'h' ? index : 0);
    if (r < 0 || r >= 10 || c < 0 || c >= 10) return false;
    cells.push(r * 10 + c);
  }

  const fleet = Array.isArray(game?.my_fleet) ? game.my_fleet : [];
  const sameSizeCount = fleet.filter(ship => Number(ship?.size) === size).length;
  const limits = { 1:4, 2:3, 3:2, 4:1 };
  if (sameSizeCount >= limits[size]) return false;

  const occupied = new Set();
  for (const ship of fleet) {
    for (const cell of ship?.cells || []) occupied.add(Number(cell));
  }
  const board = Array.isArray(game?.my_board) ? game.my_board : [];
  board.forEach((value, cell) => {
    if (String(value || '') === 'ship') occupied.add(cell);
  });

  for (const cell of cells) {
    if (occupied.has(cell)) return false;
    const r0 = Math.floor(cell / 10);
    const c0 = cell % 10;
    for (let dr = -1; dr <= 1; dr++) {
      for (let dc = -1; dc <= 1; dc++) {
        const r = r0 + dr;
        const c = c0 + dc;
        if (r < 0 || r >= 10 || c < 0 || c >= 10) continue;
        if (occupied.has(r * 10 + c)) return false;
      }
    }
  }

  return true;
}

export function gameSurfaceFingerprint(game, viewerId = ''){
  if (!game || typeof game !== 'object') return '';
  const copy = clone(game);
  for (const key of [
    'time_left',
    'setup_time_left',
    'updated_at',
    'last_move_at',
    'turn_started_at',
    'setup_deadline_at',
    'bot_move_after_at',
  ]) delete copy[key];
  return `${String(viewerId || '')}|${stableStringify(copy)}`;
}

function playerSymbol(game, viewerId){
  const player = Array.isArray(game?.players)
    ? game.players.find(item => String(item?.id || '') === String(viewerId || ''))
    : null;
  return String(player?.symbol || '');
}

function otherPlayerId(game, viewerId){
  const player = Array.isArray(game?.players)
    ? game.players.find(item => String(item?.id || '') !== String(viewerId || ''))
    : null;
  return String(player?.id || '');
}

function stableStringify(value){
  if (Array.isArray(value)) return `[${value.map(stableStringify).join(',')}]`;
  if (value && typeof value === 'object') {
    return `{${Object.keys(value).sort().map(key => `${JSON.stringify(key)}:${stableStringify(value[key])}`).join(',')}}`;
  }
  return JSON.stringify(value);
}

function clone(value){
  if (typeof structuredClone === 'function') return structuredClone(value);
  return JSON.parse(JSON.stringify(value));
}
