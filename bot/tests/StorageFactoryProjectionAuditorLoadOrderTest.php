<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$factoryPath = $projectRoot . '/bot/storage/StorageFactory.php';
$interfacePath = $projectRoot . '/bot/runtime/RuntimePrimaryProjectionAuditorInterface.php';
$adapterPath = $projectRoot . '/bot/runtime/RuntimePrimaryProjectionAuditorAdapter.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assertTrue(is_file($factoryPath), 'StorageFactory.php must exist.');
$assertTrue(is_file($interfacePath), 'Projection auditor interface must exist.');
$assertTrue(is_file($adapterPath), 'Projection auditor adapter must exist.');

$source = file_get_contents($factoryPath);
$assertTrue(is_string($source), 'StorageFactory.php must be readable.');

$interfaceRequire = "require_once __DIR__ . '/../runtime/RuntimePrimaryProjectionAuditorInterface.php';";
$classDeclaration = 'final class StorageFactory';

$interfacePosition = strpos($source, $interfaceRequire);
$classPosition = strpos($source, $classDeclaration);

$assertTrue($interfacePosition !== false, 'StorageFactory must preload the projection auditor interface.');
$assertTrue($classPosition !== false, 'StorageFactory class declaration must exist.');
$assertTrue(
    $interfacePosition < $classPosition,
    'Projection auditor interface must load before StorageFactory dependencies can be evaluated.'
);

$runtimeScript = <<<'PHP'
require $argv[1];
require $argv[2];
if (!interface_exists('RuntimePrimaryProjectionAuditorInterface', false)) {
    fwrite(STDERR, "auditor interface unavailable\n");
    exit(2);
}
if (!class_exists('RuntimePrimaryProjectionAuditorAdapter', false)) {
    fwrite(STDERR, "auditor adapter unavailable\n");
    exit(3);
}
echo "runtime-load-ok\n";
PHP;

$command = escapeshellarg(PHP_BINARY)
    . ' -r ' . escapeshellarg($runtimeScript)
    . ' ' . escapeshellarg($factoryPath)
    . ' ' . escapeshellarg($adapterPath)
    . ' 2>&1';
$output = [];
$exitCode = 1;
exec($command, $output, $exitCode);

$assertTrue(
    $exitCode === 0,
    'StorageFactory must preload the auditor interface before the adapter is declared: '
        . implode("\n", $output)
);
$assertTrue(
    in_array('runtime-load-ok', $output, true),
    'Runtime dependency load proof marker is missing.'
);

fwrite(
    STDOUT,
    "StorageFactoryProjectionAuditorLoadOrderTest passed: {$assertions} assertions.\n"
);
