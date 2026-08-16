import { t } from '@mgw/i18n';

const TICTACTOE_RULE_VARIANTS = Object.freeze({
  3:Object.freeze({ size:3, need:3, winCells:[0,4,8], opponentCells:[1,5] }),
  5:Object.freeze({ size:5, need:4, winCells:[10,11,12,13], opponentCells:[2,7,19] }),
  9:Object.freeze({ size:9, need:5, winCells:[20,30,40,50,60], opponentCells:[12,22,32,48,58,68] }),
});

export function ticTacToeRules({ variant } = {}){
  const rules = ticTacToeRuleVariant(variant);
  const values = { size:rules.size, need:rules.need };

  return `
    <div class="sheet-head game-rules-head">
      <div>
        <h2>${escapeHtml(t('rules.tictactoe.title', values))}</h2>
        <p>${escapeHtml(t('rules.tictactoe.subtitle', values))}</p>
      </div>
      <button class="close" data-close-sheet type="button" aria-label="${escapeHtml(t('common.close'))}">×</button>
    </div>
    <div class="game-rules-content tictactoe-rules" data-rule-variant="${rules.size}">
      <section class="game-rule-card">
        <div class="game-rule-copy">
          <strong>${escapeHtml(t('rules.tictactoe.turn_title'))}</strong>
          <span>${escapeHtml(t('rules.tictactoe.turn_text', values))}</span>
        </div>
        ${renderWinDiagram(rules)}
      </section>
      <section class="game-rule-card compact">
        <div class="game-rule-copy">
          <strong>${escapeHtml(t('rules.tictactoe.win_title'))}</strong>
          <span>${escapeHtml(t('rules.tictactoe.win_text', values))}</span>
        </div>
        <div class="game-rule-tip">${escapeHtml(t('rules.tictactoe.win_tip', values))}</div>
      </section>
      <section class="game-rule-card compact">
        <div class="game-rule-copy tictactoe-rule-last-copy">
          <strong>${escapeHtml(t('rules.tictactoe.draw_title'))}</strong>
          <span>${escapeHtml(t('rules.tictactoe.draw_text'))}</span>
        </div>
      </section>
    </div>
    <button class="btn primary full sheet-bottom-btn" data-close-sheet type="button">${escapeHtml(t('rules.understood'))}</button>
  `;
}

export function ticTacToeRuleVariant(value){
  const size = Number(value);
  return TICTACTOE_RULE_VARIANTS[size] || TICTACTOE_RULE_VARIANTS[3];
}

function renderWinDiagram(rules){
  const winner = new Set(rules.winCells);
  const opponent = new Set(rules.opponentCells);
  const cells = Array.from({ length:rules.size * rules.size }, (_, index) => {
    const mark = winner.has(index) ? '✕' : (opponent.has(index) ? '○' : '');
    const classes = ['rule-tic-cell'];
    if (winner.has(index)) classes.push('winner');
    if (opponent.has(index)) classes.push('opponent');
    return `<span class="${classes.join(' ')}">${mark}</span>`;
  }).join('');

  return `
    <figure class="rule-tic-figure">
      <div class="rule-tic-grid size-${rules.size}" role="img" aria-label="${escapeHtml(t('rules.tictactoe.diagram_label', { need:rules.need, size:rules.size }))}">
        ${cells}
      </div>
      <figcaption>${escapeHtml(t('rules.tictactoe.diagram_caption', { need:rules.need }))}</figcaption>
    </figure>
  `;
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
