import { t } from '@mgw/i18n';

export function checkersRules(){
  return `
    <div class="sheet-head game-rules-head">
      <div><h2>${t('rules.checkers.title')}</h2><p>${t('rules.checkers.subtitle')}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="game-rules-content">
      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>${t('rules.checkers.board_title')}</strong><span>${t('rules.checkers.board_text')}</span></div>
        ${ruleBoard('start')}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>${t('rules.checkers.move_title')}</strong><span>${t('rules.checkers.move_text')}</span></div>
        ${ruleBoard('move')}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>${t('rules.checkers.capture_title')}</strong><span>${t('rules.checkers.capture_text')}</span></div>
        ${ruleBoard('capture')}
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy"><strong>${t('rules.checkers.chain_title')}</strong><span>${t('rules.checkers.chain_text')}</span></div>
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>${t('rules.checkers.king_title')}</strong><span>${t('rules.checkers.king_text')}</span></div>
        ${ruleBoard('king')}
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy"><strong>${t('rules.checkers.promotion_title')}</strong><span>${t('rules.checkers.promotion_text')}</span></div>
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy"><strong>${t('rules.checkers.win_title')}</strong><span>${t('rules.checkers.win_text')}</span></div>
      </section>
    </div>

    <button class="btn primary full sheet-bottom-btn" data-close-sheet type="button">${t('rules.understood')}</button>
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
