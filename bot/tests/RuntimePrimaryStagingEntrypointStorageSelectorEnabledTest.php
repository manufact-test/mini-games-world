<?php
declare(strict_types=1);

final class RuntimePrimaryStagingApiSessionCoordinator
{
    public static int $constructCalls = 0;
    public static int $installCalls = 0;
    public static array $report = [
        'ok' => true,
        'entrypoint' => 'api',
        'storage_driver' => 'database',
        'request_finalizer_registered_first' => true,
        'dynamic_session_readiness' => true,
        'legacy_json_bridges_suppressed' => true,
        'webhook_allowed' => false,
        'production_changed' => false,
    ];
    public function __construct(string $projectRoot, array $config, string $configFile)
    {
        self::$constructCalls++;
    }
    public function install(): array
    {
        self::$installCalls++;
        return self::$report;
    }
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointSelectorConfig.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointStorageSelector.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $messagePart) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($messagePart))) return;
        throw new RuntimeException('Unexpected exception: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected exception was not thrown.');
};

$config = [
    'environment' => 'staging',
    'staging_db_primary_entrypoint_selector' => [
        'enabled' => true,
        'contract_version' => RuntimePrimaryStagingEntrypointSelectorConfig::CONTRACT_VERSION,
        'allowed_entrypoints' => ['api'],
    ],
];
$selector = new RuntimePrimaryStagingEntrypointStorageSelector(
    $projectRoot,
    $config,
    '/private/config.php',
    'api'
);
$assertTrue($selector->installIfEnabled() === true, 'Enabled API selector must install lifecycle coordinator');
$assertTrue(RuntimePrimaryStagingApiSessionCoordinator::$constructCalls === 1, 'Selector must construct coordinator once');
$assertTrue(RuntimePrimaryStagingApiSessionCoordinator::$installCalls === 1, 'Selector must invoke coordinator once');

RuntimePrimaryStagingApiSessionCoordinator::$report['request_finalizer_registered_first'] = false;
$invalid = new RuntimePrimaryStagingEntrypointStorageSelector(
    $projectRoot,
    $config,
    '/private/config.php',
    'api'
);
$assertThrows(
    static fn() => $invalid->installIfEnabled(),
    'incomplete install contract'
);

$webhook = new RuntimePrimaryStagingEntrypointStorageSelector(
    $projectRoot,
    $config,
    '/private/config.php',
    'webhook'
);
$assertThrows(
    static fn() => $webhook->installIfEnabled(),
    'webhook routing is not allowed'
);
$assertTrue(RuntimePrimaryStagingApiSessionCoordinator::$installCalls === 2, 'Webhook must not invoke coordinator');

fwrite(STDOUT, "RuntimePrimaryStagingEntrypointStorageSelectorEnabledTest passed: {$assertions} assertions.\n");
