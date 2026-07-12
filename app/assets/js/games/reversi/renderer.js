import { toast } from '../../components/toast.js?v=41';

let activeGameId = '';
let lastAnimatedMoveKey = '';
let lastAnimatedPassKey = '';

export function renderReversiSurface({ game, me, container, onAction }){
  resetForGame(game);
  container.className = 'board reversi-surface';
  container.dataset.gameType = 'reversi';

  const size = normalizeSize(game?.board_size);
  const board = normalizeBoard(game?.board, size);
  const myId = String(me?.id || '');
  const myTurn = game?.status === 'active' && String(game?.turn || '') === myId;
  const viewerSide = String(game?.viewer_side || sideForPlayer(game, myId));
  const legalMoves = myTurn && Array.isArray(game?.legal_moves) ? game.legal_moves : [];
  const legalByCell = new Map(legalMoves.map(move => [Number(move.cell), move]));
  const lastMoveCell = Number(game?.last_move?.cell ?? -1);
  const lastFlipped = new Set((game?.last_flipped_cells || []).map(Number));
  const moveKey = moveSignature(game);
  const passKey = passSignature(game);
  const freshMove = Boolean(moveKey && moveKey !== lastAnimatedMoveKey);
  const freshPass = Boolean(passKey && passKey !== lastAnimatedPassKey);

  const myCount = viewerSide === 'black' ? Number(game?.black_count || 0) : Number(game?.white_count || 0);
  const enemyCount = viewerSide === 'black' ? Number(game?.white_count || 0) : Number(game?.black_count || 0);
  const myDiscClass = viewerSide === 'black' ? 'black' : 'white';
  const enemyDiscClass = viewerSide === 'black' ? 'white' : 'black';

  container.innerHTML = `
    <div class="reversi-panel">
      <div class="reversi-score-line">
        <span><i class="reversi-mini-disc ${myDiscClass}"></i>Вы <strong>${myCount}</strong></span>
        <b>:</b>
        <span><i class="reversi-mini-disc ${enemyDiscClass}"></i>Соперник <strong>${enemyCount}</strong></span>
      </div>

      ${statusMarkup({ game, me, myTurn, freshPass })}

      <div class="reversi-board" style="--reversi-size:${size}" data-size="${size}">
        ${Array.from({ length:size * size }, (_, cell) => cellMarkup({
          cell,
          size,
          value:board[cell],
          legalMove:legalByCell.get(cell),
          myTurn,
          lastMoveCell,
          lastFlipped,
          freshMove,
        })).join('')}
      </div>

      <div class="reversi-legend">
        <span><i class="available"></i>доступный ход</span>
        <span><i class="last"></i>последний ход</span>
        <span><i class="flip"></i>перевёрнуто</span>
      </div>
    </div>
  `;

  if (freshMove) lastAnimatedMoveKey = moveKey;
  if (freshPass) lastAnimatedPassKey = passKey;

  container.querySelectorAll('[data-reversi-legal]').forEach(button => button.addEventListener('click', () => {
    if (!myTurn || container.classList.contains('is-submitting')) return;
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

function statusMarkup({ game, me, myTurn, freshPass }){
  if (game?.status === 'finished') {
    const black = Number(game?.final_counts?.black ?? game?.black_count ?? 0);
    const white = Number(game?.final_counts?.white ?? game?.white_count ?? 0);
    return `<div class="reversi-event-banner finished">Партия завершена · ● ${black} : ${white} ○</div>`;
  }

  const passedId = String(game?.last_passed_player_id || '');
  if (passedId !== '') {
    const passedMe = passedId === String(me?.id || '');
    return `<div class="reversi-event-banner pass ${freshPass ? 'fresh' : ''}">${passedMe ? 'У вас не было ходов — ход пропущен' : 'У соперника нет ходов — ход снова ваш'}</div>`;
  }

  if (myTurn) return `<div class="reversi-event-banner your-turn">Ваш ход — выберите подсвеченную клетку</div>`;
  return `<div class="reversi-event-banner opponent">Ход соперника — следите за полем</div>`;
}

function cellMarkup({ cell, size, value, legalMove, myTurn, lastMoveCell, lastFlipped, freshMove }){
  const isEmpty = value === '-';
  const isLegal = Boolean(legalMove);
  const isLast = cell === lastMoveCell;
  const wasFlipped = lastFlipped.has(cell);
  const distance = lastMoveCell >= 0
    ? Math.max(Math.abs(Math.floor(cell / size) - Math.floor(lastMoveCell / size)), Math.abs((cell % size) - (lastMoveCell % size)))
    : 0;

  const classes = [
    'reversi-cell',
    isLegal ? 'legal' : '',
    isLast ? 'last-move' : '',
    freshMove && isLast ? 'placed-fresh' : '',
    freshMove && wasFlipped ? 'flipped-fresh' : '',
  ].filter(Boolean).join(' ');

  const attrs = [];
  if (isLegal) attrs.push('data-reversi-legal');
  if (isEmpty) attrs.push('data-reversi-empty');
  const disabled = !isEmpty || (!myTurn && !isLegal) ? 'disabled' : '';
  const style = freshMove && wasFlipped ? `style="--flip-delay:${Math.min(6, distance) * 55}ms"` : '';
  const disc = value === 'B' || value === 'W'
    ? `<span class="reversi-disc ${value === 'B' ? 'black' : 'white'}"></span>`
    : '';
  const flips = Number(legalMove?.flips || 0);
  const hint = isLegal ? `<i class="reversi-move-dot"><b>${flips > 1 ? flips : ''}</b></i>` : '';

  return `<button class="${classes}" data-reversi-cell="${cell}" ${attrs.join(' ')} ${style} ${disabled} type="button" aria-label="${cellLabel(cell, size, value, legalMove)}">${disc}${hint}</button>`;
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
  return Array.from(raw, char => ['B','W','-'].includes(char) ? char : '-');
}

function sideForPlayer(game, playerId){
  const player = (game?.players || []).find(item => String(item?.id || '') === String(playerId || ''));
  return String(player?.side || 'black');
}

function moveSignature(game){
  if (!game?.last_move) return '';
  return [String(game?.id || ''), Number(game?.move_count || 0), Number(game?.last_move?.cell ?? -1), String(game?.last_move?.side || '')].join(':');
}

function passSignature(game){
  const sequence = Number(game?.pass_sequence || 0);
  const playerId = String(game?.last_passed_player_id || '');
  return sequence > 0 && playerId ? [String(game?.id || ''), sequence, playerId].join(':') : '';
}

function resetForGame(game){
  const gameId = String(game?.id || '');
  if (gameId === activeGameId) return;
  activeGameId = gameId;
  lastAnimatedMoveKey = '';
  lastAnimatedPassKey = '';
}

function cellLabel(cell, size, value, legalMove){
  const file = String.fromCharCode(97 + (cell % size));
  const rank = size - Math.floor(cell / size);
  if (legalMove) return `${file}${rank}: поставить фишку и перевернуть ${Number(legalMove.flips || 0)}`;
  if (value === 'B') return `${file}${rank}: чёрная фишка`;
  if (value === 'W') return `${file}${rank}: белая фишка`;
  return `${file}${rank}: пустая клетка`;
}
