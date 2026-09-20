<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/database/DatabaseConfig.php';
require_once $root . '/bot/services/StagingReadinessService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$temporary = sys_get_temp_dir() . '/mgw-r13-bot-identity-' . bin2hex(random_bytes(8));
$sourceFiles = [
    'app/v110.php',
    'app/assets/js/main-v110.js',
    'app/assets/js/main-v110-handoff-shell.js',
    'bot/api.php',
    'bot/invites.php',
    'bot/notifications.php',
    'bot/core/ConfigValidator.php',
];
foreach ($sourceFiles as $relative) {
    $path = $temporary . '/' . $relative;
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create temporary source directory: ' . $directory);
    }
    file_put_contents($path, $relative);
}

try {
    $token = '123456789:STAGING_TEST_TOKEN_ONLY';
    $config = [
        'environment' => 'staging',
        'base_url' => 'https://seashell-okapi-889488.hostingersite.com',
        'allowed_hosts' => ['seashell-okapi-889488.hostingersite.com'],
        'storage_driver' => 'json',
        'data_dir' => $temporary . '/data',
        'staging_bot_username' => '@mgw_staging_bot',
        'bot_token' => $token,
        'database' => [
            'enabled' => false,
            'driver' => 'mysql',
            'host' => '',
            'port' => 3306,
            'name' => '',
            'user' => '',
            'password' => '',
            'charset' => 'utf8mb4',
        ],
        'environment_guard' => [
            'production_hosts' => ['lemonchiffon-gerbil-545102.hostingersite.com'],
            'production_data_dir' => '/private/production/data',
            'production_database_sha256' => str_repeat('a', 64),
        ],
        'external_payments_enabled' => false,
        'payment_mode' => 'sandbox',
    ];

    $receivedToken = null;
    $verifiedService = new StagingReadinessService(
        $config,
        $temporary,
        static function (string $value) use (&$receivedToken): array {
            $receivedToken = $value;
            return [
                'ok' => true,
                'result' => [
                    'id' => 123456789,
                    'is_bot' => true,
                    'username' => 'MGW_STAGING_BOT',
                ],
            ];
        }
    );
    $verifiedReport = $verifiedService->report();
    $assert($receivedToken === $token, 'The live identity resolver must receive the configured staging token only in memory.');
    $assert($verifiedReport['isolation']['production_bot_identity_protected'] === true,
        'The expected staging bot username must protect the environment without a production token fingerprint.');
    $verifiedJson = json_encode($verifiedReport, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $assert(!str_contains($verifiedJson, $token), 'Readiness must never expose the staging bot token.');

    $wrongBotService = new StagingReadinessService(
        $config,
        $temporary,
        static fn(string $value): array => [
            'ok' => true,
            'result' => [
                'id' => 999999999,
                'is_bot' => true,
                'username' => 'production_bot',
            ],
        ]
    );
    $wrongReport = $wrongBotService->report();
    $assert($wrongReport['isolation']['production_bot_identity_protected'] === false,
        'A token belonging to any other Telegram bot must fail the staging identity gate.');

    $invalidService = new StagingReadinessService(
        $config,
        $temporary,
        static fn(string $value): array => ['ok' => false]
    );
    $invalidReport = $invalidService->report();
    $assert($invalidReport['isolation']['production_bot_identity_protected'] === false,
        'Telegram API failures must fail closed without exposing secrets.');
} finally {
    if (is_dir($temporary)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) @rmdir($item->getPathname()); else @unlink($item->getPathname());
        }
        @rmdir($temporary);
    }
}

fwrite(STDOUT, "ProductionMvp14R13StagingBotIdentityRuntimeTest: {$assertions} assertions passed\n");
