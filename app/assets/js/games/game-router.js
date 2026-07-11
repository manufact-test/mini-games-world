import {
  renderTicTacToeSurface,
  ticTacToeMeta,
  ticTacToePlayerMark,
} from './tictactoe-renderer.js?v=47';
import {
  renderFourInARowSurface,
  fourInARowMeta,
  fourInARowPlayerMark,
} from './four-in-a-row-renderer.js?v=49';

const routes = {
  tictactoe: {
    render: renderTicTacToeSurface,
    meta: ticTacToeMeta,
    playerMark: ticTacToePlayerMark,
  },
  four_in_a_row: {
    render: renderFourInARowSurface,
    meta: fourInARowMeta,
    playerMark: fourInARowPlayerMark,
  },
};

export function gameTypeOf(game){
  return String(game?.game_type || 'tictactoe');
}

export function renderGameSurface({ game, me, container, onAction }){
  const route = routeFor(game);
  if (!route) {
    renderUnsupportedGame(container, game);
    return;
  }

  route.render({ game, me, container, onAction });
}

export function gameMetaText(game){
  const route = routeFor(game);
  if (route?.meta) return route.meta(game);

  const room = String(game?.room_name || 'Игра');
  const bet = Number(game?.bet || 0);
  return bet > 0 ? `${room} · ${bet} коинов` : room;
}

export function playerMarkText(game, player){
  const route = routeFor(game);
  return route?.playerMark ? route.playerMark(player) : '•';
}

function routeFor(game){
  const gameType = gameTypeOf(game);
  return routes[gameType] || null;
}

function renderUnsupportedGame(container, game){
  container.className = 'board game-surface-unsupported';
  container.innerHTML = `
    <div class="small-note">
      Экран игры «${escapeHtml(game?.game_title || gameTypeOf(game))}» пока не подключён.
    </div>
  `;
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
