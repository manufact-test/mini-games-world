<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$projectRoot = dirname($root);
require_once $root . '/runtime/ProductionPrimaryApplicationEntrypoints.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertContains = static function (string $needle, string $source, string $message) use (&$assertions): void {
    $assertions++;
    if (!str_contains($source, $needle)) {
        throw new RuntimeException($message . ': missing ' . $needle);
    }
};

$expected = [
    'bot/api.php' => 'api',
    'bot/webhook.php' => 'webhook',
    'bot/invites.php' => 'invites',
    'bot/notifications.php' => 'notifications',
    'bot/invite-opponents.php' => 'invite_opponents',
    'bot/game-clock.php' => 'game_clock',
    'bot/game-live-v108.php' => 'game_live_v108',
    'bot/search-speed.php' => 'search_speed',
    'bot/shop-history.php' => 'shop_history',
    'bot/cron/weekly-match.php' => 'weekly_match_cron',
];

$assertSame(
    $expected,
    ProductionPrimaryApplicationEntrypoints::pathMap(),
    'Production application entrypoint map must remain exact'
);
$assertSame(
    array_values($expected),
    ProductionPrimaryApplicationEntrypoints::identifiers(),
    'Production application entrypoint identifiers must remain ordered and complete'
);

foreach ($expected as $relativePath => $entrypoint) {
    $absolutePath = $projectRoot . '/' . $relativePath;
    $assertTrue(is_file($absolutePath), 'Mapped production entrypoint must exist: ' . $relativePath);
    $assertSame(
        $entrypoint,
        ProductionPrimaryApplicationEntrypoints::resolve(
            $projectRoot,
            ['SCRIPT_FILENAME' => $absolutePath]
        ),
        'Exact production entrypoint path must resolve: ' . $relativePath
    );
    $assertSame(
        true,
        ProductionPrimaryApplicationEntrypoints::supports($entrypoint),
        'Resolved production entrypoint must be supported: ' . $entrypoint
    );
}

foreach ([
    $root . '/health.php',
    $root . '/cutover/ProductionPreflightRunner.php',
    $root . '/tests/ProductionPrimaryAllApplicationEntrypointRoutingTest.php',
] as $forbiddenPath) {
    $assertSame(
        '',
        ProductionPrimaryApplicationEntrypoints::resolve(
            $projectRoot,
            ['SCRIPT_FILENAME' => $forbiddenPath]
        ),
        'Control, health and test files must not resolve as application entrypoints'
    );
}

$tempRoot = sys_get_temp_dir() . '/mgw-entrypoint-lookalike-' . bin2hex(random_bytes(5));
mkdir($tempRoot, 0700, true);
file_put_contents($tempRoot . '/invites.php', "<?php\n");
try {
    $assertSame(
        '',
        ProductionPrimaryApplicationEntrypoints::resolve(
            $projectRoot,
            ['SCRIPT_FILENAME' => $tempRoot . '/invites.php']
        ),
        'A same-named file outside the project must not receive production DB-primary storage'
    );
} finally {
    @unlink($tempRoot . '/invites.php');
    @rmdir($tempRoot);
}

$storageFactory = file_get_contents($root . '/storage/StorageFactory.php');
$bootstrap = file_get_contents($root . '/runtime/ProductionPrimaryEntrypointBootstrap.php');
$coordinator = file_get_contents($root . '/runtime/ProductionPrimaryRuntimeCoordinator.php');
$context = file_get_contents($root . '/runtime/ProductionPrimaryEntrypointStorageContext.php');
$invites = file_get_contents($root . '/invites.php');
$notifications = file_get_contents($root . '/notifications.php');
$gameClock = file_get_contents($root . '/game-clock.php');
$gameLive = file_get_contents($root . '/game-live-v108.php');
$searchSpeed = file_get_contents($root . '/search-speed.php');
$weekly = file_get_contents($root . '/cron/weekly-match.php');
foreach ([$storageFactory, $bootstrap, $coordinator, $context, $invites, $notifications, $gameClock, $gameLive, $searchSpeed, $weekly] as $source) {
    $assertTrue(is_string($source) && $source !== '', 'Routing contract source file must be readable');
}

$assertContains(
    'ProductionPrimaryApplicationEntrypoints::resolve(',
    $storageFactory,
    'StorageFactory must resolve exact production application paths centrally'
);
$assertContains(
    '$environment === \'production\'',
    $storageFactory,
    'Expanded application entrypoints must remain production-only'
);
$assertContains(
    "'api.php' => 'api'",
    $storageFactory,
    'Staging selector must keep the existing API entrypoint'
);
$assertContains(
    "'webhook.php' => 'webhook'",
    $storageFactory,
    'Staging selector must keep the existing webhook entrypoint'
);
$assertContains(
    'ProductionPrimaryApplicationEntrypoints::supports($entrypoint)',
    $bootstrap,
    'Production bootstrap must validate the central application entrypoint registry'
);
$assertContains(
    'ProductionPrimaryApplicationEntrypoints::supports($entrypoint)',
    $coordinator,
    'Production coordinator must validate the central application entrypoint registry'
);
$assertContains(
    'ProductionPrimaryApplicationEntrypoints::supports($entrypoint)',
    $context,
    'Production context must validate the central application entrypoint registry'
);
$assertContains(
    '$legacyBridgeAllowed = RuntimePrimaryEntrypointBridgeGuard::legacyJsonBridgeAllowed();',
    $invites,
    'Invite endpoint must suppress its legacy DB mirror under atomic production storage'
);
$assertContains(
    '$legacyBridgeAllowed = RuntimePrimaryEntrypointBridgeGuard::legacyJsonBridgeAllowed();',
    $notifications,
    'Notification endpoint must suppress legacy synchronize-and-list writes under atomic production storage'
);
$assertContains(
    'StorageFactory::createJson(',
    $gameClock,
    'Bot clock endpoint must use the same guarded production storage selector'
);
$assertContains(
    'StorageFactory::createJson(',
    $gameLive,
    'Live game endpoint must use the same guarded production storage selector'
);
$assertContains(
    'StorageFactory::createJson(',
    $searchSpeed,
    'Search speed endpoint must use the same guarded production storage selector'
);
$assertContains(
    'RuntimePrimaryEntrypointBridgeGuard::legacyJsonBridgeAllowed()',
    $weekly,
    'Weekly cron must suppress its legacy JSON bridge under atomic production storage'
);

fwrite(STDOUT, "ProductionPrimaryAllApplicationEntrypointRoutingTest: {$assertions} assertions passed\n");
