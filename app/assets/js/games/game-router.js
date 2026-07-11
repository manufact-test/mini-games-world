import {
  renderTicTacToeSurface,
  ticTacToeMeta,
  ticTacToePlayerMark,
} from './tictactoe-renderer.js?v=47';
import {
  renderFourInARowSurface,
  fourInARowMeta,
  fourInARowPlayerMark,
} from './four-in-a-row-renderer.js?v=50';

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
  const explicit = String(game?.game_type || '');
  const renderer = String(game?.renderer || '');
  const rows = Number(game?.board_rows || 0);
  const connectLength = Number(game?.connect_length || 0);

  // Be tolerant of a partially cached response from the first v49 deployment.
  // The server still exposes renderer/dimensions, so the client can recover the
  // correct game instead of falling back to the tic-tac-toe renderer.
  if (
    explicit === 'four_in_a_row'
    || renderer === 'four_in_a_row'
    || (rows >= 5 && connectLength === 4)
  ) {
    return 'four_in_a_row';
  }

  return explicit || 'tictactoe';
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
  return routes[gameTypeOf(game)] || null;
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
