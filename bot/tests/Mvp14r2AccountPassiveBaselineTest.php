<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/helpers/validators.php';
require_once $root . '/bot/baseline/JsonBehaviorBaselineNormalizer.php';
require_once $root . '/bot/baseline/JsonBehaviorBaselineFixture.php';
require_once $root . '/bot/baseline/JsonBehaviorBaselineResult.php';
require_once $root . '/bot/services/UserService.php';
require_once $root . '/bot/services/SessionService.php';
require_once $root . '/bot/services/NotificationService.php';
require_once $root . '/bot/baseline/JsonAccountPassiveBaselineScenario.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$fixtureRoot = realpath($root . '/bot/tests/fixtures/mvp14r2');
if (!is_string($fixtureRoot)) throw new RuntimeException('MVP-14R.2 fixture root is unavailable.');

$expected = [
    'account_bootstrap_idle' => [
        'fingerprint' => 'dd6b4b69b0549f2035d7445341572d635bfaa0e171e797ccd02fbfe4780bf725',
        'scenario_id' => 'account.bootstrap.idle',
    ],
    'account_profile_finished_games' => [
        'fingerprint' => '78168a0acee8c635bac048905650642efae911ceda613438099e8d65deac24c6',
        'scenario_id' => 'account.profile.finished-games',
    ],
    'passive_session_secondary_lock' => [
        'fingerprint' => '230f4682d8a4e9b7b00bf34d653f4ee52f2353fcba6940d93695dc0a1c23ce4c',
        'scenario_id' => 'passive.session.secondary-lock',
    ],
    'passive_notifications_visibility_order' => [
        'fingerprint' => '5d80dba2af3fd8b7b860d47c4fe65cb75d1334e888278bdab4037db359958bf0',
        'scenario_id' => 'passive.notifications.visibility-order',
    ],
];

$runner = new JsonAccountPassiveBaselineScenario();
$results = [];
foreach ($expected as $fixtureId => $contract) {
    $fixture = JsonBehaviorBaselineFixture::load($fixtureRoot, $fixtureId);
    $result = $runner->run($fixture);
    $verifier = new JsonBehaviorBaselineResult($fixture->normalizer());

    $assert($result['scenario_id'] === $contract['scenario_id'], $fixtureId . ': scenario identity changed.');
    $assert($result['contract_version'] === JsonBehaviorBaselineResult::CONTRACT_VERSION, $fixtureId . ': result contract changed.');
    $assert($result['fingerprint_sha256'] === $contract['fingerprint'], $fixtureId . ': frozen fingerprint changed.');
    $assert($verifier->verify($result), $fixtureId . ': result fingerprint does not verify.');
    $assert($result['domains']['before'] === $result['domains']['after'], $fixtureId . ': passive projection mutated domains.');
    $assert($result['side_effects'] === ['events' => [], 'ledger' => [], 'notifications' => []], $fixtureId . ': passive projection emitted side effects.');
    $assert(($result['retry']['attempted'] ?? false) === true, $fixtureId . ': deterministic retry was not attempted.');
    $assert(($result['retry']['result']['stable'] ?? false) === true, $fixtureId . ': deterministic retry changed output.');
    $assert(($result['latency']['measured'] ?? true) === false, $fixtureId . ': latency must remain unmeasured in part 2.2.');

    $secondFixture = JsonBehaviorBaselineFixture::load($fixtureRoot, $fixtureId);
    $second = $runner->run($secondFixture);
    $assert($second === $result, $fixtureId . ': repeated baseline run is not byte-stable.');
    $results[$fixtureId] = $result;
}

$bootstrap = $results['account_bootstrap_idle']['public_result']['payload'];
$assert(($bootstrap['user']['balance_match'] ?? null) === 70, 'Bootstrap Match balance changed.');
$assert(($bootstrap['user']['gold_shop_available'] ?? null) === 20, 'Bootstrap Gold shop availability changed.');
$assert(($bootstrap['session']['locked'] ?? null) === false, 'Idle bootstrap must remain unlocked.');
$assert(($bootstrap['notifications']['unread_count'] ?? null) === 1, 'Bootstrap unread count changed.');

$profile = $results['account_profile_finished_games']['public_result']['payload'];
$assert(($profile['stats']['games_played'] ?? null) === 3, 'Profile must count only finished games for the user.');
$assert(($profile['stats']['wins'] ?? null) === 1, 'Profile win count changed.');
$assert(($profile['stats']['losses'] ?? null) === 1, 'Profile loss count changed.');
$assert(($profile['stats']['draws'] ?? null) === 1, 'Profile draw count changed.');
$assert(($profile['stats']['match_games'] ?? null) === 2, 'Profile Match game count changed.');
$assert(($profile['stats']['gold_games'] ?? null) === 1, 'Profile Gold game count changed.');
$assert(($profile['stats']['gold_shop_available'] ?? null) === 55, 'Profile turnover-limited Gold availability changed.');

$session = $results['passive_session_secondary_lock']['public_result']['payload']['session'];
$assert(($session['locked'] ?? null) === true, 'Secondary playing session must remain locked.');
$assert(($session['active_session_id'] ?? null) === 'device-owner', 'Session owner identity changed.');
$assert(($session['message'] ?? null) === 'У вас уже идёт активная игра на другом устройстве. Продолжайте игру там.', 'Playing lock message changed.');
$assert(($session['timeout_sec'] ?? null) === 180, 'Session timeout changed.');
$assert(($results['passive_session_secondary_lock']['conflict']['attempted'] ?? false) === true, 'Session conflict baseline must be marked attempted.');
$assert(($results['passive_session_secondary_lock']['conflict']['result']['locked'] ?? false) === true, 'Session conflict baseline must preserve lock.');

$notifications = $results['passive_notifications_visibility_order']['public_result']['payload'];
$ids = array_column($notifications['items'] ?? [], 'id');
$assert($ids === ['notification_003', 'notification_002', 'notification_001'], 'Notification visibility/order contract changed.');
$assert(($notifications['unread_count'] ?? null) === 2, 'Notification unread count changed.');
$assert(($notifications['items'][0]['read'] ?? null) === false, 'Newest visible notification read state changed.');
$assert(($notifications['items'][1]['read'] ?? null) === true, 'Read notification state changed.');
$assert(!in_array('notification_hidden', $ids, true), 'Hidden notification leaked into public items.');
$assert(!in_array('notification_other', $ids, true), 'Another user notification leaked into public items.');

$tampered = $results['account_profile_finished_games'];
$tampered['public_result']['payload']['stats']['wins'] = 2;
$profileFixture = JsonBehaviorBaselineFixture::load($fixtureRoot, 'account_profile_finished_games');
$assert(!(new JsonBehaviorBaselineResult($profileFixture->normalizer()))->verify($tampered), 'Business-result tampering must invalidate fingerprint.');

$tampered = $results['account_bootstrap_idle'];
$tampered['domains']['after']['account']['balance_match'] = 999;
$bootstrapFixture = JsonBehaviorBaselineFixture::load($fixtureRoot, 'account_bootstrap_idle');
$assert(!(new JsonBehaviorBaselineResult($bootstrapFixture->normalizer()))->verify($tampered), 'Domain-state tampering must invalidate fingerprint.');

fwrite(STDOUT, "Mvp14r2AccountPassiveBaselineTest passed: {$assertions} assertions.\n");
