export function buildBattleshipSetupOptimistic(game, action){
  const type = String(action?.type || '');
  const next = clone(game);
  const board = Array.from({ length:100 }, (_, index) => String(next?.my_board?.[index] || 'water'));
  const fleet = Array.isArray(next?.my_fleet) ? clone(next.my_fleet) : [];

  if (type === 'place_ship') {
    const size = Number(action?.size);
    const start = Number(action?.cell);
    const orientation = String(action?.orientation || 'h') === 'v' ? 'v' : 'h';
    if (![1,2,3,4].includes(size) || !Number.isInteger(start)) return null;
    const row = Math.floor(start / 10);
    const col = start % 10;
    const cells = [];
    for (let index = 0; index < size; index++) {
      const r = row + (orientation === 'v' ? index : 0);
      const c = col + (orientation === 'h' ? index : 0);
      if (r < 0 || r >= 10 || c < 0 || c >= 10) return null;
      cells.push(r * 10 + c);
    }
    if (!placementIsSeparated(cells, board)) return null;
    cells.forEach(cell => { board[cell] = 'ship'; });
    next.my_board = board;
    next.my_fleet = [...fleet, { id:`pending-${start}-${size}-${orientation}`, size, cells, orientation }];
    return next;
  }

  if (type === 'remove_ship') {
    const cell = Number(action?.cell);
    const ship = fleet.find(item => (item?.cells || []).map(Number).includes(cell));
    if (!ship) return null;
    (ship.cells || []).forEach(index => { board[Number(index)] = 'water'; });
    next.my_board = board;
    next.my_fleet = fleet.filter(item => item !== ship);
    return next;
  }

  if (type === 'clear_fleet') {
    next.my_board = Array(100).fill('water');
    next.my_fleet = [];
    return next;
  }

  if (type === 'ready') {
    next.my_ready = true;
    return next;
  }

  return null;
}

export function pollResultIsCurrent(startGeneration, currentGeneration, pendingActions){
  return Number(startGeneration) === Number(currentGeneration) && !Boolean(pendingActions);
}

function placementIsSeparated(cells, board){
  const candidate = new Set(cells);
  for (const cell of cells) {
    const row = Math.floor(cell / 10);
    const col = cell % 10;
    for (let dr = -1; dr <= 1; dr++) {
      for (let dc = -1; dc <= 1; dc++) {
        const r = row + dr;
        const c = col + dc;
        if (r < 0 || r >= 10 || c < 0 || c >= 10) continue;
        const neighbour = r * 10 + c;
        if (candidate.has(neighbour)) continue;
        if (String(board[neighbour] || '') === 'ship') return false;
      }
    }
  }
  return true;
}

function clone(value){
  if (typeof structuredClone === 'function') return structuredClone(value);
  return JSON.parse(JSON.stringify(value));
}
