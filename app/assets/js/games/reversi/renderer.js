import { toast } from '../../components/toast.js?v=41';

let activeGameId = '';
let previousBoard = '';
let previousMoveCount = -1;
let activeAnimationKey = '';
let animationToken = 0;
let animationTimers = [];

export function renderReversiSurface({ game, me, container, onAction }){
  resetForGame(game);

  const size = normalizeSize(game?.board_size);
  const finalBoard = normalizeBoard(game?.board, size);
  const moveCount = Number(game?.move_count || 0);
  const moveKey = moveSignature(game);

  if (activeAnimationKey && moveKey === activeAnimationKey) return;
  if (activeAnimationKey && moveKey !== activeAnimationKey) cancelAnimation();

  if (shouldAnimateMove(game, finalBoard, size, moveCount)) {
    const fromBoard = previousBoard;
    previousBoard = finalBoard;
    previousMoveCount = moveCount;
    animateMove({ game, me, container, onAction, size, fromBoard, finalBoard, moveKey });
    return;
  }

  previousBoard = finalBoard;
  previousMoveCount = moveCount;
  renderSurface({ game, me, container, onAction, size, board:finalBoard, interactive:true, animating:false });
}

export function reversiMeta(game){
  const room = String(game?.room_name || 'Игра');
  const bet = Number(game?.bet || 0);
  const size = normalizeSize(game?.board_size);
  return `${room} · ${bet} коинов · ${size}×${size}`;
}

export function reversiPlayerMark(player){
  return String(player?.side || '') === 'black' ? '● чёрные' : '○ белые';
}

export function reversiStatus(game, me){
  if (game?.status === 'finished') return finalStatus(game, me);
  if (String(game?.turn || '') === String(me?.id || '')) {
    if (String(game?.last_passed_player_id || '') !== '') return 'Ход соперника пропущен — ваш ход';
    return 'Ваш ход';
  }
  return 'Ход соперника';
}

function shouldAnimateMove(game, finalBoard, size, moveCount){
  const lastCell = Number(game?.last_move?.cell ?? -1);
  const flipped = Array.isArray(game?.last_flipped_cells) ? game.last_flipped_cells.map(Number) : [];
  if (!activeGameId || previousBoard.length !== size * size) return false;
  if (moveCount !== previousMoveCount + 1) return false;
  if (lastCell < 0 || lastCell >= size * size || flipped.length === 0) return false;
  if (previousBoard[lastCell] !== '-' || !['B','W'].includes(finalBoard[lastCell])) return false;
  return flipped.every(cell => cell >= 0 && cell < size * size && previousBoard[cell] !== finalBoard[cell]);
}

function animateMove({ game, me, container, onAction, size, fromBoard, finalBoard, moveKey }){
  cancelAnimation();
  activeAnimationKey = moveKey;
  const token = ++animationToken;
  const lastCell = Number(game?.last_move?.cell ?? -1);
  const placedSymbol = finalBoard[lastCell];
  const displayBoard = Array.from(fromBoard);
  displayBoard[lastCell] = placedSymbol;

  const orderedFlips = [...new Set((game?.last_flipped_cells || []).map(Number))]
    .filter(cell => cell >= 0 && cell < size * size)
    .sort((a, b) => distanceFrom(a, lastCell, size) - distanceFrom(b, lastCell, size));

  renderSurface({
    game,
    me,
    container,
    onAction,
    size,
    board:displayBoard.join(''),
    interactive:false,
    animating:true,
    placedCell:lastCell,
  });

  const firstFlipDelay = 320;
  const flipStep = 150;

  orderedFlips.forEach((cell, index) => {
    schedule(() => {
      if (token !== animationToken) return;
      animateSingleFlip(container, cell, finalBoard[cell], () => {
        displayBoard[cell] = finalBoard[cell];
        updateScore(container, displayBoard.join(''), String(game?.viewer_side || sideForPlayer(game, me?.id)));
      });
    }, firstFlipDelay + index * flipStep);
  });

  const totalDelay = firstFlipDelay + orderedFlips.length * flipStep + 260;
  schedule(() => {
    if (token !== animationToken) return;
    activeAnimationKey = '';
    renderSurface({ game, me, container, onAction, size, board:finalBoard, interactive:true, animating:false });
  }, totalDelay);
}

