<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/baseline/JsonBehaviorBaselineNormalizer.php';
require_once $root . '/bot/baseline/JsonBehaviorBaselineFixture.php';
require_once $root . '/bot/baseline/JsonBehaviorBaselineResult.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $needle) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($needle))) return;
        throw new RuntimeException('Unexpected exception: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected exception was not thrown.');
};

$fixtureRoot = realpath(__DIR__ . '/fixtures/mvp14r2');
if (!is_string($fixtureRoot)) throw new RuntimeException('MVP-14R.2 fixture root is unavailable.');
$fixture = JsonBehaviorBaselineFixture::load($fixtureRoot, 'foundation');
$builder = new JsonBehaviorBaselineResult($fixture->normalizer());

$record = [
    'scenario_id' => 'foundation_contract',
    'input' => [
        'user_id' => '1001',
        'session_id' => 'session-device-a',
        'bet' => 10,
    ],
    'public_result' => [
        'status' => 200,
        'payload' => [
            'ok' => true,
            'user' => ['id' => '1001', 'balance_match' => 100],
            'session' => ['id' => 'session-device-a'],
            'invite' => ['token' => 'invite-foundation-1'],
        ],
    ],
    'domains' => [
        'before' => ['users' => ['1001' => ['id' => '1001', 'balance_match' => 100]]],
        'after' => ['users' => ['1001' => [
            'id' => '1001',
            'balance_match' => 100,
            'last_seen_at' => '2026-07-29T12:00:00+00:00',
        ]]],
    ],
    'side_effects' => [
        'notifications' => [[
            'recipient_id' => '1001',
            'created_at' => '2026-07-29T12:00:00+00:00',
            'type' => 'fixture_ready',
        ]],
        'events' => [['type' => 'fixture_ready']],
        'ledger' => [],
    ],
    'retry' => ['attempted' => true, 'result' => ['status' => 'idempotent']],
    'conflict' => ['attempted' => false, 'result' => null],
    'latency' => ['measured' => false, 'reason' => 'foundation_only'],
];

$result = $builder->build($record);
$assertSame(JsonBehaviorBaselineResult::CONTRACT_VERSION, $result['contract_version'], 'Result contract version must be frozen');
$assertSame('<USER_A>', $result['input']['user_id'], 'Result must normalize user identity');
$assertSame('<SESSION_A>', $result['input']['session_id'], 'Result must normalize session identity');
$assertSame('<INVITE_1>', $result['public_result']['payload']['invite']['token'], 'Result must normalize invite identity');
$assertSame('<TIMESTAMP_1>', $result['domains']['after']['users']['1001']['last_seen_at'], 'Result must normalize timestamp');
$assertSame(100, $result['public_result']['payload']['user']['balance_match'], 'Result must preserve balance');
$assertSame(10, $result['input']['bet'], 'Result must preserve bet');
$assertTrue($builder->verify($result), 'Fresh result fingerprint must verify');
$assertTrue(
    preg_match('/\A[a-f0-9]{64}\z/', (string)$result['fingerprint_sha256']) === 1,
    'Result fingerprint must be exact SHA-256'
);

$tampered = $result;
$tampered['public_result']['payload']['user']['balance_match'] = 999;
$assertSame(false, $builder->verify($tampered), 'Business-data tampering must invalidate the fingerprint');

$reordered = $record;
$reordered['input'] = ['bet' => 10, 'session_id' => 'session-device-a', 'user_id' => '1001'];
$second = $builder->build($reordered);
$assertSame(
    $result['fingerprint_sha256'],
    $second['fingerprint_sha256'],
    'Object key order must not change the scenario fingerprint'
);
$assertTrue(
    str_contains($builder->canonicalJson($result), '"fingerprint_sha256"'),
    'Canonical result JSON must include the published fingerprint'
);
$assertThrows(
    static fn() => $builder->build($record + ['fingerprint_sha256' => str_repeat('a', 64)]),
    'generated'
);
$invalid = $record;
$invalid['public_result']['status'] = 99;
$assertThrows(static fn() => $builder->build($invalid), 'status');

fwrite(STDOUT, "Mvp14r2JsonBaselineResultTest passed: {$assertions} assertions.\n");
