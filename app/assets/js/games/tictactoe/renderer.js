export function renderTicTacToeSurface({ game, me, container, onAction }){
  const boardSize = Number(game?.board_size || 3);
  const board = String(game?.board || '');

  container.className = `board size-${boardSize}`;
  container.dataset.gameType = 'tictactoe';
  container.innerHTML = board.split('').map((cell, index) => {
    const isEmpty = cell === '-';
    const canMove = game.status === 'active'
      && String(game.turn) === String(me?.id || '')
      && isEmpty;
    const label = cell === '-' ? '' : (cell === 'X' ? '✕' : '○');

    return `<button class="cell ${cell === 'X' ? 'x' : ''} ${cell === 'O' ? 'o' : ''} ${canMove ? '' : 'locked'}" data-game-cell="${index}" ${canMove ? '' : 'disabled'} type="button">${label}</button>`;
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

export function ticTacToeMeta(game){
  return `${game.room_name} · ${game.bet} коинов · ${game.board_size}×${game.board_size}`;
}

export function ticTacToePlayerMark(player){
  if (player?.symbol === 'X') return '✕';
  if (player?.symbol === 'O') return '○';
  return '•';
}
