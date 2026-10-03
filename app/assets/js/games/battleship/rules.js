import { t } from '@mgw/i18n';

export function battleshipRules(){
  return `
    <div class="sheet-head game-rules-head">
      <div><h2>${t('rules.battleship.title')}</h2><p>${t('rules.battleship.subtitle')}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="game-rules-content">
      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>${t('rules.battleship.fleet_title')}</strong><span>${t('rules.battleship.fleet_text')}</span></div>
        <div class="battleship-rule-fleet">
          ${fleetRow(4, 1)}
          ${fleetRow(3, 2)}
          ${fleetRow(2, 3)}
          ${fleetRow(1, 4)}
        </div>
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>${t('rules.battleship.placement_title')}</strong><span>${t('rules.battleship.placement_text')}</span></div>
        <div class="battleship-rule-placement">
          <div class="valid"><strong>${t('rules.battleship.valid')}</strong>${placementGrid(false)}</div>
          <div class="invalid"><strong>${t('rules.battleship.invalid')}</strong>${placementGrid(true)}</div>
        </div>
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy"><strong>${t('rules.battleship.setup_time_title')}</strong><span>${t('rules.battleship.setup_time_text')}</span></div>
      </section>

      <section class="game-rule-card">
        <div class="game-rule-copy"><strong>${t('rules.battleship.shot_title')}</strong><span>${t('rules.battleship.shot_text')}</span></div>
        <div class="battleship-shot-examples">
          <div><i class="miss"></i><span>${t('rules.battleship.miss')}</span></div>
          <div><i class="hit"></i><span>${t('rules.battleship.hit')}</span></div>
          <div><span class="sunk-line"><i></i><i></i><i></i></span><span>${t('rules.battleship.sunk')}</span></div>
        </div>
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy"><strong>${t('rules.battleship.timer_title')}</strong><span>${t('rules.battleship.timer_text')}</span></div>
      </section>

      <section class="game-rule-card compact">
        <div class="game-rule-copy"><strong>${t('rules.battleship.win_title')}</strong><span>${t('rules.battleship.win_text')}</span></div>
      </section>
    </div>

    <button class="btn primary full sheet-bottom-btn" data-close-sheet type="button">${t('rules.understood')}</button>
  `;
}

function fleetRow(size, count){
  return `<div><span class="battleship-rule-ship">${'<i></i>'.repeat(size)}</span><strong>×${count}</strong></div>`;
}

function placementGrid(invalid){
  const shipCells = invalid ? new Set([6,7,12]) : new Set([5,6,7,18,23]);
  return `<div class="battleship-placement-grid ${invalid ? 'bad' : ''}">${Array.from({ length: 25 }, (_, cell) => `<i class="${shipCells.has(cell) ? 'ship' : ''}"></i>`).join('')}</div>`;
}
