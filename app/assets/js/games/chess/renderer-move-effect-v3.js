import {
  renderChessSurface as renderBaseChessSurface,
  chessMeta,
  chessPlayerMark,
  chessStatus,
} from './renderer.js?v=70&mvp19_5=cosmetics&fx_runtime=landing-sync-v2&move_parity=store-trail-v1&base_owner=1';

const MOVE_EFFECT_ITEM = 'game-chess-effect-move';
const playedMoveFxByGame = new Map();
const cachedMoveEffectByGamePlayer = new Map();

ensureMoveEffectV3Styles();

export { chessMeta, chessPlayerMark, chessStatus };

export function renderChessSurface(args){
  const { game, me, container } = args || {};
  const gameId = String(game?.id || '');
  const players = Array.isArray(game?.players) ? game.players : [];
  cacheMoveEffectOwnership(gameId, players);

  const lastMove = game?.last_move || null;
  const moveKey = chessMoveKey(game, lastMove);
  const moverId = String(lastMove?.player_id || playerIdForSide(players, lastMove?.side) || '');
  const directEffect = String(players.find(player => String(player?.id || '') === moverId)?.game_cosmetics?.slots?.game_chess_effect || '');
  const cachedEffect = String(cachedMoveEffectByGamePlayer.get(effectCacheKey(gameId, moverId)) || '');
  const equippedEffect = directEffect || cachedEffect;
  const ownsMoveEffect = equippedEffect === MOVE_EFFECT_ITEM;

  // Keep the accepted board/runtime owner. For Move only, strip the slot from
  // the projection passed to the base renderer so its old thick landing waves
  // and delayed surface hold cannot run. Capture and Check pass through unchanged.
  const baseGame = ownsMoveEffect ? withoutMoveEffectSlot(game, moverId) : game;
  renderBaseChessSurface({ ...args, game:baseGame });

  if (!ownsMoveEffect || !moveKey || !lastMove || !container?.querySelector) return;
  if (playedMoveFxByGame.get(gameId) === moveKey) return;

  // One physical move owns one visual. Optimistic and authoritative projections
  // share this stable key, so the authoritative response cannot replay the piece,
  // trail, or create a delayed post-effect twitch.
  playedMoveFxByGame.set(gameId, moveKey);

  const viewerSide = String(game?.viewer_side || playerSide(game, String(me?.id || '')) || 'white');
  renderMoveEffectV3(container, {
    from:Number(lastMove?.from),
    to:Number(lastMove?.to),
    viewerSide,
  });
}

function withoutMoveEffectSlot(game, moverId){
  if (!game || typeof game !== 'object') return game;
  const players = Array.isArray(game.players) ? game.players : [];
  let changed = false;
  const nextPlayers = players.map(player => {
    if (String(player?.id || '') !== moverId) return player;
    const cosmetics = player?.game_cosmetics;
    const slots = cosmetics?.slots;
    if (!slots || String(slots.game_chess_effect || '') !== MOVE_EFFECT_ITEM) return player;
    changed = true;
    return {
      ...player,
      game_cosmetics:{
        ...cosmetics,
        slots:{ ...slots, game_chess_effect:'' },
      },
    };
  });
  return changed ? { ...game, players:nextPlayers } : game;
}

