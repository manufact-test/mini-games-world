import { t } from '@mgw/i18n';

const REVERSI_RULE_SIZES = Object.freeze([6, 8, 10]);

export function reversiRules({ variant } = {}){
  const size = reversiRuleVariant(variant);
  const values = { size };

  return `
    <div class="sheet-head game-rules-head">
      <div>
        <h2>${escapeHtml(t('rules.reversi.title', values))}</h2>
        <p>${escapeHtml(t('rules.reversi.subtitle', values))}</p>
      </div>
      <button class="close" data-close-sheet type="button" aria-label="${escapeHtml(t('common.close'))}">×</button>
    </div>

    <div class="game-rules-content reversi-rules" data-rule-variant="${size}">
      <section class="game-rule-card compact">
        <div class="game-rule-copy">
          <strong>${escapeHtml(t('rules.reversi.size_title', values))}</strong>
          <span>${escapeHtml(t('rules.reversi.size_text', values))}</span>
        </div>
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy">
          <strong>${escapeHtml(t('rules.reversi.start_title'))}</strong>
          <span>${escapeHtml(t('rules.reversi.start_text', values))}</span>
        </div>
        ${ruleBoard('start', size)}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy">
          <strong>${escapeHtml(t('rules.reversi.legal_title'))}</strong>
          <span>${escapeHtml(t('rules.reversi.legal_text'))}</span>
        </div>
        ${ruleBoard('legal', size)}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy">
          <strong>${escapeHtml(t('rules.reversi.flip_title'))}</strong>
          <span>${escapeHtml(t('rules.reversi.flip_text'))}</span>
        </div>
        ${ruleBoard('flip', size)}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy">
          <strong>${escapeHtml(t('rules.reversi.multi_title'))}</strong>
          <span>${escapeHtml(t('rules.reversi.multi_text'))}</span>
        </div>
        ${ruleBoard('multi', size)}
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy">
          <strong>${escapeHtml(t('rules.reversi.pass_title'))}</strong>
          <span>${escapeHtml(t('rules.reversi.pass_text'))}</span>
        </div>
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy">
          <strong>${escapeHtml(t('rules.reversi.finish_title'))}</strong>
          <span>${escapeHtml(t('rules.reversi.finish_text'))}</span>
        </div>
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy">
          <strong>${escapeHtml(t('rules.reversi.timer_title'))}</strong>
          <span>${escapeHtml(t('rules.reversi.timer_text'))}</span>
        </div>
      </section>
    </div>

    <button class="btn primary full sheet-bottom-btn" data-close-sheet type="button">${escapeHtml(t('rules.understood'))}</button>
  `;
}

export function reversiRuleVariant(value){
  const size = Number(value);
  return REVERSI_RULE_SIZES.includes(size) ? size : 8;
}

function ruleBoard(type, size){
  const cells = Array.from({ length:size * size }, () => '');
  const set = (row, col, value) => {
    if (row < 0 || row >= size || col < 0 || col >= size) return;
    cells[row * size + col] = value;
  };
  const lower = Math.floor(size / 2);
  const upper = lower - 1;

  if (type === 'start' || type === 'legal') {
    set(upper, upper, 'white');
    set(lower, lower, 'white');
    set(upper, lower, 'black');
    set(lower, upper, 'black');
  }

  if (type === 'legal') {
    set(upper - 1, upper, 'target');
    set(upper, upper - 1, 'target');
    set(lower, lower + 1, 'target');
    set(lower + 1, lower, 'target');
  }

  if (type === 'flip') {
    const row = upper;
    set(row, lower - 2, 'black anchor');
    set(row, lower - 1, 'white flip');
    set(row, lower, 'white flip');
    set(row, lower + 1, 'target black-new');
  }

  if (type === 'multi') {
    set(upper - 1, upper - 1, 'black anchor');
    set(upper - 1, lower, 'black anchor');
    set(lower, upper - 1, 'black anchor');
    set(upper, upper, 'white flip');
    set(upper, lower, 'white flip');
    set(lower, upper, 'white flip');
    set(lower, lower, 'target black-new');
  }

  return `<div class="reversi-rule-board ${type} size-${size}" role="img" aria-label="${escapeHtml(t('rules.reversi.diagram_label', { size }))}" style="--reversi-rule-size:${size}">${cells.map((value, index) => {
    const parts = value.split(' ').filter(Boolean);
    const isTarget = parts.includes('target');
    const isFlip = parts.includes('flip');
    const isAnchor = parts.includes('anchor');
    const color = parts.includes('white') ? 'white' : (parts.includes('black') || parts.includes('black-new') ? 'black' : '');
    const classes = [isTarget ? 'target' : '', isFlip ? 'flip' : '', isAnchor ? 'anchor' : '', parts.includes('black-new') ? 'new' : ''].filter(Boolean).join(' ');
    return `<i class="${classes}" data-cell="${index}">${color ? `<b class="${color}"></b>` : ''}${isTarget && !color ? '<em></em>' : ''}</i>`;
  }).join('')}</div>`;
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
