import {
  renderChessSurface as renderBaseChessSurface,
  chessMeta,
  chessPlayerMark,
  chessStatus,
} from './renderer.js?v=70&mvp19_5=cosmetics&fx_runtime=landing-sync-v2&move_parity=store-trail-v1';

const MOVE_EFFECT_ITEM = 'game-chess-effect-move';
const CHECK_EFFECT_ITEM = 'game-chess-effect-check';
const MOVE_EFFECT_DURATION_MS = 700;
const CHECK_TEST_DURATION_MS = 1450;
const moveEffectByGamePlayer = new Map();
const seenMoveByGame = new Map();
const activeMoveFxByGame = new Map();
const activeCheckTestFxByGame = new Map();

ensureMoveEffectV3Styles();

export { chessMeta, chessPlayerMark, chessStatus };

export function renderChessSurface(args){
  const { game, me, container } = args || {};
  const gameId = String(game?.id || '');
  const players = Array.isArray(game?.players) ? game.players : [];
  cachePlayerEffects(gameId, players);

  const lastMove = game?.last_move || null;
  const moveKey = chessMoveKey(game, lastMove);
  const moverId = moverPlayerId(players, lastMove);
  const moveEquipped = equippedMoveEffect(gameId, players, moverId);
  const checkTestEquipped = equippedCheckEffect(gameId, players, moverId);
  const naturalCheck = Boolean(lastMove?.check || game?.in_check);
  const pendingOptimisticMove = Boolean(game?.__mgw_v100_pending_action);
  const viewerSide = String(game?.viewer_side || playerSide(game, String(me?.id || '')) || 'white');

  const alreadyObserved = gameId !== '' && seenMoveByGame.has(gameId);
  const previousMoveKey = gameId ? String(seenMoveByGame.get(gameId) || '') : '';
  const newObservedMove = Boolean(gameId && moveKey && alreadyObserved && previousMoveKey !== moveKey);
  if (gameId && !alreadyObserved) seenMoveByGame.set(gameId, moveKey);

  if (newObservedMove) {
    seenMoveByGame.set(gameId, moveKey);
    if (moveEquipped) startMoveEffect(gameId, moveKey, lastMove, viewerSide);
    if (checkTestEquipped && !naturalCheck) startCheckTestEffect(gameId, moveKey, game, players, moverId, lastMove);
  } else if (gameId && moveKey && pendingOptimisticMove) {
    if (moveEquipped) {
      const active = activeMoveFxByGame.get(gameId);
      if (!active || active.key !== moveKey) startMoveEffect(gameId, moveKey, lastMove, viewerSide);
    }
    if (checkTestEquipped && !naturalCheck) {
      const active = activeCheckTestFxByGame.get(gameId);
      if (!active || active.key !== moveKey) startCheckTestEffect(gameId, moveKey, game, players, moverId, lastMove);
    }
  }

  const baseGame = moveEquipped && lastMove
    ? withoutMoveEffectForMover(game, moverId)
    : game;

  if (container?.dataset) {
    container.dataset.chessMoveFxV3 = moveEquipped ? '1' : '0';
    container.dataset.chessCheckTestHook = checkTestEquipped ? '1' : '0';
  }
  renderBaseChessSurface({ ...args, game:baseGame });

  if (!gameId || !container) return;

  const activeMove = activeMoveFxByGame.get(gameId);
  if (activeMove?.key === moveKey) {
    const elapsed = Date.now() - activeMove.startedAt;
    if (elapsed >= MOVE_EFFECT_DURATION_MS) activeMoveFxByGame.delete(gameId);
    else renderMoveEffect(container, activeMove, elapsed);
  }

  const activeCheckTest = activeCheckTestFxByGame.get(gameId);
  if (activeCheckTest?.key === moveKey) {
    const elapsed = Date.now() - activeCheckTest.startedAt;
    if (elapsed >= CHECK_TEST_DURATION_MS) activeCheckTestFxByGame.delete(gameId);
    else renderStagingCheckTest(container, activeCheckTest, elapsed);
  }
}

