export function goRules(){
  return `
    <div class="sheet-head game-rules-head">
      <div><h2>Го</h2><p>Окружайте камни соперника и захватывайте территорию.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="game-rules-content">
      <section class="game-rule-card compact">
        <div class="game-rule-copy"><strong>Два размера поля</strong><span>Доступны быстрое поле 9×9 и большое 13×13. По умолчанию выбрано 9×9. Правила одинаковые в Match- и Gold-комнатах.</span></div>
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Пустое поле и первый ход</strong><span>Партия начинается на пустом поле. Камни ставятся на пересечения линий и больше не двигаются. Стороны распределяются случайно, первыми ходят чёрные.</span></div>
        ${ruleBoard('start')}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Группы и свободы</strong><span>Соседние по горизонтали или вертикали камни одного цвета образуют группу. Пустые соседние пересечения — её свободы. Диагонали камни не соединяют.</span></div>
        ${ruleBoard('liberties')}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Захват группы</strong><span>Если у камня или группы соперника не осталось свобод, вся группа снимается с поля. Один ход может захватить несколько групп.</span></div>
        ${ruleBoard('capture')}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Запрещённый ход</strong><span>Нельзя поставить камень так, чтобы у собственной группы не осталось свобод. Но ход разрешён, если он сначала захватывает соперника и освобождает точки.</span></div>
        ${ruleBoard('suicide')}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Правило ko</strong><span>Нельзя повторить уже существовавшую в этой партии позицию. После взаимного захвата сначала нужно сделать ход в другом месте.</span></div>
        ${ruleBoard('ko')}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Пас и конец партии</strong><span>Пас разрешён в любой момент. Два последовательных паса завершают партию. Перед вторым пасом игра попросит подтвердить завершение.</span></div>
        ${ruleBoard('score')}
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy"><strong>Подсчёт</strong><span>За каждый камень на поле и каждое окружённое пустое пересечение начисляется одно очко. Смешанные области нейтральны. Белые получают 6,5 komi. Все камни, оставшиеся после второго паса, считаются живыми.</span></div>
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy"><strong>Таймер</strong><span>На ход даётся 60 секунд. Выход из активной партии или окончание времени означает техническое поражение.</span></div>
      </section>
    </div>

    <button class="btn primary full sheet-bottom-btn" data-close-sheet type="button">Понятно</button>
  `;
}

function ruleBoard(type){
  const size = 9;
  const stones = new Map();
  const markers = new Map();
  const setStone = (row, col, color, extra = '') => stones.set(row * size + col, `${color} ${extra}`.trim());
  const setMarker = (row, col, marker) => markers.set(row * size + col, marker);

  if (type === 'start') {
    setMarker(4, 4, 'first');
  }

  if (type === 'liberties') {
    setStone(4, 4, 'black');
    setStone(4, 5, 'black');
    [[3,4],[3,5],[4,3],[4,6],[5,4],[5,5]].forEach(([r,c]) => setMarker(r,c,'liberty'));
  }

  if (type === 'capture') {
    setStone(4, 4, 'white capture');
    setStone(4, 5, 'white capture');
    [[3,4],[3,5],[4,3],[5,4],[5,5]].forEach(([r,c]) => setStone(r,c,'black'));
    setMarker(4,6,'target-black');
  }

  if (type === 'suicide') {
    [[3,4],[4,3],[4,5],[5,4]].forEach(([r,c]) => setStone(r,c,'white'));
    setMarker(4,4,'forbidden');
  }

  if (type === 'ko') {
    setStone(3,4,'black');
    setStone(4,3,'black');
    setStone(5,4,'black');
    setStone(4,4,'white capture');
    setStone(3,5,'white');
    setStone(5,5,'white');
    setStone(4,6,'white');
    setMarker(4,5,'ko');
  }

  if (type === 'score') {
    [[2,2],[2,3],[2,4],[3,2],[4,2]].forEach(([r,c]) => setStone(r,c,'black'));
    [[5,5],[5,6],[6,5],[6,6],[4,6]].forEach(([r,c]) => setStone(r,c,'white'));
    [[3,3],[3,4],[4,3],[4,4]].forEach(([r,c]) => setMarker(r,c,'territory-black'));
    [[5,7],[6,7],[7,5],[7,6],[7,7]].forEach(([r,c]) => setMarker(r,c,'territory-white'));
    setMarker(4,5,'neutral');
  }

  return `
    <div class="go-rule-board ${type}" style="--go-rule-size:${size}">
      ${gridSvg(size)}
      ${starMarkup(size)}
      ${Array.from({ length:size * size }, (_, cell) => rulePoint(cell, size, stones.get(cell) || '', markers.get(cell) || '')).join('')}
    </div>
  `;
}

function rulePoint(cell, size, stoneValue, marker){
  const row = Math.floor(cell / size);
  const col = cell % size;
  const inset = 6;
  const span = 88;
  const x = inset + (col / (size - 1)) * span;
  const y = inset + (row / (size - 1)) * span;
  const [color, extra = ''] = stoneValue.split(' ');
  const stone = color === 'black' || color === 'white'
    ? `<b class="${color} ${extra}"></b>`
    : '';
  const markerHtml = marker ? `<em class="${marker}"></em>` : '';
  return `<i class="go-rule-point" style="--go-x:${x}%;--go-y:${y}%;--go-rule-point-size:${82 / (size - 1)}%">${stone}${markerHtml}</i>`;
}

function gridSvg(size){
  const inset = 6;
  const span = 88;
  const lines = [];
  for (let index = 0; index < size; index += 1) {
    const position = inset + (index / (size - 1)) * span;
    lines.push(`<line x1="${inset}" y1="${position}" x2="${100 - inset}" y2="${position}"></line>`);
    lines.push(`<line x1="${position}" y1="${inset}" x2="${position}" y2="${100 - inset}"></line>`);
  }
  return `<svg class="go-grid-svg" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">${lines.join('')}</svg>`;
}

function starMarkup(size){
  const inset = 6;
  const span = 88;
  return [2,4,6].flatMap(row => [2,4,6].map(col => {
    const x = inset + (col / (size - 1)) * span;
    const y = inset + (row / (size - 1)) * span;
    return `<i class="go-star" style="--go-x:${x}%;--go-y:${y}%;--go-rule-point-size:${82 / (size - 1)}%"></i>`;
  })).join('');
}
