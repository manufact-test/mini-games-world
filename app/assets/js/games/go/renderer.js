import { toast } from '../../components/toast.js?v=41';
import { openSheet, closeSheet } from '../../components/sheet.js?v=68';

let activeGameId = '';
let previousBoard = '';
let previousMoveCount = -1;
let activeAnimationKey = '';
let animationToken = 0;
let animationTimers = [];

export function renderGoSurface({ game, me, container, onAction }){
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

export function goMeta(game){
  const room = String(game?.room_name || 'Игра');
  const bet = Number(game?.bet || 0);
  const size = normalizeSize(game?.board_size);
  return `${room} · ${bet} коинов · ${size}×${size}`;
}

export function goPlayerMark(player){
  return String(player?.side || '') === 'black' ? '● чёрные' : '○ белые';
}

export function goStatus(game, me){
  if (game?.status === 'finished') return finalStatus(game, me);
  const myTurn = String(game?.turn || '') === String(me?.id || '');
  const passedId = String(game?.last_passed_player_id || '');
  if (myTurn && passedId !== '') return 'Соперник сделал пас — ваш ход';
  return myTurn ? 'Ваш ход' : 'Ход соперника';
}

function shouldAnimateMove(game, finalBoard, size, moveCount){
  const lastMove = game?.last_move || null;
  const lastCell = Number(lastMove?.cell ?? -1);
  if (String(lastMove?.type || '') !== 'place') return false;
  if (!activeGameId || previousBoard.length !== size * size) return false;
  if (moveCount !== previousMoveCount + 1) return false;
  if (lastCell < 0 || lastCell >= size * size) return false;
  if (previousBoard[lastCell] !== '-' || !['B','W'].includes(finalBoard[lastCell])) return false;
  return true;
}

function animateMove({ game, me, container, onAction, size, fromBoard, finalBoard, moveKey }){
  cancelAnimation();
  activeAnimationKey = moveKey;
  const token = ++animationToken;
  const lastCell = Number(game?.last_move?.cell ?? -1);
  const captured = [...new Set((game?.last_captured_cells || []).map(Number))]
    .filter(cell => cell >= 0 && cell < size * size && fromBoard[cell] !== '-');
  const displayBoard = Array.from(fromBoard);
  displayBoard[lastCell] = finalBoard[lastCell];

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

  const captureStart = 330;
  captured.forEach((cell, index) => {
    schedule(() => {
      if (token !== animationToken) return;
      container.querySelector(`[data-go-cell="${cell}"]`)?.classList.add('capture-out');
    }, captureStart + Math.min(index, 10) * 38);
  });

  const totalDelay = captured.length > 0
    ? captureStart + Math.min(captured.length, 10) * 38 + 260
    : 520;

  schedule(() => {
    if (token !== animationToken) return;
    activeAnimationKey = '';
    renderSurface({ game, me, container, onAction, size, board:finalBoard, interactive:true, animating:false });
  }, totalDelay);
}

function renderSurface({ game, me, container, onAction, size, board, interactive, animating, placedCell = -1 }){
  container.className = `board go-surface${animating ? ' is-animating' : ''}`;
  container.dataset.gameType = 'go';

  const myId = String(me?.id || '');
  const myTurn = interactive && game?.status === 'active' && String(game?.turn || '') === myId;
  const viewerSide = String(game?.viewer_side || sideForPlayer(game, myId));
  const lastMoveCell = String(game?.last_move?.type || '') === 'place' ? Number(game?.last_move?.cell ?? -1) : -1;
  const finalScore = game?.final_score || null;
  const territory = territoryMaps(finalScore);

  container.innerHTML = `
    <div class="go-panel">
      ${statusMarkup({ game, me, myTurn, animating })}

      <div class="go-board" style="--go-size:${size}" data-size="${size}">
        ${gridSvg(size)}
        ${starMarkup(size)}
        ${Array.from({ length:size * size }, (_, cell) => pointMarkup({
          cell,
          size,
          value:board[cell],
          myTurn,
          lastMoveCell,
          placedCell,
          territory,
        })).join('')}
      </div>

      ${scoreMarkup(finalScore, viewerSide)}

      <button class="btn ghost full go-pass-button" data-go-pass type="button" ${myTurn ? '' : 'disabled'}>
        ${Number(game?.pass_sequence || 0) >= 1 && myTurn ? 'Пас и завершить партию' : 'Пас'}
      </button>
    </div>
  `;

  container.querySelectorAll('[data-go-empty]').forEach(button => button.addEventListener('click', () => {
    if (!myTurn || container.classList.contains('is-submitting') || container.classList.contains('is-animating')) return;
    const cell = Number(button.dataset.goCell);
    container.classList.add('is-submitting');
    onAction?.({ type:'cell', cell });
  }));

  container.querySelector('[data-go-pass]')?.addEventListener('click', () => {
    if (!myTurn || container.classList.contains('is-submitting') || container.classList.contains('is-animating')) return;
    if (Number(game?.pass_sequence || 0) >= 1) {
      openSecondPassConfirm(container, onAction);
      return;
    }
    container.classList.add('is-submitting');
    onAction?.({ type:'pass' });
  });
}

function openSecondPassConfirm(container, onAction){
  openSheet(`
    <div class="sheet-head">
      <div><h2>Завершить партию?</h2><p>Это второй пас подряд. После него территория будет подсчитана автоматически.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="small-note">Все камни, которые остались на поле, считаются живыми и входят в результат.</div>
    <div class="stack">
      <button class="btn primary full" id="confirmGoPass" type="button">Завершить партию</button>
      <button class="btn ghost full" data-close-sheet type="button">Продолжить игру</button>
    </div>
  `);
  document.getElementById('confirmGoPass')?.addEventListener('click', () => {
    closeSheet();
    container.classList.add('is-submitting');
    onAction?.({ type:'pass' });
  });
}

function statusMarkup({ game, me, myTurn, animating }){
  if (animating) {
    const movedByMe = String(game?.last_move?.player_id || '') === String(me?.id || '');
    const captured = Number(game?.last_move?.captured || 0);
    if (captured > 0) return `<div class="go-event-banner capture">${movedByMe ? 'Ваш ход' : 'Ход соперника'} — снимаем окружённую группу</div>`;
    return `<div class="go-event-banner move">${movedByMe ? 'Ваш камень поставлен' : 'Соперник поставил камень'}</div>`;
  }
  if (game?.status === 'finished') return '<div class="go-event-banner finished">Партия завершена — считаем территорию</div>';

  const passedId = String(game?.last_passed_player_id || '');
  if (passedId !== '') {
    const passedMe = passedId === String(me?.id || '');
    return `<div class="go-event-banner pass">${passedMe ? 'Вы сделали пас' : 'Соперник сделал пас'} · следующий пас завершит партию</div>`;
  }
  return myTurn
    ? '<div class="go-event-banner your-turn">Ваш ход — выберите свободное пересечение</div>'
    : '<div class="go-event-banner opponent">Ход соперника — следите за полем</div>';
}

function pointMarkup({ cell, size, value, myTurn, lastMoveCell, placedCell, territory }){
  const row = Math.floor(cell / size);
  const col = cell % size;
  const inset = 6;
  const span = 88;
  const x = inset + (col / (size - 1)) * span;
  const y = inset + (row / (size - 1)) * span;
  const isEmpty = value === '-';
  const isLast = cell === lastMoveCell;
  const isPlaced = cell === placedCell;
  const territoryClass = territory.black.has(cell)
    ? 'territory-black'
    : (territory.white.has(cell) ? 'territory-white' : (territory.neutral.has(cell) ? 'territory-neutral' : ''));
  const classes = [
    'go-point',
    isLast ? 'last-move' : '',
    isPlaced ? 'placed-fresh' : '',
    territoryClass,
  ].filter(Boolean).join(' ');
  const stone = value === 'B' || value === 'W'
    ? `<span class="go-stone ${value === 'B' ? 'black' : 'white'}"></span>`
    : '';
  const marker = territoryClass ? '<i class="go-territory-marker"></i>' : '';
  const attrs = isEmpty ? 'data-go-empty' : '';
  const disabled = !isEmpty || !myTurn ? 'disabled' : '';
  return `<button class="${classes}" data-go-cell="${cell}" ${attrs} ${disabled} style="--go-x:${x}%;--go-y:${y}%;--go-point-size:${82 / (size - 1)}%" type="button" aria-label="${pointLabel(row, col, value)}">${stone}${marker}</button>`;
}

function gridSvg(size){
  const inset = 6;
  const span = 88;
  const lines = [];
  for (let index = 0; index < size; index += 1) {
    const position = inset + (index / (size - 1)) * span;
    lines.push(`<line x1="${inset}" y1="${position}" x2="${100 - inset}" y2="${position}"></line>`);
    lines.push(`<line x1="${position}" y1="${inset}" x2="${position}" y2="${100 - inset}"></line>`);
  }
  return `<svg class="go-grid-svg" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">${lines.join('')}</svg>`;
}

function starMarkup(size){
  const indexes = size === 9 ? [2, 4, 6] : [3, 6, 9];
  const inset = 6;
  const span = 88;
  return indexes.flatMap(row => indexes.map(col => {
    const x = inset + (col / (size - 1)) * span;
    const y = inset + (row / (size - 1)) * span;
    return `<i class="go-star" style="--go-x:${x}%;--go-y:${y}%;--go-point-size:${82 / (size - 1)}%"></i>`;
  })).join('');
}

function scoreMarkup(score, viewerSide){
  if (!score) return '';
  const black = formatScore(score.black_total);
  const white = formatScore(score.white_total);
  const mine = viewerSide === 'black' ? black : white;
  const theirs = viewerSide === 'black' ? white : black;
  const mineTerritory = viewerSide === 'black' ? score.black_territory : score.white_territory;
  const theirTerritory = viewerSide === 'black' ? score.white_territory : score.black_territory;
  return `
    <div class="go-final-score">
      <strong>Итог ${mine}:${theirs}</strong>
      <span>Ваша территория: ${Number(mineTerritory || 0)} · соперника: ${Number(theirTerritory || 0)} · komi белых: ${formatScore(score.komi)}</span>
    </div>
  `;
}

function territoryMaps(score){
  return {
    black:new Set((score?.black_territory_cells || []).map(Number)),
    white:new Set((score?.white_territory_cells || []).map(Number)),
    neutral:new Set((score?.neutral_cells || []).map(Number)),
  };
}

function normalizeSize(value){
  const size = Number(value);
  return [9,13].includes(size) ? size : 9;
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

function finalStatus(game, me){
  const winnerId = String(game?.winner_id || '');
  if (!winnerId) return 'Ничья';
  return winnerId === String(me?.id || '') ? 'Победа' : 'Поражение';
}

function moveSignature(game){
  return [
    String(game?.id || ''),
    Number(game?.move_count || 0),
    String(game?.last_move?.type || ''),
    Number(game?.last_move?.cell ?? -1),
  ].join(':');
}

function pointLabel(row, col, value){
  const state = value === 'B' ? 'чёрный камень' : (value === 'W' ? 'белый камень' : 'свободно');
  return `Ряд ${row + 1}, столбец ${col + 1}: ${state}`;
}

function formatScore(value){
  const number = Number(value || 0);
  return Number.isInteger(number) ? String(number) : number.toFixed(1).replace('.', ',');
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