function cachePlayerEffects(gameId, players){
  if (!gameId) return;
  players.forEach(player => {
    const playerId = String(player?.id || '');
    if (!playerId) return;
    const effect = String(player?.game_cosmetics?.slots?.game_chess_effect || '');
    if (effect) moveEffectByGamePlayer.set(`${gameId}:${playerId}`, effect);
  });
}

function equippedMoveEffect(gameId, players, moverId){
  return equippedEffect(gameId, players, moverId) === MOVE_EFFECT_ITEM;
}

function equippedCheckEffect(gameId, players, moverId){
  return equippedEffect(gameId, players, moverId) === CHECK_EFFECT_ITEM;
}

function equippedEffect(gameId, players, moverId){
  if (!gameId || !moverId) return '';
  const direct = String(players.find(player => String(player?.id || '') === moverId)?.game_cosmetics?.slots?.game_chess_effect || '');
  const cached = String(moveEffectByGamePlayer.get(`${gameId}:${moverId}`) || '');
  return direct || cached;
}

function withoutMoveEffectForMover(game, moverId){
  if (!game || !moverId) return game;
  const players = Array.isArray(game.players) ? game.players : [];
  let changed = false;
  const nextPlayers = players.map(player => {
    if (String(player?.id || '') !== moverId) return player;
    const cosmetics = player?.game_cosmetics;
    const slots = cosmetics?.slots;
    if (!slots || String(slots.game_chess_effect || '') !== MOVE_EFFECT_ITEM) return player;
    changed = true;
    const nextSlots = { ...slots };
    delete nextSlots.game_chess_effect;
    return {
      ...player,
      game_cosmetics:{ ...cosmetics, slots:nextSlots },
    };
  });
  return changed ? { ...game, players:nextPlayers } : game;
}

function startMoveEffect(gameId, key, lastMove, viewerSide){
  const from = Number(lastMove?.from);
  const to = Number(lastMove?.to);
  if (!Number.isInteger(from) || !Number.isInteger(to)) return;
  activeMoveFxByGame.set(gameId, {
    key,
    from,
    to,
    viewerSide,
    startedAt:Date.now(),
  });
}

function startCheckTestEffect(gameId, key, game, players, moverId, lastMove){
  const mover = players.find(player => String(player?.id || '') === moverId) || null;
  const moverSide = String(lastMove?.side || mover?.side || '');
  if (moverSide !== 'white' && moverSide !== 'black') return;
  const opponentKing = moverSide === 'white' ? 'bK' : 'wK';
  const board = Array.isArray(game?.board) ? game.board : [];
  const kingCell = board.findIndex(piece => String(piece || '') === opponentKing);
  if (kingCell < 0) return;
  activeCheckTestFxByGame.set(gameId, {
    key,
    kingCell,
    startedAt:Date.now(),
  });
}

function renderMoveEffect(container, fx, elapsedMs){
  const target = container.querySelector?.(`[data-chess-cell="${fx.to}"]`);
  if (!target) return;

  target.querySelectorAll('.chess-live-move-v3').forEach(node => node.remove());

  const motion = motionBetween(fx.from, fx.to, fx.viewerSide);
  const elapsed = Math.max(0, Number(elapsedMs) || 0);
  const trailSizes = [15,14,13,12,11,10,9,8.5,8];
  const trailDots = trailSizes.map((size, index) => {
    const delay = index * 27 - elapsed;
    return `<i style="--fx-delay:${delay}ms;--fx-size:${size}%"></i>`;
  }).join('');

  const gatherPoints = [
    [-12,-3],[-8,-10],[2,-12],[10,-7],[12,3],[7,10],[-3,12],[-10,7],
  ];
  const gatherDots = gatherPoints.map(([x,y], index) => {
    const delay = 365 + index * 10 - elapsed;
    return `<i style="--g-x:${x}px;--g-y:${y}px;--g-delay:${delay}ms"></i>`;
  }).join('');
  const coreDelay = 470 - elapsed;

  target.insertAdjacentHTML('beforeend', `
    <span class="chess-live-move-v3 chess-live-move-v3-trail" aria-hidden="true" style="--trail-x:${motion.x}%;--trail-y:${motion.y}%">
      ${trailDots}
    </span>
    <span class="chess-live-move-v3 chess-live-move-v3-arrival" aria-hidden="true">
      ${gatherDots}<b style="--core-delay:${coreDelay}ms"></b>
    </span>
  `);
}

