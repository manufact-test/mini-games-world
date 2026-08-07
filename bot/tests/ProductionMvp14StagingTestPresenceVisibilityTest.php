<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/services/StatsService.php';

$directory = sys_get_temp_dir() . '/mgw-presence-visibility-' . bin2hex(random_bytes(6));
$previousConfig = $GLOBALS['config'] ?? null;
$previousCookie = $_COOKIE['mgw_staging_test_session'] ?? null;
$hadCookie = array_key_exists('mgw_staging_test_session', $_COOKIE);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$cleanup = static function (string $path): void {
    if (!is_dir($path)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
};

try {
    $GLOBALS['config'] = ['environment' => 'staging'];
    unset($_COOKIE['mgw_staging_test_session']);

    $presence = new PresenceService($directory);
    $presence->touch('real_player', 'real-session');
    $presence->touch('stg_test_player_a', 'test-a-session');
    $presence->touch('stg_test_player_b', 'test-b-session');

    $stats = new StatsService($presence);
    $realViewer = $stats->build([]);
    $assert(
        (int)($realViewer['online_players'] ?? -1) === 1,
        'Ordinary staging users must not see GitHub Actions A/B accounts in the public online count.'
    );

    $_COOKIE['mgw_staging_test_session'] = 'signed-test-session-placeholder';
    $testViewer = $stats->build([]);
    $assert(
        (int)($testViewer['online_players'] ?? -1) === 3,
        'Authenticated staging E2E contexts must retain the full presence count for regression coverage.'
    );

    $GLOBALS['config'] = ['environment' => 'production'];
    unset($_COOKIE['mgw_staging_test_session']);
    $production = $stats->build([]);
    $assert(
        (int)($production['online_players'] ?? -1) === 3,
        'The staging-only visibility rule must not alter production presence semantics.'
    );
} finally {
    $cleanup($directory);
    if ($previousConfig === null) unset($GLOBALS['config']);
    else $GLOBALS['config'] = $previousConfig;

    if ($hadCookie) $_COOKIE['mgw_staging_test_session'] = $previousCookie;
    else unset($_COOKIE['mgw_staging_test_session']);
}

fwrite(STDOUT, "ProductionMvp14StagingTestPresenceVisibilityTest: {$assertions} assertions passed\n");
