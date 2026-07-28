<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/StatsService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$now = gmdate('c');
$stale = gmdate('c', time() - 180);
$service = new StatsService();

$db = [
    'users' => [
        'legacy-device-a' => ['id'=>'legacy-device-a', 'telegram_id'=>'1001', 'last_seen_at'=>$now],
        'legacy-device-b' => ['id'=>'legacy-device-b', 'telegram_id'=>'1001', 'last_seen_at'=>$now],
        'stale-user' => ['id'=>'2002', 'telegram_id'=>'2002', 'last_seen_at'=>$stale],
        'bot-shadow' => ['id'=>'bot_shadow', 'telegram_id'=>'bot_shadow', 'last_seen_at'=>$now],
    ],
    'games' => [],
    'queue' => [],
];

$stats = $service->build($db);
$assert(($stats['online_players'] ?? 0) === 1, 'Two devices or legacy records for one Telegram account must count as one online player.');

$db['users']['second-human'] = ['id'=>'3003', 'telegram_id'=>'3003', 'last_seen_at'=>$now];
$stats = $service->build($db);
$assert(($stats['online_players'] ?? 0) === 2, 'A second distinct recently active Telegram account must increase the online count.');

$db['games']['g1'] = ['status'=>'active'];
$db['queue'][] = ['room'=>'match'];
$db['queue'][] = ['room'=>'gold'];
$stats = $service->build($db);
$assert(($stats['active_games'] ?? 0) === 1, 'Unique-account online counting must not alter active game statistics.');
$assert(($stats['search_match'] ?? 0) === 1 && ($stats['search_gold'] ?? 0) === 1, 'Unique-account online counting must not alter queue statistics.');

fwrite(STDOUT, "ProductionV103StatsUniqueOnlineTest: {$assertions} assertions passed\n");
