import { toast } from '../../components/toast.js?v=41';

let activeGameId = '';
let selectedShipSize = 4;
let pendingPlacementCells = [];
let battleView = 'enemy';
let lastTurnViewKey = '';
let lastShotViewKey = '';
let lastAnimatedShotKey = '';
let autoSwitchTimer = null;

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
  battleView = 'enemy';
  lastTurnViewKey = '';
  lastShotViewKey = '';
  lastAnimatedShotKey = '';
  if (autoSwitchTimer) clearTimeout(autoSwitchTimer);
  autoSwitchTimer = null;
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
        <div><strong>Расставьте флот</strong><span>${placedCount}/10 кораблей</span></div>
        <span class="battleship-setup-time">${formatTime(game?.setup_time_left ?? game?.time_left ?? 120)}</span>
      </div>

      ${renderCoordinateBoard(game?.my_board || [], {
        mode:'setup',
        interactive:true,
        pendingCells:pendingPlacementCells,
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

      <div class="battleship-placement-guide">
        <strong>${selectedLeft > 0 ? `Корабль на ${selectedShipSize} ${cellWord(selectedShipSize)}` : 'Все корабли размещены'}</strong>
        <span>${selectedLeft > 0
          ? (selectedProgress > 0
            ? `Выбрано ${selectedProgress}/${selectedShipSize}. Продолжайте по прямой без пропусков.`
            : `Нажмите ${selectedShipSize} ${cellWord(selectedShipSize)} подряд по горизонтали или вертикали.`)
          : 'Можно подтвердить флот или изменить расстановку.'}</span>
      </div>

      <div class="battleship-setup-actions">
        <button class="btn primary" data-battleship-randomize type="button">🎲 Перемешать флот</button>
        <button class="btn ghost" data-battleship-clear type="button">↺ Очистить поле</button>
      </div>

      <div class="small-note battleship-placement-note">Выберите корабль и отмечайте его клетки прямо на поле. Нажмите на уже поставленный корабль, чтобы убрать его целиком.</div>
      <button class="btn primary full" data-battleship-ready type="button" ${complete ? '' : 'disabled'}>Готов к бою</button>
    </div>
  `;

  container.querySelectorAll('[data-battleship-size]').forEach(button => button.addEventListener('click', () => {
    selectedShipSize = Number(button.dataset.battleshipSize);
    pendingPlacementCells = [];
    renderSetup({ game, container, onAction });
  }));

  container.querySelector('[data-battleship-randomize]')?.addEventListener('click', () => {
    pendingPlacementCells = [];
    onAction?.({ type:'randomize_fleet' });
  });

  container.querySelector('[data-battleship-clear]')?.addEventListener('click', () => {
    pendingPlacementCells = [];
    selectedShipSize = 4;
    onAction?.({ type:'clear_fleet' });
  });

  container.querySelector('[data-battleship-ready]')?.addEventListener('click', () => {
    pendingPlacementCells = [];
    onAction?.({ type:'ready' });
  });

  container.querySelectorAll('[data-battleship-cell]').forEach(button => button.addEventListener('click', () => {
    const cell = Number(button.dataset.battleshipCell);
    const cellState = String(button.dataset.cellState || 'water');

    if (cellState === 'ship') {
      pendingPlacementCells = [];
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
    renderSetup({ game, container, onAction });
    return;
  }

  const candidate = [...pendingPlacementCells, cell];
  const error = placementSelectionError(candidate, selectedShipSize, game?.my_board || []);
  if (error) {
    toast(error);
    return;
  }

  pendingPlacementCells = candidate;
  if (pendingPlacementCells.length < selectedShipSize) {
    renderSetup({ game, container, onAction });
    return;
  }

  const placement = placementFromCells(pendingPlacementCells);
  pendingPlacementCells = [];
  if (!placement) {
    toast('Корабль должен идти по прямой без пропусков.');
    renderSetup({ game, container, onAction });
    return;
  }

  onAction?.({
    type:'place_ship',
    size:selectedShipSize,
    cell:placement.startCell,
    orientation:placement.orientation,
  });
}

function placementSelectionError(cells, size, board){
  if (cells.length > size) return 'Вы уже выбрали все клетки этого корабля.';
  if (!cellsFormStraightContinuousLine(cells)) return 'Корабль нужно ставить по прямой, без изгибов и пропусков.';

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
  syncAutomaticBattleView({ game, me, myTurn, container, onAction });

  const showingEnemy = battleView !== 'own';
  const board = showingEnemy ? (game?.enemy_board || []) : (game?.my_board || []);
  const shotKey = shotSignature(game);
  const freshShot = Boolean(shotKey && shotKey !== lastAnimatedShotKey);

  container.innerHTML = `
    <div class="battleship-panel battleship-battle-panel">
      <div class="battleship-battle-summary">
        <div><span>Ваш флот</span><strong>${Number(game?.my_ships_remaining ?? 0)}/10</strong></div>
        <div><span>Флот соперника</span><strong>${Number(game?.enemy_ships_remaining ?? 0)}/10</strong></div>
      </div>

      <div class="battleship-board-tabs">
        <button class="${showingEnemy ? 'active' : ''}" data-battleship-view="enemy" type="button">Поле соперника</button>
        <button class="${!showingEnemy ? 'active' : ''}" data-battleship-view="own" type="button">Моё поле</button>
      </div>

      <div class="battleship-board-title">
        <strong>${showingEnemy ? (myTurn ? 'Выберите клетку для выстрела' : 'Поле соперника') : 'Ваш флот'}</strong>
        <span>${lastResultText(game, me)}</span>
      </div>

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
    if (autoSwitchTimer) clearTimeout(autoSwitchTimer);
    autoSwitchTimer = null;
    battleView = button.dataset.battleshipView === 'own' ? 'own' : 'enemy';
    renderBattle({ game, me, container, onAction });
  }));

  if (showingEnemy && myTurn) {
    container.querySelectorAll('[data-battleship-cell][data-cell-state="unknown"]').forEach(button => button.addEventListener('click', () => {
      onAction?.({ type:'fire', cell:Number(button.dataset.battleshipCell) });
    }));
  }
}

