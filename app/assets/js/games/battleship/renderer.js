import { toast } from '../../components/toast.js?v=41';
import { t, formatNumber as formatLocalizedNumber } from '@mgw/i18n';

const battleshipText = (key, params = {}) => t(`games.battleship.ui.${key}`, params);

let activeGameId = '';
let selectedShipSize = 4;
let pendingPlacementCells = [];
let invalidPlacementCell = -1;
let invalidPlacementTimer = null;
let battleView = 'enemy';
let lastTurnViewKey = '';
let lastShotViewKey = '';
let lastAnimatedShotKey = '';
let battleNotice = null;
let battleTransitionTimer = null;

export function renderBattleshipSurface({ game, me, container, onAction }){
  resetUiForNewGame(game);
  container.className = 'board battleship-surface';
  container.dataset.gameType = 'battleship';

  if (game?.phase === 'setup') {
    renderSetup({ game, container, onAction });
    return;
  }

  pendingPlacementCells = [];
  renderBattle({ game, me, container, onAction });
}

export function battleshipMeta(game){
  const room = String(game?.room_name || battleshipText('game_fallback'));
  const bet = formatLocalizedNumber(Number(game?.bet || 0));
  return battleshipText('meta', { room, bet });
}

export function battleshipPlayerMark(player){
  return player?.ready ? battleshipText('player.ready') : battleshipText('player.fleet');
}

export function battleshipStatus(game, me){
  if (game?.status === 'finished') return battleshipText('status.finished');
  if (game?.phase === 'setup') {
    if (game?.my_ready) return game?.opponent_ready ? battleshipText('status.starting') : battleshipText('status.waiting_opponent');
    return battleshipText('status.place_ships');
  }
  return String(game?.turn || '') === String(me?.id || '') ? battleshipText('status.your_shot') : battleshipText('status.opponent_turn');
}

function resetUiForNewGame(game){
  const gameId = String(game?.id || '');
  if (gameId === activeGameId) return;

  activeGameId = gameId;
  selectedShipSize = 4;
  pendingPlacementCells = [];
  invalidPlacementCell = -1;
  battleView = 'enemy';
  lastTurnViewKey = '';
  lastShotViewKey = '';
  lastAnimatedShotKey = '';
  battleNotice = null;
  clearInvalidPlacementTimer();
  clearBattleTransitionTimer();
}

