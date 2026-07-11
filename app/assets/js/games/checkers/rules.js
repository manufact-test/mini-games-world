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
        <div class="game-rule-copy"><strong>Серия взятий</strong><span>Если после первого взятия той же шашкой можно побить ещё одну фигуру, нужно продолжать ход этой же шашкой. Игра сама подсветит продолжение.</span></div>
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Дамка</strong><span>Дойдя до последнего ряда, шашка становится дамкой. Дамка ходит по диагонали на любое свободное расстояние и может бить фигуры на расстоянии.</span></div>
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
  const cells = Array.from({ length:25 }, () => '');
  const set = (index, value) => { cells[index] = value; };

  if (type === 'start') {
    [1,3,5,7,9].forEach(index => set(index, 'black'));
    [15,17,19,21,23].forEach(index => set(index, 'white'));
  }
  if (type === 'move') {
    set(17, 'white selected');
    set(11, 'target');
    set(13, 'target');
  }
  if (type === 'capture') {
    set(17, 'white selected');
    set(13, 'black danger');
    set(9, 'capture-target');
  }
  if (type === 'king') {
    set(21, 'white king selected');
    set(17, 'ray');
    set(13, 'black danger');
    set(9, 'capture-target');
    set(5, 'capture-target');
  }

  return `<div class="checkers-rule-board ${type}">${cells.map((value, index) => {
    const row = Math.floor(index / 5);
    const col = index % 5;
    const dark = (row + col) % 2 === 1;
    const parts = value.split(' ').filter(Boolean);
    const piece = parts.includes('white') || parts.includes('black');
    return `<i class="${dark ? 'dark' : 'light'} ${parts.filter(part => !['white','black','king'].includes(part)).join(' ')}">${piece ? `<b class="${parts.includes('white') ? 'white' : 'black'} ${parts.includes('king') ? 'king' : ''}">${parts.includes('king') ? '♛' : ''}</b>` : ''}</i>`;
  }).join('')}</div>`;
}
