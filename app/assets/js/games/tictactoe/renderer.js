const previousBoards = new Map();

export function renderTicTacToeSurface({ game, me, container, onAction }){
  const boardSize = Number(game?.board_size || 3);
  const board = String(game?.board || '');
  const players = Array.isArray(game?.players) ? game.players : [];
  const viewer = players.find(player => String(player?.id || '') === String(me?.id || '')) || null;
  const opponent = players.find(player => String(player?.id || '') !== String(me?.id || '')) || null;
  const playerBySymbol = new Map(players.map(player => [String(player?.symbol || ''), player]));
  const gameKey = String(game?.id || `local:${boardSize}`);
  const changedCell = changedCellIndex(previousBoards.get(gameKey), board);
  previousBoards.set(gameKey, board);

  const winner = players.find(player => String(player?.id || '') === String(game?.winner_id || '')) || null;
  const winnerSymbol = String(winner?.symbol || '');
  const winningCells = String(game?.status || '') === 'finished' && winnerSymbol
    ? new Set(findWinningCells(board, boardSize, winnerSymbol))
    : new Set();
  const winningLineEnabled = hasEquipped(winner, 'game_tictactoe_effect_winning_line');
  const strikeEnabled = hasEquipped(winner, 'game_tictactoe_effect_strike_through');
  const cosmeticsVisible = players.some(player => Object.keys(equippedSlots(player)).length > 0);

  container.className = `board size-${boardSize}${cosmeticsVisible ? ' ttt-cosmetics' : ''}`;
  container.dataset.gameType = 'tictactoe';
  container.dataset.tttTheme = variantFor(viewer, 'game_tictactoe_theme', 'field');
  container.dataset.tttOpponentTheme = variantFor(opponent, 'game_tictactoe_theme', 'field');
  container.innerHTML = board.split('').map((cell, index) => {
    const isEmpty = cell === '-';
    const owner = playerBySymbol.get(cell) || null;
    const canMove = game.status === 'active'
      && String(game.turn) === String(me?.id || '')
      && isEmpty;
    const label = cell === '-' ? '' : (cell === 'X' ? '✕' : '○');
    const markVariant = variantFor(owner, 'game_tictactoe_elements', 'marks');
    const signEffect = index === changedCell && hasEquipped(owner, 'game_tictactoe_effect_sign');
    const winning = winningLineEnabled && winningCells.has(index);
    const struck = strikeEnabled && winnerSymbol && cell !== '-' && cell !== winnerSymbol;
    const classes = [
      'cell',
      cell === 'X' ? 'x' : (cell === 'O' ? 'o' : ''),
      canMove ? '' : 'locked',
      signEffect ? 'ttt-sign-effect' : '',
      winning ? 'ttt-winning-cell' : '',
      struck ? 'ttt-struck-cell' : '',
    ].filter(Boolean).join(' ');

    return `<button class="${classes}" data-game-cell="${index}" data-ttt-mark="${markVariant}" ${canMove ? '' : 'disabled'} type="button" aria-label="${label}">${label}</button>`;
  }).join('');

  container.querySelectorAll('[data-game-cell]').forEach(button => {
    button.addEventListener('click', () => {
      onAction?.({
        type: 'cell',
        cell: Number(button.dataset.gameCell),
      });
    });
  });
}

function equippedSlots(player){
  const slots = player?.game_cosmetics?.slots;
  return slots && typeof slots === 'object' ? slots : {};
}

function hasEquipped(player, slot){
  return String(equippedSlots(player)[slot] || '') !== '';
}

function variantFor(player, slot, family){
  const itemId = String(equippedSlots(player)[slot] || '');
  if (!itemId) return 'base';
  const marker = `game-ttt-${family}-`;
  return itemId.startsWith(marker) ? itemId.slice(marker.length) : 'base';
}

function changedCellIndex(previous, current){
  if (typeof previous !== 'string' || previous.length !== current.length) return -1;
  let changed = -1;
  for (let index = 0; index < current.length; index++) {
    if (previous[index] === current[index]) continue;
    if (changed !== -1) return -1;
    changed = index;
  }
  return changed;
}

function findWinningCells(board, size, symbol){
  const need = size === 3 ? 3 : (size === 5 ? 4 : 5);
  const directions = [[1,0],[0,1],[1,1],[1,-1]];
  for (let row = 0; row < size; row++) {
    for (let column = 0; column < size; column++) {
      if (board[row * size + column] !== symbol) continue;
      for (const [dr, dc] of directions) {
        const cells = [];
        for (let step = 0; step < need; step++) {
          const nextRow = row + dr * step;
          const nextColumn = column + dc * step;
          if (nextRow < 0 || nextRow >= size || nextColumn < 0 || nextColumn >= size) break;
          const cell = nextRow * size + nextColumn;
          if (board[cell] !== symbol) break;
          cells.push(cell);
        }
        if (cells.length === need) return cells;
      }
    }
  }
  return [];
}

export function ticTacToeMeta(game){
  return `${game.room_name} · ${game.bet} коинов · ${game.board_size}×${game.board_size}`;
}

export function ticTacToePlayerMark(player){
  if (player?.symbol === 'X') return '✕';
  if (player?.symbol === 'O') return '◯';
  return '•';
}
