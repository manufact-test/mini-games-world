<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/baseline/JsonBehaviorBaselineNormalizer.php';
require_once $root . '/bot/baseline/JsonBehaviorBaselineFixture.php';
require_once $root . '/bot/baseline/JsonBehaviorBaselineResult.php';
require_once $root . '/bot/baseline/JsonGamesBaselineScenario.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$fixtureRoot = realpath($root . '/bot/tests/fixtures/mvp14r2');
if (!is_string($fixtureRoot)) throw new RuntimeException('MVP-14R.2 fixture root is unavailable.');

$expected = [
    'games_tictactoe_draw' => ['scenario' => 'games.tictactoe.draw', 'type' => 'tictactoe', 'fingerprint' => 'ea7cdffe9bb4b1f73a2f8e6de0cd66536a3bfde8f532f7f7d2bf0a219cdf712f', 'trace' => 5],
    'games_four_in_a_row_win' => ['scenario' => 'games.four-in-a-row.win', 'type' => 'four_in_a_row', 'fingerprint' => '4221f4236db211175e232c220553d9488a9f3c4227b24fbe7240dc24dfeb8368', 'trace' => 5],
    'games_battleship_final_shot' => ['scenario' => 'games.battleship.final-shot', 'type' => 'battleship', 'fingerprint' => '5df23987db8c19a91d07fbd94ab4b7511cefca68751d7dde189bbb0fab32284b', 'trace' => 5],
    'games_checkers_capture' => ['scenario' => 'games.checkers.capture', 'type' => 'checkers', 'fingerprint' => '4cf10d2586b7ba25e0b471e8c12aea992d136f3f439fd104df36b156aafe9fd7', 'trace' => 5],
    'games_reversi_count_finish' => ['scenario' => 'games.reversi.count-finish', 'type' => 'reversi', 'fingerprint' => 'dea655095343dcaebb5bc77ee0c6ff41b95a328d74f2b5fc2c9e58fdf47be4a9', 'trace' => 5],
    'games_chess_timeout' => ['scenario' => 'games.chess.timeout', 'type' => 'chess', 'fingerprint' => 'af400ceffb27ba7977cb0a120f6d056e6cce53b8ce1a6aaad8d103f666744cf1', 'trace' => 7],
    'games_go_two_passes' => ['scenario' => 'games.go.two-passes', 'type' => 'go', 'fingerprint' => '70c8ba648b1bd6924a9fc0f07a098bd5d9c36021cc2ed0e1120d44100659ffd4', 'trace' => 7],
    'games_domino_empty_hand' => ['scenario' => 'games.domino.empty-hand', 'type' => 'domino', 'fingerprint' => '748ffc6f3c914fa01f0e15a9cf38f8d2684ba9803a624b995fb370d4c05fb6c9', 'trace' => 5],
];

