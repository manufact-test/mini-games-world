<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/baseline/JsonBehaviorBaselineNormalizer.php';

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

$normalizer = new JsonBehaviorBaselineNormalizer(
    [
        '/request/session_id' => 'SESSION',
        '/response/token' => 'INVITE',
        '/events/*/token' => 'INVITE',
        '/events/*/created_at' => 'TIMESTAMP',
    ],
    [
        'SESSION' => ['session-a' => '<SESSION_A>'],
        'TIMESTAMP' => ['2026-07-29T12:00:00+00:00' => '<TIMESTAMP_1>'],
    ]
);

$left = [
    'response' => ['balance' => 90, 'token' => 'invite-random-value'],
    'request' => ['session_id' => 'session-a', 'bet' => 10],
    'events' => [
        ['created_at' => '2026-07-29T12:00:00+00:00', 'token' => 'invite-random-value'],
        ['created_at' => '2026-07-29T12:00:01+00:00', 'token' => 'invite-second-value'],
    ],
];
$right = [
    'events' => [
        ['token' => 'invite-random-value', 'created_at' => '2026-07-29T12:00:00+00:00'],
        ['token' => 'invite-second-value', 'created_at' => '2026-07-29T12:00:01+00:00'],
    ],
    'request' => ['bet' => 10, 'session_id' => 'session-a'],
    'response' => ['token' => 'invite-random-value', 'balance' => 90],
];

$normalizedLeft = $normalizer->normalize($left);
$normalizedRight = $normalizer->normalize($right);
$assertSame($normalizedLeft, $normalizedRight, 'Object key order must not affect normalization');
$assertSame('<SESSION_A>', $normalizedLeft['request']['session_id'], 'Explicit session alias must be applied');
$assertSame('<INVITE_1>', $normalizedLeft['response']['token'], 'First generated invite alias must be stable');
$assertSame('<INVITE_1>', $normalizedLeft['events'][0]['token'], 'Repeated invite value must reuse its alias');
$assertSame('<INVITE_2>', $normalizedLeft['events'][1]['token'], 'Second invite value must receive the next alias');
$assertSame('<TIMESTAMP_1>', $normalizedLeft['events'][0]['created_at'], 'Explicit timestamp alias must be applied');
$assertSame('<TIMESTAMP_2>', $normalizedLeft['events'][1]['created_at'], 'Unknown timestamp must receive deterministic alias');
$assertSame(90, $normalizedLeft['response']['balance'], 'Business balance must remain exact');
$assertSame(10, $normalizedLeft['request']['bet'], 'Business bet must remain exact');
$assertSame(
    $normalizer->fingerprint($normalizedLeft),
    $normalizer->fingerprint($normalizedRight),
    'Equivalent normalized payloads must have the same fingerprint'
);
$assertTrue(
    str_starts_with($normalizer->canonicalJson($normalizedLeft), '{"events"'),
    'Canonical JSON must sort object keys'
);
$assertThrows(
    static fn() => new JsonBehaviorBaselineNormalizer(['/bad/**/path' => 'TOKEN']),
    'recursive wildcard'
);
$assertThrows(
    static fn() => $normalizer->normalize(['response' => ['token' => fopen('php://memory', 'rb')]]),
    'json-compatible'
);

fwrite(STDOUT, "Mvp14r2JsonBaselineNormalizerTest passed: {$assertions} assertions.\n");
