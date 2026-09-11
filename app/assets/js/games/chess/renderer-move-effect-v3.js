import {
  renderChessSurface as renderBaseChessSurface,
  chessMeta,
  chessPlayerMark,
  chessStatus,
} from './renderer.js?v=70&mvp19_5=cosmetics&fx_runtime=landing-sync-v2&move_parity=store-trail-v1';

const MOVE_EFFECT_ITEM = 'game-chess-effect-move';
const QUANTUM_EFFECT_ITEM = 'game-chess-effect-check';
const MOVE_EFFECT_DURATION_MS = 700;
const QUANTUM_EFFECT_DURATION_MS = 820;
const effectByGamePlayer = new Map();
const seenMoveByGame = new Map();
const activeMoveFxByGame = new Map();
const activeQuantumFxByGame = new Map();

ensurePremiumEffectStyles();

export { chessMeta, chessPlayerMark, chessStatus };

export function renderChessSurface(args){
  const { game, me, container } = args || {};
  const gameId = String(game?.id || '');
  const players = Array.isArray(game?.players) ? game.players : [];
  cachePlayerEffects(gameId, players);

  const lastMove = game?.last_move || null;
  const moveKey = chessMoveKey(game, lastMove);
  const moverId = moverPlayerId(players, lastMove);
  const equipped = equippedEffect(gameId, players, moverId);
  const moveEquipped = equipped === MOVE_EFFECT_ITEM;
  const quantumEquipped = equipped === QUANTUM_EFFECT_ITEM;
  const pendingOptimisticMove = Boolean(game?.__mgw_v100_pending_action);
  const viewerSide = String(game?.viewer_side || playerSide(game, String(me?.id || '')) || 'white');

  const alreadyObserved = gameId !== '' && seenMoveByGame.has(gameId);
  const previousMoveKey = gameId ? String(seenMoveByGame.get(gameId) || '') : '';
  const newObservedMove = Boolean(gameId && moveKey && alreadyObserved && previousMoveKey !== moveKey);
  if (gameId && !alreadyObserved) seenMoveByGame.set(gameId, moveKey);

  if (newObservedMove) {
    seenMoveByGame.set(gameId, moveKey);
    if (moveEquipped) startMoveEffect(gameId, moveKey, lastMove, viewerSide);
    if (quantumEquipped) startQuantumEffect(gameId, moveKey, lastMove, viewerSide);
  } else if (gameId && moveKey && pendingOptimisticMove) {
    if (moveEquipped) {
      const active = activeMoveFxByGame.get(gameId);
      if (!active || active.key !== moveKey) startMoveEffect(gameId, moveKey, lastMove, viewerSide);
    }
    if (quantumEquipped) {
      const active = activeQuantumFxByGame.get(gameId);
      if (!active || active.key !== moveKey) startQuantumEffect(gameId, moveKey, lastMove, viewerSide);
    }
  }

  const customOwned = moveEquipped || quantumEquipped;
  const baseGame = customOwned && lastMove
    ? withoutCustomEffectForMover(game, moverId, equipped)
    : game;

  if (container?.dataset) {
    container.dataset.chessMoveFxV3 = moveEquipped ? '1' : '0';
    container.dataset.chessQuantumEchoV1 = quantumEquipped ? '1' : '0';
    delete container.dataset.chessCheckTestHook;
  }
  renderBaseChessSurface({ ...args, game:baseGame });

  if (!gameId || !container) return;

  const activeMove = activeMoveFxByGame.get(gameId);
  if (activeMove?.key === moveKey) {
    const elapsed = Date.now() - activeMove.startedAt;
    if (elapsed >= MOVE_EFFECT_DURATION_MS) activeMoveFxByGame.delete(gameId);
    else renderMoveEffect(container, activeMove, elapsed);
  }

  const activeQuantum = activeQuantumFxByGame.get(gameId);
  if (activeQuantum?.key === moveKey) {
    const elapsed = Date.now() - activeQuantum.startedAt;
    if (elapsed >= QUANTUM_EFFECT_DURATION_MS) activeQuantumFxByGame.delete(gameId);
    else renderQuantumEffect(container, activeQuantum, elapsed);
  }
}

function cachePlayerEffects(gameId, players){
  if (!gameId) return;
  players.forEach(player => {
    const playerId = String(player?.id || '');
    if (!playerId) return;
    const effect = String(player?.game_cosmetics?.slots?.game_chess_effect || '');
    if (effect) effectByGamePlayer.set(`${gameId}:${playerId}`, effect);
  });
}

function equippedEffect(gameId, players, moverId){
  if (!gameId || !moverId) return '';
  const direct = String(players.find(player => String(player?.id || '') === moverId)?.game_cosmetics?.slots?.game_chess_effect || '');
  const cached = String(effectByGamePlayer.get(`${gameId}:${moverId}`) || '');
  return direct || cached;
}

