import { toast } from '../../components/toast.js?v=41';

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
  const room = String(game?.room_name || 'Игра');
  const bet = Number(game?.bet || 0);
  return `${room} · ${bet} коинов · 10×10`;
}

export function battleshipPlayerMark(player){
  return player?.ready ? '⚓ готов' : '⚓ флот';
}

export function battleshipStatus(game, me){
  if (game?.status === 'finished') return 'Игра завершена';
  if (game?.phase === 'setup') {
    if (game?.my_ready) return game?.opponent_ready ? 'Начинаем бой…' : 'Ждём соперника';
    return 'Расставьте корабли';
  }
  return String(game?.turn || '') === String(me?.id || '') ? 'Ваш выстрел' : 'Ход соперника';
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
        <div class="battleship-setup-title"><strong>Расставьте флот</strong><span>${placedCount}/10 кораблей</span></div>
        <div class="battleship-current-ship ${complete ? 'ready' : ''}" aria-live="polite">
          ${complete ? `
            <strong>Флот готов</strong>
          ` : `
            <small>Ставите</small>
            <span class="battleship-current-ship-dots">${'<i></i>'.repeat(selectedShipSize)}</span>
            <b>×${selectedLeft}</b>
          `}
        </div>
        <span class="battleship-setup-time">${formatTime(game?.setup_time_left ?? game?.time_left ?? 120)}</span>
      </div>

      ${complete ? `
        <div class="battleship-ready-callout">
          <div>
            <strong>Флот готов к бою</strong>
            <span>Все 10 кораблей размещены. Подтвердите расстановку.</span>
          </div>
          <button class="btn primary" data-battleship-ready type="button">Готов к бою</button>
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
            <small>${left > 0 ? `осталось ${left}` : 'готово'}</small>
          </button>`;
        }).join('')}
      </div>

      ${!complete ? `
        <div class="battleship-placement-guide">
          <strong>${selectedLeft > 0 ? `Корабль на ${selectedShipSize} ${cellWord(selectedShipSize)}` : 'Выберите следующий корабль'}</strong>
          <span>${selectedLeft > 0
            ? (selectedProgress > 0
              ? `Выбрано ${selectedProgress}/${selectedShipSize}. Выбранные клетки подсвечены фиолетовым.`
              : `Нажмите ${selectedShipSize} ${cellWord(selectedShipSize)} подряд по горизонтали или вертикали.`)
            : 'Выберите корабль, который ещё остался во флоте.'}</span>
        </div>
      ` : ''}

      <div class="battleship-setup-actions">
        <button class="btn primary" data-battleship-randomize type="button">🎲 Перемешать флот</button>
        <button class="btn ghost" data-battleship-clear type="button">↺ Очистить поле</button>
      </div>

      <div class="small-note battleship-placement-note">Выберите корабль и отмечайте его клетки прямо на поле. Нажмите на уже поставленный корабль, чтобы убрать его целиком.</div>
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
      toast('Выбор клеток сброшен. Начните корабль заново.');
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
    flashInvalidPlacement({ cell, game, container, onAction, message:'Корабль должен идти по прямой без пропусков.' });
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
  if (cells.length > size) return 'Вы уже выбрали все клетки этого корабля.';
  if (!cellsFormStraightContinuousLine(cells)) return 'Так нельзя: корабль должен идти по прямой без изгибов и пропусков.';

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
          return 'Здесь нельзя: корабли не должны соприкасаться даже по диагонали.';
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

  container.innerHTML = `
    <div class="battleship-panel battleship-battle-panel">
      <div class="battleship-fleet-status-line">
        <span>Ваш флот <strong>${Number(game?.my_ships_remaining ?? 0)}/10</strong></span>
        <i>•</i>
        <span>Соперник <strong>${Number(game?.enemy_ships_remaining ?? 0)}/10</strong></span>
      </div>

      <div class="battleship-board-tabs">
        <button class="${showingEnemy ? 'active' : ''}" data-battleship-view="enemy" type="button">Поле соперника</button>
        <button class="${!showingEnemy ? 'active' : ''}" data-battleship-view="own" type="button">Моё поле</button>
      </div>

      ${battleEventMarkup({ myTurn, showingEnemy })}

      ${renderCoordinateBoard(board, {
        mode: showingEnemy ? 'enemy' : 'own',
        interactive: showingEnemy && myTurn,
        lastShot: game?.last_shot,
        lastShooterId: game?.last_shooter_id,
        meId: me?.id,
        freshShot,
      })}

      <div class="battleship-legend">
        <span><i class="miss"></i>мимо</span>
        <span><i class="hit"></i>попадание</span>
        <span><i class="sunk"></i>потоплен</span>
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
    const delay = result === 'miss' ? 1450 : 1250;

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
    if (result === 'miss') return { text:'Мимо — ход соперника', tone:'neutral' };
    if (result === 'hit') return { text:'Попадание! Стреляйте ещё', tone:'warning' };
    if (result === 'sunk') return { text:'Корабль потоплен! Стреляйте ещё', tone:'success' };
  } else {
    if (result === 'miss') return { text:'Соперник промахнулся — ваш ход', tone:'success' };
    if (result === 'hit') return { text:'По вашему кораблю попали — соперник стреляет ещё', tone:'warning' };
    if (result === 'sunk') return { text:'Ваш корабль потоплен — соперник стреляет ещё', tone:'danger' };
  }
  return null;
}

function battleEventMarkup({ myTurn, showingEnemy }){
  const fallback = myTurn
    ? (showingEnemy ? 'Ваш ход — выберите клетку' : 'Ваш ход — откройте поле соперника')
    : (showingEnemy ? 'Ход соперника' : 'Ход соперника — следим за вашим полем');
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
            aria-label="Клетка ${'ABCDEFGHIJ'[col]}${row + 1}: ${isPending ? 'выбрано для корабля' : cellStateLabel(value)}"
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
  if (value === 'ship') return 'ваш корабль';
  if (value === 'miss') return 'мимо';
  if (value === 'hit') return 'попадание';
  if (value === 'sunk') return 'потопленный корабль';
  if (value === 'water') return 'вода';
  return 'неизвестно';
}

function cellWord(size){
  return size === 1 ? 'клетку' : size < 5 ? 'клетки' : 'клеток';
}

function escapeClass(value){
  return ['unknown','water','ship','miss','hit','sunk'].includes(value) ? value : 'unknown';
}
