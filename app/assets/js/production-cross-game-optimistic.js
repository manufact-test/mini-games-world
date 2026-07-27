export function buildOptimisticGame(game, action, viewerId, type){
  if (!viewerId || String(game?.turn || '') !== viewerId || String(game?.status || '') !== 'active') return null;

  const optimistic = clone(game);
  const nextPlayerId = otherPlayerId(optimistic, viewerId);

  if (type === 'four_in_a_row') {
    const columns = Number(optimistic.board_columns || optimistic.board_size || 7);
    const rows = Number(optimistic.board_rows || Math.max(5, columns - 1));
    const column = Number(action?.column);
    const board = String(optimistic.board || '').padEnd(columns * rows, '-').slice(0, columns * rows).split('');
    if (!Number.isInteger(column) || column < 0 || column >= columns) return null;
    let target = -1;
    for (let row = rows - 1; row >= 0; row--) {
      const cell = row * columns + column;
      if (board[cell] === '-') { target = cell; break; }
    }
    if (target < 0) return null;
    const symbol = playerSymbol(optimistic, viewerId) || 'R';
    board[target] = symbol;
    optimistic.board = board.join('');
    optimistic.last_move = target;
    optimistic.move_count = Number(optimistic.move_count || 0) + 1;
    optimistic.turn = nextPlayerId;
    optimistic.time_left = Number(optimistic.move_timeout_sec || 60);
    return optimistic;
  }

  if (type === 'checkers') {
    return buildOptimisticCheckers(optimistic, action, viewerId, nextPlayerId);
  }

  if (type === 'reversi') {
    const size = Number(optimistic.board_size || 8);
    const cell = Number(action?.cell);
    const board = String(optimistic.board || '').split('');
    const symbol = playerSymbol(optimistic, viewerId) || (String(optimistic.viewer_side || '') === 'white' ? 'W' : 'B');
    const enemy = symbol === 'B' ? 'W' : 'B';
    const flips = reversiFlips(board, size, cell, symbol, enemy);
    if (!Number.isInteger(cell) || board[cell] !== '-' || flips.length === 0) return null;
    board[cell] = symbol;
    flips.forEach(index => { board[index] = symbol; });
    optimistic.board = board.join('');
    optimistic.last_move = { cell, player_id:viewerId, side:symbol === 'B' ? 'black' : 'white', flipped:flips.length };
    optimistic.last_flipped_cells = flips;
    optimistic.move_count = Number(optimistic.move_count || 0) + 1;
    optimistic.legal_moves = [];
    optimistic.turn = nextPlayerId;
    optimistic.time_left = Number(optimistic.move_timeout_sec || 60);
    updateReversiCounts(optimistic);
    return optimistic;
  }

  if (type === 'chess') {
    const from = Number(action?.from);
    const to = Number(action?.to);
    const legal = (optimistic.legal_moves || []).find(move =>
      Number(move?.from) === from
      && Number(move?.to) === to
      && (!move?.promotion_required || String(move?.promotion || '') === String(action?.promotion || ''))
    );
    const board = Array.isArray(optimistic.board) ? [...optimistic.board] : null;
    if (!legal || !board || !board[from]) return null;
    let piece = String(board[from]);
    board[from] = '';
    if (legal.en_passant) {
      const side = piece.startsWith('w') ? 'white' : 'black';
      const captured = to + (side === 'white' ? 8 : -8);
      if (captured >= 0 && captured < 64) board[captured] = '';
    }
    const promotion = String(action?.promotion || legal.promotion || '');
    if (promotion) piece = `${piece[0]}${promotion.toUpperCase()}`;
    board[to] = piece;
    if (String(legal.castle || '') === 'king') {
      const white = piece.startsWith('w');
      const rookFrom = white ? 63 : 7;
      const rookTo = white ? 61 : 5;
      board[rookTo] = board[rookFrom];
      board[rookFrom] = '';
    }
    if (String(legal.castle || '') === 'queen') {
      const white = piece.startsWith('w');
      const rookFrom = white ? 56 : 0;
      const rookTo = white ? 59 : 3;
      board[rookTo] = board[rookFrom];
      board[rookFrom] = '';
    }
    optimistic.board = board;
    optimistic.last_move = { from, to, player_id:viewerId, castle:String(legal.castle || ''), promotion };
    optimistic.move_count = Number(optimistic.move_count || 0) + 1;
    optimistic.legal_moves = [];
    optimistic.turn = nextPlayerId;
    optimistic.in_check = false;
    optimistic.checked_side = null;
    optimistic.time_left = Number(optimistic.move_timeout_sec || 60);
    return optimistic;
  }

  if (type === 'go') {
    if (String(action?.type || '') === 'pass') {
      optimistic.last_move = { type:'pass', player_id:viewerId };
      optimistic.last_passed_player_id = viewerId;
      optimistic.pass_sequence = Number(optimistic.pass_sequence || 0) + 1;
      optimistic.turn = nextPlayerId;
      optimistic.time_left = Number(optimistic.move_timeout_sec || 60);
      return optimistic;
    }
    const cell = Number(action?.cell);
    const board = String(optimistic.board || '').split('');
    const symbol = playerSymbol(optimistic, viewerId) || (String(optimistic.viewer_side || '') === 'white' ? 'W' : 'B');
    if (!Number.isInteger(cell) || board[cell] !== '-') return null;
    board[cell] = symbol;
    optimistic.board = board.join('');
    optimistic.last_move = { type:'place', cell, player_id:viewerId, captured:0 };
    optimistic.last_captured_cells = [];
    optimistic.move_count = Number(optimistic.move_count || 0) + 1;
    optimistic.turn = nextPlayerId;
    optimistic.time_left = Number(optimistic.move_timeout_sec || 60);
    return optimistic;
  }

  if (type === 'domino') {
    const actionType = String(action?.type || '');
    if (actionType === 'draw') {
      optimistic.last_action = { type:'draw', player_id:viewerId, drawn_count:1 };
      return optimistic;
    }
    if (actionType !== 'play') return null;
    const tileId = String(action?.tile || '');
    const side = String(action?.side || '');
    const tile = (optimistic.viewer_hand || []).find(item => String(item?.id || '') === tileId);
    if (!tile || !['left','right'].includes(side)) return null;
    let left = Number(tile.a || 0);
    let right = Number(tile.b || 0);
    if (side === 'left') {
      if (left === Number(optimistic.open_left)) [left, right] = [right, left];
    } else if (right === Number(optimistic.open_right)) {
      [left, right] = [right, left];
    }
    optimistic.viewer_hand = (optimistic.viewer_hand || []).filter(item => String(item?.id || '') !== tileId);
    const chainItem = {
      tile:tileId,
      left,
      right,
      player_id:viewerId,
      move_number:Number(optimistic.move_count || 0) + 1,
    };
    optimistic.chain = side === 'left'
      ? [chainItem, ...(optimistic.chain || [])]
      : [...(optimistic.chain || []), chainItem];
    if (side === 'left') optimistic.open_left = left;
    else optimistic.open_right = right;
    optimistic.move_count = Number(optimistic.move_count || 0) + 1;
    optimistic.last_action = { type:'play', player_id:viewerId, tile:tileId, side };
    optimistic.playable_sides = {};
    optimistic.turn = nextPlayerId;
    optimistic.time_left = Number(optimistic.move_timeout_sec || 60);
    return optimistic;
  }

  if (type === 'battleship') {
    const actionType = String(action?.type || '');
    if (actionType === 'place_ship') {
      const board = Array.from({ length:100 }, (_, index) => String(optimistic.my_board?.[index] || 'water'));
      const size = Number(action?.size || 1);
      const start = Number(action?.cell);
      const step = String(action?.orientation || 'h') === 'v' ? 10 : 1;
      if (!Number.isInteger(start)) return null;
      const cells = Array.from({ length:size }, (_, index) => start + index * step);
      if (cells.some(cell => cell < 0 || cell >= 100)) return null;
      cells.forEach(cell => { board[cell] = 'ship'; });
      optimistic.my_board = board;
      optimistic.my_fleet = [...(optimistic.my_fleet || []), { size, cells }];
      return optimistic;
    }
    if (actionType === 'remove_ship') {
      const cell = Number(action?.cell);
      const fleet = Array.isArray(optimistic.my_fleet) ? optimistic.my_fleet : [];
      const ship = fleet.find(item => (item.cells || []).map(Number).includes(cell));
      if (!ship) return null;
      const board = Array.from({ length:100 }, (_, index) => String(optimistic.my_board?.[index] || 'water'));
      (ship.cells || []).forEach(index => { board[Number(index)] = 'water'; });
      optimistic.my_board = board;
      optimistic.my_fleet = fleet.filter(item => item !== ship);
      return optimistic;
    }
    if (actionType === 'clear_fleet') {
      optimistic.my_board = Array(100).fill('water');
      optimistic.my_fleet = [];
      return optimistic;
    }
    if (actionType === 'fire') {
      const cell = Number(action?.cell);
      const board = Array.from({ length:100 }, (_, index) => String(optimistic.enemy_board?.[index] || 'unknown'));
      if (!Number.isInteger(cell) || board[cell] !== 'unknown') return null;
      optimistic.pending_fire_cell = cell;
      optimistic.enemy_board = board;
      return optimistic;
    }
    if (['randomize_fleet','ready'].includes(actionType)) return optimistic;
  }

  return null;
}

