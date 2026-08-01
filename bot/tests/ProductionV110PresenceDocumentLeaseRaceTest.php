<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/services/PresenceService.php';
require_once $root . '/bot/services/StatsService.php';

$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read presence source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$tempDir = sys_get_temp_dir() . '/mgw_v110_presence_lease_' . bin2hex(random_bytes(6));
$presence = new PresenceService($tempDir);
$stats = new StatsService($presence);
$db = [
    'users' => [
        '100' => ['id'=>'100', 'telegram_id'=>'100'],
        '200' => ['id'=>'200', 'telegram_id'=>'200'],
    ],
    'games' => [],
    'queue' => [],
];

$expireLease = static function (string $accountId, string $sessionId, string $leaseId) use ($tempDir): void {
    $accountDirectory = $tempDir . '/account-' . hash('sha256', $accountId);
    $leaseKey = $sessionId . "\0presence:" . $leaseId;
    $path = $accountDirectory . '/session-' . hash('sha256', $leaseKey) . '.presence';
    $state = json_decode((string)file_get_contents($path), true);
    if (!is_array($state)) throw new RuntimeException('Document presence lease was not created.');
    $state['leave_after'] = time() - 1;
    file_put_contents($path, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
};

try {
    $presence->touch('100', 'observer-device', 'observer-page');
    $presence->touch('200', 'phone-device', 'old-page');
    $assert($stats->build($db)['online_players'] === 2, 'Two active accounts must initially be online.');

    $presence->touch('200', 'phone-device', 'new-page');
    $presence->leave('200', 'phone-device', 'old-page');
    $expireLease('200', 'phone-device', 'old-page');
    $assert($stats->build($db)['online_players'] === 2,
        'A delayed leave from the old Telegram document must not turn the new document offline.');

    $presence->leave('200', 'phone-device', 'new-page');
    $expireLease('200', 'phone-device', 'new-page');
    $assert($stats->build($db)['online_players'] === 1,
        'The account may leave only after its final live document lease expires.');

    $presence->touch('200', 'legacy-device');
    $assert($stats->build($db)['online_players'] === 2,
        'Legacy two-argument presence calls must remain compatible.');

    $client = $read('app/assets/js/production-v110-presence.js');
    $endpoint = $read('bot/presence.php');
    $service = $read('bot/services/PresenceService.php');
    $shell = $read('app/assets/js/main-v110-handoff-shell.js');

    $assert(str_contains($client, 'const presenceLeaseId = createPresenceLeaseId();')
        && str_contains($client, 'presenceLeaseId,')
        && str_contains($client, 'function createPresenceLeaseId()'),
        'The canonical client presence owner must keep one unique lease for each app document.');
    $assert(str_contains($client, "// Presence transport starts before the profile bootstrap.")
        && str_contains($client, "  startPresence();\n}")
        && str_contains($client, "if (runtime.pingBusy || document.visibilityState !== 'visible') return false;")
        && !str_contains($client, "runtime.pingBusy || !runtime.appReady"),
        'A new document must register its lease before profile bootstrap completes.');
    $assert(str_contains($endpoint, '$presenceLeaseId = clean_string($payload[\'presenceLeaseId\'] ?? \'\', 120);')
        && str_contains($endpoint, '$presence->touch($accountId, $sessionId, $presenceLeaseId);')
        && str_contains($endpoint, '$presence->leave($accountId, $sessionId, $presenceLeaseId);'),
        'The endpoint must route ping and leave through the same document lease.');
    $assert(str_contains($service, 'string $presenceLeaseId = \'\'')
        && str_contains($service, '$sessionId . "\\0presence:" . $presenceLeaseId')
        && str_contains($service, 'private const LEAVE_GRACE_SEC = 12;'),
        'Presence storage must isolate documents and keep a bounded Telegram handoff grace.');
    $assert(str_contains($shell, 'production-v110-presence.js?v=1118'),
        'The active shell must load the final document-scoped presence owner.');
} finally {
    $remove = static function (string $path) use (&$remove): void {
        if (is_dir($path)) {
            foreach (array_diff(scandir($path) ?: [], ['.','..']) as $item) {
                $remove($path . DIRECTORY_SEPARATOR . $item);
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    };
    $remove($tempDir);
}

fwrite(STDOUT, "ProductionV110PresenceDocumentLeaseRaceTest: {$assertions} assertions passed\n");
