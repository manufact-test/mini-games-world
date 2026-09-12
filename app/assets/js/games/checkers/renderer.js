import { toast } from '../../components/toast.js?v=41';

let activeGameId = '';
let selectedFrom = null;
let lastAnimatedMoveKey = '';

export function renderCheckersSurface({ game, me, container, onAction }){
  resetForGame(game);
  container.className = 'board checkers-surface';
  container.dataset.gameType = 'checkers';

  const myTurn = game?.status === 'active' && String(game?.turn || '') === String(me?.id || '');
  const viewerSide = String(game?.viewer_side || sideForPlayer(game, me?.id));
  const board = Array.from({ length:64 }, (_, index) => String(game?.board?.[index] || ''));
  const legalMoves = myTurn && Array.isArray(game?.legal_moves) ? game.legal_moves : [];
  const hasForcedPiece = game?.forced_piece !== null && game?.forced_piece !== undefined && Number.isInteger(Number(game.forced_piece));
  const forcedPiece = hasForcedPiece ? Number(game.forced_piece) : null;
  const captureRequired = Boolean(game?.capture_required);
  const pendingCaptures = new Set((game?.pending_captures || []).map(Number));

  const selectable = new Set(legalMoves.map(move => Number(move.from)));
  if (forcedPiece !== null && selectable.has(forcedPiece)) selectedFrom = forcedPiece;
  if (selectedFrom !== null && !selectable.has(selectedFrom)) selectedFrom = null;

  const targetMoves = selectedFrom === null
    ? []
    : legalMoves.filter(move => Number(move.from) === selectedFrom);
  const targets = new Map(targetMoves.map(move => [Number(move.to), move]));
  const orderedCells = viewerSide === 'black'
    ? Array.from({ length:64 }, (_, index) => 63 - index)
    : Array.from({ length:64 }, (_, index) => index);

  const moveKey = lastMoveSignature(game);
  const freshMove = Boolean(moveKey && moveKey !== lastAnimatedMoveKey);
  const lastMove = game?.last_move || null;
  const lastCaptured = new Set((game?.last_captured_cells || []).map(Number));
  const hasLastPromotion = game?.last_promotion !== null && game?.last_promotion !== undefined && Number.isInteger(Number(game.last_promotion));
  const lastPromotion = hasLastPromotion ? Number(game.last_promotion) : -1;

  const myPieces = viewerSide === 'white' ? Number(game?.white_pieces ?? 0) : Number(game?.black_pieces ?? 0);
  const enemyPieces = viewerSide === 'white' ? Number(game?.black_pieces ?? 0) : Number(game?.white_pieces ?? 0);
  const myKings = viewerSide === 'white' ? Number(game?.white_kings ?? 0) : Number(game?.black_kings ?? 0);
  const enemyKings = viewerSide === 'white' ? Number(game?.black_kings ?? 0) : Number(game?.white_kings ?? 0);

  container.innerHTML = `
    <div class="checkers-panel">
      <div class="checkers-score-line">
        <span>Вы <strong>${myPieces}</strong>${myKings ? ` · ♛${myKings}` : ''}</span>
        <i>•</i>
        <span>Соперник <strong>${enemyPieces}</strong>${enemyKings ? ` · ♛${enemyKings}` : ''}</span>
      </div>

      ${statusMarkup({ game, myTurn, captureRequired, forcedPiece })}

      <div class="checkers-board" data-viewer-side="${viewerSide}">
        ${orderedCells.map(cell => cellMarkup({
          cell,
          board,
          selectable,
          targets,
          selectedFrom,
          captureRequired,
          pendingCaptures,
          lastMove,
          lastCaptured,
          lastPromotion,
          freshMove,
        })).join('')}
      </div>

      <div class="checkers-legend">
        <span><i class="select"></i>выбрано</span>
        <span><i class="move"></i>ход</span>
        <span><i class="capture"></i>взятие</span>
        <span><b>♛</b>дамка</span>
      </div>
    </div>
  `;

  if (freshMove) lastAnimatedMoveKey = moveKey;

  container.querySelectorAll('[data-checkers-piece]').forEach(button => button.addEventListener('click', () => {
    const cell = Number(button.dataset.checkersCell);
    if (!myTurn || !selectable.has(cell)) return;
    selectedFrom = cell;
    renderCheckersSurface({ game, me, container, onAction });
  }));

  container.querySelectorAll('[data-checkers-target]').forEach(button => button.addEventListener('click', () => {
    const to = Number(button.dataset.checkersCell);
    const move = targets.get(to);
    if (!move || selectedFrom === null) return;
    const from = selectedFrom;
    selectedFrom = null;
    onAction?.({ type:'move', from, to });
  }));

  container.querySelectorAll('[data-checkers-dark-empty]').forEach(button => button.addEventListener('click', () => {
    if (!myTurn || selectedFrom !== null) return;
    if (captureRequired) toast('Есть обязательное взятие — выберите подсвеченную шашку.');
  }));
}

export function checkersMeta(game){
  const room = String(game?.room_name || 'Игра');
  const bet = Number(game?.bet || 0);
  return `${room} · ${bet} коинов · 8×8`;
}

