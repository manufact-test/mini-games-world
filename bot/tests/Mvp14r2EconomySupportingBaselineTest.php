<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/baseline/JsonBehaviorBaselineNormalizer.php';
require_once $root . '/bot/baseline/JsonBehaviorBaselineFixture.php';
require_once $root . '/bot/baseline/JsonBehaviorBaselineResult.php';
require_once $root . '/bot/baseline/JsonEconomySupportingBaselineScenario.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$fixtureRoot = realpath($root . '/bot/tests/fixtures/mvp14r2');
if (!is_string($fixtureRoot)) throw new RuntimeException('MVP-14R.2 fixture root is unavailable.');

$contracts = [
    'economy_match_win_history' => ['scenario' => 'economy.match.win-history', 'fingerprint' => 'f75402ea8cb5ab1501e65c4a4d9181f0f85168b4259abd7c8627623855e1c96e', 'trace' => 5],
    'economy_gold_draw_history' => ['scenario' => 'economy.gold.draw-history', 'fingerprint' => '4542c60d566d06bb780741b4c0cbfff22f0a8e3b6fab616d635ebc66f831f0d8', 'trace' => 4],
    'economy_insufficient_balances' => ['scenario' => 'economy.insufficient-balances', 'fingerprint' => '87235b96fc8a146dc0c686866f8d5f9caacc456d6cf8cf8193e1783a3ebcee94', 'trace' => 3],
    'shop_order_complete' => ['scenario' => 'shop.order.complete', 'fingerprint' => '69f7114f1a2fa5c268ab745749bdaae1b901db7e52213794e68473c7da25942a', 'trace' => 6],
    'shop_order_reject_refund' => ['scenario' => 'shop.order.reject-refund', 'fingerprint' => 'ea4caef5cf37c9412b58f3179681f7c5ab7e58c139edeaccb6f8af2da4bdad8f', 'trace' => 5],
    'payment_apply_once' => ['scenario' => 'payments.apply-once', 'fingerprint' => '3746b195aa0d61fc4ebc105b9218f6c774a96386607ee51becdb1dc59470bad3', 'trace' => 4],
    'payment_reject_cancel' => ['scenario' => 'payments.reject-cancel', 'fingerprint' => '6d6b8c0787845c58405ec76ea93ced61ca96b48aa26309fdaed9a708f9853511', 'trace' => 8],
    'weekly_bonus_eligibility_timezone' => ['scenario' => 'weekly.eligibility-timezone', 'fingerprint' => '5bfcb03afd1c4b93379883bf7cf225dc527a3a51f8d0855d85929c8915845177', 'trace' => 5],
];

$runner = new JsonEconomySupportingBaselineScenario();
$results = [];
foreach ($contracts as $fixtureId => $contract) {
    $fixture = JsonBehaviorBaselineFixture::load($fixtureRoot, $fixtureId);
    $result = $runner->run($fixture);
    $verifier = new JsonBehaviorBaselineResult($fixture->normalizer());
    $assert($result['scenario_id'] === $contract['scenario'], $fixtureId . ': scenario identity changed.');
    $assert($result['contract_version'] === JsonBehaviorBaselineResult::CONTRACT_VERSION, $fixtureId . ': result contract changed.');
    $assert($result['fingerprint_sha256'] === $contract['fingerprint'], $fixtureId . ': frozen fingerprint changed.');
    $assert($verifier->verify($result), $fixtureId . ': fingerprint verification failed.');
    $assert(($result['retry']['attempted'] ?? false) === true, $fixtureId . ': deterministic retry was not attempted.');
    $assert(($result['retry']['result']['stable'] ?? false) === true, $fixtureId . ': deterministic retry changed output.');
    $assert(($result['latency']['measured'] ?? true) === false, $fixtureId . ': latency must remain unmeasured in part 2.5.');
    $assert(($result['latency']['samples'] ?? -1) === 0, $fixtureId . ': latency sample count changed.');
    $assert(count($result['public_result']['payload']['trace'] ?? []) === $contract['trace'], $fixtureId . ': workflow trace length changed.');
    $results[$fixtureId] = $result;
}