function renderSurface({ game, me, container, onAction, size, board, interactive, animating, placedCell = -1 }){
  container.className = `board reversi-surface${animating ? ' is-animating' : ''}`;
  container.dataset.gameType = 'reversi';

  const myId = String(me?.id || '');
  const myTurn = interactive && game?.status === 'active' && String(game?.turn || '') === myId;
  const viewerSide = String(game?.viewer_side || sideForPlayer(game, myId));
  const legalMoves = myTurn && Array.isArray(game?.legal_moves) ? game.legal_moves : [];
  const legalByCell = new Map(legalMoves.map(move => [Number(move.cell), move]));
  const lastMoveCell = Number(game?.last_move?.cell ?? -1);
  const counts = boardCounts(board, viewerSide);
  const myDiscClass = viewerSide === 'black' ? 'black' : 'white';
  const enemyDiscClass = viewerSide === 'black' ? 'white' : 'black';

  container.innerHTML = `
    <div class="reversi-panel">
      <div class="reversi-score-line">
        <span><i class="reversi-mini-disc ${myDiscClass}"></i>Вы <strong data-reversi-my-count>${counts.mine}</strong></span>
        <b>:</b>
        <span><i class="reversi-mini-disc ${enemyDiscClass}"></i>Соперник <strong data-reversi-enemy-count>${counts.enemy}</strong></span>
      </div>

      ${statusMarkup({ game, me, myTurn, animating })}

      <div class="reversi-board" style="--reversi-size:${size}" data-size="${size}">
        ${Array.from({ length:size * size }, (_, cell) => cellMarkup({
          cell,
          size,
          value:board[cell],
          legalMove:legalByCell.get(cell),
          myTurn,
          lastMoveCell,
          placedCell,
        })).join('')}
      </div>

      <div class="reversi-legend">
        <span><i class="available"></i>доступный ход</span>
        <span><i class="last"></i>последний ход</span>
      </div>
    </div>
  `;

  container.querySelectorAll('[data-reversi-legal]').forEach(button => button.addEventListener('click', () => {
    if (!myTurn || container.classList.contains('is-submitting') || container.classList.contains('is-animating')) return;
    const cell = Number(button.dataset.reversiCell);
    if (!legalByCell.has(cell)) return;
    container.classList.add('is-submitting');
    onAction?.({ type:'cell', cell });
  }));

  container.querySelectorAll('[data-reversi-empty]').forEach(button => button.addEventListener('click', () => {
    if (!myTurn || legalByCell.has(Number(button.dataset.reversiCell))) return;
    toast('Здесь нельзя поставить фишку. Выберите подсвеченную клетку.');
  }));
}

function statusMarkup({ game, me, myTurn, animating }){
  if (animating) {
    const movedByMe = String(game?.last_move?.player_id || '') === String(me?.id || '');
    return `<div class="reversi-event-banner move-sequence">${movedByMe ? 'Ваш ход — переворачиваем фишки' : 'Соперник сделал ход — переворачиваем фишки'}</div>`;
  }

  if (game?.status === 'finished') {
    const black = Number(game?.final_counts?.black ?? game?.black_count ?? 0);
    const white = Number(game?.final_counts?.white ?? game?.white_count ?? 0);
    return `<div class="reversi-event-banner finished">Партия завершена · ● ${black} : ${white} ○</div>`;
  }

  const passedId = String(game?.last_passed_player_id || '');
  if (passedId !== '') {
    const passedMe = passedId === String(me?.id || '');
    return `<div class="reversi-event-banner pass">${passedMe ? 'У вас не было ходов — ход пропущен' : 'У соперника нет ходов — ход снова ваш'}</div>`;
  }

  if (myTurn) return `<div class="reversi-event-banner your-turn">Ваш ход — выберите подсвеченную клетку</div>`;
  return `<div class="reversi-event-banner opponent">Ход соперника — следите за полем</div>`;
}