function renderSetup({ game, container, onAction }){
  const ships = Array.isArray(game?.my_fleet) ? game.my_fleet : [];
  const placedCount = ships.length;
  const remaining = remainingBySize(game);

  if ((remaining[selectedShipSize] ?? 0) <= 0) {
    selectedShipSize = firstAvailableSize(remaining);
    pendingPlacementCells = [];
  }

  const complete = placedCount === 10 && Object.values(remaining).every(value => value === 0);
  const selectedLeft = remaining[selectedShipSize] ?? 0;
  const selectedProgress = pendingPlacementCells.length;

  container.innerHTML = `
    <div class="battleship-panel battleship-setup-panel">
      <div class="battleship-setup-head">
        <div class="battleship-setup-title"><strong>${battleshipText('setup.title')}</strong><span>${battleshipText('setup.ship_count',{count:formatLocalizedNumber(placedCount)})}</span></div>
        <div class="battleship-current-ship ${complete ? 'ready' : ''}" aria-live="polite">
          ${complete ? `
            <strong>${battleshipText('setup.fleet_ready')}</strong>
          ` : `
            <small>${battleshipText('setup.placing')}</small>
            <span class="battleship-current-ship-dots">${'<i></i>'.repeat(selectedShipSize)}</span>
            <b>×${selectedLeft}</b>
          `}
        </div>
        <span class="battleship-setup-time">${formatTime(game?.setup_time_left ?? game?.time_left ?? 120)}</span>
      </div>

      ${complete && !game?.my_ready ? `
        <div class="battleship-ready-callout">
          <div>
            <strong>${battleshipText('setup.ready_title')}</strong>
            <span>${battleshipText('setup.ready_note')}</span>
          </div>
          <button class="btn primary" data-battleship-ready type="button">${battleshipText('setup.ready_button')}</button>
        </div>
      ` : ''}

      ${renderCoordinateBoard(game?.my_board || [], {
        mode:'setup',
        interactive:true,
        pendingCells:pendingPlacementCells,
        invalidCell:invalidPlacementCell,
      })}

      <div class="battleship-fleet-picker">
        ${[4,3,2,1].map(size => {
          const left = remaining[size] ?? 0;
          return `<button class="battleship-ship-choice ${selectedShipSize === size && left > 0 ? 'active' : ''} ${left <= 0 ? 'done' : ''}" data-battleship-size="${size}" type="button" ${left <= 0 ? 'disabled' : ''}>
            <span class="battleship-ship-dots">${'<i></i>'.repeat(size)}</span>
            <small>${left > 0 ? battleshipText('setup.remaining',{count:formatLocalizedNumber(left)}) : battleshipText('setup.done')}</small>
          </button>`;
        }).join('')}
      </div>

      ${!complete ? `
        <div class="battleship-placement-guide">
          <strong>${selectedLeft > 0 ? battleshipText('setup.guide_ship',{size:formatLocalizedNumber(selectedShipSize),cells:cellWord(selectedShipSize)}) : battleshipText('setup.guide_next')}</strong>
          <span>${selectedLeft > 0
            ? (selectedProgress > 0
              ? battleshipText('setup.guide_selected',{selected:formatLocalizedNumber(selectedProgress),size:formatLocalizedNumber(selectedShipSize)})
              : battleshipText('setup.guide_press',{size:formatLocalizedNumber(selectedShipSize),cells:cellWord(selectedShipSize)}))
            : battleshipText('setup.guide_choose_remaining')}</span>
        </div>
      ` : ''}

      <div class="battleship-setup-actions">
        <button class="btn primary" data-battleship-randomize type="button">${battleshipText('setup.randomize')}</button>
        <button class="btn ghost" data-battleship-clear type="button">${battleshipText('setup.clear')}</button>
      </div>

      <div class="small-note battleship-placement-note">${battleshipText('setup.note')}</div>
    </div>
  `;

  container.querySelectorAll('[data-battleship-size]').forEach(button => button.addEventListener('click', () => {
    selectedShipSize = Number(button.dataset.battleshipSize);
    pendingPlacementCells = [];
    invalidPlacementCell = -1;
    renderSetup({ game, container, onAction });
  }));

  container.querySelector('[data-battleship-randomize]')?.addEventListener('click', () => {
    pendingPlacementCells = [];
    invalidPlacementCell = -1;
    onAction?.({ type:'randomize_fleet' });
  });

  container.querySelector('[data-battleship-clear]')?.addEventListener('click', () => {
    pendingPlacementCells = [];
    invalidPlacementCell = -1;
    selectedShipSize = 4;
    onAction?.({ type:'clear_fleet' });
  });

  container.querySelector('[data-battleship-ready]')?.addEventListener('click', () => {
    pendingPlacementCells = [];
    invalidPlacementCell = -1;
    onAction?.({ type:'ready' });
  });

  container.querySelectorAll('[data-battleship-cell]').forEach(button => button.addEventListener('click', () => {
    const cell = Number(button.dataset.battleshipCell);
    const cellState = String(button.dataset.cellState || 'water');

    if (cellState === 'ship') {
      pendingPlacementCells = [];
      invalidPlacementCell = -1;
      onAction?.({ type:'remove_ship', cell });
      return;
    }

    if ((remaining[selectedShipSize] ?? 0) <= 0) return;
    selectPlacementCell({ cell, game, container, onAction });
  }));
}

function selectPlacementCell({ cell, game, container, onAction }){
  if (pendingPlacementCells.includes(cell)) {
    if (pendingPlacementCells[pendingPlacementCells.length - 1] === cell) {
      pendingPlacementCells.pop();
    } else {
      pendingPlacementCells = [];
      toast(battleshipText('errors.selection_reset'));
    }
    invalidPlacementCell = -1;
    renderSetup({ game, container, onAction });
    return;
  }

  const candidate = [...pendingPlacementCells, cell];
  const error = placementSelectionError(candidate, selectedShipSize, game?.my_board || []);
  if (error) {
    flashInvalidPlacement({ cell, game, container, onAction, message:error });
    return;
  }

  invalidPlacementCell = -1;
  pendingPlacementCells = candidate;
  if (pendingPlacementCells.length < selectedShipSize) {
    renderSetup({ game, container, onAction });
    return;
  }

  const placement = placementFromCells(pendingPlacementCells);
  pendingPlacementCells = [];
  if (!placement) {
    flashInvalidPlacement({ cell, game, container, onAction, message:battleshipText('errors.straight_no_gaps') });
    return;
  }

  onAction?.({
    type:'place_ship',
    size:selectedShipSize,
    cell:placement.startCell,
    orientation:placement.orientation,
  });
}

