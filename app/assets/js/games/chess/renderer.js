import { openSheet, closeSheet } from '../../components/sheet.js?v=27';
import { toast } from '../../components/toast.js?v=41';

const selectedByGame = new Map();
const mountedGameIds = new Set();
const initialLastMoveByGame = new Map();
const animatedMoveByGame = new Map();
const animatedEffectByGame = new Map();
const moveEffectHoldByGame = new Map();
const EFFECT_LANDING_DELAY_MS = 400;
const MOVE_EFFECT_HOLD_MS = 1250;
const GLYPHS = {
  wK:'♚',wQ:'♛',wR:'♜',wB:'♝',wN:'♞',wP:'♟',
  bK:'♚',bQ:'♛',bR:'♜',bB:'♝',bN:'♞',bP:'♟',
};
const EFFECT_VARIANTS = Object.freeze({
  'game-chess-effect-move':'move',
  'game-chess-effect-capture':'capture',
  'game-chess-effect-check':'check',
});

ensureLiveChessMoveEffectStyles();

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
  const lastKey = lastMove ? [
    Number(game?.move_count || 0),
    Number(lastMove?.from),
    Number(lastMove?.to),
    String(lastMove?.player_id || ''),
  ].join(':') : '';

  const firstSurfaceForGame = gameId !== '' && !mountedGameIds.has(gameId);
  if (gameId !== '') mountedGameIds.add(gameId);
  if (firstSurfaceForGame && lastKey) initialLastMoveByGame.set(gameId, lastKey);
  const suppressInitialSnapshot = Boolean(lastKey && initialLastMoveByGame.get(gameId) === lastKey);
  if (lastKey && initialLastMoveByGame.has(gameId) && !suppressInitialSnapshot) {
    initialLastMoveByGame.delete(gameId);
  }

  const animateDestination = Boolean(lastKey
    && !suppressInitialSnapshot
    && animatedMoveByGame.get(gameId) !== lastKey);
  if (animateDestination) animatedMoveByGame.set(gameId, lastKey);
  const motionByCell = animateDestination ? moveMotions(lastMove, mySide) : new Map();
  const mover = lastMove
    ? playerBySide.get(String(lastMove?.side || ''))
      || players.find(player => String(player?.id || '') === String(lastMove?.player_id || ''))
      || null
    : null;

  const effectCandidate = lastMove ? activeMoveEffect(game, lastMove, mover) : '';
  const effectKey = effectCandidate && lastKey ? `${lastKey}:${effectCandidate}` : '';
  const pendingOptimisticMove = Boolean(game?.__mgw_v100_pending_action);
  const heldMoveEffect = gameId ? moveEffectHoldByGame.get(gameId) : null;

  // The accepted runtime renders an optimistic Chess board first. Replacing that
  // DOM immediately with the authoritative board used to cut the paid Move trail
  // off halfway through. If the same move has just started its cosmetic animation,
  // keep that exact surface alive until the trail + landing wave finish, then mount
  // the latest authoritative snapshot once. Game status/clock/player chrome lives
  // outside this surface and continues updating normally.
  if (!pendingOptimisticMove
    && effectCandidate === 'move'
    && heldMoveEffect
    && heldMoveEffect.effectKey === effectKey
    && heldMoveEffect.until > Date.now()) {
    holdAuthoritativeMoveSurface(heldMoveEffect, { game, me, container, onAction });
    return;
  }

  const animateEffect = Boolean(effectKey
    && !suppressInitialSnapshot
    && animatedEffectByGame.get(gameId) !== effectKey);
  if (animateEffect) animatedEffectByGame.set(gameId, effectKey);
  const moveEffect = animateEffect ? effectCandidate : '';
  if (moveEffect === 'move' && pendingOptimisticMove && gameId) {
    beginMoveEffectHold(gameId, effectKey);
  }
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
      ${moveEffect === 'move' && motion?.role === 'piece' ? renderChessMoveTrail(motion) : ''}
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

function beginMoveEffectHold(gameId, effectKey){
  const previous = moveEffectHoldByGame.get(gameId);
  if (previous?.timer) clearTimeout(previous.timer);
  const hold = {
    effectKey,
    until:Date.now() + MOVE_EFFECT_HOLD_MS,
    timer:null,
    latest:null,
  };
  hold.timer = globalThis.setTimeout(() => {
    if (moveEffectHoldByGame.get(gameId) === hold) moveEffectHoldByGame.delete(gameId);
  }, MOVE_EFFECT_HOLD_MS + 250);
  moveEffectHoldByGame.set(gameId, hold);
}

function holdAuthoritativeMoveSurface(hold, snapshot){
  hold.latest = snapshot;
  const remaining = Math.max(0, hold.until - Date.now());
  if (hold.timer) clearTimeout(hold.timer);
  hold.timer = globalThis.setTimeout(() => {
    const gameId = String(hold.latest?.game?.id || '');
    if (gameId && moveEffectHoldByGame.get(gameId) === hold) moveEffectHoldByGame.delete(gameId);
    const latest = hold.latest;
    if (latest?.game && latest?.me && latest?.container) renderChessSurface(latest);
  }, remaining);
}

function renderChessMoveTrail(motion){
  const style = ` style="--chess-trail-x:${motion.x}%;--chess-trail-y:${motion.y}%"`;
  return `<span class="chess-fx-trail" aria-hidden="true"${style}><i></i><i></i><i></i><i></i><i></i></span>`;
}

