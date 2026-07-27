const FLEET_COUNTS = { 4:1, 3:2, 2:3, 1:4 };

export function buildV102BattleshipSetupOptimistic(game, action){
  const type = String(action?.type || '');
  const next = clone(game);
  const fleet = sanitizeFleet(next?.my_fleet || []);

  if (type === 'place_ship') {
    const size = Number(action?.size);
    const start = Number(action?.cell);
    const orientation = String(action?.orientation || 'h') === 'v' ? 'v' : 'h';
    const cells = shipCells(start, size, orientation);
    if (!cells || !fleetAllowsSize(fleet, size) || !canPlaceCells(cells, fleet)) return null;
    fleet.push(newShip(size, cells, `pending-${start}-${size}-${orientation}`));
    return applyFleet(next, fleet);
  }

  if (type === 'remove_ship') {
    const cell = Number(action?.cell);
    const shipId = String(action?.ship_id || '');
    const index = fleet.findIndex(ship => (shipId && String(ship.id) === shipId) || ship.cells.includes(cell));
    if (index < 0) return null;
    fleet.splice(index, 1);
    return applyFleet(next, fleet);
  }

  if (type === 'clear_fleet') return applyFleet(next, []);

  if (type === 'randomize_fleet') {
    const randomized = sanitizeFleet(action?.ships || []);
    const complete = isCompleteFleet(randomized) ? randomized : createV102RandomFleet();
    return applyFleet(next, complete);
  }

  if (type === 'ready') {
    if (!isCompleteFleet(fleet)) return null;
    next.my_ready = true;
    return applyFleet(next, fleet);
  }

  return null;
}

export function createV102RandomFleet(){
  const sizes = [4,3,3,2,2,2,1,1,1,1];
  for (let attempt = 0; attempt < 24; attempt++) {
    const fleet = [];
    if (placeMissing(fleet, sizes, 0)) return applyStableIds(fleet);
  }
  return fallbackFleet();
}

export function createV102RandomizeAction(){
  return { type:'randomize_fleet', ships:createV102RandomFleet() };
}

export function isCompleteV102Fleet(value){
  return isCompleteFleet(sanitizeFleet(value));
}

function placeMissing(fleet, sizes, index){
  if (index >= sizes.length) return true;
  const size = sizes[index];
  const candidates = shuffled(candidatePlacements(size));
  for (const cells of candidates) {
    if (!canPlaceCells(cells, fleet)) continue;
    fleet.push(newShip(size, cells));
    if (placeMissing(fleet, sizes, index + 1)) return true;
    fleet.pop();
  }
  return false;
}

function candidatePlacements(size){
  const result = [];
  for (const orientation of ['h','v']) {
    for (let cell = 0; cell < 100; cell++) {
      const cells = shipCells(cell, size, orientation);
      if (cells) result.push(cells);
    }
  }
  return result;
}

function shipCells(start, size, orientation){
  if (![1,2,3,4].includes(Number(size)) || !Number.isInteger(Number(start))) return null;
  const row = Math.floor(Number(start) / 10);
  const col = Number(start) % 10;
  const cells = [];
  for (let step = 0; step < Number(size); step++) {
    const r = row + (orientation === 'v' ? step : 0);
    const c = col + (orientation === 'h' ? step : 0);
    if (r < 0 || r >= 10 || c < 0 || c >= 10) return null;
    cells.push(r * 10 + c);
  }
  return cells;
}

function canPlaceCells(cells, fleet){
  const occupied = new Set(fleet.flatMap(ship => ship.cells));
  for (const cell of cells) {
    if (occupied.has(cell)) return false;
    const row = Math.floor(cell / 10);
    const col = cell % 10;
    for (let dr = -1; dr <= 1; dr++) {
      for (let dc = -1; dc <= 1; dc++) {
        const r = row + dr;
        const c = col + dc;
        if (r < 0 || r >= 10 || c < 0 || c >= 10) continue;
        if (occupied.has(r * 10 + c)) return false;
      }
    }
  }
  return true;
}

