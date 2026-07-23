<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$factoryPath = $projectRoot . '/bot/storage/StorageFactory.php';
$interfacePath = $projectRoot . '/bot/runtime/RuntimePrimaryProjectionAuditorInterface.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assertTrue(is_file($factoryPath), 'StorageFactory.php must exist.');
$assertTrue(is_file($interfacePath), 'Projection auditor interface must exist.');

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

fwrite(
    STDOUT,
    "StorageFactoryProjectionAuditorLoadOrderTest passed: {$assertions} assertions.\n"
);
