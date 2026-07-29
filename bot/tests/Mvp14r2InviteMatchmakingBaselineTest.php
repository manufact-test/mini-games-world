<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/baseline/JsonBehaviorBaselineNormalizer.php';
require_once $root . '/bot/baseline/JsonBehaviorBaselineFixture.php';
require_once $root . '/bot/baseline/JsonBehaviorBaselineResult.php';
require_once $root . '/bot/baseline/JsonInviteMatchmakingBaselineScenario.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$fixtureRoot = realpath($root . '/bot/tests/fixtures/mvp14r2');
if (!is_string($fixtureRoot)) throw new RuntimeException('MVP-14R.2 fixture root is unavailable.');

$expected = [
    'invite_direct_accept_start' => ['scenario' => 'invites.direct.accept-start', 'fingerprint' => '97faa670d8795375c982789daf5a96624cd63b7d1297bffa943ff6a8acdbb846'],
    'invite_link_open_cancel' => ['scenario' => 'invites.link.open-cancel', 'fingerprint' => 'fd27f2c4e9d4699bee36a2a320d1214fdcbaf2ff243fad4533213158e64cf30d'],
    'invite_rematch_reuse_start' => ['scenario' => 'invites.rematch.reuse-start', 'fingerprint' => 'b4bbf4bdd01af4c5718b42adb7df387a792c962ce45e867d39cf1aacf2ef4f40'],
    'matchmaking_queue_cancel' => ['scenario' => 'matchmaking.queue.cancel', 'fingerprint' => 'b7e2522ec978d2eacdbc872ecad789799d2dff6fdd33d9f76078337753d559ef'],
    'matchmaking_human_match' => ['scenario' => 'matchmaking.human.exact-match', 'fingerprint' => '3e4cbec62c39d2d3fc471079d8315a385484b2e74d80c8b337a03ed8ec4b1389'],
    'matchmaking_bot_fallback' => ['scenario' => 'matchmaking.bot.fallback', 'fingerprint' => '2c932fcca69f242babb5dd27adcdce2f82027cf67131b3503947e07b8a7d63f2'],
];

$runner = new JsonInviteMatchmakingBaselineScenario();
$results = [];
foreach ($expected as $fixtureId => $contract) {
    $fixture = JsonBehaviorBaselineFixture::load($fixtureRoot, $fixtureId);
    $result = $runner->run($fixture);
    $verifier = new JsonBehaviorBaselineResult($fixture->normalizer());
    $assert($result['scenario_id'] === $contract['scenario'], $fixtureId . ': scenario identity changed.');
    $assert($result['fingerprint_sha256'] === $contract['fingerprint'], $fixtureId . ': frozen fingerprint changed.');
    $assert($verifier->verify($result), $fixtureId . ': result fingerprint does not verify.');
    $assert(($result['retry']['attempted'] ?? false) === true, $fixtureId . ': deterministic retry was not attempted.');
    $assert(($result['retry']['result']['stable'] ?? false) === true, $fixtureId . ': deterministic retry is unstable.');
    $assert(($result['latency']['measured'] ?? true) === false, $fixtureId . ': latency must remain unmeasured in part 2.3.');
    $second = $runner->run(JsonBehaviorBaselineFixture::load($fixtureRoot, $fixtureId));
    $assert($second === $result, $fixtureId . ': repeated run is not byte-stable.');
    $results[$fixtureId] = $result;
}

