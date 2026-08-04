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

$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$invites = $read('app/assets/js/games/game-invites-v110.js');
$endpoint = $read('bot/invite-opponents.php');
$wrapperPath = $root . '/app/assets/js/production-v110-opponent-picker-stability.js';

$assert(
    !file_exists($wrapperPath)
        && !str_contains($shell, 'production-v110-opponent-picker-stability.js')
        && !str_contains($shell, 'initV110OpponentPickerStability'),
    'Opponent loading must not install a legacy second runtime owner.'
);
$assert(
    str_contains($invites, 'async function openPlayerPicker(context)')
        && str_contains($invites, 'notifications-loading')
        && str_contains($invites, 'postJson(OPPONENTS_URL, {})')
        && str_contains($invites, 'renderPlayerPicker(items, context);'),
    'The canonical invitation owner must own the honest loading state, request and render transition.'
);
$assert(
    substr_count($invites, 'const OPPONENTS_URL = `${window.location.origin}/bot/invite-opponents.php`;') === 1
        && substr_count($invites, 'async function openPlayerPicker(context)') === 1,
    'The player picker must have one endpoint and one canonical UI owner.'
);
$assert(
    str_contains($endpoint, 'new PresenceService()')
        && str_contains($endpoint, 'onlineAccountIds()')
        && str_contains($endpoint, "StorageFactory::createJson((string)(\$config['data_dir']")
        && str_contains($endpoint, 'str_starts_with($candidateId, \'bot_\')')
        && str_contains($endpoint, 'array_slice($items, 0, 10)'),
    'The endpoint must use canonical JSON profiles plus shared presence, exclude bots and remain bounded.'
);
$assert(
    str_contains($endpoint, '$presenceOnline = isset($onlineOpponentIds[$candidateId]);')
        && str_contains($endpoint, '$hasHistory = isset($lastGameAt[$candidateId]);')
        && str_contains($endpoint, 'time() - $lastSeen > 86400 * 30'),
    'The authoritative list must include online users and bounded recent known humans without requiring a finished match.'
);
$assert(
    str_contains($endpoint, '$unresolvedOnlineCount')
        && str_contains($endpoint, "'authoritative' => \$complete")
        && str_contains($endpoint, "'complete' => \$complete")
        && !str_contains($endpoint, 'DatabasePrimaryStateStorageAdapter'),
    'Final empty must require complete live-presence resolution against the canonical profile catalog.'
);
$assert(
    !str_contains($shell, 'window.fetch =')
        && !str_contains($invites, 'window.fetch =')
        && !str_contains($endpoint, 'sleep(')
        && !str_contains($endpoint, 'usleep('),
    'The endpoint and canonical UI owner must not hide latency with server sleeps or a legacy wrapper.'
);

fwrite(STDOUT, "ProductionV110OpponentPickerStabilityContractTest: {$assertions} assertions passed\n");
