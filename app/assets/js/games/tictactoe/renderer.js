const previousBoards = new Map();
const previousVisualSignatures = new Map();

const EFFECT_VARIANTS = Object.freeze({
  'game-ttt-effect-sign':'impact',
  'game-ttt-effect-winning-line':'sparks',
  'game-ttt-effect-strike':'wave',
});
const EFFECT_CLASSES = Object.freeze({
  impact:'ttt-fx-impact',
  sparks:'ttt-fx-sparks',
  wave:'ttt-fx-wave',
});

export function renderTicTacToeSurface({ game, me, container, onAction }){
  const boardSize = Number(game?.board_size || 3);
  const board = String(game?.board || '');
  const players = Array.isArray(game?.players) ? game.players : [];
  const viewer = players.find(player => String(player?.id || '') === String(me?.id || '')) || null;
  const opponent = players.find(player => String(player?.id || '') !== String(me?.id || '')) || null;
  const playerBySymbol = new Map(players.map(player => [String(player?.symbol || ''), player]));
  const gameKey = String(game?.id || `local:${boardSize}`);
  const previousBoard = previousBoards.get(gameKey);
  const changedCell = changedCellIndex(previousBoard, board);
  const visualSignature = visualSignatureFor(boardSize, players);
  const canReuseBoardDom = previousBoard === board
    && previousVisualSignatures.get(gameKey) === visualSignature
    && String(container.dataset.tttGameKey || '') === gameKey
    && container.querySelectorAll('[data-game-cell]').length === board.length;

  previousBoards.set(gameKey, board);
  previousVisualSignatures.set(gameKey, visualSignature);

  const cosmeticsVisible = players.some(player => Object.keys(equippedSlots(player)).length > 0);
  const viewerMoveEffect = effectVariantFor(viewer);
  container.className = `board size-${boardSize}${cosmeticsVisible ? ' ttt-cosmetics' : ''}${viewerMoveEffect ? ' ttt-move-effect-enabled' : ''}`;
  container.dataset.gameType = 'tictactoe';
  container.dataset.tttGameKey = gameKey;
  container.dataset.tttTheme = variantFor(viewer, 'game_tictactoe_theme', 'field');
  container.dataset.tttOpponentTheme = variantFor(opponent, 'game_tictactoe_theme', 'field');

  // Polling can render the same authoritative board several times per second.
  // Replacing innerHTML on every identical snapshot destroys the one-shot mark
  // animation before Impact/Sparks/Wave can finish. Preserve the existing DOM
  // while the board and its visual cosmetics are unchanged; only move
  // availability needs to be synchronized on those repeated snapshots.
  if (canReuseBoardDom) {
    updateCellInteractivity(container, game, me, board);
    return;
  }

  container.innerHTML = board.split('').map((cell, index) => {
    const isEmpty = cell === '-';
    const owner = playerBySymbol.get(cell) || null;
    const canMove = game.status === 'active'
      && String(game.turn) === String(me?.id || '')
      && isEmpty;
    const label = cell === '-' ? '' : (cell === 'X' ? '✕' : '○');
    const markVariant = variantFor(owner, 'game_tictactoe_elements', 'marks');
    const moveEffect = index === changedCell ? effectVariantFor(owner) : '';
    const moveEffectClass = EFFECT_CLASSES[moveEffect] || '';
    const classes = [
      'cell',
      cell === 'X' ? 'x' : (cell === 'O' ? 'o' : ''),
      canMove ? '' : 'locked',
    ].filter(Boolean).join(' ');
    const markClasses = [
      'ttt-mark',
      moveEffectClass ? 'ttt-effect-mark' : '',
      moveEffectClass,
    ].filter(Boolean).join(' ');

    return `<button class="${classes}" data-game-cell="${index}" data-ttt-mark="${markVariant}" ${canMove ? '' : 'disabled'} type="button" aria-label="${label}">${label ? `<span class="${markClasses}" aria-hidden="true"><span class="ttt-mark-glyph">${label}</span></span>` : ''}</button>`;
  }).join('');

  bindCellActions(container, onAction);
}

function bindCellActions(container, onAction){
  container.querySelectorAll('[data-game-cell]').forEach(button => {
    button.addEventListener('click', () => {
      onAction?.({
        type: 'cell',
        cell: Number(button.dataset.gameCell),
      });
    });
  });
}

function updateCellInteractivity(container, game, me, board){
  const myTurn = game.status === 'active' && String(game.turn) === String(me?.id || '');
  container.querySelectorAll('[data-game-cell]').forEach(button => {
    const index = Number(button.dataset.gameCell);
    const canMove = myTurn && board[index] === '-';
    button.disabled = !canMove;
    button.classList.toggle('locked', !canMove);
  });
}

function visualSignatureFor(boardSize, players){
  const visualPlayers = players.map(player => {
    const slots = equippedSlots(player);
    return [
      String(player?.id || ''),
      String(player?.symbol || ''),
      String(slots.game_tictactoe_theme || ''),
      String(slots.game_tictactoe_elements || ''),
      String(slots.game_tictactoe_effect || ''),
      String(slots.game_tictactoe_effect_sign || ''),
      String(slots.game_tictactoe_effect_winning_line || ''),
      String(slots.game_tictactoe_effect_strike_through || ''),
    ];
  }).sort((left, right) => left[0].localeCompare(right[0]));

  return JSON.stringify([boardSize, visualPlayers]);
}

function equippedSlots(player){
  const slots = player?.game_cosmetics?.slots;
  return slots && typeof slots === 'object' ? slots : {};
}

function effectVariantFor(player){
  const slots = equippedSlots(player);
  const current = String(slots.game_tictactoe_effect || '');
  if (EFFECT_VARIANTS[current]) return EFFECT_VARIANTS[current];

  // Deployment-race fallback only. Migration 0018 collapses these legacy slots into one slot.
  for (const legacySlot of [
    'game_tictactoe_effect_sign',
    'game_tictactoe_effect_winning_line',
    'game_tictactoe_effect_strike_through',
  ]) {
    const itemId = String(slots[legacySlot] || '');
    if (EFFECT_VARIANTS[itemId]) return EFFECT_VARIANTS[itemId];
  }
  return '';
}

function variantFor(player, slot, family){
  const itemId = String(equippedSlots(player)[slot] || '');
  if (!itemId) return 'base';
  const marker = `game-ttt-${family}-`;
  return itemId.startsWith(marker) ? itemId.slice(marker.length) : 'base';
}

function changedCellIndex(previous, current){
  if (typeof previous !== 'string' || previous.length !== current.length) return -1;
  let changed = -1;
  for (let index = 0; index < current.length; index++) {
    if (previous[index] === current[index]) continue;
    if (changed !== -1) return -1;
    changed = index;
  }
  return changed;
}

export function ticTacToeMeta(game){
  return `${game.room_name} · ${game.bet} коинов · ${game.board_size}×${game.board_size}`;
}

export function ticTacToePlayerMark(player){
  if (player?.symbol === 'X') return '✕';
  if (player?.symbol === 'O') return '◯';
  return '•';
}
