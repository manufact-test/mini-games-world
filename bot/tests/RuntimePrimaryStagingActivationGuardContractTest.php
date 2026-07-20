<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$guardPath = $projectRoot . '/bot/runtime/RuntimePrimaryStagingActivationGuard.php';
$cliPath = $projectRoot . '/ops/runtime/inspect-staging-db-primary-activation.php';
$collectorPath = $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceCollector.php';
$v2Path = $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV2Verifier.php';

$guard = file_get_contents($guardPath);
$cli = file_get_contents($cliPath);
$collector = file_get_contents($collectorPath);
$v2 = file_get_contents($v2Path);
if (!is_string($guard) || !is_string($cli) || !is_string($collector) || !is_string($v2)) {
    throw new RuntimeException('Staging activation safety sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(
    str_contains($guard, "if (\$environment !== 'staging')")
        && str_contains($guard, 'DB-primary activation guard is staging-only.'),
    'Activation guard must reject every non-staging environment'
);
$assertTrue(
    str_contains($guard, "if (\$this->jsonStorage->driver() !== 'json')")
        && str_contains($guard, "if (\$this->database->driver() !== 'mysql')"),
    'Activation guard must require JSON rollback storage and MySQL/MariaDB'
);
$assertTrue(
    str_contains($guard, 'RuntimePrimaryPrivateConfigGuard::assertExternal(')
        && str_contains($guard, 'RuntimePrimaryStagingActivationConfig::fromApplicationConfig(')
        && str_contains($guard, 'RuntimePrimaryStagingActivationEvidenceLoader('),
    'Activation guard must require external private config, strict approval and private evidence loading'
);
$assertTrue(
    str_contains($guard, 'RuntimePrimaryStagingEvidenceV2Gate(')
        && str_contains($guard, 'database identity fingerprint')
        && str_contains($guard, 'evidence fingerprint does not match the approval'),
    'Activation guard must bind evidence v2 to exact DB identity and approval fingerprint'
);
$assertTrue(
    str_contains($guard, 'runtime_primary_projection_outbox.enabled to be boolean true')
        && str_contains($guard, 'RuntimePrimaryProjectionOutboxWriter::PROJECTION_VERSION'),
    'Activation guard must require the transactional outbox and exact projection version'
);
$assertTrue(
    str_contains($guard, 'RuntimePrimaryStagingSchemaInspector(')
        && str_contains($guard, 'Current staging schema does not match evidence:'),
    'Activation guard must compare read-only live schema fingerprints to evidence'
);
$assertTrue(
    str_contains($guard, 'DatabasePrimaryStateStorageAdapter($this->database))->status()')
        && str_contains($guard, 'contiguous completed revision chain')
        && str_contains($guard, 'clean completed evidenced revision'),
    'Activation guard must prove exact state and a clean contiguous completed outbox chain'
);
$assertTrue(
    str_contains($guard, '$this->projector->auditOnly(')
        && !str_contains($guard, '$this->projector->project(')
        && str_contains($guard, "'read_only_audit' => true"),
    'Activation guard must use audit-only parity and never invoke projector mutations'
);
$assertTrue(
    substr_count($guard, 'RuntimePrimaryJsonEvidence::capture($this->jsonStorage)') >= 2
        && substr_count($guard, 'DatabasePrimaryStateStorageAdapter($this->database))->status()') >= 2
        && substr_count($guard, '$this->targetEvent($targetRevision)') >= 2
        && substr_count($guard, '$this->queueStatus($targetRevision)') >= 2
        && str_contains($guard, "'drift_check_passed' => true"),
    'Activation guard must repeat JSON/state/event/queue checks after the all-module audit'
);
$assertTrue(
    !str_contains($guard, '->execute(')
        && !str_contains($guard, '->transaction(')
        && !str_contains($guard, 'StorageFactory::createDatabasePrimary('),
    'Activation guard must contain no direct mutation or storage-switch path'
);
$assertTrue(
    str_contains($collector, 'RuntimePrimaryStagingEvidenceV2Verifier::MANIFEST_VERSION')
        && str_contains($collector, "'identity_fingerprint' => \$databaseIdentity")
        && str_contains($collector, 'RuntimePrimaryStagingEvidenceV2Gate('),
    'Collector must emit and verify database-bound evidence v2'
);
$assertTrue(
    str_contains($v2, "public const MANIFEST_VERSION = 'v2-staging-db-primary-evidence'")
        && str_contains($v2, "'identity_fingerprint'")
        && str_contains($v2, 'database identity fingerprint must be SHA-256'),
    'Evidence v2 must require an exact SHA-256 database identity'
);
$assertTrue(
    str_contains($cli, 'count($argv ?? []) !== 1')
        && str_contains($cli, 'RuntimePrimaryStagingActivationGuard(')
        && str_contains($cli, '->assertReady()')
        && !str_contains($cli, '->execute(')
        && !str_contains($cli, '->transaction('),
    'Activation CLI must remain no-argument and read-only'
);
$assertTrue(
    !str_contains($guard, 'bot/api.php')
        && !str_contains($guard, 'WebhookHandler.php')
        && !str_contains($guard, 'crontab')
        && !str_contains($guard, 'production-cutover.php')
        && !str_contains($cli, 'bot/api.php')
        && !str_contains($cli, 'WebhookHandler.php')
        && !str_contains($cli, 'crontab')
        && !str_contains($cli, 'production-cutover.php'),
    'Activation readiness must not modify application entrypoints, Cron or production cutover'
);
$assertTrue(
    str_contains($guard, "'application_entrypoints_changed' => false")
        && str_contains($guard, "'cron_changed' => false")
        && str_contains($guard, "'production_changed' => false")
        && str_contains($guard, "'sensitive_identifiers_exposed' => false"),
    'Activation readiness report must preserve explicit safety flags'
);

fwrite(STDOUT, "RuntimePrimaryStagingActivationGuardContractTest passed: {$assertions} assertions.\n");
