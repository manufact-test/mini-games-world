import { openSheet, closeSheet } from '../../components/sheet.js?v=27';
import { toast } from '../../components/toast.js?v=41';

const selectedByGame = new Map();
const animatedMoveByGame = new Map();
const GLYPHS = {
  wK:'♚',wQ:'♛',wR:'♜',wB:'♝',wN:'♞',wP:'♟',
  bK:'♚',bQ:'♛',bR:'♜',bB:'♝',bN:'♞',bP:'♟',
};
const EFFECT_VARIANTS = Object.freeze({
  'game-chess-effect-move':'move',
  'game-chess-effect-capture':'capture',
  'game-chess-effect-check':'check',
});

export function renderChessSurface({ game, me, container, onAction }){
  const gameId = String(game?.id || '');
  const myId = String(me?.id || '');
  const players = Array.isArray(game?.players) ? game.players : [];
  const viewer = players.find(player => String(player?.id || '') === myId) || null;
  const opponent = players.find(player => String(player?.id || '') !== myId) || null;
  const playerBySide = new Map(players.map(player => [String(player?.side || ''), player]));
  const mySide = String(game?.viewer_side || playerSide(game, myId) || 'white');
  const isMyTurn = game?.status === 'active' && String(game?.turn || '') === myId;
  const board = Array.isArray(game?.board) ? game.board : Array(64).fill('');
  let selected = Number(selectedByGame.get(gameId));

  if (!Number.isInteger(selected) || selected < 0 || selected > 63 || !isMyTurn || pieceSide(board[selected]) !== mySide) {
    selected = -1;
    selectedByGame.delete(gameId);
  }

  const moves = Array.isArray(game?.legal_moves) ? game.legal_moves : [];
  const selectedMoves = selected >= 0 ? moves.filter(move => Number(move.from) === selected) : [];
  const moveByTarget = new Map();

  selectedMoves.forEach(move => {
    const to = Number(move.to);
    if (!moveByTarget.has(to)) moveByTarget.set(to, []);
    moveByTarget.get(to).push(move);
  });

  const order = mySide === 'black'
    ? Array.from({ length:64 }, (_, index) => 63 - index)
    : Array.from({ length:64 }, (_, index) => index);
  const lastMove = game?.last_move || null;
  const lastKey = lastMove ? `${lastMove.from}:${lastMove.to}:${game?.move_count || 0}` : '';
  const animateDestination = Boolean(lastKey && animatedMoveByGame.get(gameId) !== lastKey);
  if (lastKey) animatedMoveByGame.set(gameId, lastKey);
  const motionByCell = animateDestination ? moveMotions(lastMove, mySide) : new Map();
  const mover = lastMove ? playerBySide.get(String(lastMove?.side || '')) || players.find(player => String(player?.id || '') === String(lastMove?.player_id || '')) || null : null;
  const moveEffect = animateDestination ? activeMoveEffect(game, lastMove, mover) : '';
  const effectCell = moveEffect ? chessEffectCell(game, board, lastMove, moveEffect) : -1;
  const cosmeticsVisible = players.some(player => Object.keys(equippedSlots(player)).some(slot => slot.startsWith('game_chess_')));

  container.className = `board chess-board ${isMyTurn ? 'is-my-turn' : ''}${cosmeticsVisible ? ' chess-cosmetics' : ''}`;
  container.dataset.gameType = 'chess';
  container.dataset.viewerSide = mySide;
  container.dataset.chessTheme = variantFor(viewer, 'game_chess_theme', 'board');
  container.dataset.chessOpponentTheme = variantFor(opponent, 'game_chess_theme', 'board');
  container.innerHTML = order.map(cell => {
    const piece = String(board[cell] || '');
    const row = Math.floor(cell / 8);
    const col = cell % 8;
    const targets = moveByTarget.get(cell) || [];
    const capture = targets.some(move => Boolean(move.capture));
    const checkedSide = String(game?.checked_side || '');
    const checkedKing = piece === `${checkedSide === 'white' ? 'w' : 'b'}K` && checkedSide !== '';
    const motion = motionByCell.get(cell) || null;
    const owner = playerBySide.get(pieceSide(piece)) || null;
    const pieceVariant = variantFor(owner, 'game_chess_elements', 'pieces');
    const classes = [
      'chess-cell',
      (row + col) % 2 ? 'dark' : 'light',
      selected === cell ? 'selected' : '',
      targets.length ? (capture ? 'capture-target' : 'legal-target') : '',
      checkedKing ? 'in-check' : '',
      cell === effectCell ? `chess-effect-cell chess-effect-${moveEffect}` : '',
    ].filter(Boolean).join(' ');
    const pieceClasses = [
      'chess-piece',
      pieceSide(piece),
      motion ? 'moved-fresh' : '',
      motion?.role === 'rook' ? 'castle-rook-fresh' : '',
    ].filter(Boolean).join(' ');
    const pieceStyle = motion
      ? ` style="--chess-move-x:${motion.x}%;--chess-move-y:${motion.y}%;--chess-move-delay:${motion.delay}ms"`
      : '';

    return `<button class="${classes}" data-chess-cell="${cell}" type="button" ${!isMyTurn ? 'disabled' : ''}>
      ${cell === effectCell ? renderChessEffectLayer(moveEffect) : ''}
      ${piece ? `<span class="${pieceClasses}" data-chess-piece-style="${pieceVariant}"${pieceStyle} aria-label="${pieceName(piece)}">${GLYPHS[piece] || ''}</span>` : ''}
      ${targets.length && !capture ? '<i class="chess-move-dot"></i>' : ''}
      ${targets.length && capture ? '<i class="chess-capture-ring"></i>' : ''}
    </button>`;
  }).join('');

  container.querySelectorAll('[data-chess-cell]').forEach(button => button.addEventListener('click', () => {
    if (!isMyTurn) return;
    const cell = Number(button.dataset.chessCell);
    const piece = String(board[cell] || '');
    const targetMoves = moveByTarget.get(cell) || [];

    if (selected >= 0 && targetMoves.length) {
      const promotions = targetMoves.filter(move => Boolean(move.promotion_required || move.promotion));
      if (promotions.length) openPromotionChoice(game, selected, cell, mySide, promotions, onAction);
      else {
        selectedByGame.delete(gameId);
        onAction({ type:'chess_move', from:selected, to:cell });
      }
      return;
    }

    if (piece && pieceSide(piece) === mySide) {
      const hasMoves = moves.some(move => Number(move.from) === cell);
      if (!hasMoves) {
        selectedByGame.delete(gameId);
        toast('У этой фигуры сейчас нет допустимых ходов.');
      } else {
        selectedByGame.set(gameId, cell);
      }
      renderChessSurface({ game, me, container, onAction });
      return;
    }

    if (selected >= 0) toast('Выберите подсвеченную клетку.');
  }));
}