function renderStagingCheckTest(container, fx, elapsedMs){
  const target = container.querySelector?.(`[data-chess-cell="${fx.kingCell}"]`);
  if (!target) return;

  target.querySelectorAll('.mgw-staging-check-test').forEach(node => node.remove());
  const elapsed = Math.max(0, Number(elapsedMs) || 0);
  const landing = checkTestAnimationStyle(400, elapsed);
  const secondWave = checkTestAnimationStyle(480, elapsed);

  target.insertAdjacentHTML('beforeend', `
    <span class="chess-fx-layer chess-fx-check mgw-staging-check-test" aria-hidden="true">
      <i${landing}></i><i${secondWave}></i><b${landing}></b><em${landing}></em>
    </span>
  `);
}

function checkTestAnimationStyle(delayMs, elapsedMs){
  const delay = Math.round(Number(delayMs) - Number(elapsedMs || 0));
  return ` style="opacity:0;animation-delay:${delay}ms;animation-fill-mode:forwards"`;
}

function chessMoveKey(game, lastMove){
  if (!lastMove) return '';
  return [
    Number(game?.move_count || 0),
    Number(lastMove?.from),
    Number(lastMove?.to),
    String(lastMove?.player_id || ''),
  ].join(':');
}

function moverPlayerId(players, lastMove){
  const direct = String(lastMove?.player_id || '');
  if (direct) return direct;
  const side = String(lastMove?.side || '');
  return String(players.find(player => String(player?.side || '') === side)?.id || '');
}

function motionBetween(from, to, viewerSide){
  const fromDisplay = viewerSide === 'black' ? 63 - from : from;
  const toDisplay = viewerSide === 'black' ? 63 - to : to;
  return {
    x:(fromDisplay % 8 - toDisplay % 8) * 100,
    y:(Math.floor(fromDisplay / 8) - Math.floor(toDisplay / 8)) * 100,
  };
}

function playerSide(game, playerId){
  return (game?.players || []).find(player => String(player?.id || '') === playerId)?.side || '';
}