function buildOptimisticCheckers(optimistic, action, viewerId, nextPlayerId){
  const from = Number(action?.from);
  const to = Number(action?.to);
  const legal = (optimistic.legal_moves || []).find(move => Number(move?.from) === from && Number(move?.to) === to);
  const board = Array.isArray(optimistic.board) ? [...optimistic.board] : null;
  if (!legal || !board || !Number.isInteger(from) || !Number.isInteger(to)) return null;

  let piece = String(board[from] || '');
  if (!piece) return null;
  const side = piece.toLowerCase() === 'w' ? 'white' : 'black';
  const pending = [...new Set((optimistic.pending_captures || []).map(Number).filter(Number.isInteger))];
  const captured = legal.capture ? Number(legal.captured ?? -1) : -1;

  board[from] = '';
  if (piece === 'w' && Math.floor(to / 8) === 0) piece = 'W';
  if (piece === 'b' && Math.floor(to / 8) === 7) piece = 'B';
  board[to] = piece;

  if (captured >= 0 && !pending.includes(captured)) pending.push(captured);

  optimistic.board = board;
  optimistic.last_move = {
    from,
    to,
    capture:captured >= 0,
    captured:captured >= 0 ? captured : null,
    player_id:viewerId,
    promoted:piece === 'W' || piece === 'B',
  };
  optimistic.last_promotion = piece === 'W' || piece === 'B' ? to : null;
  optimistic.time_left = Number(optimistic.move_timeout_sec || 60);

  if (captured >= 0) {
    const continuations = checkersCaptureMoves(board, to, side, pending);
    if (continuations.length) {
      optimistic.turn = viewerId;
      optimistic.forced_piece = to;
      optimistic.capture_required = true;
      optimistic.pending_captures = pending;
      optimistic.last_captured_cells = [];
      optimistic.legal_moves = continuations;
      optimistic.last_move.chain_continues = true;
      updateCheckersCounts(optimistic, board);
      return optimistic;
    }
  }

  pending.forEach(cell => {
    if (cell >= 0 && cell < board.length) board[cell] = '';
  });
  optimistic.board = board;
  optimistic.turn = nextPlayerId;
  optimistic.forced_piece = null;
  optimistic.capture_required = false;
  optimistic.pending_captures = [];
  optimistic.last_captured_cells = pending;
  optimistic.legal_moves = [];
  optimistic.last_move.chain_continues = false;
  updateCheckersCounts(optimistic, board);
  return optimistic;
}