$match = $results['economy_match_win_history'];
$matchAfter = $match['domains']['after'];
$matchHistory = $match['public_result']['payload']['history'];
$assert(($matchAfter['users']['u1']['balance_match'] ?? null) === 108, 'Match winner balance changed.');
$assert(($matchAfter['users']['u2']['balance_match'] ?? null) === 90, 'Match loser balance changed.');
$assert(($matchAfter['system']['fees_match'] ?? null) === 2, 'Match fee changed.');
$assert(($matchAfter['games']['econ_match_001']['payout'] ?? null) === 18, 'Match payout changed.');
$assert(($matchAfter['games']['econ_match_001']['commission'] ?? null) === 2, 'Match commission changed.');
$assert(($matchAfter['games']['econ_match_001']['payout_done'] ?? false) === true, 'Match payout marker changed.');
$assert(($matchAfter['users']['u1']['stats']['wins'] ?? null) === 1, 'Match winner stat changed.');
$assert(($matchAfter['users']['u2']['stats']['losses'] ?? null) === 1, 'Match loser stat changed.');
$assert(($matchAfter['users']['u1']['status'] ?? null) === 'idle', 'Match winner was not released.');
$assert(array_key_exists('current_game_id', $matchAfter['users']['u2']) && $matchAfter['users']['u2']['current_game_id'] === null, 'Match loser current game was not cleared.');
$assert(array_column($matchAfter['transactions'], 'type') === ['game_start','balance_change','game_finish'], 'Match ledger order changed.');
$assert(count(array_filter($matchAfter['transactions'], static fn(array $tx): bool => ($tx['type'] ?? '') === 'game_finish')) === 1, 'Match settlement duplicated game_finish.');
$assert(array_column($matchHistory['u1']['operations'], 'amount') === [18,-10], 'Match winner history amounts changed.');
$assert(array_column($matchHistory['u1']['operations'], 'title') === ['Выигрыш','Ставка на игру'], 'Match winner history titles changed.');
$assert(array_column($matchHistory['u2']['operations'], 'amount') === [-10], 'Match loser history changed.');
$assert(($matchHistory['u1']['matches'][0]['result'] ?? null) === 'Победа', 'Match winner result changed.');
$assert(($matchHistory['u2']['matches'][0]['result'] ?? null) === 'Поражение', 'Match loser result changed.');

$gold = $results['economy_gold_draw_history'];
$goldAfter = $gold['domains']['after'];
$goldHistory = $gold['public_result']['payload']['history'];
$assert(($goldAfter['users']['u1']['balance_gold'] ?? null) === 50, 'Gold draw u1 refund changed.');
$assert(($goldAfter['users']['u2']['balance_gold'] ?? null) === 50, 'Gold draw u2 refund changed.');
$assert(($goldAfter['users']['u1']['gold_wagered_total'] ?? null) === 20, 'Gold wager total u1 changed.');
$assert(($goldAfter['users']['u2']['gold_wagered_total'] ?? null) === 20, 'Gold wager total u2 changed.');
$assert(array_key_exists('winner_id', $goldAfter['games']['econ_gold_001']) && $goldAfter['games']['econ_gold_001']['winner_id'] === null, 'Gold draw winner changed.');
$assert(($goldAfter['games']['econ_gold_001']['payout'] ?? null) === 20, 'Gold draw payout changed.');
$assert(($goldAfter['games']['econ_gold_001']['commission'] ?? null) === 0, 'Gold draw commission changed.');
$assert(array_column($goldAfter['transactions'], 'type') === ['game_start','balance_change','balance_change','game_finish'], 'Gold draw ledger order changed.');
$assert(count(array_filter($goldAfter['transactions'], static fn(array $tx): bool => ($tx['category'] ?? '') === 'game_refund')) === 2, 'Gold draw refunds changed.');
$assert(array_column($goldHistory['u1']['operations'], 'amount') === [20,-20], 'Gold draw history amounts changed.');
$assert(($goldHistory['u1']['matches'][0]['result'] ?? null) === 'Ничья', 'Gold draw history result changed.');

