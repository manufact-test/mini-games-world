import {
  renderChessSurface as renderBaseChessSurface,
  chessMeta,
  chessPlayerMark,
  chessStatus,
} from './renderer.js?v=70&mvp19_5=cosmetics&fx_runtime=landing-sync-v2&move_parity=store-trail-v1';

const effectByGamePlayer = new Map();
const seenMoveByGame = new Map();
const playedMoveFxByGame = new Map();
const activeMoveFxByGame = new Map();
const MOVE_FX_DURATION_MS = 860;
const MOVE_EFFECT_ITEM = 'game-chess-effect-move';

ensureMoveEffectV2Styles();

export { chessMeta, chessPlayerMark, chessStatus };

export function renderChessSurface(args){
  const { game, container } = args || {};
  const gameId = String(game?.id || '');
  const players = Array.isArray(game?.players) ? game.players : [];
  cachePlayerEffects(gameId, players);

  const pending = game?.__mgw_v100_pending_action || null;
  if (String(pending?.type || '') === 'chess_move') {
    clearPendingSelection(container);
    return;
  }

  const lastMove = game?.last_move || null;
  const moveKey = chessMoveKey(game, lastMove);
  const now = Date.now();
  const active = gameId ? activeMoveFxByGame.get(gameId) : null;

  // Keep one already-authoritative board surface alive only while its exact move
  // effect is visibly running. There is no timer or delayed forced rerender: a
  // later distinct move always renders immediately, while redundant same-move
  // polls cannot restart the piece or make it twitch on landing.
  if (active && active.key === moveKey && active.until > now) return;
  if (active && active.until <= now) activeMoveFxByGame.delete(gameId);

  renderBaseChessSurface(args);

  if (!gameId) return;

  if (!seenMoveByGame.has(gameId)) {
    seenMoveByGame.set(gameId, moveKey);
    return;
  }

  if (moveKey && seenMoveByGame.get(gameId) !== moveKey) {
    seenMoveByGame.set(gameId, moveKey);
  }

  if (!moveKey || !lastMove) return;

  const moverId = String(lastMove?.player_id || playerIdForSide(players, lastMove?.side) || '');
  const directEffect = String(players.find(player => String(player?.id || '') === moverId)?.game_cosmetics?.slots?.game_chess_effect || '');
  const cachedEffect = String(effectByGamePlayer.get(effectCacheKey(gameId, moverId)) || '');
  const equippedEffect = directEffect || cachedEffect;
  if (equippedEffect !== MOVE_EFFECT_ITEM) return;

  if (playedMoveFxByGame.get(gameId) !== moveKey) {
    playedMoveFxByGame.set(gameId, moveKey);
    const viewerSide = String(game?.viewer_side || playerSide(game, String(args?.me?.id || '')) || 'white');
    const fx = {
      key:moveKey,
      from:Number(lastMove?.from),
      to:Number(lastMove?.to),
      viewerSide,
      startedAt:now,
      until:now + MOVE_FX_DURATION_MS,
    };
    activeMoveFxByGame.set(gameId, fx);
    renderMoveEffectV2(container, fx, 0);
  }
}

function cachePlayerEffects(gameId, players){
  if (!gameId) return;
  players.forEach(player => {
    const playerId = String(player?.id || '');
    if (!playerId) return;
    const effect = String(player?.game_cosmetics?.slots?.game_chess_effect || '');
    if (effect) effectByGamePlayer.set(effectCacheKey(gameId, playerId), effect);
  });
}