function renderChessEffectLayer(effect){
  const landing = effectAnimationStyle(EFFECT_LANDING_DELAY_MS);
  const secondWave = effectAnimationStyle(EFFECT_LANDING_DELAY_MS + 80);
  if (effect === 'move') {
    return `<span class="chess-fx-layer chess-fx-move" aria-hidden="true"><i${landing}></i><i${secondWave}></i><b${landing}></b></span>`;
  }
  if (effect === 'capture') {
    return `<span class="chess-fx-layer chess-fx-capture" aria-hidden="true"><i${landing}></i><i${landing}></i><i${landing}></i><i${landing}></i><i${landing}></i><i${landing}></i><b${landing}></b></span>`;
  }
  if (effect === 'check') {
    return `<span class="chess-fx-layer chess-fx-check" aria-hidden="true"><i${landing}></i><i${secondWave}></i><b${landing}></b><em${landing}></em></span>`;
  }
  return '';
}

function effectAnimationStyle(delayMs){
  const reducedMotion = typeof globalThis.matchMedia === 'function'
    && globalThis.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reducedMotion) return '';
  return ` style="opacity:0;animation-delay:${Math.max(0, Number(delayMs) || 0)}ms;animation-fill-mode:forwards"`;
}

function activeMoveEffect(game, lastMove, mover){
  const itemId = String(equippedSlots(mover).game_chess_effect || '');
  const variant = EFFECT_VARIANTS[itemId] || '';
  if (variant === 'move') return 'move';
  if (variant === 'capture' && Boolean(lastMove?.capture)) return 'capture';
  if (variant === 'check' && Boolean(lastMove?.check || game?.in_check)) return 'check';
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

function ensureLiveChessMoveEffectStyles(){
  if (typeof document === 'undefined' || document.getElementById('mgwChessMoveEffectParity')) return;
  const style = document.createElement('style');
  style.id = 'mgwChessMoveEffectParity';
  style.textContent = `
    #gameBoard[data-game-type="chess"] .chess-piece.moved-fresh:not(.castle-rook-fresh){
      animation-duration:.42s;
      animation-timing-function:cubic-bezier(.22,.72,.2,1);
    }
    #gameBoard[data-game-type="chess"] .chess-fx-trail{
      position:absolute;
      z-index:10;
      inset:0;
      pointer-events:none;
      overflow:visible;
    }
    #gameBoard[data-game-type="chess"] .chess-fx-trail i{
      position:absolute;
      left:calc(50% + var(--chess-trail-x,0%));
      top:calc(50% + var(--chess-trail-y,0%));
      width:13%;
      aspect-ratio:1;
      border-radius:50%;
      background:radial-gradient(circle at 35% 30%,rgba(255,255,255,.98),rgba(105,235,255,.94) 34%,rgba(89,182,255,.64) 61%,rgba(117,95,255,.08) 76%,transparent 78%);
      box-shadow:0 0 7px rgba(73,221,255,.78),0 0 13px rgba(112,91,255,.32);
      transform:translate(-50%,-50%) scale(.55);
      opacity:0;
      animation:chessLiveMoveTrail .44s cubic-bezier(.24,.72,.26,1) both;
    }
    #gameBoard[data-game-type="chess"] .chess-fx-trail i:nth-child(1){animation-delay:.015s}
    #gameBoard[data-game-type="chess"] .chess-fx-trail i:nth-child(2){animation-delay:.065s;width:11%}
    #gameBoard[data-game-type="chess"] .chess-fx-trail i:nth-child(3){animation-delay:.115s;width:9.5%}
    #gameBoard[data-game-type="chess"] .chess-fx-trail i:nth-child(4){animation-delay:.165s;width:8%}
    #gameBoard[data-game-type="chess"] .chess-fx-trail i:nth-child(5){animation-delay:.215s;width:6.5%}
    #gameBoard[data-game-type="chess"] .chess-fx-move i{
      width:72%;
      height:72%;
      border-width:3px;
      box-shadow:0 0 12px rgba(72,220,255,.88),0 0 24px rgba(90,114,255,.36),inset 0 0 12px rgba(72,220,255,.20);
      animation-name:chessLiveMoveWave;
      animation-duration:.78s;
      animation-timing-function:cubic-bezier(.18,.76,.24,1);
    }
    #gameBoard[data-game-type="chess"] .chess-fx-move i:nth-child(2){
      width:86%;
      height:86%;
    }
    #gameBoard[data-game-type="chess"] .chess-fx-move b{
      width:34%;
      height:34%;
      background:radial-gradient(circle,rgba(255,255,255,.98),rgba(102,234,255,.74) 28%,rgba(109,111,255,.24) 52%,transparent 72%);
      box-shadow:0 0 13px rgba(83,228,255,.72);
      animation-name:chessLiveMoveFlash;
      animation-duration:.58s;
      animation-timing-function:ease-out;
    }
    @keyframes chessLiveMoveTrail{
      0%{left:calc(50% + var(--chess-trail-x,0%));top:calc(50% + var(--chess-trail-y,0%));opacity:0;transform:translate(-50%,-50%) scale(.45)}
      12%{opacity:.96}
      62%{opacity:.78;transform:translate(-50%,-50%) scale(1)}
      100%{left:50%;top:50%;opacity:0;transform:translate(-50%,-50%) scale(.28)}
    }
    @keyframes chessLiveMoveWave{
      0%{opacity:0;transform:scale(.24)}
      10%{opacity:1;transform:scale(.48)}
      58%{opacity:.64;transform:scale(1.34)}
      100%{opacity:0;transform:scale(2.08)}
    }
    @keyframes chessLiveMoveFlash{
      0%{opacity:0;transform:scale(.24)}
      12%{opacity:1;transform:scale(.82)}
      55%{opacity:.42;transform:scale(1.42)}
      100%{opacity:0;transform:scale(2.05)}
    }
    @media(prefers-reduced-motion:reduce){
      #gameBoard[data-game-type="chess"] .chess-fx-trail{display:none!important}
    }
  `;
  document.head.appendChild(style);
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