$insufficient = $results['economy_insufficient_balances'];
$insufficientAfter = $insufficient['domains']['after'];
$assert(($insufficient['conflict']['attempted'] ?? false) === true, 'Insufficient-balance conflicts missing.');
$assert(count($insufficient['conflict']['result']['errors'] ?? []) === 2, 'Insufficient-balance conflict count changed.');
$assert(($insufficientAfter['users']['u1']['balance_match'] ?? null) === 9, 'Insufficient Match balance mutated.');
$assert(($insufficientAfter['users']['u1']['balance_gold'] ?? null) === 19, 'Insufficient Gold balance mutated.');
$assert($insufficientAfter['games'] === [], 'Insufficient balance created a game.');
$assert($insufficientAfter['transactions'] === [], 'Insufficient balance created a transaction.');

$shopDone = $results['shop_order_complete'];
$shopDonePayload = $shopDone['public_result']['payload'];
$shopDoneAfter = $shopDone['domains']['after'];
$catalog = $shopDonePayload['trace'][0]['result'];
$assert(($catalog['version'] ?? null) === 3, 'Catalog version changed.');
$assert(($catalog['currency'] ?? null) === 'GOLD', 'Catalog currency changed.');
$assert(count($catalog['countries'] ?? []) === 1, 'Disabled catalog country leaked.');
$assert(count($catalog['items'] ?? []) === 1, 'Disabled catalog item leaked.');
$assert(count($catalog['items'][0]['denominations'] ?? []) === 1, 'Disabled denomination leaked.');
$assert(($shopDonePayload['trace'][1]['result']['request_replayed'] ?? true) === false, 'New shop order replay marker changed.');
$assert(($shopDonePayload['trace'][2]['result']['request_replayed'] ?? false) === true, 'Duplicate shop request was not replayed.');
$assert(($shopDoneAfter['users']['u1']['balance_gold'] ?? null) === 500, 'Completed order balance changed.');
$assert(($shopDoneAfter['users']['u1']['gold_shop_spent_total'] ?? null) === 1000, 'Completed order spent total changed.');
$assert(count($shopDoneAfter['shop_orders']) === 1, 'Duplicate request created another shop order.');
$assert(($shopDoneAfter['shop_orders'][0]['status'] ?? null) === 'done', 'Completed order status changed.');
$assert(($shopDoneAfter['shop_orders'][0]['refund_done'] ?? true) === false, 'Completed order refund marker changed.');
$assert(($shopDoneAfter['shop_orders'][0]['user_id'] ?? null) === 'u1', 'Shop order ownership changed.');
$assert(($shopDoneAfter['shop_orders'][0]['prize_snapshot']['title'] ?? null) === 'Подарочная карта', 'Prize snapshot changed.');
$assert(array_column($shopDoneAfter['transactions'], 'category') === ['shop_order','shop_order_done'], 'Completed shop ledger changed.');
$assert(array_column($shopDonePayload['history']['u1']['operations'], 'amount') === [-1000], 'Completed shop history changed.');

$shopReject = $results['shop_order_reject_refund'];
$shopRejectAfter = $shopReject['domains']['after'];
$shopRejectHistory = $shopReject['public_result']['payload']['history']['u1']['operations'];
$assert(($shopRejectAfter['users']['u1']['balance_gold'] ?? null) === 900, 'Rejected order did not restore balance.');
$assert(($shopRejectAfter['users']['u1']['gold_shop_spent_total'] ?? null) === 0, 'Rejected order did not restore turnover availability.');
$assert(($shopRejectAfter['shop_orders'][0]['status'] ?? null) === 'rejected', 'Rejected order status changed.');
$assert(($shopRejectAfter['shop_orders'][0]['refund_done'] ?? false) === true, 'Rejected order refund marker changed.');
$assert(($shopRejectAfter['shop_orders'][0]['refund_amount'] ?? null) === 500, 'Rejected order refund amount changed.');
$assert(array_column($shopRejectAfter['transactions'], 'category') === ['shop_order','shop_refund','shop_order_reject'], 'Rejected shop ledger changed.');
$assert(array_column($shopRejectHistory, 'amount') === [500,-500], 'Rejected shop history changed.');
$assert(count($shopReject['conflict']['result']['errors'] ?? []) === 1, 'Rejected shop terminal conflict changed.');