export function chessMeta(game){
  const room = String(game?.room_name || 'Шахматы');
  return `${room} · ${Number(game?.bet || 0)} коинов · 8×8`;
}

export function chessPlayerMark(player){
  return String(player?.side || '') === 'black' ? 'Чёрные' : 'Белые';
}

export function chessStatus(game, me){
  if (game?.status === 'finished') {
    return ({
      checkmate:'Мат',
      stalemate:'Пат — ничья',
      insufficient_material:'Ничья: недостаточно фигур',
      threefold_repetition:'Ничья: повторение позиции',
      fifty_move:'Ничья: правило 50 ходов',
      timeout:'Время вышло',
      player_left:'Партия завершена',
    })[String(game?.chess_end_reason || '')] || 'Игра завершена';
  }
  const isMine = String(game?.turn || '') === String(me?.id || '');
  if (game?.in_check) return isMine ? 'Шах вашему королю' : 'Шах сопернику';
  return isMine ? 'Ваш ход' : 'Ход соперника';
}

function renderChessEffectLayer(effect){
  if (effect === 'move') {
    return '<span class="chess-fx-layer chess-fx-move" aria-hidden="true"><i></i><i></i><b></b></span>';
  }
  if (effect === 'capture') {
    return '<span class="chess-fx-layer chess-fx-capture" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><b></b></span>';
  }
  if (effect === 'check') {
    return '<span class="chess-fx-layer chess-fx-check" aria-hidden="true"><i></i><i></i><b></b><em></em></span>';
  }
  return '';
}

