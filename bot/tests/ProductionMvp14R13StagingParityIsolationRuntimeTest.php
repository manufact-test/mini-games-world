<?php
declare(strict_types=1);

use Mgw\CleanRuntime\Server\RuntimeConfig;
use Mgw\CleanRuntime\Server\RuntimeEnvironmentGuard;

$root = dirname(__DIR__, 2);
require_once $root . '/app/runtime/server/RuntimeConfig.php';
require_once $root . '/app/runtime/server/RuntimeEnvironmentGuard.php';
require_once $root . '/bot/database/DatabaseConfig.php';
require_once $root . '/bot/services/StagingReadinessService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$expect = static function (string $class, callable $callback, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if ($error instanceof $class) return;
        throw new RuntimeException($message . ' Wrong exception: ' . $error::class);
    }
    throw new RuntimeException($message . ' No exception was thrown.');
};

$environmentNames = [
    'MGW_CLEAN_RUNTIME_ENV',
    'MGW_ENV',
    'MGW_CLEAN_ALLOWED_HOSTS',
    'MGW_CLEAN_ALLOW_BROWSER_IDENTITY',
    'MGW_CLEAN_RUNTIME_DATA_DIR',
];
$previous = [];
foreach ($environmentNames as $name) {
    $value = getenv($name);
    $previous[$name] = $value === false ? null : $value;
    putenv($name);
}

$temporary = sys_get_temp_dir() . '/mgw-r13-parity-' . bin2hex(random_bytes(8));
@mkdir($temporary, 0770, true);