$runner = new JsonGamesBaselineScenario();
$results = [];
foreach ($expected as $fixtureId => $contract) {
    $fixture = JsonBehaviorBaselineFixture::load($fixtureRoot, $fixtureId);
    $result = $runner->run($fixture);
    $verifier = new JsonBehaviorBaselineResult($fixture->normalizer());
    $payload = $result['public_result']['payload'];
    $final = $payload['final_game'];

    $assert($result['scenario_id'] === $contract['scenario'], $fixtureId . ': scenario identity changed.');
    $assert($result['contract_version'] === JsonBehaviorBaselineResult::CONTRACT_VERSION, $fixtureId . ': result contract changed.');
    $assert($result['fingerprint_sha256'] === $contract['fingerprint'], $fixtureId . ': frozen fingerprint changed.');
    $assert($verifier->verify($result), $fixtureId . ': result fingerprint does not verify.');
    $assert(($result['retry']['attempted'] ?? false) === true, $fixtureId . ': deterministic retry was not attempted.');
    $assert(($result['retry']['result']['stable'] ?? false) === true, $fixtureId . ': deterministic retry changed output.');
    $assert(($result['latency']['measured'] ?? true) === false, $fixtureId . ': latency must remain unmeasured in part 2.4.');
    $assert(($result['conflict']['attempted'] ?? false) === true, $fixtureId . ': rejected action was not captured.');
    $assert(count($result['conflict']['result']['errors'] ?? []) === 1, $fixtureId . ': rejected action count changed.');
    $assert(count($payload['trace'] ?? []) === $contract['trace'], $fixtureId . ': workflow trace length changed.');
    $assert(($payload['trace'][0]['game']['time_left'] ?? null) === 55, $fixtureId . ': deterministic initial timer changed.');
    $assert(($final['game_type'] ?? null) === $contract['type'], $fixtureId . ': final game type changed.');
    $assert(($final['status'] ?? null) === 'finished', $fixtureId . ': game did not finish.');
    $assert(($payload['rematch']['available'] ?? false) === true, $fixtureId . ': human rematch must be available.');
    $assert(($payload['rematch']['source_game_id'] ?? '') === ($final['id'] ?? null), $fixtureId . ': rematch source game changed.');
    $assert(count(array_filter($result['domains']['after']['transactions'] ?? [], static fn(array $tx): bool => ($tx['type'] ?? '') === 'game_finish')) === 1, $fixtureId . ': settlement must emit exactly one game_finish row.');
    $assert(($result['domains']['after']['users']['u1']['status'] ?? null) === 'idle', $fixtureId . ': u1 was not released.');
    $assert(($result['domains']['after']['users']['u2']['status'] ?? null) === 'idle', $fixtureId . ': u2 was not released.');
    $assert(array_key_exists('current_game_id', $result['domains']['after']['users']['u1']) && $result['domains']['after']['users']['u1']['current_game_id'] === null, $fixtureId . ': u1 current game was not cleared.');
    $assert(array_key_exists('current_game_id', $result['domains']['after']['users']['u2']) && $result['domains']['after']['users']['u2']['current_game_id'] === null, $fixtureId . ': u2 current game was not cleared.');
    $results[$fixtureId] = $result;
}

$draw = $results['games_tictactoe_draw'];
$drawFinal = $draw['public_result']['payload']['final_game'];
$assert(($drawFinal['board'] ?? null) === 'XOXXOOOXX', 'Tic-tac-toe draw board changed.');
$assert(array_key_exists('winner_id', $drawFinal) && $drawFinal['winner_id'] === null, 'Tic-tac-toe draw winner changed.');
$assert(($drawFinal['finish_reason'] ?? null) === 'draw', 'Tic-tac-toe draw reason changed.');
$assert(($drawFinal['payout'] ?? null) === 10, 'Tic-tac-toe draw refund changed.');
$assert(($drawFinal['commission'] ?? null) === 0, 'Tic-tac-toe draw commission changed.');
$assert(($draw['domains']['after']['users']['u1']['balance_match'] ?? null) === 100, 'Tic-tac-toe u1 refund changed.');
$assert(($draw['domains']['after']['users']['u2']['balance_match'] ?? null) === 100, 'Tic-tac-toe u2 refund changed.');
$assert(($draw['domains']['after']['users']['u1']['stats']['draws'] ?? null) === 1, 'Tic-tac-toe u1 draw stat changed.');
$assert(($draw['domains']['after']['users']['u2']['stats']['draws'] ?? null) === 1, 'Tic-tac-toe u2 draw stat changed.');
$assert(($draw['side_effects']['ledger'][0]['category'] ?? null) === 'game_refund', 'First Tic-tac-toe refund ledger row changed.');
$assert(($draw['side_effects']['ledger'][1]['category'] ?? null) === 'game_refund', 'Second Tic-tac-toe refund ledger row changed.');
$assert(($draw['side_effects']['ledger'][2]['type'] ?? null) === 'game_finish', 'Tic-tac-toe finish ledger row changed.');

foreach (array_diff(array_keys($expected), ['games_tictactoe_draw']) as $fixtureId) {
    $result = $results[$fixtureId];
    $final = $result['public_result']['payload']['final_game'];
    $after = $result['domains']['after'];
    $assert(($final['winner_id'] ?? null) === 'u1', $fixtureId . ': winner changed.');
    $assert(($final['payout'] ?? null) === 18, $fixtureId . ': winner payout changed.');
    $assert(($final['commission'] ?? null) === 2, $fixtureId . ': commission changed.');
    $assert(($after['users']['u1']['balance_match'] ?? null) === 108, $fixtureId . ': winner balance changed.');
    $assert(($after['users']['u2']['balance_match'] ?? null) === 90, $fixtureId . ': loser balance changed.');
    $assert(($after['system']['fees_match'] ?? null) === 2, $fixtureId . ': Match fee total changed.');
    $assert(($after['users']['u1']['stats']['wins'] ?? null) === 1, $fixtureId . ': winner stat changed.');
    $assert(($after['users']['u2']['stats']['losses'] ?? null) === 1, $fixtureId . ': loser stat changed.');
    $assert(array_column($result['side_effects']['ledger'], 'type') === ['balance_change', 'game_finish'], $fixtureId . ': winner ledger order changed.');
}

