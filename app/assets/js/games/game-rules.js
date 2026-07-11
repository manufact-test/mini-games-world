import { openSheet } from '../components/sheet.js?v=27';
import { state } from '../state.js?v=27';

let initialized = false;

export function initGameRules(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('click', event => {
    const button = event.target.closest('[data-game-rules], [data-game-rules-current]');
    if (!button) return;

    const gameType = button.hasAttribute('data-game-rules-current')
      ? String(state.activeGame?.game_type || 'tictactoe')
      : String(button.dataset.gameRules || 'tictactoe');

    openGameRules(gameType);
  });
}

export function openGameRules(gameType){
  const content = gameType === 'four_in_a_row'
    ? fourInARowRules()
    : ticTacToeRules();

  openSheet(content);
}

function fourInARowRules(){
  return `
    <div class="sheet-head game-rules-head">
      <div><h2>4 в ряд</h2><p>Соберите четыре свои фишки в одну линию раньше соперника.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="game-rules-content">
      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Как ходить</strong><span>Выберите столбец. Фишка падает в самую нижнюю свободную клетку.</span></div>
        ${fourBoard([
          '', '', '', '', '', '', '',
          '', '', '', '', '', '', '',
          '', '', '', 'yellow', '', '', '',
          '', '', '', 'red', '', '', '',
          '', '', 'yellow', 'yellow', '', '', '',
          '', 'red', 'red', 'red', '', '', '',
        ])}
        <div class="game-rule-arrow">↓ Фишки всегда падают вниз</div>
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Как победить</strong><span>Первым соберите 4 фишки подряд — по горизонтали, вертикали или диагонали.</span></div>
        <div class="rule-win-examples">
          <div><span>Горизонталь</span>${miniLine(['yellow','yellow','yellow','yellow'])}</div>
          <div><span>Вертикаль</span>${miniColumn(['red','red','red','red'])}</div>
          <div><span>Диагональ</span>${miniDiagonal()}</div>
        </div>
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy"><strong>Ничья</strong><span>Если поле заполнено полностью и никто не собрал четыре фишки подряд.</span></div>
      </section>

      <section class="game-rule-tip">💡 Стройте свою линию, но следите за угрозами соперника — иногда важнее вовремя заблокировать столбец.</section>
    </div>

    <button class="btn primary full sheet-bottom-btn" data-close-sheet type="button">Понятно</button>
  `;
}

function ticTacToeRules(){
  return `
    <div class="sheet-head game-rules-head">
      <div><h2>Крестики-нолики</h2><p>Соберите нужную линию своих знаков раньше соперника.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="game-rules-content">
      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Как ходить</strong><span>Игроки по очереди ставят ✕ и ○ в свободные клетки поля.</span></div>
        <div class="rule-tic-grid"><span>✕</span><span>○</span><span></span><span></span><span>✕</span><span>○</span><span></span><span></span><span>✕</span></div>
      </section>
      <section class="game-rule-card compact">
        <div class="game-rule-copy"><strong>Как победить</strong><span>На поле 3×3 нужно собрать 3 знака подряд. На больших полях действует текущая длина победной линии.</span></div>
      </section>
    </div>
    <button class="btn primary full sheet-bottom-btn" data-close-sheet type="button">Понятно</button>
  `;
}

function fourBoard(cells){
  return `<div class="rule-four-board">${cells.map(value => `<span class="${value}"></span>`).join('')}</div>`;
}

function miniLine(values){
  return `<div class="rule-line">${values.map(value => `<i class="${value}"></i>`).join('')}</div>`;
}

function miniColumn(values){
  return `<div class="rule-column">${values.map(value => `<i class="${value}"></i>`).join('')}</div>`;
}

function miniDiagonal(){
  return `<div class="rule-diagonal"><i class="yellow a"></i><i class="yellow b"></i><i class="yellow c"></i><i class="yellow d"></i></div>`;
}
