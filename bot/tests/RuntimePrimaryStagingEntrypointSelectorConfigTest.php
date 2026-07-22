<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointSelectorConfig.php';

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

$disabled = RuntimePrimaryStagingEntrypointSelectorConfig::fromApplicationConfig([]);
$assertTrue($disabled->enabled() === false, 'Selector must default to disabled');
$assertTrue($disabled->enabledFor('api') === false, 'Disabled selector must not enable API');
$assertTrue($disabled->enabledFor('webhook') === false, 'Disabled selector must never enable webhook');
$summary = $disabled->safeSummary();
$assertTrue(($summary['api_only'] ?? false) === true, 'Selector summary must remain API-only');
$assertTrue(($summary['webhook_allowed'] ?? true) === false, 'Selector summary must forbid webhook');
$assertTrue(($summary['production_allowed'] ?? true) === false, 'Selector must never allow production');

$api = RuntimePrimaryStagingEntrypointSelectorConfig::fromApplicationConfig([
    'staging_db_primary_entrypoint_selector' => [
        'enabled' => true,
        'contract_version' => RuntimePrimaryStagingEntrypointSelectorConfig::CONTRACT_VERSION,
        'allowed_entrypoints' => ['api'],
    ],
]);
$assertTrue($api->enabled() === true, 'Exact API-only selector contract must enable the latch');
$assertTrue($api->enabledFor('api') === true, 'Exact API allowlist must enable API');
$assertTrue($api->enabledFor('webhook') === false, 'Enabled selector must still reject webhook');

$assertThrows(
    static fn() => RuntimePrimaryStagingEntrypointSelectorConfig::fromApplicationConfig([
        'staging_db_primary_entrypoint_selector' => 'enabled',
    ]),
    'configuration array'
);
foreach (['true', 1, 0, null] as $malformedEnabled) {
    $assertThrows(
        static fn() => RuntimePrimaryStagingEntrypointSelectorConfig::fromApplicationConfig([
            'staging_db_primary_entrypoint_selector' => [
                'enabled' => $malformedEnabled,
                'contract_version' => RuntimePrimaryStagingEntrypointSelectorConfig::CONTRACT_VERSION,
                'allowed_entrypoints' => ['api'],
            ],
        ]),
        'strict boolean'
    );
}
foreach ([
    'wrong',
    ' ' . RuntimePrimaryStagingEntrypointSelectorConfig::CONTRACT_VERSION,
    RuntimePrimaryStagingEntrypointSelectorConfig::CONTRACT_VERSION . ' ',
] as $malformedContract) {
    $assertThrows(
        static fn() => RuntimePrimaryStagingEntrypointSelectorConfig::fromApplicationConfig([
            'staging_db_primary_entrypoint_selector' => [
                'enabled' => true,
                'contract_version' => $malformedContract,
                'allowed_entrypoints' => ['api'],
            ],
        ]),
        'contract version is invalid'
    );
}
foreach ([123, null] as $malformedContractType) {
    $assertThrows(
        static fn() => RuntimePrimaryStagingEntrypointSelectorConfig::fromApplicationConfig([
            'staging_db_primary_entrypoint_selector' => [
                'enabled' => true,
                'contract_version' => $malformedContractType,
                'allowed_entrypoints' => ['api'],
            ],
        ]),
        'must be a string value'
    );
}
$assertThrows(
    static fn() => RuntimePrimaryStagingEntrypointSelectorConfig::fromApplicationConfig([
        'staging_db_primary_entrypoint_selector' => [
            'enabled' => true,
            'contract_version' => RuntimePrimaryStagingEntrypointSelectorConfig::CONTRACT_VERSION,
            'allowed_entrypoints' => [],
        ],
    ]),
    'must allow exactly api'
);
$assertThrows(
    static fn() => RuntimePrimaryStagingEntrypointSelectorConfig::fromApplicationConfig([
        'staging_db_primary_entrypoint_selector' => [
            'enabled' => true,
            'contract_version' => RuntimePrimaryStagingEntrypointSelectorConfig::CONTRACT_VERSION,
            'allowed_entrypoints' => ['api', 'api'],
        ],
    ]),
    'duplicate staging db-primary entrypoint'
);
foreach ([['webhook'], ['API'], [' api'], ['api ']] as $malformedEntrypoints) {
    $assertThrows(
        static fn() => RuntimePrimaryStagingEntrypointSelectorConfig::fromApplicationConfig([
            'staging_db_primary_entrypoint_selector' => [
                'enabled' => true,
                'contract_version' => RuntimePrimaryStagingEntrypointSelectorConfig::CONTRACT_VERSION,
                'allowed_entrypoints' => $malformedEntrypoints,
            ],
        ]),
        'supports only api'
    );
}
foreach ([[1], [null]] as $malformedEntrypoints) {
    $assertThrows(
        static fn() => RuntimePrimaryStagingEntrypointSelectorConfig::fromApplicationConfig([
            'staging_db_primary_entrypoint_selector' => [
                'enabled' => true,
                'contract_version' => RuntimePrimaryStagingEntrypointSelectorConfig::CONTRACT_VERSION,
                'allowed_entrypoints' => $malformedEntrypoints,
            ],
        ]),
        'values must be strings'
    );
}
$assertThrows(
    static fn() => RuntimePrimaryStagingEntrypointSelectorConfig::fromApplicationConfig([
        'staging_db_primary_entrypoint_selector' => [
            'enabled' => true,
            'contract_version' => RuntimePrimaryStagingEntrypointSelectorConfig::CONTRACT_VERSION,
            'allowed_entrypoints' => null,
        ],
    ]),
    'must be a list'
);
$assertThrows(static fn() => $api->enabledFor('admin'), 'supports only api or webhook');
$assertThrows(static fn() => $api->enabledFor(' API '), 'supports only api or webhook');

fwrite(STDOUT, "RuntimePrimaryStagingEntrypointSelectorConfigTest passed: {$assertions} assertions.\n");
