import {
  renderTicTacToeSurface,
  ticTacToeMeta,
  ticTacToePlayerMark,
} from './tictactoe/renderer.js?v=53';
import {
  renderFourInARowSurface,
  fourInARowMeta,
  fourInARowPlayerMark,
} from './four-in-a-row/renderer.js?v=53';
import {
  renderBattleshipSurface,
  battleshipMeta,
  battleshipPlayerMark,
  battleshipStatus,
} from './battleship/renderer.js?v=56';
import {
  renderCheckersSurface,
  checkersMeta,
  checkersPlayerMark,
  checkersStatus,
} from './checkers/renderer.js?v=57';
import {
  renderReversiSurface,
  reversiMeta,
  reversiPlayerMark,
  reversiStatus,
} from './reversi/renderer.js?v=66';
import {
  renderChessSurface,
  chessMeta,
  chessPlayerMark,
  chessStatus,
} from './chess/renderer.js?v=68';
import {
  renderGoSurface,
  goMeta,
  goPlayerMark,
  goStatus,
} from './go/renderer.js?v=70';
import {
  renderDominoSurface,
  dominoMeta,
  dominoPlayerMark,
  dominoStatus,
} from './domino/renderer.js?v=72';

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
  battleship: {
    render: renderBattleshipSurface,
    meta: battleshipMeta,
    playerMark: battleshipPlayerMark,
    status: battleshipStatus,
  },
  checkers: {
    render: renderCheckersSurface,
    meta: checkersMeta,
    playerMark: checkersPlayerMark,
    status: checkersStatus,
  },
  reversi: {
    render: renderReversiSurface,
    meta: reversiMeta,
    playerMark: reversiPlayerMark,
    status: reversiStatus,
  },
  chess: {
    render: renderChessSurface,
    meta: chessMeta,
    playerMark: chessPlayerMark,
    status: chessStatus,
  },
  go: {
    render: renderGoSurface,
    meta: goMeta,
    playerMark: goPlayerMark,
    status: goStatus,
  },
  domino: {
    render: renderDominoSurface,
    meta: dominoMeta,
    playerMark: dominoPlayerMark,
    status: dominoStatus,
  },
};

export function gameTypeOf(game){
  const explicit = String(game?.game_type || '');
  const renderer = String(game?.renderer || '');
  const actionType = String(game?.action_type || '');
  const title = String(game?.game_title || '').toLowerCase();
  const columns = Number(game?.board_columns || 0);
  const rows = Number(game?.board_rows || 0);
  const connectLength = Number(game?.connect_length || 0);
  const boardArrayLength = Array.isArray(game?.board) ? game.board.length : 0;
  const boardStringLength = typeof game?.board === 'string' ? game.board.length : 0;

  if (
    explicit === 'domino'
    || renderer === 'domino'
    || actionType === 'domino_action'
    || title.includes('домино')
    || title.includes('domino')
    || Boolean(game?.domino_initialized)
    || Array.isArray(game?.viewer_hand)
    || Array.isArray(game?.chain)
  ) {
    return 'domino';
  }

  if (
    explicit === 'go'
    || renderer === 'go'
    || actionType === 'go_action'
    || title === 'го'
    || title.includes('go')
    || Boolean(game?.go_initialized)
    || Boolean(game?.go_sides)
    || Boolean(game?.final_score?.komi)
  ) {
    return 'go';
  }

  if (
    explicit === 'chess'
    || renderer === 'chess'
    || actionType === 'chess_move'
    || title.includes('шахмат')
    || title.includes('chess')
    || Boolean(game?.chess_initialized)
    || Boolean(game?.chess_sides)
    || (boardArrayLength === 64 && Boolean(game?.king_cells))
  ) {
    return 'chess';
  }

  if (
    explicit === 'reversi'
    || renderer === 'reversi'
    || title.includes('реверси')
    || title.includes('reversi')
    || Boolean(game?.reversi_initialized)
    || Boolean(game?.reversi_sides)
    || Boolean(game?.final_counts && Object.prototype.hasOwnProperty.call(game.final_counts, 'black'))
  ) {
    return 'reversi';
  }

  if (
    explicit === 'checkers'
    || renderer === 'checkers'
    || actionType === 'checkers_move'
    || title.includes('шашк')
    || title.includes('checkers')
    || Boolean(game?.checkers_initialized)
    || Boolean(game?.checkers_sides)
    || (boardArrayLength === 64 && Number(game?.board_size) === 8 && Boolean(game?.viewer_side))
  ) {
    return 'checkers';
  }

  if (
    explicit === 'battleship'
    || renderer === 'battleship'
    || actionType === 'battleship_action'
    || title.includes('морской бой')
    || title.includes('battleship')
    || Boolean(game?.battleship_initialized)
    || Array.isArray(game?.my_board)
    || Array.isArray(game?.enemy_board)
    || Boolean(game?.phase === 'setup' && Number(game?.board_size) === 10)
  ) {
    return 'battleship';
  }

  const looksLikeFourBoard = columns >= 6
    && columns <= 8
    && rows === columns - 1
    && boardStringLength === columns * rows;

  if (
    explicit === 'four_in_a_row'
    || renderer === 'four_in_a_row'
    || actionType === 'column'
    || title.includes('4 в ряд')
    || title.includes('four in a row')
    || Boolean(game?.four_in_a_row_initialized)
    || (rows >= 5 && connectLength === 4)
    || looksLikeFourBoard
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

export function gameStatusText(game, me){
  const route = routeFor(game);
  if (route?.status) return route.status(game, me);
  if (game?.status === 'finished') return 'Игра завершена';
  return String(game?.turn || '') === String(me?.id || '') ? 'Ваш ход' : 'Ход соперника';
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