function syncAutomaticBattleView({ game, me, myTurn, container, onAction }){
  const turnKey = `${String(game?.id || '')}:${String(game?.phase || '')}:${String(game?.turn || '')}`;
  const shotKey = shotSignature(game);
  const shooterIsMe = String(game?.last_shooter_id || '') === String(me?.id || '');
  const newOpponentShot = Boolean(shotKey && shotKey !== lastShotViewKey && !shooterIsMe);

  if (turnKey !== lastTurnViewKey) {
    battleView = myTurn ? 'enemy' : 'own';
    lastTurnViewKey = turnKey;
  }

  if (shotKey && shotKey !== lastShotViewKey) lastShotViewKey = shotKey;

  if (newOpponentShot) {
    battleView = 'own';
    if (autoSwitchTimer) clearTimeout(autoSwitchTimer);
    autoSwitchTimer = null;

    if (myTurn) {
      autoSwitchTimer = setTimeout(() => {
        battleView = 'enemy';
        autoSwitchTimer = null;
        renderBattle({ game, me, container, onAction });
      }, 1100);
    }
  }
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
          return `<button
            class="battleship-cell ${escapeClass(value)} ${isLast ? 'last-shot' : ''} ${isLast && options.freshShot ? 'shot-impact' : ''} ${isPending ? 'pending' : ''} ${interactive ? 'interactive' : ''}"
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

function lastResultText(game, me){
  const result = String(game?.last_result || '');
  if (!result) return '';
  const mine = String(game?.last_shooter_id || '') === String(me?.id || '');

  if (mine) {
    if (result === 'miss') return 'Мимо';
    if (result === 'hit') return 'Попадание';
    if (result === 'sunk') return 'Корабль потоплен';
  } else {
    if (result === 'miss') return 'Соперник промахнулся';
    if (result === 'hit') return 'По вам попали';
    if (result === 'sunk') return 'Ваш корабль потоплен';
  }

  return '';
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
