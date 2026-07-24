<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$paths = [
    'bootstrap' => $projectRoot . '/bot/core/bootstrap.php',
    'validator' => $projectRoot . '/bot/core/ConfigValidator.php',
    'factory' => $projectRoot . '/bot/storage/StorageFactory.php',
    'cli' => $projectRoot . '/ops/deploy/production-cutover.php',
    'guard' => $projectRoot . '/bot/cutover/ProductionCutoverPackageGuardTrait.php',
];

$sources = [];
foreach ($paths as $name => $path) {
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Storage contract source is unavailable: ' . $name . '.');
    }
    $sources[$name] = $source;
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$bootstrapValidation = strpos(
    $sources['bootstrap'],
    '$config = ConfigValidator::validate($config, $_SERVER);'
);
$bootstrapNormalization = strpos(
    $sources['bootstrap'],
    "$globalStorageDriver = strtolower(trim((string)(\$config['storage_driver'] ?? 'json')));"
);
$bootstrapPublication = strpos(
    $sources['bootstrap'],
    "$config['storage_driver'] = $globalStorageDriver !== '' ? $globalStorageDriver : 'json';"
);

$assertTrue(
    $bootstrapValidation !== false
        && $bootstrapNormalization !== false
        && $bootstrapPublication !== false
        && $bootstrapValidation < $bootstrapNormalization
        && $bootstrapNormalization < $bootstrapPublication,
    'Bootstrap must publish the validated implicit JSON storage driver before control guards run'
);
$assertTrue(
    str_contains($sources['validator'], "\$config['storage_driver'] ?? 'json'")
        && str_contains($sources['validator'], "Only the JSON storage driver is available before the database cutover."),
    'Config validation must treat a missing storage driver as JSON and reject non-JSON values'
);
$assertTrue(
    str_contains($sources['factory'], "\$config['storage_driver'] ?? 'json'")
        && str_contains($sources['factory'], "if (\$driver === '')")
        && str_contains($sources['factory'], "\$driver = 'json';"),
    'StorageFactory must keep missing and empty storage drivers JSON-compatible'
);
$assertTrue(
    str_contains($sources['cli'], "(\$config['storage_driver'] ?? null) !== RuntimeStorageRouter::DRIVER_JSON")
        && str_contains($sources['guard'], "(\$this->config['storage_driver'] ?? null) !== RuntimeStorageRouter::DRIVER_JSON"),
    'Cutover guards may require an explicit driver only after bootstrap canonicalizes it'
);

fwrite(
    STDOUT,
    "ProductionCutoverImplicitJsonStorageContractTest passed: {$assertions} assertions.\n"
);