export function checkersPlayerMark(player){
  const side = String(player?.side || '');
  return side === 'white' ? '○ белые' : '● чёрные';
}

export function checkersStatus(game, me){
  if (game?.status === 'finished') return 'Игра завершена';
  const myTurn = String(game?.turn || '') === String(me?.id || '');
  if (!myTurn) return 'Ход соперника';
  if (game?.forced_piece !== null && game?.forced_piece !== undefined) return 'Продолжайте взятие';
  if (game?.capture_required) return 'Обязательное взятие';
  return 'Ваш ход';
}

function resetForGame(game){
  const gameId = String(game?.id || '');
  if (gameId === activeGameId) return;
  activeGameId = gameId;
  selectedFrom = null;
  lastAnimatedMoveKey = '';
}

function statusMarkup({ game, myTurn, captureRequired, forcedPiece }){
  if (game?.status === 'finished') {
    return `<div class="checkers-event-banner finished">Игра завершена</div>`;
  }
  if (!myTurn) {
    return `<div class="checkers-event-banner opponent">Ход соперника — следите за доской</div>`;
  }
  if (forcedPiece !== null) {
    return `<div class="checkers-event-banner chain">Продолжайте взятие этой же шашкой</div>`;
  }
  if (captureRequired) {
    return `<div class="checkers-event-banner capture">Обязательное взятие — выберите подсвеченную шашку</div>`;
  }
  return `<div class="checkers-event-banner your-turn">Ваш ход — выберите шашку</div>`;
}

function cellMarkup(options){
  const {
    cell,
    board,
    selectable,
    targets,
    selectedFrom,
    captureRequired,
    pendingCaptures,
    lastMove,
    lastCaptured,
    lastPromotion,
    freshMove,
  } = options;
  const row = Math.floor(cell / 8);
  const col = cell % 8;
  const dark = (row + col) % 2 === 1;
  const piece = String(board[cell] || '');
  const isPiece = ['w','W','b','B'].includes(piece);
  const isKing = piece === 'W' || piece === 'B';
  const isWhite = piece.toLowerCase() === 'w';
  const isSelectable = selectable.has(cell);
  const isSelected = selectedFrom === cell;
  const targetMove = targets.get(cell);
  const isTarget = Boolean(targetMove);
  const isCaptureTarget = Boolean(targetMove?.capture);
  const isPendingCaptured = pendingCaptures.has(cell);
  const isLastFrom = Number(lastMove?.from) === cell;
  const isLastTo = Number(lastMove?.to) === cell;
  const wasCaptured = lastCaptured.has(cell);
  const promoted = lastPromotion === cell;

  const classes = [
    'checkers-cell', dark ? 'dark' : 'light',
    isSelectable ? 'selectable' : '',
    isSelectable && captureRequired ? 'capture-source' : '',
    isSelected ? 'selected' : '',
    isTarget ? 'target' : '',
    isCaptureTarget ? 'capture-target' : '',
    isPendingCaptured ? 'pending-captured' : '',
    isLastFrom ? 'last-from' : '',
    isLastTo ? 'last-to' : '',
    freshMove && isLastTo ? 'move-impact' : '',
    freshMove && wasCaptured ? 'captured-flash' : '',
    freshMove && promoted ? 'promotion-flash' : '',
  ].filter(Boolean).join(' ');

  const attrs = [];
  if (isSelectable) attrs.push('data-checkers-piece');
  if (isTarget) attrs.push('data-checkers-target');
  if (dark && !isPiece && !isTarget) attrs.push('data-checkers-dark-empty');

  const pieceMarkup = isPiece
    ? `<span class="checkers-piece ${isWhite ? 'white' : 'black'} ${isKing ? 'king' : ''}">${isKing ? '<b>♛</b>' : ''}</span>`
    : '';

  return `<button class="${classes}" data-checkers-cell="${cell}" ${attrs.join(' ')} type="button" ${dark ? '' : 'disabled'} aria-label="${cellLabel(cell, piece, isTarget, isCaptureTarget)}">${pieceMarkup}<i class="checkers-target-dot"></i></button>`;
}

function cellLabel(cell, piece, isTarget, isCaptureTarget){
  const file = 'abcdefgh'[cell % 8];
  const rank = 8 - Math.floor(cell / 8);
  if (isTarget) return `${file}${rank}: ${isCaptureTarget ? 'взять шашку' : 'сделать ход'}`;
  if (piece === 'w') return `${file}${rank}: белая шашка`;
  if (piece === 'W') return `${file}${rank}: белая дамка`;
  if (piece === 'b') return `${file}${rank}: чёрная шашка`;
  if (piece === 'B') return `${file}${rank}: чёрная дамка`;
  return `${file}${rank}`;
}

function sideForPlayer(game, playerId){
  const player = (game?.players || []).find(item => String(item?.id || '') === String(playerId || ''));
  return String(player?.side || 'white');
}

function lastMoveSignature(game){
  const move = game?.last_move;
  if (!move) return '';
  return [String(game?.id || ''), String(move?.from ?? ''), String(move?.to ?? ''), String(move?.captured ?? ''), String(move?.promoted ?? '')].join(':');
}