function flashInvalidPlacement({ cell, game, container, onAction, message }){
  invalidPlacementCell = cell;
  toast(message);
  renderSetup({ game, container, onAction });
  clearInvalidPlacementTimer();
  invalidPlacementTimer = setTimeout(() => {
    invalidPlacementCell = -1;
    invalidPlacementTimer = null;
    renderSetup({ game, container, onAction });
  }, 520);
}

function clearInvalidPlacementTimer(){
  if (invalidPlacementTimer) clearTimeout(invalidPlacementTimer);
  invalidPlacementTimer = null;
}

function placementSelectionError(cells, size, board){
  if (cells.length > size) return battleshipText('errors.too_many_cells');
  if (!cellsFormStraightContinuousLine(cells)) return battleshipText('errors.straight_continuous');

  const occupied = new Set();
  Array.from({ length:100 }, (_, cell) => {
    if (String(board?.[cell] || '') === 'ship') occupied.add(cell);
  });

  for (const cell of cells) {
    const row = Math.floor(cell / 10);
    const col = cell % 10;
    for (let dr = -1; dr <= 1; dr++) {
      for (let dc = -1; dc <= 1; dc++) {
        const r = row + dr;
        const c = col + dc;
        if (r < 0 || r >= 10 || c < 0 || c >= 10) continue;
        if (occupied.has(r * 10 + c)) {
          return battleshipText('errors.touching');
        }
      }
    }
  }

  return '';
}

function cellsFormStraightContinuousLine(cells){
  if (cells.length <= 1) return true;
  const sorted = [...cells].sort((a, b) => a - b);
  const sameRow = sorted.every(cell => Math.floor(cell / 10) === Math.floor(sorted[0] / 10));
  const sameColumn = sorted.every(cell => cell % 10 === sorted[0] % 10);

  if (sameRow) return sorted.every((cell, index) => cell === sorted[0] + index);
  if (sameColumn) return sorted.every((cell, index) => cell === sorted[0] + index * 10);
  return false;
}

function placementFromCells(cells){
  if (!cellsFormStraightContinuousLine(cells) || cells.length === 0) return null;
  const sorted = [...cells].sort((a, b) => a - b);
  const sameRow = sorted.every(cell => Math.floor(cell / 10) === Math.floor(sorted[0] / 10));
  return {
    startCell: sorted[0],
    orientation: sameRow ? 'h' : 'v',
  };
}

