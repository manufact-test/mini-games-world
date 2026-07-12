export function reversiRules(){
  return `
    <div class="sheet-head game-rules-head">
      <div><h2>Реверси</h2><p>Переворачивайте фишки соперника и соберите большинство.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="game-rules-content">
      <section class="game-rule-card compact">
        <div class="game-rule-copy"><strong>Три размера поля</strong><span>Доступны 6×6, классическое 8×8 и большое 10×10. По умолчанию выбрано 8×8. Правила на всех полях одинаковые.</span></div>
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Старт партии</strong><span>В центре стоят четыре фишки по диагонали. Стороны распределяются случайно, первыми всегда ходят чёрные.</span></div>
        ${ruleBoard('start')}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Допустимый ход</strong><span>Поставьте фишку так, чтобы между новой и уже стоящей вашей фишкой оказалась непрерывная линия фишек соперника. Доступные клетки подсвечиваются.</span></div>
        ${ruleBoard('legal')}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Переворот линии</strong><span>Все зажатые фишки соперника переворачиваются в ваш цвет. Проверяются горизонтали, вертикали и диагонали.</span></div>
        ${ruleBoard('flip')}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Несколько направлений</strong><span>Один ход может одновременно перевернуть несколько линий. Переворачиваются сразу все фишки, которые новая фишка закрыла вашими фишками.</span></div>
        ${ruleBoard('multi')}
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy"><strong>Пропуск хода</strong><span>Если доступных ходов нет, система автоматически передаёт ход сопернику. Самостоятельно пропускать допустимый ход нельзя.</span></div>
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy"><strong>Конец и подсчёт</strong><span>Партия заканчивается, когда поле заполнено или ни у кого не осталось допустимых ходов. Побеждает игрок с большим числом фишек. При равном счёте — ничья и возврат ставки.</span></div>
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy"><strong>Таймер</strong><span>На ход даётся 60 секунд. Выход из активной партии или окончание времени означает техническое поражение.</span></div>
      </section>
    </div>

    <button class="btn primary full sheet-bottom-btn" data-close-sheet type="button">Понятно</button>
  `;
}

function ruleBoard(type){
  const size = 6;
  const cells = Array.from({ length:size * size }, () => '');
  const set = (row, col, value) => { cells[row * size + col] = value; };

  if (type === 'start') {
    set(2,2,'white'); set(3,3,'white'); set(2,3,'black'); set(3,2,'black');
  }

  if (type === 'legal') {
    set(2,2,'white'); set(3,3,'white'); set(2,3,'black'); set(3,2,'black');
    set(1,2,'target'); set(2,1,'target'); set(3,4,'target'); set(4,3,'target');
  }

  if (type === 'flip') {
    set(2,1,'black anchor'); set(2,2,'white flip'); set(2,3,'white flip'); set(2,4,'target black-new');
  }

  if (type === 'multi') {
    set(1,1,'black anchor'); set(1,3,'black anchor'); set(3,1,'black anchor');
    set(2,2,'white flip'); set(2,3,'white flip'); set(3,2,'white flip');
    set(3,3,'target black-new');
  }

  return `<div class="reversi-rule-board ${type}" style="--reversi-rule-size:${size}">${cells.map((value, index) => {
    const parts = value.split(' ').filter(Boolean);
    const isTarget = parts.includes('target');
    const isFlip = parts.includes('flip');
    const isAnchor = parts.includes('anchor');
    const color = parts.includes('white') ? 'white' : (parts.includes('black') || parts.includes('black-new') ? 'black' : '');
    const classes = [isTarget ? 'target' : '', isFlip ? 'flip' : '', isAnchor ? 'anchor' : '', parts.includes('black-new') ? 'new' : ''].filter(Boolean).join(' ');
    return `<i class="${classes}" data-cell="${index}">${color ? `<b class="${color}"></b>` : ''}${isTarget && !color ? '<em></em>' : ''}</i>`;
  }).join('')}</div>`;
}
