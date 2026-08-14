<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$checkpointCreator = file_get_contents($root . '/ops/runtime/create-mvp14r-safety-checkpoint.sh');
$checkpointVerifier = file_get_contents($root . '/ops/runtime/verify-mvp14r-safety-checkpoint.sh');
$rollbackExport = file_get_contents($root . '/ops/runtime/run-production-primary-rollback-export.php');
$health = file_get_contents($root . '/bot/health.php');
$latencyAcceptance = file_get_contents($root . '/ops/checks/mvp14r2-latency-acceptance-local.sh');
$jsonBackup = file_get_contents($root . '/ops/backup/README.md');

foreach ([
    'checkpoint creator' => $checkpointCreator,
    'checkpoint verifier' => $checkpointVerifier,
    'rollback export' => $rollbackExport,
    'health endpoint' => $health,
    'latency acceptance' => $latencyAcceptance,
    'legacy JSON backup documentation' => $jsonBackup,
] as $label => $source) {
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('MVP-14.9 closure source unavailable: ' . $label);
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(
    str_contains($checkpointCreator, '--single-transaction')
        && str_contains($checkpointCreator, 'mariadb-dump')
        && str_contains($checkpointCreator, 'mysqldump')
        && str_contains($checkpointCreator, 'database.sql'),
    'DB-primary backup owner must remain a single-transaction MariaDB/MySQL dump, not the legacy JSON snapshot.'
);

$assert(
    str_contains($checkpointVerifier, 'sha256sum -c checksums.sha256')
        && str_contains($checkpointVerifier, 'gzip -t "$CHECKPOINT_DIR/database.sql.gz"')
        && str_contains($checkpointVerifier, 'DATABASE_WRITE_EXECUTED=false'),
    'Database checkpoint verification must remain checksum-verified, compressed-dump verified and read-only.'
);

$assert(
    str_contains($rollbackExport, 'ProductionPrimaryRollbackExport')
        || str_contains($rollbackExport, 'rollback'),
    'The guarded DB-primary recovery export owner must remain available for recovery.'
);

$assert(
    str_contains($health, 'PdoConnectionFactory::create($databaseConfig)')
        && str_contains($health, "fetchValue('SELECT 1')")
        && str_contains($health, 'pending_migrations')
        && str_contains($health, 'schema_current'),
    'The canonical health endpoint must own database connectivity and schema-current monitoring.'
);

$assert(
    str_contains($latencyAcceptance, 'MGW_MVP14R2_LATENCY_ACCEPTANCE=PASSED')
        && str_contains($latencyAcceptance, 'guardrails')
        && str_contains($latencyAcceptance, 'production_evidence')
        && str_contains($latencyAcceptance, 'PRODUCTION_CHANGED=false'),
    'The bounded latency/load acceptance owner must remain explicit and production-safe.'
);

$assert(
    str_contains($jsonBackup, 'protects the current JSON storage before the database migration'),
    'Legacy ops/backup must stay explicitly classified as pre-DB JSON backup and must not silently become the DB backup owner.'
);

fwrite(STDOUT, "Mvp14DatabaseOperationsClosureTest: {$assertions} assertions passed\n");