function cacheMoveEffectOwnership(gameId, players){
  if (!gameId) return;
  players.forEach(player => {
    const playerId = String(player?.id || '');
    if (!playerId) return;
    const effect = String(player?.game_cosmetics?.slots?.game_chess_effect || '');
    if (effect === MOVE_EFFECT_ITEM) {
      cachedMoveEffectByGamePlayer.set(effectCacheKey(gameId, playerId), effect);
    }
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

function playerIdForSide(players, side){
  return String(players.find(player => String(player?.side || '') === String(side || ''))?.id || '');
}

function playerSide(game, playerId){
  return (game?.players || []).find(player => String(player?.id || '') === playerId)?.side || '';
}

function renderMoveEffectV3(container, fx){
  const target = container.querySelector(`[data-chess-cell="${fx.to}"]`);
  if (!target) return;

  target.querySelectorAll('.chess-live-move-v3').forEach(node => node.remove());

  const motion = motionBetween(fx.from, fx.to, fx.viewerSide);
  const trailDots = Array.from({ length:11 }, (_, index) => {
    const delay = 10 + index * 28;
    const size = Math.max(4.8, 8.2 - index * .30);
    const opacity = Math.max(.50, .98 - index * .04);
    return `<i style="--fx-delay:${delay}ms;--fx-size:${size}%;--fx-opacity:${opacity}"></i>`;
  }).join('');

  const gatherPoints = [
    [-18,-5],[-14,-14],[-5,-18],[6,-18],[15,-12],[19,-2],
    [16,10],[8,17],[-3,19],[-13,14],[-19,6],[-16,-7],
  ];
  const gatherDots = gatherPoints.map(([x,y], index) => {
    const delay = 365 + index * 12;
    return `<i style="--g-x:${x}px;--g-y:${y}px;--g-delay:${delay}ms"></i>`;
  }).join('');

  target.insertAdjacentHTML('beforeend', `
    <span class="chess-live-move-v3 chess-live-move-v3-trail" aria-hidden="true" style="--trail-x:${motion.x}%;--trail-y:${motion.y}%">
      ${trailDots}
    </span>
    <span class="chess-live-move-v3 chess-live-move-v3-gather" aria-hidden="true">
      ${gatherDots}<b></b>
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

function ensureMoveEffectV3Styles(){
  if (typeof document === 'undefined' || document.getElementById('mgwChessMoveEffectV3')) return;
  const style = document.createElement('style');
  style.id = 'mgwChessMoveEffectV3';
  style.textContent = `
    #gameBoard[data-game-type="chess"] .chess-live-move-v3{
      position:absolute;inset:0;pointer-events:none;overflow:visible;isolation:isolate
    }
    #gameBoard[data-game-type="chess"] .chess-live-move-v3-trail{z-index:10}
    #gameBoard[data-game-type="chess"] .chess-live-move-v3-trail i{
      position:absolute;
      left:calc(50% + var(--trail-x,0%));
      top:calc(50% + var(--trail-y,0%));
      width:var(--fx-size,6%);
      aspect-ratio:1;
      border-radius:50%;
      background:radial-gradient(circle at 34% 30%,#fff 0 13%,rgba(130,243,255,.98) 32%,rgba(72,196,255,.88) 58%,rgba(91,113,255,.20) 76%,transparent 80%);
      box-shadow:0 0 5px rgba(70,226,255,.92),0 0 10px rgba(83,127,255,.42);
      opacity:0;
      transform:translate(-50%,-50%) scale(.58);
      animation:chessMoveV3Trail .52s cubic-bezier(.20,.72,.20,1) var(--fx-delay,0ms) both;
    }
    #gameBoard[data-game-type="chess"] .chess-live-move-v3-gather{z-index:11}
    #gameBoard[data-game-type="chess"] .chess-live-move-v3-gather i,
    #gameBoard[data-game-type="chess"] .chess-live-move-v3-gather b{
      position:absolute;left:50%;top:50%;border-radius:50%;pointer-events:none
    }
    #gameBoard[data-game-type="chess"] .chess-live-move-v3-gather i{
      width:6%;aspect-ratio:1;
      background:radial-gradient(circle,#fff 0 10%,rgba(118,241,255,.98) 34%,rgba(75,184,255,.76) 64%,transparent 80%);
      box-shadow:0 0 5px rgba(74,228,255,.78);
      opacity:0;
      animation:chessMoveV3Gather .26s cubic-bezier(.18,.72,.20,1) var(--g-delay,365ms) both;
    }
    #gameBoard[data-game-type="chess"] .chess-live-move-v3-gather b{
      width:7.5%;aspect-ratio:1;
      background:radial-gradient(circle,#fff 0 18%,rgba(115,239,255,.94) 42%,rgba(80,169,255,.40) 68%,transparent 80%);
      box-shadow:0 0 7px rgba(74,226,255,.65);
      opacity:0;
      animation:chessMoveV3Core .18s ease-out .50s both;
    }
    @keyframes chessMoveV3Trail{
      0%{left:calc(50% + var(--trail-x,0%));top:calc(50% + var(--trail-y,0%));opacity:0;transform:translate(-50%,-50%) scale(.48)}
      12%{opacity:var(--fx-opacity,.9)}
      65%{opacity:.76;transform:translate(-50%,-50%) scale(1)}
      100%{left:50%;top:50%;opacity:0;transform:translate(-50%,-50%) scale(.25)}
    }
    @keyframes chessMoveV3Gather{
      0%{opacity:0;transform:translate(-50%,-50%) translate(var(--g-x),var(--g-y)) scale(.48)}
      18%{opacity:.94}
      72%{opacity:.78;transform:translate(-50%,-50%) translate(0,0) scale(.86)}
      100%{opacity:0;transform:translate(-50%,-50%) translate(0,0) scale(.20)}
    }
    @keyframes chessMoveV3Core{
      0%{opacity:0;transform:translate(-50%,-50%) scale(.28)}
      42%{opacity:.88;transform:translate(-50%,-50%) scale(.86)}
      100%{opacity:0;transform:translate(-50%,-50%) scale(.18)}
    }
    @media(prefers-reduced-motion:reduce){
      #gameBoard[data-game-type="chess"] .chess-live-move-v3{display:none!important}
    }
  `;
  document.head.appendChild(style);
}
