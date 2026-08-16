import { openSheet } from '../components/sheet.js?v=68';
import { state } from '../state.js?v=27';
import { gameTypeOf } from './game-router.js?v=74';
import { ticTacToeRules } from './tictactoe/rules.js?v=54';
import { fourInARowRules } from './four-in-a-row/rules.js?v=53';
import { battleshipRules } from './battleship/rules.js?v=54';
import { checkersRules } from './checkers/rules.js?v=58';
import { reversiRules } from './reversi/rules.js?v=66';
import { chessRules } from './chess/rules.js?v=69';
import { goRules } from './go/rules.js?v=71';
import { dominoRules } from './domino/rules.js?v=75';

const RULE_RENDERERS = {
  tictactoe: ticTacToeRules,
  four_in_a_row: fourInARowRules,
  battleship: battleshipRules,
  checkers: checkersRules,
  reversi: reversiRules,
  chess: chessRules,
  go: goRules,
  domino: dominoRules,
};

let initialized = false;

export function initGameRules(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('click', event => {
    const button = event.target instanceof Element
      ? event.target.closest('[data-game-rules], [data-game-rules-current]')
      : null;
    if (!button) return;

    const current = button.hasAttribute('data-game-rules-current');
    const game = current ? state.activeGame : null;
    const gameType = current
      ? gameTypeOf(game)
      : String(button.dataset.gameRules || 'tictactoe');
    const variant = current
      ? ruleVariantForGame(gameType, game)
      : normalizeRuleVariant(button.dataset.gameRulesVariant);

    openGameRules(gameType, { variant, game });
  });
}

export function openGameRules(gameType, context = {}){
  const renderer = RULE_RENDERERS[gameType] || RULE_RENDERERS.tictactoe;
  const variant = context.variant ?? ruleVariantForGame(gameType, context.game);
  openSheet(renderer({ ...context, gameType, variant }));
}

export function ruleVariantForGame(gameType, game){
  if (gameType === 'tictactoe') {
    return normalizeRuleVariant(game?.board_size ?? game?.boardSize);
  }
  return null;
}

function normalizeRuleVariant(value){
  const numeric = Number(value);
  return Number.isFinite(numeric) && numeric > 0 ? numeric : null;
}
