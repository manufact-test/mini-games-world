export function dominoRules(){
  return `
    <div class="sheet-head game-rules-head">
      <div><h2>Домино</h2><p>Соединяйте одинаковые числа и первым избавьтесь от всех костяшек.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="game-rules-content">
      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Начало партии</strong><span>Каждый получает по 7 костяшек, остальные лежат в закрытом запасе. Старший дубль автоматически выходит на стол, затем ходит второй игрок.</span></div>
        ${startRule()}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Соединяйте одинаковые числа</strong><span>Костяшку можно поставить к одному из двух концов цепочки, если одно из её чисел совпадает с открытым числом.</span></div>
        ${matchRule()}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Выбор конца цепочки</strong><span>Если костяшка подходит к обоим концам, сначала выберите её, затем нажмите на одно из двух подсвеченных мест на столе.</span></div>
        ${sideRule()}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Дубли</strong><span>Костяшки с одинаковыми числами ставятся поперёк цепочки. Дополнительного хода дубль не даёт.</span></div>
        ${doubleRule()}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Добор и пропуск</strong><span>Если подходящей костяшки нет, нажмите «Добрать». За одно нажатие берётся одна костяшка. Если она не подошла, нажмите «Добрать» ещё раз. Когда запас пуст, ход пропускается автоматически.</span></div>
        ${drawRule()}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>Победа и заблокированная партия</strong><span>Вы побеждаете, когда выкладываете последнюю костяшку. Если никто не может ходить и запас пуст, выигрывает меньшая сумма точек. При равной сумме объявляется ничья.</span></div>
        ${scoreRule()}
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy"><strong>Таймер</strong><span>На ход даётся 60 секунд. Выход из активной партии или окончание времени означает техническое поражение.</span></div>
      </section>
    </div>

    <button class="btn primary full sheet-bottom-btn" data-close-sheet type="button">Понятно</button>
  `;
}

function startRule(){
  return `
    <div class="domino-rule-scene start">
      <div class="domino-rule-stock">${Array.from({length:5}, () => '<i></i>').join('')}<b>+9</b></div>
      <div class="domino-rule-hand">${[[0,3],[1,5],[2,2],[2,6],[3,4],[4,5],[1,1]].map(tile => tileMarkup(...tile)).join('')}</div>
      <div class="domino-rule-start-tile">${tileMarkup(6,6,true)}<span>Старший дубль</span></div>
    </div>
  `;
}

function matchRule(){
  return `
    <div class="domino-rule-scene match">
      <div class="domino-rule-chain">${tileMarkup(6,2)}${tileMarkup(2,4)}${tileMarkup(4,1)}</div>
      <div class="domino-rule-arrow">↓</div>
      <div class="domino-rule-choice correct">${tileMarkup(1,5)}<span>Число 1 совпадает</span></div>
    </div>
  `;
}

function sideRule(){
  return `
    <div class="domino-rule-scene sides">
      <button type="button" tabindex="-1">← 3</button>
      <div class="domino-rule-chain">${tileMarkup(3,6)}${tileMarkup(6,2)}${tileMarkup(2,3)}</div>
      <button type="button" tabindex="-1">3 →</button>
      <div class="domino-rule-selected">${tileMarkup(3,3,true)}<span>Подходит к обоим концам</span></div>
    </div>
  `;
}

function doubleRule(){
  return `
    <div class="domino-rule-scene doubles">
      <div class="domino-rule-chain">${tileMarkup(5,2)}${tileMarkup(2,4)}<span class="domino-rule-cross">${tileMarkup(4,4,true)}</span>${tileMarkup(4,1)}</div>
      <span>Дубль расположен поперёк</span>
    </div>
  `;
}

function drawRule(){
  return `
    <div class="domino-rule-scene draw">
      <div class="domino-rule-stock">${Array.from({length:4}, () => '<i></i>').join('')}<b>8</b></div>
      <div class="domino-rule-draw-arrow">→</div>
      <div class="domino-rule-drawn">${tileMarkup(2,6)}<span>Одно нажатие — одна костяшка</span></div>
    </div>
  `;
}

function scoreRule(){
  return `
    <div class="domino-rule-scene score">
      <div><strong>Вы</strong><span>${tileMarkup(1,2)}${tileMarkup(0,3)}</span><b>6 очков</b></div>
      <em>меньше</em>
      <div><strong>Соперник</strong><span>${tileMarkup(4,5)}${tileMarkup(2,6)}</span><b>17 очков</b></div>
    </div>
  `;
}

function tileMarkup(a, b, double = false){
  return `<span class="domino-rule-tile ${double ? 'double' : ''}">${halfMarkup(a)}${halfMarkup(b)}</span>`;
}

function halfMarkup(value){
  const active = new Set(pipPositions(value));
  return `<i class="domino-rule-half">${Array.from({length:9}, (_, index) => `<b class="${active.has(index + 1) ? 'active' : ''}"></b>`).join('')}</i>`;
}

function pipPositions(value){
  return ({0:[],1:[5],2:[1,9],3:[1,5,9],4:[1,3,7,9],5:[1,3,5,7,9],6:[1,3,4,6,7,9]})[Number(value)] || [];
}