function checkersCaptureMoves(board, cell, side, pendingCaptures){
  const piece = String(board[cell] || '');
  if (!belongsToCheckersSide(piece, side)) return [];

  const row = Math.floor(cell / 8);
  const col = cell % 8;
  const moves = [];

  if (piece === 'W' || piece === 'B') {
    for (const [dr, dc] of [[-1,-1],[-1,1],[1,-1],[1,1]]) {
      let r = row + dr;
      let c = col + dc;
      let enemyCell = null;
      while (insideCheckers(r, c)) {
        const index = r * 8 + c;
        const occupant = String(board[index] || '');
        if (occupant === '') {
          if (enemyCell !== null) {
            moves.push({ from:cell, to:index, capture:true, captured:enemyCell, promotes:false });
          }
          r += dr;
          c += dc;
          continue;
        }
        if (enemyCell !== null) break;
        if (belongsToCheckersSide(occupant, side)) break;
        if (pendingCaptures.includes(index)) break;
        enemyCell = index;
        r += dr;
        c += dc;
      }
    }
    return moves;
  }

  for (const [dr, dc] of [[-1,-1],[-1,1],[1,-1],[1,1]]) {
    const enemyRow = row + dr;
    const enemyCol = col + dc;
    const landRow = row + dr * 2;
    const landCol = col + dc * 2;
    if (!insideCheckers(enemyRow, enemyCol) || !insideCheckers(landRow, landCol)) continue;

    const enemyCell = enemyRow * 8 + enemyCol;
    const landingCell = landRow * 8 + landCol;
    const enemyPiece = String(board[enemyCell] || '');
    if (!enemyPiece || belongsToCheckersSide(enemyPiece, side)) continue;
    if (pendingCaptures.includes(enemyCell)) continue;
    if (String(board[landingCell] || '') !== '') continue;

    moves.push({
      from:cell,
      to:landingCell,
      capture:true,
      captured:enemyCell,
      promotes:(side === 'white' && landRow === 0) || (side === 'black' && landRow === 7),
    });
  }

  return moves;
}

