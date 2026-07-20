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
$assertTrue($production->installIfEnabled() === false, 'Production with disabled selector must preserve JSON without private or DB access');

$staging = new RuntimePrimaryStagingEntrypointStorageSelector(
    $projectRoot,
    ['environment' => 'staging'],
    '/missing/private/config.php',
    'webhook'
);
$assertTrue($staging->installIfEnabled() === false, 'Staging with disabled selector must preserve JSON without private or DB access');

$notAllowed = new RuntimePrimaryStagingEntrypointStorageSelector(
    $projectRoot,
    [
        'environment' => 'staging',
        'staging_db_primary_entrypoint_selector' => [
            'enabled' => true,
            'contract_version' => RuntimePrimaryStagingEntrypointSelectorConfig::CONTRACT_VERSION,
            'allowed_entrypoints' => ['webhook'],
        ],
    ],
    '/missing/private/config.php',
    'api'
);
$assertTrue($notAllowed->installIfEnabled() === false, 'Unlisted staging entrypoint must preserve JSON before private or DB access');

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
