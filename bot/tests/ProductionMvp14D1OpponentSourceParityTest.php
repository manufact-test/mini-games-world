<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$opponents = $read('bot/invite-opponents.php');
$invites = $read('bot/invites.php');
$service = $read('bot/services/InviteOpponentService.php');
$e2e = $read('e2e/staging/d1-bug-b-player-picker-v122.spec.mjs');

$assert(
    str_contains($opponents, "require_once __DIR__ . '/services/InviteOpponentService.php';")
        && str_contains($opponents, 'new InviteOpponentService()')
        && substr_count($opponents, 'InviteOpponentService') === 2,
    'The endpoint must delegate opponent selection to one canonical service.'
);

$assert(
    str_contains($opponents, 'StorageFactory::createJson(')
        && str_contains($invites, 'StorageFactory::createJson(')
        && !str_contains($opponents, 'DatabasePrimaryStateStorageAdapter')
        && !str_contains($opponents, 'PdoConnectionFactory')
        && !str_contains($opponents, "environment === 'staging'"),
    'Player selection and create_direct must read the same active JSON runtime without a staging-only snapshot.'
);

$assert(
    str_contains($service, 'final class InviteOpponentService')
        && str_contains($service, 'public function list(')
        && str_contains($service, "str_starts_with(\$candidateId, 'bot_')")
        && str_contains($service, 'self::MAX_ITEMS'),
    'One service must own filtering, bot exclusion and the bounded result.'
);

$assert(
    str_contains($e2e, 'page.route(OPPONENTS_ROUTE')
        && str_contains($e2e, 'route.continue()')
        && !str_contains($e2e, 'route.fulfill(')
        && str_contains($e2e, "openPlayer(browser, 'B', false)")
        && str_contains($e2e, "expect(payload?.storage_driver).toBe('json');")
        && str_contains($e2e, 'data-direct-opponent="stg_test_player_b"')
        && str_contains($e2e, 'expect(requests).toBe(1);'),
    'BUG B E2E must delay the live endpoint without fabricating its response and must use a real second player.'
);

fwrite(STDOUT, "ProductionMvp14D1OpponentSourceParityTest: {$assertions} assertions passed\n");