function ensureMoveEffectV3Styles(){
  if (typeof document === 'undefined' || document.getElementById('mgwChessMoveEffectV3')) return;
  const style = document.createElement('style');
  style.id = 'mgwChessMoveEffectV3';
  style.textContent = `
    /* Move v3 owns only paid Move presentation. Capture and Check remain base-owned. */
    #gameBoard[data-game-type="chess"][data-chess-move-fx-v3="1"] .chess-fx-layer.chess-fx-move,
    #gameBoard[data-game-type="chess"][data-chess-move-fx-v3="1"] .chess-fx-trail{display:none!important}

    #gameBoard[data-game-type="chess"][data-chess-move-fx-v3="1"] .chess-piece.moved-fresh:not(.castle-rook-fresh){
      animation-name:chessMoveV3Piece;
      animation-duration:.42s;
      animation-timing-function:cubic-bezier(.22,.72,.2,1);
      animation-fill-mode:both;
    }

    #gameBoard[data-game-type="chess"] .chess-live-move-v3{
      position:absolute;
      inset:0;
      pointer-events:none;
      overflow:visible;
      isolation:isolate;
    }
    #gameBoard[data-game-type="chess"] .chess-live-move-v3-trail{z-index:10}
    #gameBoard[data-game-type="chess"] .chess-live-move-v3-trail i{
      position:absolute;
      left:calc(50% + var(--trail-x,0%));
      top:calc(50% + var(--trail-y,0%));
      width:var(--fx-size,10%);
      aspect-ratio:1;
      border-radius:50%;
      background:radial-gradient(circle at 34% 30%,#fff 0 13%,rgba(128,243,255,.98) 31%,rgba(69,196,255,.90) 58%,rgba(98,94,255,.20) 76%,transparent 80%);
      box-shadow:0 0 5px rgba(73,225,255,.92),0 0 10px rgba(84,130,255,.40);
      opacity:0;
      transform:translate(-50%,-50%) scale(.58);
      animation:chessMoveV3Trail .34s cubic-bezier(.22,.72,.22,1) var(--fx-delay,0ms) both;
    }

    #gameBoard[data-game-type="chess"] .chess-live-move-v3-arrival{z-index:11}
    #gameBoard[data-game-type="chess"] .chess-live-move-v3-arrival i,
    #gameBoard[data-game-type="chess"] .chess-live-move-v3-arrival b{
      position:absolute;
      left:50%;
      top:50%;
      border-radius:50%;
      pointer-events:none;
    }
    #gameBoard[data-game-type="chess"] .chess-live-move-v3-arrival i{
      width:8%;
      aspect-ratio:1;
      background:radial-gradient(circle,#fff 0 12%,rgba(125,240,255,.96) 34%,rgba(80,183,255,.72) 64%,transparent 78%);
      box-shadow:0 0 4px rgba(73,224,255,.76);
      opacity:0;
      animation:chessMoveV3Gather .22s cubic-bezier(.2,.72,.22,1) var(--g-delay,365ms) both;
    }
    #gameBoard[data-game-type="chess"] .chess-live-move-v3-arrival b{
      width:18%;
      aspect-ratio:1;
      background:radial-gradient(circle,#fff 0 13%,rgba(119,239,255,.92) 30%,rgba(71,183,255,.46) 56%,transparent 76%);
      box-shadow:0 0 7px rgba(72,222,255,.54);
      opacity:0;
      animation:chessMoveV3Core .17s ease-out var(--core-delay,470ms) both;
    }

    @keyframes chessMoveV3Piece{
      0%{transform:translate(var(--chess-move-x,0),calc(var(--chess-move-y,0) - 1px)) scale(.96);filter:brightness(1.12)}
      100%{transform:translateY(-1px) scale(1);filter:brightness(1)}
    }
    @keyframes chessMoveV3Trail{
      0%{left:calc(50% + var(--trail-x,0%));top:calc(50% + var(--trail-y,0%));opacity:0;transform:translate(-50%,-50%) scale(.5)}
      14%{opacity:.98}
      68%{opacity:.86;transform:translate(-50%,-50%) scale(1)}
      100%{left:50%;top:50%;opacity:0;transform:translate(-50%,-50%) scale(.34)}
    }
    @keyframes chessMoveV3Gather{
      0%{opacity:0;transform:translate(-50%,-50%) translate(var(--g-x),var(--g-y)) scale(.6)}
      20%{opacity:.92}
      72%{opacity:.76;transform:translate(-50%,-50%) translate(0,0) scale(.82)}
      100%{opacity:0;transform:translate(-50%,-50%) translate(0,0) scale(.2)}
    }
    @keyframes chessMoveV3Core{
      0%{opacity:0;transform:translate(-50%,-50%) scale(.34)}
      38%{opacity:.88;transform:translate(-50%,-50%) scale(.92)}
      100%{opacity:0;transform:translate(-50%,-50%) scale(.24)}
    }

    @media(prefers-reduced-motion:reduce){
      #gameBoard[data-game-type="chess"] .chess-live-move-v3{display:none!important}
      #gameBoard[data-game-type="chess"][data-chess-move-fx-v3="1"] .chess-piece.moved-fresh:not(.castle-rook-fresh){animation:none!important}
    }
  `;
  document.head.appendChild(style);
}
