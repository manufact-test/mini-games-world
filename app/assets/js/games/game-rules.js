import { openSheet } from '../components/sheet.js?v=68';
import { state } from '../state.js?v=27';
import { gameTypeOf } from './game-router.js?v=68';
import { ticTacToeRules } from './tictactoe/rules.js?v=53';
import { fourInARowRules } from './four-in-a-row/rules.js?v=53';
import { battleshipRules } from './battleship/rules.js?v=54';
import { checkersRules } from './checkers/rules.js?v=58';
import { reversiRules } from './reversi/rules.js?v=66';
import { chessRules } from './chess/rules.js?v=69';

const RULE_RENDERERS = {
  tictactoe: ticTacToeRules,
  four_in_a_row: fourInARowRules,
  battleship: battleshipRules,
  checkers: checkersRules,
  reversi: reversiRules,
  chess: chessRules,
};

let initialized = false;

export function initGameRules(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('click', event => {
    const button = event.target.closest('[data-game-rules], [data-game-rules-current]');
    if (!button) return;

    const gameType = button.hasAttribute('data-game-rules-current')
      ? gameTypeOf(state.activeGame)
      : String(button.dataset.gameRules || 'tictactoe');

    openGameRules(gameType);
  });
}

export function openGameRules(gameType){
  const renderer = RULE_RENDERERS[gameType] || RULE_RENDERERS.tictactoe;
  openSheet(renderer());
}
