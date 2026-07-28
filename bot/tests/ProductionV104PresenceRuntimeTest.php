<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/services/PresenceService.php';
require_once $root . '/bot/services/StatsService.php';

$tempDir = sys_get_temp_dir() . '/mgw_v104_presence_' . bin2hex(random_bytes(6));
$presence = new PresenceService($tempDir);
$stats = new StatsService($presence);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$db = [
    'users' => [
        '100' => ['id'=>'100', 'telegram_id'=>'100', 'last_seen_at'=>gmdate('c', time() - 100)],
        '200' => ['id'=>'200', 'telegram_id'=>'200', 'last_seen_at'=>gmdate('c', time() - 100)],
    ],
    'games' => [],
    'queue' => [],
];
$originalDb = serialize($db);

try {
    $presence->touch('100', 'desktop');
    $presence->touch('100', 'mobile');
    $assert($stats->build($db)['online_players'] === 1, 'Two devices for one Telegram account must count as one online player.');

    $presence->touch('200', 'phone');
    $assert($stats->build($db)['online_players'] === 2, 'Two active Telegram accounts must count as two online players.');

    $presence->leave('100', 'desktop');
    $assert($stats->build($db)['online_players'] === 2, 'Closing one of two sessions must keep the account online.');

    $presence->leave('100', 'mobile');
    $assert($stats->build($db)['online_players'] === 1, 'Closing the final session must remove the account immediately.');

    $account200 = $tempDir . '/account-' . hash('sha256', '200');
    $sessionFiles = glob($account200 . '/session-*.presence') ?: [];
    if ($sessionFiles === []) throw new RuntimeException('Presence test session file was not created.');
    file_put_contents($sessionFiles[0], (string)(time() - 20), LOCK_EX);
    $assert($stats->build($db)['online_players'] === 0, 'A stale session must fall out without waiting for legacy last_seen.');

    $assert(serialize($db) === $originalDb, 'Presence tracking must not add or change fields in application JSON data.');
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

fwrite(STDOUT, "ProductionV104PresenceRuntimeTest: {$assertions} assertions passed\n");