function cellMarkup({ cell, size, value, legalMove, myTurn, lastMoveCell, placedCell }){
  const isEmpty = value === '-';
  const isLegal = Boolean(legalMove);
  const isLast = cell === lastMoveCell;
  const isPlaced = cell === placedCell;
  const classes = [
    'reversi-cell',
    isLegal ? 'legal' : '',
    isLast ? 'last-move' : '',
    isPlaced ? 'placed-fresh' : '',
  ].filter(Boolean).join(' ');

  const attrs = [];
  if (isLegal) attrs.push('data-reversi-legal');
  if (isEmpty) attrs.push('data-reversi-empty');
  const disabled = !isEmpty || !myTurn ? 'disabled' : '';
  const disc = value === 'B' || value === 'W'
    ? `<span class="reversi-disc ${value === 'B' ? 'black' : 'white'}"></span>`
    : '';
  const hint = isLegal ? '<i class="reversi-move-dot"></i>' : '';

  return `<button class="${classes}" data-reversi-cell="${cell}" ${attrs.join(' ')} ${disabled} type="button" aria-label="${cellLabel(cell, size, value, legalMove)}">${disc}${hint}</button>`;
}

function animateSingleFlip(container, cell, finalSymbol, onMidpoint){
  const cellElement = container.querySelector(`[data-reversi-cell="${cell}"]`);
  const disc = cellElement?.querySelector('.reversi-disc');
  if (!cellElement || !disc) return;

  cellElement.classList.remove('flip-in');
  cellElement.classList.add('flip-out');

  schedule(() => {
    disc.className = `reversi-disc ${finalSymbol === 'B' ? 'black' : 'white'}`;
    cellElement.classList.remove('flip-out');
    cellElement.classList.add('flip-in');
    onMidpoint?.();
  }, 105);

  schedule(() => cellElement.classList.remove('flip-in'), 255);
}

function updateScore(container, board, viewerSide){
  const counts = boardCounts(board, viewerSide);
  const mine = container.querySelector('[data-reversi-my-count]');
  const enemy = container.querySelector('[data-reversi-enemy-count]');
  if (mine) mine.textContent = String(counts.mine);
  if (enemy) enemy.textContent = String(counts.enemy);
}

function boardCounts(board, viewerSide){
  const black = String(board || '').split('B').length - 1;
  const white = String(board || '').split('W').length - 1;
  return viewerSide === 'black'
    ? { mine:black, enemy:white }
    : { mine:white, enemy:black };
}

function finalStatus(game, me){
  const winnerId = String(game?.winner_id || '');
  if (!winnerId) return 'Ничья';
  return winnerId === String(me?.id || '') ? 'Победа' : 'Поражение';
}

function normalizeSize(value){
  const size = Number(value);
  return [6,8,10].includes(size) ? size : 8;
}

function normalizeBoard(value, size){
  const raw = typeof value === 'string' ? value : '';
  if (raw.length !== size * size) return '-'.repeat(size * size);
  return Array.from(raw, char => ['B','W','-'].includes(char) ? char : '-').join('');
}

function sideForPlayer(game, playerId){
  const player = (game?.players || []).find(item => String(item?.id || '') === String(playerId || ''));
  return String(player?.side || 'black');
}

function moveSignature(game){
  if (!game?.last_move) return '';
  return [String(game?.id || ''), Number(game?.move_count || 0), Number(game?.last_move?.cell ?? -1), String(game?.last_move?.side || '')].join(':');
}

function distanceFrom(cell, origin, size){
  return Math.max(
    Math.abs(Math.floor(cell / size) - Math.floor(origin / size)),
    Math.abs((cell % size) - (origin % size)),
  );
}

function schedule(callback, delay){
  const timer = window.setTimeout(callback, delay);
  animationTimers.push(timer);
  return timer;
}

function cancelAnimation(){
  animationTimers.forEach(timer => window.clearTimeout(timer));
  animationTimers = [];
  activeAnimationKey = '';
  animationToken += 1;
}

function resetForGame(game){
  const gameId = String(game?.id || '');
  if (gameId === activeGameId) return;
  cancelAnimation();
  activeGameId = gameId;
  previousBoard = '';
  previousMoveCount = -1;
}

function cellLabel(cell, size, value, legalMove){
  const file = String.fromCharCode(97 + (cell % size));
  const rank = size - Math.floor(cell / size);
  if (legalMove) return `${file}${rank}: поставить фишку`;
  if (value === 'B') return `${file}${rank}: чёрная фишка`;
  if (value === 'W') return `${file}${rank}: белая фишка`;
  return `${file}${rank}: пустая клетка`;
}
