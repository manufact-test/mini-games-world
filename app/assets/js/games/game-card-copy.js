import { TIC_TAC_TOE_META } from './tictactoe/meta.js?v=53';
import { FOUR_IN_A_ROW_META } from './four-in-a-row/meta.js?v=53';
import { BATTLESHIP_META } from './battleship/meta.js?v=53';
import { CHECKERS_META } from './checkers/meta.js?v=58';
import { REVERSI_META } from './reversi/meta.js?v=65';
import { CHESS_META } from './chess/meta.js?v=67';
import { GO_META } from './go/meta.js?v=70';
import { DOMINO_META } from './domino/meta.js?v=72';

const GAME_META = {
  [TIC_TAC_TOE_META.id]: TIC_TAC_TOE_META,
  [FOUR_IN_A_ROW_META.id]: FOUR_IN_A_ROW_META,
  [BATTLESHIP_META.id]: BATTLESHIP_META,
  [CHECKERS_META.id]: CHECKERS_META,
  [REVERSI_META.id]: REVERSI_META,
  [CHESS_META.id]: CHESS_META,
  [GO_META.id]: GO_META,
  [DOMINO_META.id]: DOMINO_META,
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