$four = $results['games_four_in_a_row_win']['public_result']['payload']['final_game'];
$assert(($four['winning_cells'] ?? null) === [14, 21, 28, 35], 'Four in a Row winning cells changed.');
$assert(($four['last_move'] ?? null) === 14, 'Four in a Row last move changed.');

$battle = $results['games_battleship_final_shot']['public_result']['payload']['final_game'];
$assert(($battle['last_shot'] ?? null) === 42, 'Battleship final shot changed.');
$assert(($battle['last_result'] ?? null) === 'sunk', 'Battleship final result changed.');
$assert(($battle['my_shots']['42'] ?? null) === 'sunk', 'Battleship shot map changed.');

$checkers = $results['games_checkers_capture']['public_result']['payload']['final_game'];
$assert(($checkers['board'][28] ?? null) === 'w', 'Checkers landing square changed.');
$assert(array_key_exists(35, $checkers['board']) && $checkers['board'][35] === '', 'Checkers captured piece was not removed.');
$assert(($checkers['last_captured_cells'] ?? null) === [35], 'Checkers captured-cell trace changed.');

$reversi = $results['games_reversi_count_finish']['public_result']['payload']['final_game'];
$assert(($reversi['black_count'] ?? null) === 36, 'Reversi black count changed.');
$assert(($reversi['white_count'] ?? null) === 0, 'Reversi white count changed.');
$assert(($reversi['last_flipped_cells'] ?? null) === [1], 'Reversi flipped cells changed.');

$chess = $results['games_chess_timeout']['public_result']['payload'];
$assert(($chess['final_game']['finish_reason'] ?? null) === 'timeout', 'Chess timeout reason changed.');
$assert(($chess['final_game']['last_move']['from'] ?? null) === 52, 'Chess move source changed.');
$assert(($chess['final_game']['last_move']['to'] ?? null) === 36, 'Chess move destination changed.');
$assert(($chess['trace'][3]['game']['time_left'] ?? null) === 52, 'Chess timer after legal move changed.');

$go = $results['games_go_two_passes']['public_result']['payload']['final_game'];
$assert(($go['captures']['black'] ?? null) === 1, 'Go capture count changed.');
$assert(($go['pass_sequence'] ?? null) === 2, 'Go two-pass finish changed.');
$assert(($go['final_score'] ?? null) === ['black' => 10.0, 'white' => 8.5], 'Go final score changed.');
$assert(($go['last_captured_cells'] ?? null) === [], 'Go final pass should clear captured cells.');

$domino = $results['games_domino_empty_hand']['public_result']['payload']['final_game'];
$assert(($domino['viewer_hand'] ?? null) === [], 'Domino winner hand must be empty.');
$assert(($domino['end_reason'] ?? null) === 'empty_hand', 'Domino end reason changed.');
$assert(($domino['final_points'] ?? null) === ['u1' => 0, 'u2' => 6], 'Domino final points changed.');
$assert(count($domino['chain'] ?? []) === 2, 'Domino chain length changed.');

$tampered = $results['games_battleship_final_shot'];
$tampered['public_result']['payload']['final_game']['winner_id'] = 'u2';
$fixture = JsonBehaviorBaselineFixture::load($fixtureRoot, 'games_battleship_final_shot');
$assert(!(new JsonBehaviorBaselineResult($fixture->normalizer()))->verify($tampered), 'Winner tampering must invalidate the fingerprint.');

$tampered = $results['games_tictactoe_draw'];
$tampered['domains']['after']['users']['u1']['balance_match'] = 999;
$fixture = JsonBehaviorBaselineFixture::load($fixtureRoot, 'games_tictactoe_draw');
$assert(!(new JsonBehaviorBaselineResult($fixture->normalizer()))->verify($tampered), 'Settlement-state tampering must invalidate the fingerprint.');

fwrite(STDOUT, "Mvp14r2GamesBaselineTest passed: {$assertions} assertions.\n");
