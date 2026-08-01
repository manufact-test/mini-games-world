<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/database/DatabaseFailureClassifier.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$connection = new PDOException('SQLSTATE[HY000] [2002] Connection refused');
$connection->errorInfo = ['HY000', 2002, 'Connection refused'];
$result = DatabaseFailureClassifier::classify(
    new RuntimeException('Database connection failed.', 0, $connection)
);
$assert(($result['category'] ?? '') === 'server_unreachable', 'Connection refusal must be classified safely.');
$assert(($result['sqlstate'] ?? '') === 'HY000', 'SQLSTATE must remain available for diagnosis.');
$assert(($result['driver_code'] ?? null) === 2002, 'Driver code must remain available for diagnosis.');

$credentials = new PDOException('SQLSTATE[28000] [1045] Access denied for user');
$credentials->errorInfo = ['28000', 1045, 'Access denied for user'];
$result = DatabaseFailureClassifier::classify(
    new RuntimeException('Database connection failed.', 0, $credentials)
);
$assert(($result['category'] ?? '') === 'credentials_rejected', 'Rejected credentials must have a distinct category.');

$missing = new PDOException('SQLSTATE[HY000] [1049] Unknown database');
$missing->errorInfo = ['HY000', 1049, 'Unknown database'];
$result = DatabaseFailureClassifier::classify($missing);
$assert(($result['category'] ?? '') === 'database_not_found', 'Missing database must have a distinct category.');

$health = file_get_contents($root . '/bot/health.php');
$classifier = file_get_contents($root . '/bot/database/DatabaseFailureClassifier.php');
$assert(is_string($health)
    && str_contains($health, "'failure' => DatabaseFailureClassifier::classify(\$databaseError)")
    && str_contains($health, "require_once __DIR__ . '/database/DatabaseFailureClassifier.php';"),
    'Health must publish the safe classifier result through its existing database check.');
$assert(is_string($classifier)
    && !str_contains($classifier, "'message' =>")
    && !str_contains($classifier, "'dsn' =>")
    && !str_contains($classifier, "'user' =>")
    && !str_contains($classifier, "'password' =>"),
    'Public diagnostics must never expose connection strings or credentials.');

fwrite(STDOUT, "ProductionDatabaseHealthDiagnosticsContractTest: {$assertions} assertions passed\n");
