let selectedShipSize = 4;
let orientation = 'h';
let battleView = 'enemy';

export function renderBattleshipSurface({ game, me, container, onAction }){
  container.className = 'board battleship-surface';
  container.dataset.gameType = 'battleship';

  if (game?.phase === 'setup') {
    renderSetup({ game, container, onAction });
    return;
  }

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

function renderSetup({ game, container, onAction }){
  const ships = Array.isArray(game?.my_fleet) ? game.my_fleet : [];
  const placedCount = ships.length;
  const remaining = remainingBySize(game);
  if ((remaining[selectedShipSize] ?? 0) <= 0) {
    selectedShipSize = firstAvailableSize(remaining);
  }

  if (game?.my_ready) {
    container.innerHTML = `
      <div class="battleship-panel battleship-waiting">
        <div class="battleship-wait-icon">⚓</div>
        <h3>Ваш флот готов</h3>
        <p>${game?.opponent_ready ? 'Соперник тоже готов. Начинаем бой…' : 'Ждём готовности соперника…'}</p>
        ${renderCoordinateBoard(game?.my_board || [], { mode:'own', interactive:false })}
      </div>
    `;
    return;
  }

  const complete = placedCount === 10 && Object.values(remaining).every(value => value === 0);
  container.innerHTML = `
    <div class="battleship-panel battleship-setup-panel">
      <div class="battleship-setup-head">
        <div><strong>Расставьте флот</strong><span>${placedCount}/10 кораблей</span></div>
        <span class="battleship-setup-time">${formatTime(game?.setup_time_left ?? game?.time_left ?? 120)}</span>
      </div>

      ${renderCoordinateBoard(game?.my_board || [], { mode:'setup', interactive:true })}

      <div class="battleship-fleet-picker">
        ${[4,3,2,1].map(size => {
          const left = remaining[size] ?? 0;
          return `<button class="battleship-ship-choice ${selectedShipSize === size && left > 0 ? 'active' : ''} ${left <= 0 ? 'done' : ''}" data-battleship-size="${size}" type="button" ${left <= 0 ? 'disabled' : ''}>
            <span>${'▰'.repeat(size)}</span><small>${left > 0 ? `осталось ${left}` : 'готово'}</small>
          </button>`;
        }).join('')}
      </div>

      <div class="battleship-orientation-row">
        <span>Направление</span>
        <div class="battleship-orientation-switch">
          <button class="${orientation === 'h' ? 'active' : ''}" data-battleship-orientation="h" type="button">↔ Горизонтально</button>
          <button class="${orientation === 'v' ? 'active' : ''}" data-battleship-orientation="v" type="button">↕ Вертикально</button>
        </div>
      </div>

      <div class="battleship-setup-actions">
        <button class="btn primary" data-battleship-randomize type="button">🎲 Перемешать флот</button>
        <button class="btn ghost" data-battleship-clear type="button">↺ Очистить поле</button>
      </div>

      <div class="small-note battleship-placement-note">Выберите размер и нажмите на клетку, чтобы поставить корабль. Нажмите на уже поставленный корабль, чтобы убрать его.</div>
      <button class="btn primary full" data-battleship-ready type="button" ${complete ? '' : 'disabled'}>Готов к бою</button>
    </div>
  `;

  container.querySelectorAll('[data-battleship-size]').forEach(button => button.addEventListener('click', () => {
    selectedShipSize = Number(button.dataset.battleshipSize);
    renderSetup({ game, container, onAction });
  }));

  container.querySelectorAll('[data-battleship-orientation]').forEach(button => button.addEventListener('click', () => {
    orientation = button.dataset.battleshipOrientation === 'v' ? 'v' : 'h';
    renderSetup({ game, container, onAction });
  }));

  container.querySelector('[data-battleship-randomize]')?.addEventListener('click', () => onAction?.({ type:'randomize_fleet' }));
  container.querySelector('[data-battleship-clear]')?.addEventListener('click', () => onAction?.({ type:'clear_fleet' }));
  container.querySelector('[data-battleship-ready]')?.addEventListener('click', () => onAction?.({ type:'ready' }));

  container.querySelectorAll('[data-battleship-cell]').forEach(button => button.addEventListener('click', () => {
    const cell = Number(button.dataset.battleshipCell);
    const state = String(button.dataset.cellState || 'water');
    if (state === 'ship') {
      onAction?.({ type:'remove_ship', cell });
      return;
    }
    if ((remaining[selectedShipSize] ?? 0) <= 0) return;
    onAction?.({ type:'place_ship', size:selectedShipSize, cell, orientation });
  }));
}

function renderBattle({ game, me, container, onAction }){
  const myTurn = game?.status === 'active' && String(game?.turn || '') === String(me?.id || '');
  const showingEnemy = battleView !== 'own';
  const board = showingEnemy ? (game?.enemy_board || []) : (game?.my_board || []);

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
      })}

      <div class="battleship-legend">
        <span><i class="miss"></i>мимо</span>
        <span><i class="hit"></i>попадание</span>
        <span><i class="sunk"></i>потоплен</span>
      </div>
    </div>
  `;

  container.querySelectorAll('[data-battleship-view]').forEach(button => button.addEventListener('click', () => {
    battleView = button.dataset.battleshipView === 'own' ? 'own' : 'enemy';
    renderBattle({ game, me, container, onAction });
  }));

  if (showingEnemy && myTurn) {
    container.querySelectorAll('[data-battleship-cell][data-cell-state="unknown"]').forEach(button => button.addEventListener('click', () => {
      onAction?.({ type:'fire', cell:Number(button.dataset.battleshipCell) });
    }));
  }
}

function renderCoordinateBoard(board, options = {}){
  const values = Array.from({ length:100 }, (_, index) => String(board?.[index] || (options.mode === 'enemy' ? 'unknown' : 'water')));
  const hasLastShot = options.lastShot !== null && options.lastShot !== undefined && Number.isInteger(Number(options.lastShot));
  const lastShot = hasLastShot ? Number(options.lastShot) : -1;
  const markLast = String(options.lastShooterId || '') === String(options.meId || '') && options.mode === 'enemy';

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
          return `<button
            class="battleship-cell ${escapeClass(value)} ${markLast && cell === lastShot ? 'last-shot' : ''} ${interactive ? 'interactive' : ''}"
            data-battleship-cell="${cell}"
            data-cell-state="${escapeClass(value)}"
            type="button"
            ${interactive ? '' : 'disabled'}
            aria-label="Клетка ${'ABCDEFGHIJ'[col]}${row + 1}: ${cellStateLabel(value)}"
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
  const prefix = mine ? 'Ваш последний выстрел' : 'Последний выстрел соперника';
  if (result === 'miss') return `${prefix}: мимо`;
  if (result === 'hit') return `${prefix}: попадание`;
  if (result === 'sunk') return `${prefix}: корабль потоплен`;
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

function escapeClass(value){
  return ['unknown','water','ship','miss','hit','sunk'].includes(value) ? value : 'unknown';
}