function fleetAllowsSize(fleet, size){
  const required = FLEET_COUNTS[Number(size)] || 0;
  return required > 0 && fleet.filter(ship => ship.size === Number(size)).length < required;
}

function applyFleet(game, fleet){
  const clean = sanitizeFleet(fleet);
  const counts = countBySize(clean);
  game.my_fleet = clean;
  game.my_board = buildBoard(clean);
  game.fleet_placed = [4,3,2,1].map(size => ({
    size,
    placed:Math.min(FLEET_COUNTS[size], counts[size] || 0),
    required:FLEET_COUNTS[size],
  }));
  game.remaining_to_place = [4,3,2,1]
    .map(size => ({ size, count:Math.max(0, FLEET_COUNTS[size] - (counts[size] || 0)) }))
    .filter(item => item.count > 0);
  return game;
}

function buildBoard(fleet){
  const board = Array(100).fill('water');
  for (const ship of fleet) for (const cell of ship.cells) board[cell] = 'ship';
  return board;
}

function sanitizeFleet(value){
  const result = [];
  for (const raw of Array.isArray(value) ? value : []) {
    const size = Number(raw?.size);
    const cells = [...new Set((raw?.cells || []).map(Number))].sort((a,b) => a-b);
    if (!FLEET_COUNTS[size] || cells.length !== size || cells.some(cell => !Number.isInteger(cell) || cell < 0 || cell >= 100)) continue;
    result.push({
      id:String(raw?.id || `client-${size}-${cells.join('-')}`),
      size,
      cells,
      hits:[],
      sunk:false,
    });
  }
  return result;
}

function isCompleteFleet(fleet){
  if (fleet.length !== 10) return false;
  const counts = countBySize(fleet);
  if ([4,3,2,1].some(size => counts[size] !== FLEET_COUNTS[size])) return false;
  const accepted = [];
  for (const ship of fleet) {
    if (!canPlaceCells(ship.cells, accepted)) return false;
    accepted.push(ship);
  }
  return true;
}

function countBySize(fleet){
  const result = {4:0,3:0,2:0,1:0};
  for (const ship of fleet) result[ship.size] = (result[ship.size] || 0) + 1;
  return result;
}

function newShip(size, cells, id = ''){
  return {
    id:id || `client-${size}-${cells.join('-')}-${randomInt(1_000_000)}`,
    size:Number(size),
    cells:[...cells].sort((a,b) => a-b),
    hits:[],
    sunk:false,
  };
}

function applyStableIds(fleet){
  return fleet.map((ship, index) => ({ ...ship, id:`client-random-${index}-${ship.cells.join('-')}` }));
}

function fallbackFleet(){
  return applyStableIds([
    newShip(4,[0,1,2,3]),
    newShip(3,[20,21,22]),
    newShip(3,[40,50,60]),
    newShip(2,[25,26]),
    newShip(2,[44,45]),
    newShip(2,[68,78]),
    newShip(1,[9]),
    newShip(1,[29]),
    newShip(1,[49]),
    newShip(1,[99]),
  ]);
}

function shuffled(values){
  const result = [...values];
  for (let index = result.length - 1; index > 0; index--) {
    const target = randomInt(index + 1);
    [result[index], result[target]] = [result[target], result[index]];
  }
  return result;
}

function randomInt(limit){
  const safe = Math.max(1, Math.trunc(Number(limit || 1)));
  try {
    const buffer = new Uint32Array(1);
    crypto.getRandomValues(buffer);
    return Number(buffer[0] % safe);
  } catch (error) {
    return Math.floor(Math.random() * safe);
  }
}

function clone(value){
  if (typeof structuredClone === 'function') return structuredClone(value);
  return JSON.parse(JSON.stringify(value));
}