$direct = $results['invite_direct_accept_start'];
$trace = $direct['public_result']['payload']['trace'];
$assert(($trace[0]['result']['invite']['status'] ?? null) === 'pending', 'Direct invite must start pending.');
$assert(($trace[1]['result']['invite']['status'] ?? null) === 'accepted', 'Accepted invite public status changed.');
$assert(($trace[1]['result']['invite']['waiting_seconds'] ?? null) === 90, 'Accepted invite ready window changed.');
$assert(($trace[2]['result']['invite']['status'] ?? null) === 'active', 'Started invite must become active.');
$assert(($trace[2]['result']['game']['match_source'] ?? null) === 'invite', 'Private invite game source changed.');
$assert(($trace[2]['result']['game']['player_ids'] ?? null) === ['u1', 'u2'], 'Private invite player order changed.');
$after = $direct['domains']['after'];
$assert(($after['users']['u1']['balance_match'] ?? null) === 90, 'Inviter Match debit changed.');
$assert(($after['users']['u2']['balance_match'] ?? null) === 90, 'Invitee Match debit changed.');
$assert(($after['users']['u1']['status'] ?? null) === 'playing', 'Inviter status must become playing.');
$assert(($after['users']['u2']['status'] ?? null) === 'playing', 'Invitee status must become playing.');
$assert(count($direct['side_effects']['notifications']) === 2, 'Direct lifecycle notification count changed.');
$assert(array_column($direct['side_effects']['notifications'], 'type') === ['invite_received', 'invite_accepted'], 'Direct lifecycle notification types changed.');
$assert(count($direct['side_effects']['ledger']) === 3, 'Direct start ledger entry count changed.');
$assert(array_sum(array_column(array_filter($direct['side_effects']['ledger'], static fn(array $row): bool => ($row['type'] ?? '') === 'balance_change'), 'amount')) === -20, 'Direct start aggregate debit changed.');
$assert(!empty($after['notifications'][0]['read_at']) && !empty($after['notifications'][1]['read_at']), 'Starting invite must mark both invite notifications read.');

$link = $results['invite_link_open_cancel'];
$linkTrace = $link['public_result']['payload']['trace'];
$assert(array_map(static fn(array $step): mixed => $step['result']['invite']['status'] ?? null, $linkTrace) === ['draft', 'pending', 'pending', 'cancelled'], 'Link invite lifecycle statuses changed.');
$linkAfter = $link['domains']['after'];
$assert(($linkAfter['users']['u1']['balance_match'] ?? null) === 100, 'Link lifecycle must not debit inviter.');
$assert(($linkAfter['users']['u2']['balance_match'] ?? null) === 100, 'Link lifecycle must not debit invitee.');
$assert(($linkAfter['invites'][0]['cancelled_by'] ?? null) === 'u1', 'Link cancel actor changed.');
$assert(($linkAfter['notifications'][1]['hidden_at'] ?? null) === '2026-07-29T12:00:02+00:00', 'Opened link notification must be hidden immediately.');
$assert(($linkAfter['notifications'][1]['read_at'] ?? null) === '2026-07-29T12:00:02+00:00', 'Opened link notification must be marked read.');
$assert(($linkAfter['notifications'][0]['type'] ?? null) === 'invite_cancelled', 'Link cancellation notification changed.');
$assert($link['side_effects']['ledger'] === [], 'Link lifecycle must not emit ledger entries.');

$rematch = $results['invite_rematch_reuse_start'];
$rematchTrace = $rematch['public_result']['payload']['trace'];
$assert(($rematchTrace[0]['result']['reused'] ?? null) === false, 'First rematch request must create an invite.');
$assert(($rematchTrace[1]['result']['reused'] ?? null) === true, 'Second rematch request must reuse open invite.');
$assert(($rematchTrace[1]['result']['invite']['status'] ?? null) === 'active', 'Reused rematch must auto-start.');
$assert(($rematchTrace[1]['result']['game']['match_source'] ?? null) === 'rematch', 'Rematch game source changed.');
$assert(($rematchTrace[1]['result']['game']['source_game_id'] ?? null) === 'game_finished_001', 'Rematch source game linkage changed.');
$assert(($rematch['domains']['after']['games']['game_finished_001']['status'] ?? null) === 'finished', 'Source game must remain finished.');
$assert(($rematch['domains']['after']['users']['u1']['balance_match'] ?? null) === 90, 'Rematch inviter debit changed.');
$assert(($rematch['domains']['after']['users']['u2']['balance_match'] ?? null) === 90, 'Rematch opponent debit changed.');
$assert(count($rematch['side_effects']['ledger']) === 3, 'Rematch ledger entry count changed.');