function effectCacheKey(gameId, playerId){
  return `${gameId}:${playerId}`;
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

function clearPendingSelection(container){
  if (!container?.querySelectorAll) return;
  container.querySelectorAll('.chess-cell.selected,.chess-cell.legal-target,.chess-cell.capture-target').forEach(node => {
    node.classList.remove('selected','legal-target','capture-target');
  });
  container.querySelectorAll('.chess-move-dot,.chess-capture-ring').forEach(node => node.remove());
}

function renderMoveEffectV2(container, fx, elapsedMs){
  if (!container?.querySelector) return;
  const target = container.querySelector(`[data-chess-cell="${fx.to}"]`);
  if (!target) return;

  target.querySelectorAll('.chess-live-move-v2').forEach(node => node.remove());

  const motion = motionBetween(fx.from, fx.to, fx.viewerSide);
  const elapsed = Math.max(0, Number(elapsedMs) || 0);
  const trailDots = Array.from({ length:9 }, (_, index) => {
    const delay = 18 + index * 34 - elapsed;
    const size = Math.max(4.2, 7.3 - index * .35);
    return `<i style="--fx-delay:${delay}ms;--fx-size:${size}%"></i>`;
  }).join('');

  const gatherPoints = [
    [-17,-4],[-13,-13],[-4,-17],[7,-16],[16,-8],
    [18,3],[11,14],[0,18],[-12,13],[-18,6],
  ];
  const gatherDots = gatherPoints.map(([x,y], index) => {
    const delay = 375 + index * 18 - elapsed;
    return `<i style="--g-x:${x}px;--g-y:${y}px;--g-delay:${delay}ms"></i>`;
  }).join('');
  const coreDelay = 605 - elapsed;

  target.insertAdjacentHTML('beforeend', `
    <span class="chess-live-move-v2 chess-live-move-v2-trail" aria-hidden="true" style="--trail-x:${motion.x}%;--trail-y:${motion.y}%">
      ${trailDots}
    </span>
    <span class="chess-live-move-v2 chess-live-move-v2-gather" aria-hidden="true">
      ${gatherDots}<b style="--core-delay:${coreDelay}ms"></b>
    </span>
  `);
}

function motionBetween(from, to, viewerSide){
  const fromDisplay = viewerSide === 'black' ? 63 - from : from;
  const toDisplay = viewerSide === 'black' ? 63 - to : to;
  return {
    x:(fromDisplay % 8 - toDisplay % 8) * 100,
    y:(Math.floor(fromDisplay / 8) - Math.floor(toDisplay / 8)) * 100,
  };
}

function playerIdForSide(players, side){
  return String(players.find(player => String(player?.side || '') === String(side || ''))?.id || '');
}

function playerSide(game, playerId){
  return (game?.players || []).find(player => String(player?.id || '') === playerId)?.side || '';
}

function ensureMoveEffectV2Styles(){
  if (typeof document === 'undefined' || document.getElementById('mgwChessMoveEffectV2')) return;
  const style = document.createElement('style');
  style.id = 'mgwChessMoveEffectV2';
  style.textContent = `
    /* Hide the superseded fat-ring Move presentation from the base renderer. */
    #gameBoard[data-game-type="chess"] .chess-fx-layer.chess-fx-move,
    #gameBoard[data-game-type="chess"] .chess-fx-trail{display:none!important}

    #gameBoard[data-game-type="chess"] .chess-live-move-v2{
      position:absolute;inset:0;pointer-events:none;overflow:visible;isolation:isolate
    }
    #gameBoard[data-game-type="chess"] .chess-live-move-v2-trail{z-index:10}
    #gameBoard[data-game-type="chess"] .chess-live-move-v2-trail i{
      position:absolute;
      left:calc(50% + var(--trail-x,0%));
      top:calc(50% + var(--trail-y,0%));
      width:var(--fx-size,6%);
      aspect-ratio:1;
      border-radius:50%;
      background:radial-gradient(circle at 35% 30%,#fff 0 14%,rgba(127,241,255,.98) 34%,rgba(82,188,255,.82) 61%,rgba(106,91,255,.18) 78%,transparent 80%);
      box-shadow:0 0 4px rgba(74,222,255,.84),0 0 8px rgba(93,126,255,.34);
      opacity:0;
      transform:translate(-50%,-50%) scale(.55);
      animation:chessMoveV2Trail .46s cubic-bezier(.22,.7,.22,1) var(--fx-delay,0ms) both;
    }

    #gameBoard[data-game-type="chess"] .chess-live-move-v2-gather{z-index:11}
    #gameBoard[data-game-type="chess"] .chess-live-move-v2-gather i,
    #gameBoard[data-game-type="chess"] .chess-live-move-v2-gather b{
      position:absolute;left:50%;top:50%;border-radius:50%;pointer-events:none
    }
    #gameBoard[data-game-type="chess"] .chess-live-move-v2-gather i{
      width:6.4%;aspect-ratio:1;
      background:radial-gradient(circle,#fff 0 12%,rgba(115,238,255,.96) 34%,rgba(91,174,255,.72) 66%,transparent 78%);
      box-shadow:0 0 4px rgba(70,224,255,.75);
      opacity:0;
      animation:chessMoveV2Gather .31s cubic-bezier(.2,.72,.22,1) var(--g-delay,380ms) both;
    }
    #gameBoard[data-game-type="chess"] .chess-live-move-v2-gather b{
      width:9%;aspect-ratio:1;
      background:radial-gradient(circle,#fff 0 16%,rgba(117,240,255,.98) 38%,rgba(80,170,255,.62) 68%,transparent 80%);
      box-shadow:0 0 6px rgba(75,226,255,.72);
      opacity:0;
      animation:chessMoveV2Core .22s ease-out var(--core-delay,605ms) both;
    }

    @keyframes chessMoveV2Trail{
      0%{left:calc(50% + var(--trail-x,0%));top:calc(50% + var(--trail-y,0%));opacity:0;transform:translate(-50%,-50%) scale(.42)}
      14%{opacity:.92}
      62%{opacity:.82;transform:translate(-50%,-50%) scale(.88)}
      100%{left:50%;top:50%;opacity:0;transform:translate(-50%,-50%) scale(.26)}
    }
    @keyframes chessMoveV2Gather{
      0%{opacity:0;transform:translate(-50%,-50%) translate(var(--g-x),var(--g-y)) scale(.46)}
      18%{opacity:.94}
      70%{opacity:.82;transform:translate(-50%,-50%) translate(0,0) scale(.82)}
      100%{opacity:0;transform:translate(-50%,-50%) translate(0,0) scale(.18)}
    }
    @keyframes chessMoveV2Core{
      0%{opacity:0;transform:translate(-50%,-50%) scale(.25)}
      40%{opacity:.9;transform:translate(-50%,-50%) scale(.9)}
      100%{opacity:0;transform:translate(-50%,-50%) scale(.18)}
    }
    @media(prefers-reduced-motion:reduce){
      #gameBoard[data-game-type="chess"] .chess-live-move-v2{display:none!important}
    }
  `;
  document.head.appendChild(style);
}
