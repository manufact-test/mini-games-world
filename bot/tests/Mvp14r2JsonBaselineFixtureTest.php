<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/baseline/JsonBehaviorBaselineNormalizer.php';
require_once $root . '/bot/baseline/JsonBehaviorBaselineFixture.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
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

$assertSame('foundation', $fixture->fixtureId(), 'Fixture identity must load exactly');
$assertSame('2026-07-29T12:00:00+00:00', $fixture->now()->format(DATE_ATOM), 'Fixture clock must be fixed in UTC');
$assertSame(140201, $fixture->randomSeed(), 'Fixture random seed must be exact');
$assertSame(100, $fixture->state()['users']['1001']['balance_match'], 'Fixture state must preserve business balances');
$assertSame('foundation_contract', $fixture->scenario()['id'], 'Fixture scenario identity must be exact');
$assertSame('request-foundation-1', $fixture->nextId('request'), 'First request ID must be deterministic');
$assertSame('request-foundation-2', $fixture->nextId('request'), 'Second request ID must be deterministic');
$assertThrows(static fn() => $fixture->nextId('request'), 'exhausted');
$fixture->resetIdSequences();
$assertSame('request-foundation-1', $fixture->nextId('request'), 'Reset must restore deterministic ID order');
$assertThrows(static fn() => $fixture->nextId('unknown'), 'unavailable');
$assertThrows(
    static fn() => JsonBehaviorBaselineFixture::load($fixtureRoot, '../foundation'),
    'fixture id'
);

$normalized = $fixture->normalizer()->normalize([
    'input' => ['user_id' => '1001', 'session_id' => 'session-device-a'],
]);
$assertSame('<USER_A>', $normalized['input']['user_id'], 'Fixture normalizer must alias the first user');
$assertSame('<SESSION_A>', $normalized['input']['session_id'], 'Fixture normalizer must alias the first session');

fwrite(STDOUT, "Mvp14r2JsonBaselineFixtureTest passed: {$assertions} assertions.\n");