$paymentApply = $results['payment_apply_once'];
$paymentApplyAfter = $paymentApply['domains']['after'];
$assert(($paymentApplyAfter['users']['u1']['balance_match'] ?? null) === 1005, 'Payment Match balance changed.');
$assert(($paymentApplyAfter['users']['u1']['match_deposited_total'] ?? null) === 1000, 'Payment deposited total changed.');
$assert(count($paymentApplyAfter['payments']) === 1, 'Payment apply created duplicate payment.');
$assert(($paymentApplyAfter['payments'][0]['status'] ?? null) === 'paid', 'Applied payment status changed.');
$assert(($paymentApplyAfter['payments'][0]['balance_applied'] ?? false) === true, 'Applied payment marker changed.');
$assert(($paymentApplyAfter['payments'][0]['coins'] ?? null) === 1000, 'Payment conversion rate changed.');
$assert(array_column($paymentApplyAfter['transactions'], 'category') === ['payment_draft','payment_apply'], 'Payment apply ledger changed.');
$assert(count(array_filter($paymentApplyAfter['transactions'], static fn(array $tx): bool => ($tx['category'] ?? '') === 'payment_apply')) === 1, 'Payment applied more than once.');
$assert(($paymentApply['public_result']['payload']['payments']['summary']['paid'] ?? null) === 1, 'Payment paid summary changed.');
$assert(count($paymentApply['conflict']['result']['errors'] ?? []) === 1, 'Paid-payment rejection conflict changed.');

$paymentTerminal = $results['payment_reject_cancel'];
$paymentTerminalAfter = $paymentTerminal['domains']['after'];
$paymentSummary = $paymentTerminal['public_result']['payload']['payments']['summary'];
$assert(($paymentTerminalAfter['users']['u1']['balance_gold'] ?? null) === 50, 'Rejected/cancelled payments changed balance.');
$assert(count($paymentTerminalAfter['payments']) === 2, 'Rejected/cancelled payment count changed.');
$assert(($paymentSummary['rejected'] ?? null) === 1, 'Rejected payment summary changed.');
$assert(($paymentSummary['cancelled'] ?? null) === 1, 'Cancelled payment summary changed.');
$assert(($paymentTerminalAfter['payments'][0]['status'] ?? null) === 'rejected', 'First payment terminal status changed.');
$assert(($paymentTerminalAfter['payments'][1]['status'] ?? null) === 'cancelled', 'Second payment terminal status changed.');
$assert(array_column($paymentTerminalAfter['transactions'], 'category') === ['payment_draft','payment_reject','payment_draft','payment_cancel'], 'Terminal payment ledger changed.');
$assert(count($paymentTerminal['conflict']['result']['errors'] ?? []) === 2, 'Terminal payment apply conflicts changed.');

