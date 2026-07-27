<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$runtime = file_get_contents($root . '/app/assets/js/production-v97-runtime-owner.js');
$session = file_get_contents($root . '/bot/services/SessionService.php');
$api = file_get_contents($root . '/bot/api.php');
if (!is_string($runtime) || !is_string($session) || !is_string($api)) {
    throw new RuntimeException('Cannot read v97 search/session sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$cancelPosition = strpos($runtime, '++searchEpoch;');
$leavePosition = strpos($runtime, 'rawLeaveSearch().then');
$assert(
    $cancelPosition !== false && $leavePosition !== false && $cancelPosition < $leavePosition,
    'Client search epoch must be invalidated before the asynchronous leave request.'
);
$assert(
    str_contains($runtime, 'if (epoch !== searchEpoch || !searchActive)')
        && str_contains($runtime, 'rawLeaveSearch().catch(() => null);'),
    'A late start_search response must clean the server queue without reopening Search.'
);
$assert(
    str_contains($session, 'if ($status === \'playing\')')
        && str_contains($session, 'У вас уже идёт активная игра на другом устройстве.'),
    'Server session authority must keep its explicit active-game lock.'
);
$assert(
    str_contains($api, '\'active_game\' => $active ?')
        && str_contains($runtime, 'active_game:null')
        && str_contains($runtime, 'game:null'),
    'The v97 client gate must remove bootstrap game data from a locked secondary session.'
);

fwrite(STDOUT, "ProductionV97SearchSessionContractTest: {$assertions} assertions passed\n");