function updateCheckersCounts(game, board){
  game.white_pieces = board.filter(piece => String(piece).toLowerCase() === 'w').length;
  game.black_pieces = board.filter(piece => String(piece).toLowerCase() === 'b').length;
  game.white_kings = board.filter(piece => piece === 'W').length;
  game.black_kings = board.filter(piece => piece === 'B').length;
}

function belongsToCheckersSide(piece, side){
  if (!piece) return false;
  return side === 'white' ? piece.toLowerCase() === 'w' : piece.toLowerCase() === 'b';
}

function insideCheckers(row, col){
  return row >= 0 && row < 8 && col >= 0 && col < 8;
}

function reversiFlips(board, size, cell, symbol, enemy){
  if (!Number.isInteger(cell) || cell < 0 || cell >= size * size || board[cell] !== '-') return [];
  const row = Math.floor(cell / size);
  const col = cell % size;
  const result = [];
  const directions = [-1,0,1].flatMap(dr => [-1,0,1].map(dc => [dr,dc])).filter(([dr,dc]) => dr || dc);
  for (const [dr, dc] of directions) {
    const line = [];
    let r = row + dr;
    let c = col + dc;
    while (r >= 0 && r < size && c >= 0 && c < size && board[r * size + c] === enemy) {
      line.push(r * size + c);
      r += dr;
      c += dc;
    }
    if (line.length && r >= 0 && r < size && c >= 0 && c < size && board[r * size + c] === symbol) {
      result.push(...line);
    }
  }
  return [...new Set(result)];
}

function updateReversiCounts(game){
  const board = String(game.board || '');
  game.black_count = board.split('B').length - 1;
  game.white_count = board.split('W').length - 1;
}

function playerSymbol(game, playerId){
  return String((game?.players || []).find(player => String(player?.id || '') === String(playerId || ''))?.symbol || '');
}

function otherPlayerId(game, playerId){
  return String((game?.players || []).find(player => String(player?.id || '') !== String(playerId || ''))?.id || '');
}

function clone(value){
  if (typeof structuredClone === 'function') return structuredClone(value);
  return JSON.parse(JSON.stringify(value));
}
