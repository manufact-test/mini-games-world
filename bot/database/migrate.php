<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/core/bootstrap.php';

$options = getopt('', ['status', 'dry-run', 'migrate', 'allow-production']);
$mode = array_key_exists('migrate', $options)
    ? 'migrate'
    : (array_key_exists('dry-run', $options) ? 'dry-run' : 'status');

try {
    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        throw new RuntimeException('Database is not enabled in the private configuration.');
    }

    $environment = strtolower(trim((string)($config['environment'] ?? 'production')));
    if ($mode === 'migrate' && $environment === 'production') {
        $explicitCliApproval = array_key_exists('allow-production', $options);
        $privateApproval = !empty($config['database_migrations_allow_production']);
        if (!$explicitCliApproval || !$privateApproval) {
            throw new RuntimeException('Production migrations require both private approval and --allow-production.');
        }
    }

    $connection = PdoConnectionFactory::create($databaseConfig);
    $runner = new MigrationRunner($connection, __DIR__ . '/migrations');

    $result = match ($mode) {
        'migrate' => $runner->migrate(false),
        'dry-run' => $runner->migrate(true),
        default => $runner->status(),
    };

    $result['mode'] = $mode;
    $result['environment'] = $environment;
    $result['database'] = $databaseConfig->safeSummary();

    fwrite(STDOUT, json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => $error->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}
