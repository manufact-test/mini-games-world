<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/core/bootstrap.php';

try {
    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    $fingerprint = $databaseConfig->identityFingerprint();
    if ($fingerprint === '') {
        throw new RuntimeException('Database identity is not configured in the private config.');
    }

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'database_identity_sha256' => $fingerprint,
        'database' => $databaseConfig->safeSummary(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => $error->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}
