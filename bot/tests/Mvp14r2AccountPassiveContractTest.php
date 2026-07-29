<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read account/passive source: ' . $path);
    return $content;
};

$api = $read('bot/api.php');
$userService = $read('bot/services/UserService.php');
$sessionService = $read('bot/services/SessionService.php');
$notificationService = $read('bot/services/NotificationService.php');
$runner = $read('bot/baseline/JsonAccountPassiveBaselineScenario.php');
$bootstrap = $read('bot/core/bootstrap.php');
$check = $read('ops/checks/mvp14r2-account-passive-local.sh');

$assert(str_contains($api, "case 'bootstrap':") && str_contains($api, "case 'profile':"), 'Bootstrap/profile API actions are missing.');
$assert(str_contains($api, "'user' => \$users->publicUser(\$user)") && str_contains($api, "'session' => \$sessions->publicState(\$user, \$sessionId)"), 'API public account/session projection changed.');
$assert(str_contains($api, "'stats' => \$users->profileStats(\$user, \$data)"), 'Profile must continue recalculating stats from domain games.');
$assert(str_contains($userService, "if ((\$game['status'] ?? '') !== 'finished')") && str_contains($userService, "in_array(\$userId, \$players, true)"), 'Profile stats finished-game ownership guards changed.');
$assert(str_contains($userService, "'gold_shop_available' => \$this->goldShopAvailable(\$user)") && str_contains($userService, 'max(0, min($balance, $turnoverAvailable))'), 'Public Gold turnover projection changed.');

$assert(str_contains($sessionService, "in_array(\$status, ['searching', 'playing'], true)") && str_contains($sessionService, "\$activeId !== \$sessionId"), 'Secondary-device lock conditions changed.');
$assert(str_contains($sessionService, 'У вас уже идёт активная игра на другом устройстве. Продолжайте игру там.'), 'Playing lock message changed.');
$assert(str_contains($sessionService, 'Вы уже ищете матч на другом устройстве. Завершите поиск там или подождите несколько минут.'), 'Searching lock message changed.');
$assert(str_contains($sessionService, "'timeout_sec' => \$this->timeoutSec()"), 'Public session timeout projection changed.');

$assert(str_contains($notificationService, "&& empty(\$notification['hidden_at'])"), 'Hidden notification filter changed.');
$assert(str_contains($notificationService, 'return $rightTime <=> $leftTime;') && str_contains($notificationService, "strcmp((string)(\$right['id'] ?? ''), (string)(\$left['id'] ?? ''))"), 'Notification ordering changed.');
$assert(str_contains($notificationService, "if (!empty(\$notification['hidden_at']) || !empty(\$notification['read_at'])) continue;"), 'Unread-count visibility/read guard changed.');

foreach (['bootstrap', 'profile', 'session_state', 'notifications'] as $action) {
    $assert(str_contains($runner, "'{$action}'"), 'Baseline runner action is missing: ' . $action . '.');
}
$assert(str_contains($runner, 'Account/passive baseline projection mutated domain state.'), 'Read-only domain mutation guard is missing.');
$assert(str_contains($runner, 'Account/passive baseline retry is not deterministic.'), 'Deterministic retry guard is missing.');
$assert(str_contains($runner, "'measured' => false") && str_contains($runner, "'samples' => 0"), 'Part 2.2 must not fabricate latency measurements.');
$assert(!str_contains($bootstrap, 'JsonAccountPassiveBaselineScenario'), 'Baseline runner must not load in production bootstrap.');

foreach (['curl ', 'wget ', 'ssh ', 'scp ', 'rsync ', 'mysql ', 'mysqldump', 'git push', 'git merge', 'HOSTINGER', 'DATABASE_URL', 'DB_PASSWORD'] as $forbidden) {
    $assert(!str_contains($runner, $forbidden) && !str_contains($check, $forbidden), 'Baseline package contains forbidden live operation: ' . $forbidden . '.');
}
$assert(str_contains($check, 'PRODUCTION_CONTACTED=false') && str_contains($check, 'PRODUCTION_CHANGED=false'), 'Focused check safety receipt is incomplete.');

fwrite(STDOUT, "Mvp14r2AccountPassiveContractTest passed: {$assertions} assertions.\n");
