<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$databaseConfigPath = $projectRoot . '/bot/database/DatabaseConfig.php';
$runnerPath = $projectRoot . '/bot/cutover/ProductionPreflightRunner.php';

require_once $databaseConfigPath;

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$config = DatabaseConfig::fromApplicationConfig([
    'database' => [
        'enabled' => true,
        'driver' => 'mysql',
        'host' => 'db.example.internal',
        'port' => 3306,
        'name' => 'mgw_production',
        'user' => 'mgw_runtime',
        'password' => 'not-exported',
        'charset' => 'utf8mb4',
    ],
]);

$fingerprint = $config->identityFingerprint();
$assertTrue(
    preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) === 1,
    'Configured database identity must produce an exact SHA-256 fingerprint.'
);
$assertTrue(
    !str_contains($fingerprint, 'not-exported'),
    'Database identity fingerprint must not expose the password.'
);

$source = file_get_contents($runnerPath);
$assertTrue(is_string($source), 'ProductionPreflightRunner.php must be readable.');
$assertTrue(
    str_contains(
        $source,
        '$databaseSummary[\'identity_fingerprint\'] = $databaseConfig->identityFingerprint();'
    ),
    'Production preflight must attach the safe database identity fingerprint.'
);
$assertTrue(
    str_contains($source, '\'database\' => $databaseSummary'),
    'Production preflight runtime report must publish the augmented database summary.'
);

fwrite(
    STDOUT,
    "ProductionPreflightDatabaseIdentityTest passed: {$assertions} assertions.\n"
);
