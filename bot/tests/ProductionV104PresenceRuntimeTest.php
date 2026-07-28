<?php
declare(strict_types=1);

if (!function_exists('now_iso')) {
    function now_iso(): string { return gmdate('c'); }
}

$root = dirname(__DIR__, 2);
require_once $root . '/bot/services/PresenceService.php';
require_once $root . '/bot/services/StatsService.php';

$presence = new PresenceService();
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

$presence->touch($db['users']['100'], 'desktop');
$presence->touch($db['users']['100'], 'mobile');
$assert($stats->build($db)['online_players'] === 1, 'Two devices for one Telegram account must count as one online player.');

$presence->touch($db['users']['200'], 'phone');
$assert($stats->build($db)['online_players'] === 2, 'Two active Telegram accounts must count as two online players.');

$presence->leave($db['users']['100'], 'desktop');
$assert($stats->build($db)['online_players'] === 2, 'Closing one of two sessions must keep the account online.');

$presence->leave($db['users']['100'], 'mobile');
$assert($stats->build($db)['online_players'] === 1, 'Closing the final session must remove the account immediately.');

$db['users']['200']['presence_sessions']['phone']['last_seen_at'] = gmdate('c', time() - 20);
$assert($stats->build($db)['online_players'] === 0, 'A stale session must fall out without waiting for legacy last_seen.');

fwrite(STDOUT, "ProductionV104PresenceRuntimeTest: {$assertions} assertions passed\n");
