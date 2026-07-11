export function ticTacToeRules(){
  return `
    <div class="sheet-head game-rules-head">
      <div><h2>Крестики-нолики</h2><p>Соберите победную линию своих знаков раньше соперника.</p></div>
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
