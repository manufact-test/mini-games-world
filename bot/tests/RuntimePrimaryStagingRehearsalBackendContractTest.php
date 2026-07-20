<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$path = $projectRoot . '/bot/runtime/RuntimePrimaryStagingRehearsalBackend.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('Staging rehearsal backend source is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(
    str_contains($source, "if (!in_array(\$environment, ['local', 'staging'], true))")
        && str_contains($source, 'rehearsal backend is local/staging-only'),
    'Real backend must reject production at construction time'
);
$assertTrue(
    str_contains($source, 'new RuntimePrimaryStateSchemaInstaller($this->database)')
        && str_contains($source, 'new RuntimePrimaryProjectionOutboxSchemaInstaller($this->database)'),
    'Schema preparation must install and verify state and outbox on the same database'
);
$assertTrue(
    str_contains($source, '$this->jsonStorage->readOnly(')
        && !str_contains($source, '$this->jsonStorage->transaction('),
    'Rehearsal backend must never mutate the JSON rollback source'
);
$assertTrue(
    str_contains($source, 'new DatabasePrimaryStateStorageAdapter(')
        && str_contains($source, 'new RuntimePrimaryProjectionOutboxWriter()'),
    'Snapshot synchronization must atomically emit outbox events'
);
$assertTrue(
    str_contains($source, '$adapter->initializeFromSnapshot($snapshot)')
        && str_contains($source, '$adapter->transaction(static function (array &$state) use ($snapshot): void')
        && str_contains($source, '$state = $snapshot;'),
    'Backend must support first seed, idempotent seed and exact changed-snapshot revisions'
);
$assertTrue(
    str_contains($source, "if (!hash_equals(\$snapshotSha, (string)\$after['state_sha256']))")
        && str_contains($source, '$event = $this->eventStatus((int)$after[\'revision\']);')
        && str_contains($source, 'projection event is missing or mismatched'),
    'Snapshot synchronization must verify committed state and matching event fingerprints'
);
$assertTrue(
    str_contains($source, 'new RuntimePrimaryRepositoryProjectorFactory(')
        && str_contains($source, 'new RuntimePrimaryProjectionWorker(')
        && str_contains($source, '->runOnce();'),
    'Worker execution must use the strict all-module repository projector'
);
$assertTrue(
    str_contains($source, 'GROUP BY status ORDER BY status')
        && str_contains($source, 'MIN(state_revision) AS min_revision')
        && str_contains($source, 'MAX(state_revision) AS max_revision'),
    'Read-only status must expose aggregate outbox state without payloads'
);
$assertTrue(
    str_contains($source, "'snapshot_inventory' => \$this->snapshotInventory(\$snapshot)")
        && !str_contains($source, "'snapshot' => \$snapshot")
        && !str_contains($source, "'state_json' =>"),
    'Backend reports must expose only counts and fingerprints, never full state payloads'
);
$assertTrue(
    str_contains($source, "'production_changed' => false")
        && str_contains($source, "'sensitive_identifiers_exposed' => false"),
    'Every backend report must preserve the non-production and non-sensitive contract'
);

fwrite(STDOUT, "RuntimePrimaryStagingRehearsalBackendContractTest passed: {$assertions} assertions.\n");
