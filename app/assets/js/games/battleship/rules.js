export function battleshipRules(){
  return `
    <div class="sheet-head game-rules-head">
      <div><h2>Морской бой</h2><p>Потопите весь флот соперника раньше, чем он уничтожит ваш.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="game-rules-content">
      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Ваш флот</strong><span>На поле 10×10 нужно разместить 10 кораблей — всего 20 занятых клеток.</span></div>
        <div class="battleship-rule-fleet">
          ${fleetRow(4, 1)}
          ${fleetRow(3, 2)}
          ${fleetRow(2, 3)}
          ${fleetRow(1, 4)}
        </div>
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Расстановка</strong><span>Выберите корабль и нажмите нужное количество соседних клеток по прямой. Корабли не могут пересекаться и соприкасаться даже по диагонали.</span></div>
        <div class="battleship-rule-placement">
          <div class="valid"><strong>Можно</strong>${placementGrid(false)}</div>
          <div class="invalid"><strong>Нельзя</strong>${placementGrid(true)}</div>
        </div>
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy"><strong>2 минуты на расстановку</strong><span>Кнопку «Перемешать флот» можно нажимать сколько угодно раз. Можно также расставить корабли вручную. Если время закончится, уже поставленные корабли сохранятся, а система добавит только недостающие.</span></div>
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Как стрелять</strong><span>Выберите клетку на поле соперника. Промах передаёт ход. При попадании или уничтожении корабля вы стреляете ещё раз.</span></div>
        <div class="battleship-shot-examples">
          <div><i class="miss"></i><span>Мимо</span></div>
          <div><i class="hit"></i><span>Попадание</span></div>
          <div><span class="sunk-line"><i></i><i></i><i></i></span><span>Потоплен</span></div>
        </div>
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy"><strong>60 секунд на выстрел</strong><span>После каждого выстрела таймер начинается заново. Если время хода истекло — засчитывается техническое поражение.</span></div>
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy"><strong>Как победить</strong><span>Первым уничтожьте все 10 кораблей соперника.</span></div>
      </section>
    </div>

    <button class="btn primary full sheet-bottom-btn" data-close-sheet type="button">Понятно</button>
  `;
}

function fleetRow(size, count){
  return `<div><span class="battleship-rule-ship">${'<i></i>'.repeat(size)}</span><strong>×${count}</strong></div>`;
}

function placementGrid(invalid){
  const shipCells = invalid ? new Set([6,7,12]) : new Set([5,6,7,18,23]);
  return `<div class="battleship-placement-grid ${invalid ? 'bad' : ''}">${Array.from({ length: 25 }, (_, cell) => `<i class="${shipCells.has(cell) ? 'ship' : ''}"></i>`).join('')}</div>`;
}