function renderBattle({ game, me, container, onAction }){
  const myTurn = game?.status === 'active' && String(game?.turn || '') === String(me?.id || '');
  syncBattleExperience({ game, me, myTurn, container, onAction });

  const showingEnemy = battleView !== 'own';
  const board = showingEnemy ? (game?.enemy_board || []) : (game?.my_board || []);
  const shotKey = shotSignature(game);
  const freshShot = Boolean(shotKey && shotKey !== lastAnimatedShotKey);
  const animateFreshShot = freshShot && ['hit','sunk'].includes(String(game?.last_result || ''));

  container.innerHTML = `
    <div class="battleship-panel battleship-battle-panel">
      <div class="battleship-fleet-status-line">
        <span>${battleshipText('battle.my_fleet')} <strong>${formatLocalizedNumber(Number(game?.my_ships_remaining ?? 0))}/10</strong></span>
        <i>•</i>
        <span>${battleshipText('battle.opponent')} <strong>${formatLocalizedNumber(Number(game?.enemy_ships_remaining ?? 0))}/10</strong></span>
      </div>

      <div class="battleship-board-tabs">
        <button class="${showingEnemy ? 'active' : ''}" data-battleship-view="enemy" type="button">${battleshipText('battle.enemy_field')}</button>
        <button class="${!showingEnemy ? 'active' : ''}" data-battleship-view="own" type="button">${battleshipText('battle.own_field')}</button>
      </div>

      ${battleEventMarkup({ myTurn, showingEnemy })}

      ${renderCoordinateBoard(board, {
        mode: showingEnemy ? 'enemy' : 'own',
        interactive: showingEnemy && myTurn,
        lastShot: game?.last_shot,
        lastShooterId: game?.last_shooter_id,
        meId: me?.id,
        freshShot:animateFreshShot,
      })}

      <div class="battleship-legend">
        <span><i class="miss"></i>${battleshipText('battle.legend_miss')}</span>
        <span><i class="hit"></i>${battleshipText('battle.legend_hit')}</span>
        <span><i class="sunk"></i>${battleshipText('battle.legend_sunk')}</span>
      </div>
    </div>
  `;

  if (freshShot) lastAnimatedShotKey = shotKey;

  container.querySelectorAll('[data-battleship-view]').forEach(button => button.addEventListener('click', () => {
    clearBattleTransitionTimer();
    battleNotice = null;
    battleView = button.dataset.battleshipView === 'own' ? 'own' : 'enemy';
    renderBattle({ game, me, container, onAction });
  }));

  if (showingEnemy && myTurn) {
    container.querySelectorAll('[data-battleship-cell][data-cell-state="unknown"]').forEach(button => button.addEventListener('click', () => {
      clearBattleTransitionTimer();
      onAction?.({ type:'fire', cell:Number(button.dataset.battleshipCell) });
    }));
  }
}

function syncBattleExperience({ game, me, myTurn, container, onAction }){
  const turnKey = `${String(game?.id || '')}:${String(game?.phase || '')}:${String(game?.turn || '')}`;
  const shotKey = shotSignature(game);

  if (shotKey && shotKey !== lastShotViewKey) {
    lastShotViewKey = shotKey;
    lastTurnViewKey = turnKey;
    clearBattleTransitionTimer();

    const shooterIsMe = String(game?.last_shooter_id || '') === String(me?.id || '');
    const result = String(game?.last_result || '');
    battleView = shooterIsMe ? 'enemy' : 'own';
    battleNotice = shotNotice(result, shooterIsMe);

    if (game?.status !== 'active') return;

    const nextView = result === 'miss'
      ? (shooterIsMe ? 'own' : 'enemy')
      : battleView;
    const delay = result === 'miss' ? 900 : 1250;

    battleTransitionTimer = setTimeout(() => {
      battleNotice = null;
      battleView = nextView;
      battleTransitionTimer = null;
      renderBattle({ game, me, container, onAction });
    }, delay);
    return;
  }

  if (turnKey !== lastTurnViewKey && !battleNotice) {
    battleView = myTurn ? 'enemy' : 'own';
    lastTurnViewKey = turnKey;
  }
}

function clearBattleTransitionTimer(){
  if (battleTransitionTimer) clearTimeout(battleTransitionTimer);
  battleTransitionTimer = null;
}

function shotNotice(result, shooterIsMe){
  if (shooterIsMe) {
    if (result === 'miss') return { text:battleshipText('shot.self_miss'), tone:'neutral' };
    if (result === 'hit') return { text:battleshipText('shot.self_hit'), tone:'warning' };
    if (result === 'sunk') return { text:battleshipText('shot.self_sunk'), tone:'success' };
  } else {
    if (result === 'miss') return { text:battleshipText('shot.opponent_miss'), tone:'success' };
    if (result === 'hit') return { text:battleshipText('shot.opponent_hit'), tone:'warning' };
    if (result === 'sunk') return { text:battleshipText('shot.opponent_sunk'), tone:'danger' };
  }
  return null;
}

function battleEventMarkup({ myTurn, showingEnemy }){
  const fallback = myTurn
    ? (showingEnemy ? battleshipText('turn.your_choose') : battleshipText('turn.your_open_enemy'))
    : (showingEnemy ? battleshipText('turn.opponent') : battleshipText('turn.opponent_own_field'));
  const text = battleNotice?.text || fallback;
  const tone = battleNotice?.tone || (myTurn ? 'your-turn' : 'opponent-turn');
  return `<div class="battleship-event-slot"><div class="battleship-event-banner ${tone}">${text}</div></div>`;
}

