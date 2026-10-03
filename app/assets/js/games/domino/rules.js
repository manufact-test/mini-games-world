import { t } from '@mgw/i18n';

export function dominoRules(){
  return `
    <div class="sheet-head game-rules-head">
      <div><h2>${t('rules.domino.title')}</h2><p>${t('rules.domino.subtitle')}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="game-rules-content">
      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>${t('rules.domino.start_title')}</strong><span>${t('rules.domino.start_text')}</span></div>
        ${startRule()}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>${t('rules.domino.match_title')}</strong><span>${t('rules.domino.match_text')}</span></div>
        ${matchRule()}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>${t('rules.domino.side_title')}</strong><span>${t('rules.domino.side_text')}</span></div>
        ${sideRule()}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>${t('rules.domino.doubles_title')}</strong><span>${t('rules.domino.doubles_text')}</span></div>
        ${doubleRule()}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>${t('rules.domino.draw_title')}</strong><span>${t('rules.domino.draw_text')}</span></div>
        ${drawRule()}
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>${t('rules.domino.win_title')}</strong><span>${t('rules.domino.win_text')}</span></div>
        ${scoreRule()}
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy"><strong>${t('rules.domino.timer_title')}</strong><span>${t('rules.domino.timer_text')}</span></div>
      </section>
    </div>

    <button class="btn primary full sheet-bottom-btn" data-close-sheet type="button">${t('rules.understood')}</button>
  `;
}

function startRule(){
  return `
    <div class="domino-rule-scene start">
      <div class="domino-rule-stock">${Array.from({length:5}, () => '<i></i>').join('')}<b>+9</b></div>
      <div class="domino-rule-hand">${[[0,3],[1,5],[2,2],[2,6],[3,4],[4,5],[1,1]].map(tile => tileMarkup(...tile)).join('')}</div>
      <div class="domino-rule-start-tile">${tileMarkup(6,6,true)}<span>${t('rules.domino.scene_start_double')}</span></div>
    </div>
  `;
}

function matchRule(){
  return `
    <div class="domino-rule-scene match">
      <div class="domino-rule-chain">${tileMarkup(6,2)}${tileMarkup(2,4)}${tileMarkup(4,1)}</div>
      <div class="domino-rule-arrow">↓</div>
      <div class="domino-rule-choice correct">${tileMarkup(1,5)}<span>${t('rules.domino.scene_match')}</span></div>
    </div>
  `;
}

function sideRule(){
  return `
    <div class="domino-rule-scene sides">
      <button type="button" tabindex="-1">← 3</button>
      <div class="domino-rule-chain">${tileMarkup(3,6)}${tileMarkup(6,2)}${tileMarkup(2,3)}</div>
      <button type="button" tabindex="-1">3 →</button>
      <div class="domino-rule-selected">${tileMarkup(3,3,true)}<span>${t('rules.domino.scene_both_ends')}</span></div>
    </div>
  `;
}

function doubleRule(){
  return `
    <div class="domino-rule-scene doubles">
      <div class="domino-rule-chain">${tileMarkup(5,2)}${tileMarkup(2,4)}<span class="domino-rule-cross">${tileMarkup(4,4,true)}</span>${tileMarkup(4,1)}</div>
      <span>${t('rules.domino.scene_double_cross')}</span>
    </div>
  `;
}

function drawRule(){
  return `
    <div class="domino-rule-scene draw">
      <div class="domino-rule-stock">${Array.from({length:4}, () => '<i></i>').join('')}<b>8</b></div>
      <div class="domino-rule-draw-arrow">→</div>
      <div class="domino-rule-drawn">${tileMarkup(2,6)}<span>${t('rules.domino.scene_draw_one')}</span></div>
    </div>
  `;
}

function scoreRule(){
  return `
    <div class="domino-rule-scene score">
      <div><strong>${t('rules.domino.scene_you')}</strong><span>${tileMarkup(1,2)}${tileMarkup(0,3)}</span><b>${t('rules.domino.scene_you_points')}</b></div>
      <em>${t('rules.domino.scene_less')}</em>
      <div><strong>${t('rules.domino.scene_opponent')}</strong><span>${tileMarkup(4,5)}${tileMarkup(2,6)}</span><b>${t('rules.domino.scene_opponent_points')}</b></div>
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
