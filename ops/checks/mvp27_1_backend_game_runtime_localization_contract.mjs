import fs from 'node:fs';

const CYR = /[\u0400-\u04FF]/;
const owners = [
  "bot/services/GameService.php",
  "bot/games/battleship/BattleshipService.php",
  "bot/games/domino/DominoService.php",
  "bot/games/chess/ChessService.php",
  "bot/services/GameActionService.php",
  "bot/services/GameReactionService.php",
  "bot/games/go/GoService.php",
  "bot/games/checkers/CheckersService.php",
  "bot/games/reversi/ReversiService.php",
  "bot/services/FourInARowService.php",
  "bot/game-watch.php",
  "bot/game-clock.php",
  "bot/services/PresenceService.php",
  "bot/services/MatchPreparationClockService.php",
  "bot/game-live-v108.php",
  "bot/games/checkers/CheckersBotService.php",
  "bot/services/GameSettlementService.php",
  "bot/services/ReconnectLifecycleService.php",
  "bot/services/ChessRuntimeService.php",
  "bot/services/GameRuntimeService.php",
  "bot/runtime/UnifiedGameZonePolicy.php",
  "bot/games/chess/ChessBotService.php",
  "bot/games/reversi/ReversiBotService.php",
  "bot/services/BotProfilePolicy.php",
  "bot/services/GameCatalogService.php"
];
const locale = JSON.parse(fs.readFileSync('app/locales/ru.json', 'utf8'));
const baseline = JSON.parse(fs.readFileSync('ops/checks/mvp27_1_hardcoded_text_baseline.json', 'utf8'));

function get(path) {
  let value = locale;
  for (const part of path.split('.')) value = value?.[part];
  return value;
}
function assert(condition, message) {
  if (!condition) throw new Error(message);
}

assert(locale?._meta?.locale === 'ru', 'RU locale identity changed.');
assert(Number(locale?._meta?.version ?? 0) >= 69, 'Game-runtime localization must preserve RU locale version 69 or a valid successor.');
assert(baseline?.by_scope?.client === 0, 'Client localization debt must remain zero.');
assert(baseline?.by_scope?.['client-entry'] === 0, 'Client-entry localization debt must remain zero.');
assert(Number(baseline?.by_scope?.backend ?? Number.POSITIVE_INFINITY) <= 1087, 'Backend localization debt must not regress above the accepted 1087 game-runtime ceiling.');

const staticKeys = new Set();
const dynamicKeys = new Set();
for (const file of owners) {
  const source = fs.readFileSync(file, 'utf8');
  assert(!CYR.test(source), file + ' still contains direct Cyrillic source copy.');
  assert(source.includes('ServerLocalization::copy('), file + ' must delegate player-facing copy to ServerLocalization.');

  for (const match of source.matchAll(/ServerLocalization::copy\(\s*'([^']+)'/g)) {
    staticKeys.add(match[1]);
  }
  for (const match of source.matchAll(/'label_key'\s*=>\s*'([^']+)'/g)) {
    dynamicKeys.add(match[1]);
  }
}

for (const key of [...staticKeys, ...dynamicKeys]) {
  const value = get(key);
  assert(typeof value === 'string', 'Missing locale key: ' + key);
}

const requiredExact = {
  'server.game_runtime.common.game_not_found': 'Игра не найдена.',
  'server.game_runtime.common.not_participant': 'Вы не участник этой игры.',
  'server.game_runtime.common.not_participating': 'Вы не участвуете в этой игре.',
  'server.game_runtime.common.not_your_turn': 'Сейчас не ваш ход.',
  'server.game_runtime.common.opponent_turn': 'Сейчас ход соперника.',
  'server.game_runtime.economy.entry_description': 'Участие в матче против {opponent}',
  'server.game_runtime.economy.draw_refund': 'Возврат коинов при ничьей',
  'server.game_runtime.economy.match_win': 'Выигрыш за матч',
  'server.game_runtime.reactions.invalid': 'Некорректная реакция.',
  'server.game_runtime.battleship.two_players': 'Для Морского боя нужны два игрока.',
  'server.game_runtime.domino.two_players': 'Для домино нужны два игрока.',
  'server.game_runtime.chess.two_players': 'Для шахмат нужны два игрока.',
  'server.game_runtime.go.two_players': 'Для Го нужны два игрока.',
  'server.game_runtime.checkers.two_players': 'Для шашек нужны два игрока.',
  'server.game_runtime.reversi.two_players': 'Для Реверси нужны два игрока.',
  'server.game_runtime.four_in_a_row.two_players': 'Для игры «4 в ряд» нужны два игрока.',
  'acceptance_runtime.search.room_gold': 'Gold-комната',
  'acceptance_runtime.search.room_match': 'Матч-комната',
  'profile.reactions.codes.handshake': 'Хорошая игра'
};
for (const [key, expected] of Object.entries(requiredExact)) {
  assert(get(key) === expected, 'Visible RU copy changed for ' + key);
}

const gameService = fs.readFileSync('bot/services/GameService.php', 'utf8');
assert(gameService.includes("'server.game_runtime.economy.entry_description'"), 'GameService must locale-own entry history descriptions.');
assert(gameService.includes("['opponent' =>"), 'GameService must interpolate the opponent into localized entry history copy.');

const reactions = fs.readFileSync('bot/services/GameReactionService.php', 'utf8');
assert(!reactions.includes("'label' => '"), 'Reaction labels must not return to direct source ownership.');
assert(reactions.includes("'label_key' => 'profile.reactions.codes."), 'Reaction labels must reuse canonical profile reaction locale keys.');

console.log('MVP27_1_BACKEND_GAME_RUNTIME_LOCALIZATION_CONTRACT=PASS');
console.log('owners=' + owners.length);
console.log('static_locale_keys=' + staticKeys.size);
console.log('dynamic_locale_keys=' + dynamicKeys.size);
console.log('backend_debt=' + baseline.by_scope.backend);
console.log('backend_debt_ceiling=1087');