function shotSignature(game){
  if (game?.last_shot === null || game?.last_shot === undefined) return '';
  return [
    String(game?.id || ''),
    String(game?.last_shooter_id || ''),
    String(game?.last_shot),
    String(game?.last_result || ''),
  ].join(':');
}

function renderCoordinateBoard(board, options = {}){
  const values = Array.from({ length:100 }, (_, index) => String(board?.[index] || (options.mode === 'enemy' ? 'unknown' : 'water')));
  const pending = new Set((options.pendingCells || []).map(Number));
  const invalidCell = Number.isInteger(Number(options.invalidCell)) ? Number(options.invalidCell) : -1;
  const hasLastShot = options.lastShot !== null && options.lastShot !== undefined && Number.isInteger(Number(options.lastShot));
  const lastShot = hasLastShot ? Number(options.lastShot) : -1;
  const shooterIsMe = String(options.lastShooterId || '') === String(options.meId || '');
  const markLast = (options.mode === 'enemy' && shooterIsMe) || (options.mode === 'own' && !shooterIsMe);

  return `
    <div class="battleship-coordinate-board">
      <div class="battleship-corner"></div>
      ${'ABCDEFGHIJ'.split('').map(letter => `<span class="battleship-col-label">${letter}</span>`).join('')}
      ${Array.from({ length:10 }, (_, row) => `
        <span class="battleship-row-label">${row + 1}</span>
        ${Array.from({ length:10 }, (_, col) => {
          const cell = row * 10 + col;
          const value = values[cell];
          const interactive = Boolean(options.interactive) && (value === 'unknown' || value === 'water' || value === 'ship');
          const isLast = markLast && cell === lastShot;
          const isPending = pending.has(cell);
          const isInvalid = cell === invalidCell;
          return `<button
            class="battleship-cell ${escapeClass(value)} ${isLast ? 'last-shot' : ''} ${isLast && options.freshShot ? 'shot-impact' : ''} ${isPending ? 'pending' : ''} ${isInvalid ? 'invalid-pick' : ''} ${interactive ? 'interactive' : ''}"
            data-battleship-cell="${cell}"
            data-cell-state="${escapeClass(value)}"
            type="button"
            ${interactive ? '' : 'disabled'}
            aria-label="${battleshipText('aria.cell',{coord:`${'ABCDEFGHIJ'[col]}${row + 1}`,state:isPending ? battleshipText('aria.selected_for_ship') : cellStateLabel(value)})}"
          ><i></i></button>`;
        }).join('')}
      `).join('')}
    </div>
  `;
}

function remainingBySize(game){
  const result = { 4:1, 3:2, 2:3, 1:4 };
  for (const item of game?.remaining_to_place || []) {
    result[Number(item.size)] = Number(item.count || 0);
  }
  for (const item of game?.fleet_placed || []) {
    const size = Number(item.size);
    if (!Object.prototype.hasOwnProperty.call(result, size)) continue;
    result[size] = Math.max(0, Number(item.required || result[size]) - Number(item.placed || 0));
  }
  return result;
}

function firstAvailableSize(remaining){
  return [4,3,2,1].find(size => (remaining[size] ?? 0) > 0) || 1;
}

function formatTime(seconds){
  const safe = Math.max(0, Number(seconds || 0));
  const minutes = Math.floor(safe / 60);
  const rest = Math.floor(safe % 60);
  return `${String(minutes).padStart(2,'0')}:${String(rest).padStart(2,'0')}`;
}

function cellStateLabel(value){
  if (value === 'ship') return battleshipText('cell.ship');
  if (value === 'miss') return battleshipText('cell.miss');
  if (value === 'hit') return battleshipText('cell.hit');
  if (value === 'sunk') return battleshipText('cell.sunk');
  if (value === 'water') return battleshipText('cell.water');
  return battleshipText('cell.unknown');
}

function cellWord(size){
  return size === 1 ? battleshipText('cell_word.one') : size < 5 ? battleshipText('cell_word.few') : battleshipText('cell_word.many');
}

function escapeClass(value){
  return ['unknown','water','ship','miss','hit','sunk'].includes(value) ? value : 'unknown';
}
