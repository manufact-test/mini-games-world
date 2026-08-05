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

$endpoint = $read('bot/invite-opponents.php');
$service = $read('bot/services/InviteOpponentService.php');
$client = $read('app/assets/js/games/game-invites-v110.js');
$presence = $read('bot/services/PresenceService.php');

$assert(str_contains($endpoint, 'new PresenceService()')
    && str_contains($endpoint, '->onlineAccountIds()')
    && str_contains($presence, 'public function onlineAccountIds(): array'),
    'The player picker must use the same shared presence authority as the online counter.');
$assert(str_contains($service, "foreach (\$data['users'] ?? [] as \$candidateId => \$candidate)")
    && str_contains($service, '$hasHistory = isset($lastGameAt[$candidateId]);')
    && str_contains($service, 'RECENT_WINDOW_SEC = 86400 * 30'),
    'The list must include online and recently known human players instead of only finished-match opponents.');
$assert(str_contains($service, '$candidateId === $userId')
    && str_contains($service, "str_starts_with(\$candidateId, 'bot_')")
    && str_contains($service, 'array_slice($result, 0, self::MAX_ITEMS)'),
    'The player source must exclude self and bots and remain bounded.');
$assert(str_contains($service, "'online' => (bool)\$activity['online']")
    && str_contains($service, "'busy' => (bool)\$activity['busy']")
    && str_contains($service, "unset(\$item['_score'])"),
    'The response must expose only stable player status fields without internal ranking data.');
$assert(str_contains($endpoint, 'StorageFactory::createJson(')
    && str_contains($endpoint, 'new InviteOpponentService()')
    && !str_contains($endpoint, 'DatabasePrimaryStateStorageAdapter'),
    'The endpoint must read the same active runtime used by direct invitation creation.');
$assert(str_contains($client, '<strong>Загружаем соперников…</strong>')
    && str_contains($client, 'const result = await postJson(OPPONENTS_URL, {});')
    && !str_contains($client, 'renderPlayerPicker([], context);'),
    'The client must show an honest loading state and never paint a fake empty list before the response.');

fwrite(STDOUT, "ProductionV110InviteOpponentSourceR12ContractTest: {$assertions} assertions passed\n");
