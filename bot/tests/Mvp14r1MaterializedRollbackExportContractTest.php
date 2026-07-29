<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'bootstrap' => $root . '/bot/runtime/ProductionPrimaryRollbackExportBootstrap.php',
    'materializer' => $root . '/bot/runtime/ProductionPrimaryRollbackSnapshotMaterializer.php',
    'connection' => $root . '/bot/runtime/ProductionPrimaryRollbackMaterializedStateConnection.php',
    'service' => $root . '/bot/runtime/ProductionPrimaryRollbackMaterializedExportService.php',
    'cli' => $root . '/ops/runtime/run-production-primary-rollback-export.php',
];
$sources = [];
foreach ($files as $name => $path) {
    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        throw new RuntimeException('Materialized rollback source is unavailable: ' . $name);
    }
    $sources[$name] = $raw;
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

foreach ([
    'ProductionPrimaryRollbackSnapshotMaterializer.php',
    'ProductionPrimaryRollbackMaterializedStateConnection.php',
    'ProductionPrimaryRollbackMaterializedExportService.php',
] as $file) {
    $assertTrue(
        str_contains($sources['bootstrap'], $file),
        'Rollback export bootstrap must load ' . $file
    );
}

foreach ([
    "public const CONTRACT_VERSION = 'v1-normalized-accounts'",
    'mgw_account_ownership',
    'mgw_users',
    'mgw_identities',
    "'source_state_sha256'",
    "'materialized_state_sha256'",
    "'read_only' => true",
    "'database_write_executed' => false",
] as $required) {
    $assertTrue(
        str_contains($sources['materializer'], $required),
        'Account materializer is missing required read-only evidence: ' . $required
    );
}
$assertTrue(
    !str_contains($sources['materializer'], '->execute(')
        && !str_contains($sources['materializer'], 'INSERT INTO')
        && !str_contains($sources['materializer'], 'UPDATE mgw_')
        && !str_contains($sources['materializer'], 'DELETE FROM'),
    'Account materialization must remain SQL-read-only'
);

foreach ([
    'write-sealed',
    'source state changed before lock acquisition',
    'where singleton_id = 1 for update',
    'sourceLockVerified',
    'stateSubstitutionCount',
    'public function fetchValue(',
] as $required) {
    $assertTrue(
        str_contains($sources['connection'], $required),
        'Materialized state connection is missing guard: ' . $required
    );
}
$assertTrue(
    !str_contains($sources['connection'], '$this->database->execute('),
    'Write-sealed connection must never delegate execute calls'
);

foreach ([
    'ProductionPrimaryRollbackExportService',
    'ProductionPrimaryRollbackAuditorFactory',
    "'source_state_revision'",
    "'source_state_sha256'",
    "'materialized_state_sha256'",
    "'materialization_read_only' => true",
    "'artifact_materialization_metadata_verified' => true",
    'authorizationFingerprint',
    'enrichArtifact',
    'removeDirectory',
] as $required) {
    $assertTrue(
        str_contains($sources['service'], $required),
        'Materialized export orchestrator is missing required behavior: ' . $required
    );
}

$assertTrue(
    str_contains($sources['cli'], 'new ProductionPrimaryRollbackMaterializedExportService(')
        && !str_contains($sources['cli'], 'new ProductionPrimaryRollbackExportService('),
    'Production CLI must route through the materialized export orchestrator'
);
foreach ([
    'SOURCE_STATE_REVISION=',
    'SOURCE_STATE_SHA256=',
    'MATERIALIZED_STATE_SHA256=',
    'MATERIALIZATION_CONTRACT_VERSION=',
    'MATERIALIZATION_APPLIED=',
    'MATERIALIZED_USER_COUNT=',
    'MATERIALIZED_FIELD_COUNT=',
    'MATERIALIZATION_READ_ONLY=',
    'SOURCE_STATE_ROW_LOCKED=',
    'ARTIFACT_MATERIALIZATION_METADATA_VERIFIED=',
    'DATABASE_WRITE_EXECUTED=false',
    'LIVE_JSON_CHANGED=false',
    'PERSISTENT_CONFIG_CHANGED=false',
    'WEBHOOK_CHANGED=false',
    'CRON_CHANGED=false',
    'PRODUCTION_CHANGED=false',
] as $required) {
    $assertTrue(
        str_contains($sources['cli'], $required),
        'Production CLI is missing materialization marker: ' . $required
    );
}

fwrite(
    STDOUT,
    "Mvp14r1MaterializedRollbackExportContractTest passed: {$assertions} assertions.\n"
);