$queue = $results['matchmaking_queue_cancel'];
$queueTrace = $queue['public_result']['payload']['trace'];
$assert(($queueTrace[0]['result']['queued'] ?? null) === true, 'Initial search must queue user.');
$assert(($queueTrace[1]['result']['cancelled'] ?? null) === true, 'Search cancellation result changed.');
$assert(($queueTrace[1]['result']['removed_queue_ids'] ?? null) === ['queue_search_001'], 'Cancelled queue identity changed.');
$assert($queue['domains']['after']['queue'] === [], 'Cancelled search must leave queue empty.');
$assert(($queue['domains']['after']['users']['u1']['status'] ?? null) === 'idle', 'Cancelled search must restore idle status.');
$assert(($queue['domains']['after']['users']['u1']['balance_match'] ?? null) === 100, 'Queue/cancel must not debit Match balance.');
$assert($queue['side_effects']['ledger'] === [], 'Queue/cancel must not emit ledger entries.');

$human = $results['matchmaking_human_match'];
$humanLast = $human['public_result']['payload']['last'];
$assert(($humanLast['game']['is_bot_game'] ?? null) === false, 'Exact human match must not become a bot game.');
$assert(($humanLast['game']['player_ids'] ?? null) === ['u2', 'u1'], 'Human matcher player order changed.');
$assert($human['domains']['after']['queue'] === [], 'Human match must consume the queue entry.');
$assert(($human['domains']['after']['users']['u1']['balance_match'] ?? null) === 90, 'Queued human debit changed.');
$assert(($human['domains']['after']['users']['u2']['balance_match'] ?? null) === 90, 'Joining human debit changed.');
$assert(($human['domains']['after']['users']['u1']['current_game_id'] ?? null) === 'game_human_001', 'Queued human game ownership changed.');
$assert(($human['domains']['after']['users']['u2']['current_game_id'] ?? null) === 'game_human_001', 'Joining human game ownership changed.');
$assert(count($human['side_effects']['ledger']) === 3, 'Human match ledger entry count changed.');

$bot = $results['matchmaking_bot_fallback'];
$botLast = $bot['public_result']['payload']['last'];
$assert(($botLast['human_preferred'] ?? null) === false, 'Bot fallback human-preference marker changed.');
$assert(($botLast['game']['is_bot_game'] ?? null) === true, 'Bot fallback must create a bot game.');
$assert(($botLast['game']['bot_id'] ?? null) === 'bot_leo_001', 'Bot identity changed.');
$assert(($botLast['game']['bot_name'] ?? null) === 'Leo', 'Bot display name changed.');
$assert(($botLast['game']['bot_difficulty'] ?? null) === 'medium', 'Bot difficulty fixture changed.');
$assert($bot['domains']['after']['queue'] === [], 'Bot fallback must consume queue entry.');
$assert(($bot['domains']['after']['users']['u1']['balance_match'] ?? null) === 90, 'Bot fallback Match debit changed.');
$assert(count($bot['side_effects']['ledger']) === 2, 'Bot fallback ledger entry count changed.');
$assert(($bot['side_effects']['ledger'][0]['amount'] ?? null) === -10, 'Bot fallback entry debit changed.');

$tampered = $direct;
$tampered['domains']['after']['users']['u1']['balance_match'] = 999;
$fixture = JsonBehaviorBaselineFixture::load($fixtureRoot, 'invite_direct_accept_start');
$assert(!(new JsonBehaviorBaselineResult($fixture->normalizer()))->verify($tampered), 'Invite balance tampering must invalidate fingerprint.');
$tampered = $bot;
$tampered['public_result']['payload']['last']['game']['bot_difficulty'] = 'hard';
$fixture = JsonBehaviorBaselineFixture::load($fixtureRoot, 'matchmaking_bot_fallback');
$assert(!(new JsonBehaviorBaselineResult($fixture->normalizer()))->verify($tampered), 'Bot business behavior tampering must invalidate fingerprint.');

fwrite(STDOUT, "Mvp14r2InviteMatchmakingBaselineTest passed: {$assertions} assertions.\n");
