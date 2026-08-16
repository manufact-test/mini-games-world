import { t } from '@mgw/i18n';

const FOUR_RULE_VARIANTS = Object.freeze({
  6:Object.freeze({ columns:6, rows:5, connect:4 }),
  7:Object.freeze({ columns:7, rows:6, connect:4 }),
  8:Object.freeze({ columns:8, rows:7, connect:4 }),
});

export function fourInARowRules({ variant } = {}){
  const rules = fourInARowRuleVariant(variant);
  const values = { columns:rules.columns, rows:rules.rows, connect:rules.connect };

  return `
    <div class="sheet-head game-rules-head">
      <div>
        <h2>${escapeHtml(t('rules.four_in_a_row.title', values))}</h2>
        <p>${escapeHtml(t('rules.four_in_a_row.subtitle', values))}</p>
      </div>
      <button class="close" data-close-sheet type="button" aria-label="${escapeHtml(t('common.close'))}">×</button>
    </div>

    <div class="game-rules-content four-in-a-row-rules" data-rule-variant="${rules.columns}">
      <section class="game-rule-card">
        <div class="game-rule-copy">
          <strong>${escapeHtml(t('rules.four_in_a_row.turn_title'))}</strong>
          <span>${escapeHtml(t('rules.four_in_a_row.turn_text', values))}</span>
        </div>
        ${fourBoard(rules)}
        <div class="game-rule-arrow">${escapeHtml(t('rules.four_in_a_row.gravity'))}</div>
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy">
          <strong>${escapeHtml(t('rules.four_in_a_row.win_title'))}</strong>
          <span>${escapeHtml(t('rules.four_in_a_row.win_text', values))}</span>
        </div>
        <div class="rule-win-examples">
          <div><span>${escapeHtml(t('rules.four_in_a_row.horizontal'))}</span>${miniLine(['yellow','yellow','yellow','yellow'])}</div>
          <div><span>${escapeHtml(t('rules.four_in_a_row.vertical'))}</span>${miniColumn(['red','red','red','red'])}</div>
          <div><span>${escapeHtml(t('rules.four_in_a_row.diagonal'))}</span>${miniDiagonal()}</div>
        </div>
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy">
          <strong>${escapeHtml(t('rules.four_in_a_row.size_title'))}</strong>
          <span>${escapeHtml(t('rules.four_in_a_row.size_text', values))}</span>
        </div>
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy">
          <strong>${escapeHtml(t('rules.four_in_a_row.draw_title'))}</strong>
          <span>${escapeHtml(t('rules.four_in_a_row.draw_text', values))}</span>
        </div>
      </section>

      <section class="game-rule-tip">${escapeHtml(t('rules.four_in_a_row.tip'))}</section>
    </div>

    <button class="btn primary full sheet-bottom-btn" data-close-sheet type="button">${escapeHtml(t('rules.understood'))}</button>
  `;
}

export function fourInARowRuleVariant(value){
  const columns = Number(value);
  return FOUR_RULE_VARIANTS[columns] || FOUR_RULE_VARIANTS[7];
}

function fourBoard(rules){
  const cells = Array.from({ length:rules.columns * rules.rows }, () => '');
  const set = (row, col, value) => {
    if (row < 0 || row >= rules.rows || col < 0 || col >= rules.columns) return;
    cells[row * rules.columns + col] = value;
  };
  const center = Math.floor(rules.columns / 2);
  set(rules.rows - 1, center, 'yellow');
  set(rules.rows - 2, center, 'red');
  set(rules.rows - 1, Math.max(0, center - 1), 'red');
  set(rules.rows - 1, Math.min(rules.columns - 1, center + 1), 'yellow');
  set(rules.rows - 2, Math.max(0, center - 1), 'yellow');
  if (rules.rows >= 6) set(rules.rows - 3, center, 'yellow');

  return `<div class="rule-four-board" role="img" aria-label="${escapeHtml(t('rules.four_in_a_row.diagram_label', {
    columns:rules.columns,
    rows:rules.rows,
  }))}" style="--four-rule-columns:${rules.columns};--four-rule-rows:${rules.rows};grid-template-columns:repeat(${rules.columns},minmax(0,1fr));aspect-ratio:${rules.columns}/${rules.rows}">${cells.map(value => `<span class="${value}"></span>`).join('')}</div>`;
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

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
