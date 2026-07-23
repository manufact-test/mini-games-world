<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

umask(0077);
ini_set('display_errors', '0');
set_error_handler(static function (int $severity): never {
    if (!(error_reporting() & $severity)) {
        throw new ErrorException('Suppressed rollback export warning.', 0, $severity);
    }
    throw new RuntimeException('Rollback export filesystem operation failed.');
});

const MGW_ROLLBACK_CONFIRMATION = 'EXPORT_DB_PRIMARY_TO_JSON_ROLLBACK';

$values = [
    'config' => '',
    'cutover' => '',
    'authorization' => '',
    'output' => '',
    'request_id' => '',
    'confirm' => '',
];
$prefixes = [
    '--config=' => 'config',
    '--cutover-state=' => 'cutover',
    '--authorization=' => 'authorization',
    '--output-root=' => 'output',
    '--request-id=' => 'request_id',
    '--confirm=' => 'confirm',
];
$seen = [];

foreach (array_slice($argv ?? [], 1) as $argument) {
    if (!is_string($argument)) {
        fwrite(STDERR, "Invalid rollback export argument.\n");
        exit(2);
    }
    $name = '';
    $prefix = '';
    foreach ($prefixes as $candidate => $candidateName) {
        if (!str_starts_with($argument, $candidate)) continue;
        $name = $candidateName;
        $prefix = $candidate;
        break;
    }
    if ($name === '') {
        fwrite(STDERR, "Unknown rollback export argument.\n");
        exit(2);
    }
    if (isset($seen[$name])) {
        fwrite(STDERR, "Rollback export option may be specified only once.\n");
        exit(2);
    }
    $seen[$name] = true;
    $value = substr($argument, strlen($prefix));
    if ($value === '' || str_contains($value, "\0")) {
        fwrite(STDERR, "Rollback export option value is empty or invalid.\n");
        exit(2);
    }
    if (in_array($name, ['config', 'cutover', 'authorization', 'output'], true)) {
        if (str_contains($value, '\\')
            || !str_starts_with($value, '/')
            || ($value !== '/' && str_ends_with($value, '/'))) {
            fwrite(STDERR, "Rollback export paths must be exact absolute Linux paths.\n");
            exit(2);
        }
    }
    $values[$name] = $value;
}

foreach (array_keys($values) as $required) {
    if (!isset($seen[$required])) {
        fwrite(STDERR, "Rollback export requires every explicit option.\n");
        exit(2);
    }
}
if (preg_match('/\A[a-f0-9]{32}\z/', $values['request_id']) !== 1) {
    fwrite(STDERR, "Rollback export request ID must be 32 lowercase hex characters.\n");
    exit(2);
}
if (!hash_equals(MGW_ROLLBACK_CONFIRMATION, $values['confirm'])) {
    fwrite(STDERR, "Rollback export confirmation phrase is invalid.\n");
    exit(2);
}

$projectRoot = realpath(dirname(__DIR__, 2));
if (!is_string($projectRoot) || !is_dir($projectRoot)) {
    fwrite(STDERR, "PRODUCTION_ROLLBACK_EXPORT=BLOCKED\n");
    fwrite(STDERR, "REASON=project_root_unavailable\n");
    exit(1);
}

require_once $projectRoot . '/bot/runtime/ProductionPrimaryRollbackExportBootstrap.php';

