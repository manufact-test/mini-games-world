<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingStorageResolverConfig.php';

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

$disabled = RuntimePrimaryStagingStorageResolverConfig::fromApplicationConfig([]);
$assertThrows(static fn() => $disabled->assertEnabled(), 'resolver is disabled');
$assertTrue(($disabled->safeSummary()['automatic_routing'] ?? true) === false, 'Resolver latch must never imply automatic routing');

$enabled = RuntimePrimaryStagingStorageResolverConfig::fromApplicationConfig([
    'staging_db_primary_storage_resolver' => ['enabled' => true],
]);
$enabled->assertEnabled();
$assertTrue(($enabled->safeSummary()['enabled'] ?? false) === true, 'Exact boolean true must enable the resolver latch');
$assertTrue(($enabled->safeSummary()['staging_only'] ?? false) === true, 'Resolver latch must remain staging-only');

$stringEnabled = RuntimePrimaryStagingStorageResolverConfig::fromApplicationConfig([
    'staging_db_primary_storage_resolver' => ['enabled' => 'enabled'],
]);
$stringEnabled->assertEnabled();

$assertThrows(
    static fn() => RuntimePrimaryStagingStorageResolverConfig::fromApplicationConfig([
        'staging_db_primary_storage_resolver' => 'enabled',
    ]),
    'configuration array'
);
$assertThrows(
    static fn() => RuntimePrimaryStagingStorageResolverConfig::fromApplicationConfig([
        'staging_db_primary_storage_resolver' => ['enabled' => 'tru'],
    ]),
    'strict boolean'
);
$assertThrows(
    static fn() => RuntimePrimaryStagingStorageResolverConfig::fromApplicationConfig([
        'staging_db_primary_storage_resolver' => ['enabled' => 2],
    ]),
    'strict boolean'
);

fwrite(STDOUT, "RuntimePrimaryStagingStorageResolverConfigTest passed: {$assertions} assertions.\n");