$weekly = $results['weekly_bonus_eligibility_timezone'];
$weeklyAfter = $weekly['domains']['after'];
$weeklyTrace = $weekly['public_result']['payload']['trace'];
$assert(($weeklyTrace[0]['result']['timezone'] ?? null) === 'Europe/Warsaw', 'Weekly status timezone changed.');
$assert(($weeklyTrace[0]['result']['next_bonus_at'] ?? null) === '2026-07-20T12:00:00+02:00', 'Weekly pre-noon boundary changed.');
$assert(($weeklyTrace[0]['result']['completed_games'] ?? null) === 2, 'Weekly status must exclude a game finishing exactly at read time.');
$assert(($weeklyTrace[1]['result']['cycle_key'] ?? null) === '2026-07-20', 'Weekly cycle key changed.');
$assert(($weeklyTrace[1]['result']['checked'] ?? null) === 2, 'Weekly checked count changed.');
$assert(($weeklyTrace[1]['result']['awarded'] ?? null) === 1, 'Weekly awarded count changed.');
$assert(($weeklyTrace[1]['result']['ineligible'] ?? null) === 1, 'Weekly ineligible count changed.');
$assert(($weeklyTrace[1]['result']['skipped_dev'] ?? null) === 1, 'Weekly dev-user skip changed.');
$assert(($weeklyTrace[2]['result']['already_checked'] ?? null) === 2, 'Repeated weekly run idempotency changed.');
$assert(($weeklyTrace[2]['result']['awarded'] ?? null) === 0, 'Repeated weekly run reported another award.');
$assert(($weeklyAfter['users']['u1']['balance_match'] ?? null) === 70, 'Eligible weekly balance changed.');
$assert(($weeklyAfter['users']['u2']['balance_match'] ?? null) === 30, 'Ineligible weekly balance changed.');
$assert(($weeklyAfter['users']['u3']['balance_match'] ?? null) === 40, 'Dev weekly balance changed.');
$assert(($weeklyAfter['users']['u1']['weekly_match_bonus_checked_games'] ?? null) === 3, 'Eligible weekly qualifying count changed.');
$assert(($weeklyAfter['users']['u2']['weekly_match_bonus_checked_games'] ?? null) === 2, 'Gold game incorrectly qualified weekly bonus.');
$assert(($weeklyAfter['users']['u1']['weekly_match_bonus_last_amount'] ?? null) === 50, 'Weekly +50 amount changed.');
$assert(count(array_filter($weeklyAfter['transactions'], static fn(array $tx): bool => ($tx['category'] ?? '') === 'weekly_bonus')) === 1, 'Weekly grant duplicated.');
$assert(count($weeklyAfter['notifications']) === 1, 'Weekly notification duplicated.');
$assert(($weeklyAfter['notifications'][0]['qualifying_games'] ?? null) === 3, 'Weekly notification qualification changed.');
$assert(($weeklyTrace[3]['result']['next_bonus_at'] ?? null) === '2026-07-27T12:00:00+02:00', 'Weekly next-cycle boundary changed.');
$assert(($weeklyTrace[3]['result']['completed_games'] ?? null) === 1, 'Game at exact cycle boundary must count toward next week.');
$assert(($weeklyTrace[4]['result']['completed_games'] ?? null) === 0, 'Ineligible next-week status changed.');

$tampered = $results['economy_match_win_history'];
$tampered['domains']['after']['users']['u1']['balance_match'] = 999;
$fixture = JsonBehaviorBaselineFixture::load($fixtureRoot, 'economy_match_win_history');
$assert(!(new JsonBehaviorBaselineResult($fixture->normalizer()))->verify($tampered), 'Economy balance tampering must invalidate fingerprint.');

$tampered = $results['shop_order_complete'];
$tampered['domains']['after']['shop_orders'][0]['user_id'] = 'u2';
$fixture = JsonBehaviorBaselineFixture::load($fixtureRoot, 'shop_order_complete');
$assert(!(new JsonBehaviorBaselineResult($fixture->normalizer()))->verify($tampered), 'Shop ownership tampering must invalidate fingerprint.');

$tampered = $results['weekly_bonus_eligibility_timezone'];
$tampered['domains']['after']['transactions'][] = $tampered['domains']['after']['transactions'][0];
$fixture = JsonBehaviorBaselineFixture::load($fixtureRoot, 'weekly_bonus_eligibility_timezone');
$assert(!(new JsonBehaviorBaselineResult($fixture->normalizer()))->verify($tampered), 'Duplicate weekly grant tampering must invalidate fingerprint.');

fwrite(STDOUT, "Mvp14r2EconomySupportingBaselineTest passed: {$assertions} assertions.\n");
