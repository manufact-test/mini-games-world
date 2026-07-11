import { TIC_TAC_TOE_META } from './tictactoe/meta.js?v=52';
import { FOUR_IN_A_ROW_META } from './four-in-a-row/meta.js?v=52';

const GAME_META = {
  [TIC_TAC_TOE_META.id]: TIC_TAC_TOE_META,
  [FOUR_IN_A_ROW_META.id]: FOUR_IN_A_ROW_META,
};

export function initGameCardCopy(){
  document.querySelectorAll('[data-game-card]').forEach(card => {
    const meta = GAME_META[String(card.dataset.gameCard || '')];
    if (!meta) return;

    const title = card.querySelector('[data-game-title]');
    const icon = card.querySelector('[data-game-icon]');
    const description = card.querySelector('[data-game-description]');
    const rulesButton = card.querySelector('[data-game-rules]');

    if (title) title.textContent = meta.title;
    if (icon) icon.textContent = meta.icon;
    if (description) description.textContent = meta.description;
    if (rulesButton) rulesButton.setAttribute('aria-label', `Правила игры ${meta.title}`);
  });
}