$gateReport = [];
try {
    $inputs = (new ProductionPrimaryRollbackExportInputLoader($projectRoot))->load(
        $values['config'],
        $values['cutover'],
        $values['authorization'],
        $values['output']
    );
    $config = $inputs['config'];
    $authorization = $inputs['authorization'];
    if (!is_array($config) || !is_array($authorization)) {
        throw new RuntimeException('Rollback export inputs are invalid.');
    }
    if (!hash_equals(
        $values['request_id'],
        strtolower(trim((string)($authorization['request_id'] ?? '')))
    )) {
        throw new RuntimeException('CLI request ID does not match authorization.');
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        throw new RuntimeException('Production rollback export database is not enabled.');
    }
    $databaseIdentity = $databaseConfig->identityFingerprint();

    $gateReport = (new ProductionPrimaryRollbackExportGate())->inspect(
        $config,
        (array)$inputs['cutover'],
        $authorization,
        $databaseIdentity,
        (string)$inputs['output_root_fingerprint'],
        time()
    );
    if (($gateReport['ready'] ?? false) !== true) {
        fwrite(STDERR, "PRODUCTION_ROLLBACK_EXPORT=BLOCKED\n");
        fwrite(STDERR, 'BLOCKER_COUNT=' . count((array)($gateReport['blockers'] ?? [])) . "\n");
        foreach ((array)($gateReport['blockers'] ?? []) as $index => $blocker) {
            fwrite(STDERR, 'BLOCKER_' . ($index + 1) . '=' . trim((string)$blocker) . "\n");
        }
        fwrite(STDERR, "DATABASE_CONTACTED=false\n");
        fwrite(STDERR, "DATABASE_WRITE_EXECUTED=false\n");
        fwrite(STDERR, "LIVE_JSON_CHANGED=false\n");
        fwrite(STDERR, "PERSISTENT_CONFIG_CHANGED=false\n");
        fwrite(STDERR, "WEBHOOK_CHANGED=false\n");
        fwrite(STDERR, "CRON_CHANGED=false\n");
        fwrite(STDERR, "PRODUCTION_CHANGED=false\n");
        exit(1);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    if ((int)$database->fetchValue('SELECT 1') !== 1) {
        throw new RuntimeException('Production rollback export database readiness failed.');
    }

    $auditor = (new ProductionPrimaryRollbackAuditorFactory(
        $config,
        $database,
        $gateReport
    ))->create();
    $verifier = new ProductionPrimaryRollbackExportVerifier();
    $result = (new ProductionPrimaryRollbackExportService(
        $database,
        $auditor,
        $verifier
    ))->export(
        $projectRoot,
        (string)$inputs['output_root'],
        $gateReport
    );

    printf("PRODUCTION_ROLLBACK_EXPORT=PASSED\n");
    printf("CONTRACT_VERSION=%s\n", ProductionPrimaryRollbackExportGate::CONTRACT_VERSION);
    printf("BACKUP_ID=%s\n", (string)($result['backup_id'] ?? ''));
    printf("REQUEST_ID=%s\n", (string)($result['request_id'] ?? ''));
    printf("STATE_REVISION=%d\n", (int)($result['state_revision'] ?? 0));
    printf("STATE_SHA256=%s\n", (string)($result['state_sha256'] ?? ''));
    printf("SNAPSHOT_SHA256=%s\n", (string)($result['snapshot_sha256'] ?? ''));
    printf("ALL_MODULE_FINGERPRINT=%s\n", (string)($result['all_module_fingerprint'] ?? ''));
    printf("DATA_FILES=%d\n", (int)($result['data_files'] ?? 0));
    printf("STATE_ROW_LOCKED=%s\n", ($result['state_row_locked'] ?? false) === true ? 'true' : 'false');
    printf("BACKUP_MANAGER_COMPATIBLE=%s\n", ($result['backup_manager_compatible'] ?? false) === true ? 'true' : 'false');
    printf("ISOLATED_RESTORE_REQUIRED=%s\n", ($result['isolated_restore_required'] ?? false) === true ? 'true' : 'false');
    printf("DATABASE_CONTACTED=true\n");
    printf("DATABASE_WRITE_EXECUTED=false\n");
    printf("LIVE_JSON_CHANGED=false\n");
    printf("PERSISTENT_CONFIG_CHANGED=false\n");
    printf("WEBHOOK_CHANGED=false\n");
    printf("CRON_CHANGED=false\n");
    printf("PRODUCTION_CHANGED=false\n");
    exit(0);
} catch (Throwable $error) {
    $fingerprint = hash('sha256', get_class($error) . '|' . $error->getMessage());
    fwrite(STDERR, "PRODUCTION_ROLLBACK_EXPORT=BLOCKED\n");
    fwrite(STDERR, "REASON=rollback_export_precondition_or_execution_failed\n");
    fwrite(STDERR, 'ERROR_FINGERPRINT=' . $fingerprint . "\n");
    fwrite(STDERR, 'DATABASE_CONTACTED=' . (($gateReport['ready'] ?? false) === true ? 'possible_read_only' : 'false') . "\n");
    fwrite(STDERR, "DATABASE_WRITE_EXECUTED=false\n");
    fwrite(STDERR, "LIVE_JSON_CHANGED=false\n");
    fwrite(STDERR, "PERSISTENT_CONFIG_CHANGED=false\n");
    fwrite(STDERR, "WEBHOOK_CHANGED=false\n");
    fwrite(STDERR, "CRON_CHANGED=false\n");
    fwrite(STDERR, "PRODUCTION_CHANGED=false\n");
    exit(1);
}
