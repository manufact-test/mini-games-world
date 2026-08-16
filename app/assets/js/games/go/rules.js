import { t } from '@mgw/i18n';

const GO_RULE_SIZES = Object.freeze([9, 13]);
const GO_KOMI = 6.5;

export function goRules({ variant } = {}){
  const size = goRuleVariant(variant);
  const values = { size, komi:String(GO_KOMI).replace('.', ',') };

  return `
    <div class="sheet-head game-rules-head">
      <div>
        <h2>${escapeHtml(t('rules.go.title', values))}</h2>
        <p>${escapeHtml(t('rules.go.subtitle', values))}</p>
      </div>
      <button class="close" data-close-sheet type="button" aria-label="${escapeHtml(t('common.close'))}">×</button>
    </div>

    <div class="game-rules-content go-rules" data-rule-variant="${size}">
      <section class="game-rule-card compact">
        <div class="game-rule-copy">
          <strong>${escapeHtml(t('rules.go.size_title', values))}</strong>
          <span>${escapeHtml(t('rules.go.size_text', values))}</span>
        </div>
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy">
          <strong>${escapeHtml(t('rules.go.start_title'))}</strong>
          <span>${escapeHtml(t('rules.go.start_text', values))}</span>
        </div>
        ${ruleBoard('start', size)}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy">
          <strong>${escapeHtml(t('rules.go.liberties_title'))}</strong>
          <span>${escapeHtml(t('rules.go.liberties_text'))}</span>
        </div>
        ${ruleBoard('liberties', size)}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy">
          <strong>${escapeHtml(t('rules.go.capture_title'))}</strong>
          <span>${escapeHtml(t('rules.go.capture_text'))}</span>
        </div>
        ${ruleBoard('capture', size)}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy">
          <strong>${escapeHtml(t('rules.go.suicide_title'))}</strong>
          <span>${escapeHtml(t('rules.go.suicide_text'))}</span>
        </div>
        ${ruleBoard('suicide', size)}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy">
          <strong>${escapeHtml(t('rules.go.ko_title'))}</strong>
          <span>${escapeHtml(t('rules.go.ko_text'))}</span>
        </div>
        ${ruleBoard('ko', size)}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy">
          <strong>${escapeHtml(t('rules.go.pass_title'))}</strong>
          <span>${escapeHtml(t('rules.go.pass_text'))}</span>
        </div>
        ${ruleBoard('score', size)}
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy">
          <strong>${escapeHtml(t('rules.go.score_title'))}</strong>
          <span>${escapeHtml(t('rules.go.score_text', values))}</span>
        </div>
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy">
          <strong>${escapeHtml(t('rules.go.timer_title'))}</strong>
          <span>${escapeHtml(t('rules.go.timer_text'))}</span>
        </div>
      </section>
    </div>

    <button class="btn primary full sheet-bottom-btn" data-close-sheet type="button">${escapeHtml(t('rules.understood'))}</button>
  `;
}

export function goRuleVariant(value){
  const size = Number(value);
  return GO_RULE_SIZES.includes(size) ? size : 9;
}

function ruleBoard(type, size){
  const stones = new Map();
  const markers = new Map();
  const setStone = (row, col, color, extra = '') => {
    if (row < 0 || row >= size || col < 0 || col >= size) return;
    stones.set(row * size + col, `${color} ${extra}`.trim());
  };
  const setMarker = (row, col, marker) => {
    if (row < 0 || row >= size || col < 0 || col >= size) return;
    markers.set(row * size + col, marker);
  };
  const mid = Math.floor(size / 2);

  if (type === 'start') {
    setStone(mid, mid, 'black', 'first-stone');
  }

  if (type === 'liberties') {
    setStone(mid, mid, 'black');
    setStone(mid, mid + 1, 'black');
    [[mid - 1,mid],[mid - 1,mid + 1],[mid,mid - 1],[mid,mid + 2],[mid + 1,mid],[mid + 1,mid + 1]].forEach(([r,c]) => setMarker(r,c,'liberty'));
  }

  if (type === 'capture') {
    setStone(mid, mid, 'white capture');
    setStone(mid, mid + 1, 'white capture');
    [[mid - 1,mid],[mid - 1,mid + 1],[mid,mid - 1],[mid + 1,mid],[mid + 1,mid + 1]].forEach(([r,c]) => setStone(r,c,'black'));
    setMarker(mid, mid + 2, 'target-black');
  }

  if (type === 'suicide') {
    [[mid - 1,mid],[mid,mid - 1],[mid,mid + 1],[mid + 1,mid]].forEach(([r,c]) => setStone(r,c,'white'));
    setMarker(mid,mid,'forbidden');
  }

  if (type === 'ko') {
    setStone(mid - 1,mid,'black');
    setStone(mid,mid - 1,'black');
    setStone(mid + 1,mid,'black');
    setStone(mid,mid,'white capture');
    setStone(mid - 1,mid + 1,'white');
    setStone(mid + 1,mid + 1,'white');
    setStone(mid,mid + 2,'white');
    setMarker(mid,mid + 1,'ko');
  }

  if (type === 'score') {
    const blackBase = Math.max(2, Math.floor(size * .25));
    const whiteBase = Math.min(size - 3, Math.ceil(size * .7));
    [[blackBase,blackBase],[blackBase,blackBase + 1],[blackBase,blackBase + 2],[blackBase + 1,blackBase],[blackBase + 2,blackBase]].forEach(([r,c]) => setStone(r,c,'black'));
    [[whiteBase,whiteBase],[whiteBase,whiteBase + 1],[whiteBase + 1,whiteBase],[whiteBase + 1,whiteBase + 1],[whiteBase - 1,whiteBase + 1]].forEach(([r,c]) => setStone(r,c,'white'));
    [[blackBase + 1,blackBase + 1],[blackBase + 1,blackBase + 2],[blackBase + 2,blackBase + 1],[blackBase + 2,blackBase + 2]].forEach(([r,c]) => setMarker(r,c,'territory-black'));
    [[whiteBase,whiteBase + 2],[whiteBase + 1,whiteBase + 2],[whiteBase + 2,whiteBase],[whiteBase + 2,whiteBase + 1],[whiteBase + 2,whiteBase + 2]].forEach(([r,c]) => setMarker(r,c,'territory-white'));
    setMarker(mid,mid,'neutral');
  }

  return `
    <div class="go-rule-board ${type} size-${size}" role="img" aria-label="${escapeHtml(t('rules.go.diagram_label', { size }))}" style="--go-rule-size:${size}">
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
  const coordinates = size === 13 ? [3,6,9] : [2,4,6];
  return coordinates.flatMap(row => coordinates.map(col => {
    const x = inset + (col / (size - 1)) * span;
    const y = inset + (row / (size - 1)) * span;
    return `<i class="go-star" style="--go-x:${x}%;--go-y:${y}%;--go-rule-point-size:${82 / (size - 1)}%"></i>`;
  })).join('');
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
