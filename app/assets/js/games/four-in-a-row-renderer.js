export function renderFourInARowSurface({ game, me, container, onAction }){
  const columns = Math.max(4, Number(game?.board_columns || game?.board_size || 7));
  const rows = Math.max(4, Number(game?.board_rows || Math.max(5, columns - 1)));
  const expectedCells = columns * rows;
  const board = String(game?.board || '').padEnd(expectedCells, '-').slice(0, expectedCells);
  const winning = new Set((game?.winning_cells || []).map(Number));
  const lastMove = game?.last_move !== null && game?.last_move !== undefined && Number.isInteger(Number(game.last_move))
    ? Number(game.last_move)
    : -1;
  const myTurn = game?.status === 'active' && String(game?.turn || '') === String(me?.id || '');

  const cells = board.split('').map((value, index) => {
    const tokenClass = value === 'Y' ? 'yellow' : (value === 'R' ? 'red' : 'empty');
    const classes = [
      'four-disc-slot',
      tokenClass,
      winning.has(index) ? 'winning' : '',
      index === lastMove ? 'last-move' : '',
    ].filter(Boolean).join(' ');

    return `<div class="${classes}" role="gridcell" aria-label="${discLabel(value)}"><span></span></div>`;
  }).join('');

  const controls = Array.from({ length: columns }, (_, column) => {
    const full = board[column] !== '-';
    const enabled = myTurn && !full;
    return `<button class="four-column-hit ${enabled ? '' : 'locked'}" data-four-column="${column}" type="button" ${enabled ? '' : 'disabled'} aria-label="Бросить фишку в столбец ${column + 1}"></button>`;
  }).join('');

  container.className = 'board four-in-a-row-board';
  container.dataset.gameType = 'four_in_a_row';
  container.innerHTML = `
    <div
      class="four-board-frame"
      style="--four-columns:${columns};--four-rows:${rows}"
      role="grid"
      aria-label="Поле игры 4 в ряд ${columns} на ${rows}"
    >
      <div class="four-disc-grid">${cells}</div>
      <div class="four-column-controls">${controls}</div>
    </div>
  `;

  container.querySelectorAll('[data-four-column]').forEach(button => {
    button.addEventListener('click', () => {
      onAction?.({
        type: 'column',
        column: Number(button.dataset.fourColumn),
      });
    });
  });
}

export function fourInARowMeta(game){
  const columns = Number(game?.board_columns || game?.board_size || 7);
  const rows = Number(game?.board_rows || Math.max(5, columns - 1));
  return `${game.room_name} · ${game.bet} коинов · ${columns}×${rows}`;
}

export function fourInARowPlayerMark(player){
  if (player?.symbol === 'Y') return '🟡';
  if (player?.symbol === 'R') return '🔴';
  return '●';
}

function discLabel(value){
  if (value === 'Y') return 'Жёлтая фишка';
  if (value === 'R') return 'Красная фишка';
  return 'Пустая клетка';
}