try {
    $expect(RuntimeException::class, fn() => RuntimeConfig::fromEnvironment(), 'Clean runtime must fail closed without an explicit environment.');

    putenv('MGW_CLEAN_RUNTIME_ENV=production');
    $expect(RuntimeException::class, fn() => RuntimeConfig::fromEnvironment(), 'Clean runtime must fail closed in production.');

    putenv('MGW_CLEAN_RUNTIME_ENV=staging');
    $expect(RuntimeException::class, fn() => RuntimeConfig::fromEnvironment(), 'Clean runtime staging must require an explicit host allowlist.');

    putenv('MGW_CLEAN_ALLOWED_HOSTS=SEASHELL-OKAPI-889488.HOSTINGERSITE.COM,seashell-okapi-889488.hostingersite.com');
    putenv('MGW_CLEAN_RUNTIME_DATA_DIR=' . $temporary . '/clean');
    $configured = RuntimeConfig::fromEnvironment();
    $assert($configured->environment === 'staging', 'Explicit staging must produce one staging config.');
    $assert($configured->allowedHosts === ['seashell-okapi-889488.hostingersite.com'], 'Allowed hosts must normalize and deduplicate.');
    $assert($configured->allowBrowserStagingIdentity === false, 'Browser staging identity must be disabled by default.');
    $assert($configured->build === 'mgw-clean-server-v5-fail-closed', 'The guarded clean runtime must publish the exact build marker.');

    RuntimeEnvironmentGuard::assertAvailable($configured, [
        'REQUEST_METHOD' => 'GET',
        'HTTP_HOST' => 'seashell-okapi-889488.hostingersite.com',
    ]);
    $assert(true, 'The exact staging host must be accepted.');
    $expect(RuntimeException::class, fn() => RuntimeEnvironmentGuard::assertAvailable($configured, [
        'REQUEST_METHOD' => 'GET',
        'HTTP_HOST' => 'lemonchiffon-gerbil-545102.hostingersite.com',
    ]), 'Production host must be rejected by the clean runtime guard.');

    putenv('MGW_CLEAN_ALLOW_BROWSER_IDENTITY=1');
    $browserEnabled = RuntimeConfig::fromEnvironment();
    $assert($browserEnabled->allowBrowserStagingIdentity === true, 'Browser staging identity must require one explicit flag.');

    $databasePassword = 'staging-password-never-returned';
    $config = [
        'environment' => 'staging',
        'base_url' => 'https://seashell-okapi-889488.hostingersite.com',
        'allowed_hosts' => ['seashell-okapi-889488.hostingersite.com'],
        'storage_driver' => 'json',
        'data_dir' => $temporary . '/canonical-staging-data',
        'staging_bot_username' => 'mgw_staging_bot',
        'bot_token' => '123456789:staging-token-never-returned',
        'database' => [
            'enabled' => false,
            'driver' => 'mysql',
            'host' => 'staging-db.internal',
            'port' => 3306,
            'name' => 'mgw_staging',
            'user' => 'mgw_staging_user',
            'password' => $databasePassword,
            'charset' => 'utf8mb4',
        ],
        'environment_guard' => [
            'production_hosts' => ['lemonchiffon-gerbil-545102.hostingersite.com'],
            'production_data_dir' => '/private/production/data',
            'production_database_sha256' => str_repeat('a', 64),
            'production_bot_token_sha256' => str_repeat('b', 64),
        ],
        'external_payments_enabled' => false,
        'payment_mode' => 'sandbox',
    ];

    $service = new StagingReadinessService($config, $root);
    $report = $service->report();
    $assert($report['ok'] === true && $report['environment'] === 'staging', 'Readiness must expose only a validated staging projection.');
    $assert($report['build'] === 'mgw-staging-parity-r13.2-v1', 'Readiness must publish one exact parity build marker.');
    $assert(preg_match('/^[a-f0-9]{64}$/', (string)$report['source_fingerprint_sha256']) === 1, 'Readiness must fingerprint the canonical source graph.');
    $assert(preg_match('/^[a-f0-9]{64}$/', (string)$report['storage']['data_identity_sha256']) === 1, 'Readiness must hash the data identity instead of exposing its path.');
    $assert(preg_match('/^[a-f0-9]{64}$/', (string)$report['storage']['database_identity_sha256']) === 1, 'Readiness must expose only the database identity fingerprint.');
    $assert($report['storage']['database']['enabled'] === false && $report['storage']['database']['identity_configured'] === true, 'Readiness must expose only the database safe summary.');
    $assert(!in_array(false, $report['isolation'], true), 'Every required staging isolation control must be present.');

    $json = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    foreach ([$temporary, $databasePassword, 'staging-token-never-returned', 'staging-db.internal', 'mgw_staging_user'] as $secretValue) {
        $assert(!str_contains($json, $secretValue), 'Readiness must not expose paths, credentials or database coordinates.');
    }

    $productionConfig = array_replace($config, ['environment' => 'production']);
    $expect(RuntimeException::class, fn() => (new StagingReadinessService($productionConfig, $root))->report(), 'Staging readiness must fail closed in production.');

    $endpoint = file_get_contents($root . '/bot/staging-readiness.php');
    $index = file_get_contents($root . '/app/runtime/index.php');
    $api = file_get_contents($root . '/app/runtime/api.php');
    $assert(is_string($endpoint)
        && str_contains($endpoint, "['GET', 'HEAD']")
        && str_contains($endpoint, "http_response_code(404)")
        && !str_contains($endpoint, 'bot_token')
        && !str_contains($endpoint, 'password'),
        'The public readiness endpoint must be GET/HEAD-only, staging-only and secret-free.');
    $assert(is_string($index) && is_string($api)
        && str_contains($index, 'RuntimeEnvironmentGuard::assertAvailable')
        && str_contains($api, 'RuntimeEnvironmentGuard::assertAvailable')
        && str_contains($index, "http_response_code(404)")
        && str_contains($api, "http_response_code(404)"),
        'Both clean runtime entrypoints must fail closed through the same environment guard.');
} finally {
    foreach ($previous as $name => $value) {
        if ($value === null) putenv($name); else putenv($name . '=' . $value);
    }
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

fwrite(STDOUT, "ProductionMvp14R13StagingParityIsolationRuntimeTest: {$assertions} assertions passed\n");
