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
$service = $read('bot/services/InviteOpponentService.php');
$wrapperPath = $root . '/app/assets/js/production-v110-opponent-picker-stability.js';

$assert(
    !file_exists($wrapperPath)
        && !str_contains($shell, 'production-v110-opponent-picker-stability.js')
        && !str_contains($shell, 'initV110OpponentPickerStability'),
    'Opponent loading must not install a global fetch wrapper or a second runtime owner.'
);
$assert(
    str_contains($invites, 'async function openPlayerPicker(context, sourceButton = null)')
        && !str_contains($invites, 'Загружаем соперников')
        && str_contains($invites, 'playerPickerRequestGeneration')
        && str_contains($invites, 'postJson(OPPONENTS_URL, {})')
        && strpos($invites, 'postJson(OPPONENTS_URL, {})') < strpos($invites, 'renderPlayerPicker(items, context);'),
    'The canonical invitation owner must keep the setup sheet visible and render the picker only after the authoritative response.'
);
$assert(
    substr_count($invites, 'const OPPONENTS_URL = `${window.location.origin}/bot/invite-opponents.php`;') === 1
        && substr_count($invites, 'async function openPlayerPicker(context, sourceButton = null)') === 1,
    'The player picker must have one endpoint and one canonical UI owner.'
);
$assert(
    str_contains($endpoint, 'new PresenceService()')
        && str_contains($endpoint, '->onlineAccountIds()')
        && str_contains($endpoint, 'new InviteOpponentService()')
        && str_contains($service, "str_starts_with(\$candidateId, 'bot_')")
        && str_contains($service, 'array_slice($result, 0, self::MAX_ITEMS)'),
    'The endpoint and one canonical selector service must use shared presence, exclude bots and return one bounded authoritative list.'
);
$assert(
    str_contains($service, '$presenceOnline = isset($onlineIds[$candidateId]);')
        && str_contains($service, '$hasHistory = isset($lastGameAt[$candidateId]);')
        && str_contains($service, 'time() - $lastSeen > self::RECENT_WINDOW_SEC'),
    'The authoritative list must include online users and bounded recent known human users without requiring a finished match.'
);
$assert(
    str_contains($endpoint, 'StorageFactory::createJson(')
        && !str_contains($endpoint, 'DatabasePrimaryStateStorageAdapter')
        && !str_contains($shell, 'window.fetch =')
        && !str_contains($invites, 'window.fetch =')
        && !str_contains($endpoint, 'sleep(')
        && !str_contains($endpoint, 'usleep('),
    'The fix must share active invitation storage and must not hide latency with interception or retry delays.'
);

fwrite(STDOUT, "ProductionV110OpponentPickerStabilityContractTest: {$assertions} assertions passed\n");
