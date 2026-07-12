import { openSheet } from '../components/sheet.js?v=68';
import { state } from '../state.js?v=27';
import { gameTypeOf } from './game-router.js?v=70';
import { ticTacToeRules } from './tictactoe/rules.js?v=53';
import { fourInARowRules } from './four-in-a-row/rules.js?v=53';
import { battleshipRules } from './battleship/rules.js?v=54';
import { checkersRules } from './checkers/rules.js?v=58';
import { reversiRules } from './reversi/rules.js?v=66';
import { chessRules } from './chess/rules.js?v=69';
import { goRules } from './go/rules.js?v=70';

const ruleRenderers = {
  tictactoe: ticTacToeRules,
  four_in_a_row: fourInARowRules,
  battleship: battleshipRules,
  checkers: checkersRules,
  reversi: reversiRules,
  chess: chessRules,
  go: goRules,
};

export function initGameRules(){
  document.addEventListener('click', event => {
    const button = event.target.closest('[data-game-rules]');
    if (!button) return;
    const explicit = String(button.dataset.gameRules || '');
    const gameType = explicit || gameTypeOf(state.activeGame || {});
    const renderer = ruleRenderers[gameType];
    if (!renderer) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    openSheet(renderer());
  }, true);
}
