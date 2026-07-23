<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80400) {
    fwrite(STDERR, "Production live rollback requires PHP 8.3.x.\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/runtime/ProductionPrimaryLiveRollbackDependencies.php';

$fail = static function (string $message, int $code = 1): never {
    fwrite(STDERR, $message . "\n");
    exit($code);
};

$options = [
    'config' => null,
    'cutover' => null,
    'authorization' => null,
    'export' => null,
    'live-data' => null,
    'request-id' => null,
    'confirm' => null,
];
foreach (array_slice($argv, 1) as $argument) {
    if (!is_string($argument)
        || preg_match('/\A--([a-z-]+)=(.*)\z/s', $argument, $matches) !== 1) {
        $fail('Production live rollback arguments must use --name=value syntax.', 2);
    }
    $name = $matches[1];
    $value = $matches[2];
    if (!array_key_exists($name, $options)) {
        $fail('Unknown production live rollback option.', 2);
    }
    if ($options[$name] !== null) {
        $fail('Duplicate production live rollback option.', 2);
    }
    if ($value === '' || str_contains($value, "\0") || str_contains($value, '\\')) {
        $fail('Production live rollback option value is invalid.', 2);
    }
    $options[$name] = $value;
}
foreach ($options as $name => $value) {
    if (!is_string($value) || $value === '') {
        $fail('Missing required production live rollback option: ' . $name . '.', 2);
    }
}
if ($options['confirm'] !== ProductionPrimaryLiveRollbackGate::CONFIRMATION) {
    $fail('Production live rollback confirmation phrase is invalid.', 2);
}
if (preg_match('/\A[a-f0-9]{32}\z/', $options['request-id']) !== 1) {
    $fail('Production live rollback request ID is invalid.', 2);
}

try {
    $verifier = new ProductionPrimaryRollbackExportVerifier();
    $identity = new ProductionPrimaryRollbackArtifactIdentity($verifier);
    $loader = new ProductionPrimaryLiveRollbackInputLoader($projectRoot, $identity);
    $loaded = $loader->load(
        $options['config'],
        $options['cutover'],
        $options['authorization'],
        $options['export'],
        $options['live-data']
    );
    if (($loaded['authorization']['request_id'] ?? null) !== $options['request-id']) {
        throw new RuntimeException(
            'Production live rollback CLI request ID does not match authorization.'
        );
    }
    if (($loaded['authorization']['confirmation'] ?? null) !== $options['confirm']) {
        throw new RuntimeException(
            'Production live rollback CLI confirmation does not match authorization.'
        );
    }

    $result = (new ProductionPrimaryLiveRollbackBootstrap($projectRoot))->execute(
        $loaded,
        time()
    );

    $safe = [
        'ok' => ($result['ok'] ?? false) === true,
        'action' => (string)($result['action'] ?? ''),
        'request_id' => (string)($result['request_id'] ?? ''),
        'state_revision' => (int)($result['state_revision'] ?? 0),
        'state_sha256' => (string)($result['state_sha256'] ?? ''),
        'snapshot_sha256' => (string)($result['snapshot_sha256'] ?? ''),
        'database_route_enabled' => ($result['database_route_enabled'] ?? true) === true,
        'json_write_block_active' => ($result['json_write_block_active'] ?? true) === true,
        'maintenance_enabled' => ($result['maintenance_enabled'] ?? true) === true,
        'financial_read_only' => ($result['financial_read_only'] ?? true) === true,
        'previous_json_retained' => ($result['previous_json_retained'] ?? false) === true,
        'database_contacted' => ($result['database_contacted'] ?? false) === true,
        'database_write_executed' => ($result['database_write_executed'] ?? true) === true,
        'live_json_changed' => ($result['live_json_changed'] ?? false) === true,
        'persistent_config_changed' => ($result['persistent_config_changed'] ?? false) === true,
        'webhook_changed' => ($result['webhook_changed'] ?? true) === true,
        'cron_changed' => ($result['cron_changed'] ?? true) === true,
        'production_changed' => ($result['production_changed'] ?? false) === true,
        'resume_required' => ($result['resume_required'] ?? true) === true,
        'sensitive_identifiers_exposed' => false,
    ];
    fwrite(
        STDOUT,
        json_encode(
            $safe,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n"
    );
    exit($safe['ok'] ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, "Production live rollback failed.\n");
    fwrite(STDERR, 'ERROR_FINGERPRINT=' . hash('sha256', $error->getMessage()) . "\n");
    fwrite(STDERR, "RESUME_STATE_REQUIRED=true\n");
    exit(1);
}
