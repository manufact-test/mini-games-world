<?php
declare(strict_types=1);

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

$production = new RuntimePrimaryStagingEntrypointStorageSelector(
    $projectRoot,
    ['environment' => 'production'],
    '/missing/private/config.php',
    'api'
);
$assertTrue($production->installIfEnabled() === false, 'Disabled production selector must preserve JSON');

$stagingApi = new RuntimePrimaryStagingEntrypointStorageSelector(
    $projectRoot,
    ['environment' => 'staging'],
    '/missing/private/config.php',
    'api'
);
$assertTrue($stagingApi->installIfEnabled() === false, 'Disabled staging API selector must preserve JSON');

$stagingWebhook = new RuntimePrimaryStagingEntrypointStorageSelector(
    $projectRoot,
    ['environment' => 'staging'],
    '/missing/private/config.php',
    'webhook'
);
$assertTrue($stagingWebhook->installIfEnabled() === false, 'Disabled staging webhook path must preserve JSON');

$productionEnabled = new RuntimePrimaryStagingEntrypointStorageSelector(
    $projectRoot,
    [
        'environment' => 'production',
        'staging_db_primary_entrypoint_selector' => [
            'enabled' => true,
            'contract_version' => RuntimePrimaryStagingEntrypointSelectorConfig::CONTRACT_VERSION,
            'allowed_entrypoints' => ['api'],
        ],
    ],
    '/missing/private/config.php',
    'api'
);
$assertThrows(
    static fn() => $productionEnabled->installIfEnabled(),
    'cannot be enabled outside staging'
);

$webhookEnabled = new RuntimePrimaryStagingEntrypointStorageSelector(
    $projectRoot,
    [
        'environment' => 'staging',
        'staging_db_primary_entrypoint_selector' => [
            'enabled' => true,
            'contract_version' => RuntimePrimaryStagingEntrypointSelectorConfig::CONTRACT_VERSION,
            'allowed_entrypoints' => ['api'],
        ],
    ],
    '/missing/private/config.php',
    'webhook'
);
$assertThrows(
    static fn() => $webhookEnabled->installIfEnabled(),
    'webhook routing is not allowed'
);

$assertThrows(
    static fn() => new RuntimePrimaryStagingEntrypointStorageSelector(
        $projectRoot,
        ['environment' => 'staging'],
        '/missing/private/config.php',
        'admin'
    ),
    'supports only api or webhook'
);

fwrite(STDOUT, "RuntimePrimaryStagingEntrypointStorageSelectorDisabledTest passed: {$assertions} assertions.\n");