function activeMoveEffect(game, lastMove, mover){
  const itemId = String(equippedSlots(mover).game_chess_effect || '');
  const variant = EFFECT_VARIANTS[itemId] || '';
  if (variant === 'move') return 'move';
  if (variant === 'capture' && Boolean(lastMove?.capture)) return 'capture';
  if (variant === 'check' && Boolean(game?.in_check)) return 'check';
  return '';
}

function chessEffectCell(game, board, lastMove, effect){
  if (effect === 'check') {
    const checkedSide = String(game?.checked_side || '');
    const king = checkedSide === 'white' ? 'wK' : (checkedSide === 'black' ? 'bK' : '');
    const kingCell = king ? board.findIndex(piece => String(piece || '') === king) : -1;
    if (kingCell >= 0) return kingCell;
  }
  const to = Number(lastMove?.to);
  return Number.isInteger(to) && to >= 0 && to < 64 ? to : -1;
}

function equippedSlots(player){
  const slots = player?.game_cosmetics?.slots;
  return slots && typeof slots === 'object' ? slots : {};
}

function variantFor(player, slot, family){
  const itemId = String(equippedSlots(player)[slot] || '');
  if (!itemId) return 'base';
  const marker = `game-chess-${family}-`;
  return itemId.startsWith(marker) ? itemId.slice(marker.length) : 'base';
}

function openPromotionChoice(game, from, to, side, promotions, onAction){
  const available = new Set(promotions.map(move => String(move.promotion || '')));
  const choices = [['q','Ферзь'],['r','Ладья'],['b','Слон'],['n','Конь']].filter(([code]) => available.has(code));
  const prefix = side === 'white' ? 'w' : 'b';

  openSheet(`
    <div class="sheet-head">
      <div><h2>Превращение пешки</h2><p>Выберите новую фигуру.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="chess-promotion-grid">
      ${choices.map(([code, label]) => `<button class="chess-promotion-choice" data-chess-promotion="${code}" type="button"><b class="${side}">${GLYPHS[prefix + code.toUpperCase()]}</b><span>${label}</span></button>`).join('')}
    </div>
  `);

  document.querySelectorAll('[data-chess-promotion]').forEach(button => button.addEventListener('click', () => {
    selectedByGame.delete(String(game?.id || ''));
    closeSheet();
    onAction({ type:'chess_move', from, to, promotion:String(button.dataset.chessPromotion || 'q') });
  }));
}

function moveMotions(lastMove, viewerSide){
  const motions = new Map();
  const from = Number(lastMove?.from);
  const to = Number(lastMove?.to);
  if (Number.isInteger(from) && Number.isInteger(to)) {
    motions.set(to, motionBetween(from, to, viewerSide, 0, 'piece'));
  }

  const castle = String(lastMove?.castle || '');
  const side = String(lastMove?.side || 'white');
  if (castle === 'king') {
    const rookFrom = side === 'white' ? 63 : 7;
    const rookTo = side === 'white' ? 61 : 5;
    motions.set(rookTo, motionBetween(rookFrom, rookTo, viewerSide, 90, 'rook'));
  } else if (castle === 'queen') {
    const rookFrom = side === 'white' ? 56 : 0;
    const rookTo = side === 'white' ? 59 : 3;
    motions.set(rookTo, motionBetween(rookFrom, rookTo, viewerSide, 90, 'rook'));
  }
  return motions;
}

function motionBetween(from, to, viewerSide, delay, role){
  const fromDisplay = viewerSide === 'black' ? 63 - from : from;
  const toDisplay = viewerSide === 'black' ? 63 - to : to;
  return {
    x: (fromDisplay % 8 - toDisplay % 8) * 100,
    y: (Math.floor(fromDisplay / 8) - Math.floor(toDisplay / 8)) * 100,
    delay,
    role,
  };
}

function playerSide(game, playerId){
  return (game?.players || []).find(player => String(player?.id || '') === playerId)?.side || '';
}

function pieceSide(piece){
  return !piece ? '' : (piece[0] === 'w' ? 'white' : 'black');
}

function pieceName(piece){
  const type = ({K:'король',Q:'ферзь',R:'ладья',B:'слон',N:'конь',P:'пешка'})[piece?.[1]] || 'фигура';
  return `${pieceSide(piece) === 'white' ? 'Белая' : 'Чёрная'} ${type}`;
}