export function checkersRules(){
  return `
    <div class="sheet-head game-rules-head">
      <div><h2>Шашки</h2><p>Заберите все шашки соперника или лишите его возможности сделать ход.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="game-rules-content">
      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Поле и фигуры</strong><span>Игра идёт на поле 8×8. У каждого игрока по 12 шашек. Ходить можно только по тёмным клеткам.</span></div>
        ${ruleBoard('start')}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Обычный ход</strong><span>Обычная шашка ходит на одну свободную клетку по диагонали вперёд.</span></div>
        ${ruleBoard('move')}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Взятие обязательно</strong><span>Если можно побить шашку соперника, обычный ход делать нельзя. Обычная шашка может бить и вперёд, и назад.</span></div>
        ${ruleBoard('capture')}
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy"><strong>Серия взятий</strong><span>Если после первого взятия той же шашкой можно побить ещё одну фигуру, нужно продолжать ход этой же шашкой. Если есть несколько допустимых путей взятия, можно выбрать любой.</span></div>
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Дамка</strong><span>Дойдя до последнего ряда, шашка становится дамкой. Дамка ходит по диагонали на любое свободное расстояние. При взятии она перепрыгивает фигуру соперника и может приземлиться на любую допустимую свободную клетку дальше по диагонали.</span></div>
        ${ruleBoard('king')}
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy"><strong>Превращение во время взятия</strong><span>Если шашка дошла до последнего ряда во время серии взятий, она сразу становится дамкой и продолжает эту же серию уже как дамка.</span></div>
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy"><strong>Как победить</strong><span>Заберите все шашки соперника или оставьте его без единого допустимого хода. При многократном повторении одной позиции партия может завершиться ничьей.</span></div>
      </section>
    </div>

    <button class="btn primary full sheet-bottom-btn" data-close-sheet type="button">Понятно</button>
  `;
}

function ruleBoard(type){
  const cells = Array.from({ length:64 }, () => '');
  const set = (index, value) => { cells[index] = value; };

  if (type === 'start') {
    for (let row = 0; row < 3; row += 1) {
      for (let col = 0; col < 8; col += 1) {
        if ((row + col) % 2 === 1) set(row * 8 + col, 'black');
      }
    }
    for (let row = 5; row < 8; row += 1) {
      for (let col = 0; col < 8; col += 1) {
        if ((row + col) % 2 === 1) set(row * 8 + col, 'white');
      }
    }
  }

  if (type === 'move') {
    set(42, 'white selected');
    set(33, 'target');
    set(35, 'target');
  }

  if (type === 'capture') {
    set(42, 'white selected');
    set(35, 'black danger');
    set(28, 'capture-target');
  }

  if (type === 'king') {
    set(56, 'white king selected');
    set(49, 'ray');
    set(42, 'ray');
    set(35, 'black danger');
    [28,21,14,7].forEach(index => set(index, 'capture-target'));
  }

  return `<div class="checkers-rule-board ${type}">${cells.map((value, index) => {
    const row = Math.floor(index / 8);
    const col = index % 8;
    const dark = (row + col) % 2 === 1;
    const parts = value.split(' ').filter(Boolean);
    const piece = parts.includes('white') || parts.includes('black');
    const cellClasses = parts.filter(part => !['white','black','king'].includes(part)).join(' ');
    const pieceClasses = [parts.includes('white') ? 'white' : 'black', parts.includes('king') ? 'king' : ''].filter(Boolean).join(' ');
    return `<i class="${dark ? 'dark' : 'light'} ${cellClasses}">${piece ? `<b class="${pieceClasses}">${parts.includes('king') ? '♛' : ''}</b>` : ''}</i>`;
  }).join('')}</div>`;
}