function withoutCustomEffectForMover(game, moverId, itemId){
  if (!game || !moverId || ![MOVE_EFFECT_ITEM, QUANTUM_EFFECT_ITEM].includes(itemId)) return game;
  const players = Array.isArray(game.players) ? game.players : [];
  let changed = false;
  const nextPlayers = players.map(player => {
    if (String(player?.id || '') !== moverId) return player;
    const cosmetics = player?.game_cosmetics;
    const slots = cosmetics?.slots;
    if (!slots || String(slots.game_chess_effect || '') !== itemId) return player;
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
  activeMoveFxByGame.set(gameId, { key, from, to, viewerSide, startedAt:Date.now() });
}

function startQuantumEffect(gameId, key, lastMove, viewerSide){
  const from = Number(lastMove?.from);
  const to = Number(lastMove?.to);
  if (!Number.isInteger(from) || !Number.isInteger(to)) return;
  activeQuantumFxByGame.set(gameId, { key, from, to, viewerSide, startedAt:Date.now() });
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

function renderQuantumEffect(container, fx, elapsedMs){
  const target = container.querySelector?.(`[data-chess-cell="${fx.to}"]`);
  const piece = target?.querySelector?.('.chess-piece');
  if (!target || !piece) return;

  target.querySelectorAll('.chess-live-quantum-v1').forEach(node => node.remove());

  const motion = motionBetween(fx.from, fx.to, fx.viewerSide);
  const elapsed = Math.max(0, Number(elapsedMs) || 0);
  const layer = document.createElement('span');
  layer.className = 'chess-live-quantum-v1';
  layer.setAttribute('aria-hidden', 'true');
  layer.style.setProperty('--q-x', `${motion.x}%`);
  layer.style.setProperty('--q-y', `${motion.y}%`);

  [
    { delay:55, alpha:.42, scale:.99, blur:.15 },
    { delay:112, alpha:.25, scale:.965, blur:.4 },
    { delay:168, alpha:.13, scale:.93, blur:.75 },
  ].forEach(spec => {
    const ghost = piece.cloneNode(true);
    ghost.classList.remove('moved-fresh', 'castle-rook-fresh');
    ghost.classList.add('chess-quantum-ghost');
    ghost.removeAttribute('aria-label');
    ghost.setAttribute('aria-hidden', 'true');
    ghost.removeAttribute('style');
    ghost.style.setProperty('--q-delay', `${spec.delay - elapsed}ms`);
    ghost.style.setProperty('--q-alpha', String(spec.alpha));
    ghost.style.setProperty('--q-scale', String(spec.scale));
    ghost.style.setProperty('--q-blur', `${spec.blur}px`);
    layer.appendChild(ghost);
  });

  const haloDelay = 430 - elapsed;
  const sparkDelay = 455 - elapsed;
  const shimmerDelay = 410 - elapsed;
  layer.insertAdjacentHTML('beforeend', `
    <b class="chess-quantum-halo" style="--q-halo-delay:${haloDelay}ms"></b>
    <em class="chess-quantum-sparks" style="--q-spark-delay:${sparkDelay}ms"></em>
    <u class="chess-quantum-shimmer" style="--q-shimmer-delay:${shimmerDelay}ms"></u>
  `);
  target.appendChild(layer);
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

function ensurePremiumEffectStyles(){
  if (typeof document === 'undefined' || document.getElementById('mgwChessPremiumEffectsV1')) return;
  const style = document.createElement('style');
  style.id = 'mgwChessPremiumEffectsV1';
  style.textContent = `
    /* Accepted Move v3 and premium Quantum Echo are custom-owned here. Capture remains base-owned. */
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

    #gameBoard[data-game-type="chess"] .chess-live-quantum-v1{
      position:absolute;
      inset:0;
      z-index:13;
      pointer-events:none;
      overflow:visible;
      isolation:isolate;
    }
    #gameBoard[data-game-type="chess"] .chess-live-quantum-v1 > .chess-quantum-ghost{
      position:absolute!important;
      inset:0!important;
      z-index:13!important;
      width:100%!important;
      height:100%!important;
      margin:0!important;
      opacity:0;
      transition:none!important;
      animation:chessQuantumEchoGhost .56s cubic-bezier(.2,.72,.18,1) var(--q-delay,0ms) both!important;
      filter:blur(var(--q-blur,0px)) drop-shadow(0 0 5px rgba(111,231,255,.5)) drop-shadow(0 0 9px rgba(122,90,255,.38));
      mix-blend-mode:screen;
    }
    #gameBoard[data-game-type="chess"] .chess-quantum-halo,
    #gameBoard[data-game-type="chess"] .chess-quantum-sparks,
    #gameBoard[data-game-type="chess"] .chess-quantum-shimmer{
      position:absolute;
      left:50%;
      top:50%;
      display:block;
      pointer-events:none;
      opacity:0;
    }
    #gameBoard[data-game-type="chess"] .chess-quantum-halo{
      z-index:14;
      width:82%;
      aspect-ratio:1;
      border-radius:50%;
      background:conic-gradient(from 0deg,transparent 0 12%,rgba(134,245,255,.92) 18%,rgba(113,101,255,.98) 32%,transparent 42% 56%,rgba(208,144,255,.88) 66%,rgba(91,226,255,.82) 80%,transparent 90% 100%);
      -webkit-mask:radial-gradient(circle,transparent 0 63%,#000 66% 74%,transparent 77%);
      mask:radial-gradient(circle,transparent 0 63%,#000 66% 74%,transparent 77%);
      filter:drop-shadow(0 0 5px rgba(98,223,255,.72)) drop-shadow(0 0 8px rgba(122,91,255,.44));
      animation:chessQuantumHalo .48s cubic-bezier(.16,.72,.24,1) var(--q-halo-delay,430ms) both;
    }
    #gameBoard[data-game-type="chess"] .chess-quantum-sparks{
      z-index:15;
      width:6%;
      aspect-ratio:1;
      border-radius:50%;
      background:#dcfbff;
      box-shadow:-18px -4px 0 -1px rgba(117,241,255,.94),-11px -17px 0 -1px rgba(169,145,255,.88),7px -18px 0 -1px rgba(111,226,255,.94),18px -7px 0 -1px rgba(205,151,255,.86),15px 12px 0 -1px rgba(97,221,255,.9),-6px 18px 0 -1px rgba(161,133,255,.82);
      filter:drop-shadow(0 0 4px rgba(123,230,255,.78));
      animation:chessQuantumSparks .34s ease-out var(--q-spark-delay,455ms) both;
    }
    #gameBoard[data-game-type="chess"] .chess-quantum-shimmer{
      z-index:12;
      width:62%;
      aspect-ratio:1;
      border-radius:50%;
      background:radial-gradient(circle,#fff 0 5%,rgba(146,245,255,.78) 15%,rgba(118,112,255,.34) 38%,transparent 70%);
      box-shadow:0 0 12px rgba(99,218,255,.42),0 0 20px rgba(128,91,255,.26);
      animation:chessQuantumShimmer .44s ease-out var(--q-shimmer-delay,410ms) both;
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
    @keyframes chessQuantumEchoGhost{
      0%{opacity:0;transform:translate(var(--q-x,0),var(--q-y,0)) translateY(-1px) scale(.94)}
      14%{opacity:var(--q-alpha,.3)}
      68%{opacity:var(--q-alpha,.3);filter:blur(var(--q-blur,0px)) drop-shadow(0 0 7px rgba(111,231,255,.62)) drop-shadow(0 0 11px rgba(122,90,255,.44))}
      91%{opacity:.08;transform:translate(0,0) translateY(-1px) scale(var(--q-scale,1))}
      100%{opacity:0;transform:translate(0,0) translateY(-1px) scale(.88)}
    }
    @keyframes chessQuantumHalo{
      0%{opacity:0;transform:translate(-50%,-50%) rotate(-90deg) scale(.52)}
      26%{opacity:.96;transform:translate(-50%,-50%) rotate(28deg) scale(.9)}
      70%{opacity:.78;transform:translate(-50%,-50%) rotate(230deg) scale(1.04)}
      100%{opacity:0;transform:translate(-50%,-50%) rotate(330deg) scale(1.12)}
    }
    @keyframes chessQuantumSparks{
      0%{opacity:0;transform:translate(-50%,-50%) scale(.35) rotate(-12deg)}
      24%{opacity:1;transform:translate(-50%,-50%) scale(.85) rotate(2deg)}
      74%{opacity:.78;transform:translate(-50%,-50%) scale(1.22) rotate(10deg)}
      100%{opacity:0;transform:translate(-50%,-50%) scale(1.5) rotate(18deg)}
    }
    @keyframes chessQuantumShimmer{
      0%{opacity:0;transform:translate(-50%,-50%) scale(.35)}
      34%{opacity:.9;transform:translate(-50%,-50%) scale(.92)}
      100%{opacity:0;transform:translate(-50%,-50%) scale(1.2)}
    }

    @media(prefers-reduced-motion:reduce){
      #gameBoard[data-game-type="chess"] .chess-live-move-v3,
      #gameBoard[data-game-type="chess"] .chess-live-quantum-v1{display:none!important}
      #gameBoard[data-game-type="chess"][data-chess-move-fx-v3="1"] .chess-piece.moved-fresh:not(.castle-rook-fresh){animation:none!important}
    }
  `;
  document.head.appendChild(style);
}
