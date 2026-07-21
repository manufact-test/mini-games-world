<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$smoke = file_get_contents(
    $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiReadOnlySmoke.php'
);
$overlay = file_get_contents(
    $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiReadOnlySmokeConfigOverlay.php'
);
$cli = file_get_contents(
    $projectRoot . '/ops/runtime/run-staging-db-primary-api-read-only-smoke.php'
);
if (!is_string($smoke) || !is_string($overlay) || !is_string($cli)) {
    throw new RuntimeException('Read-only API smoke sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(
    str_contains($smoke, '$this->storage->status()')
        && str_contains($smoke, '$this->storage->readOnly(')
        && str_contains($smoke, '$this->database->fetchAll(')
        && !str_contains($smoke, '$this->storage->transaction(')
        && !str_contains($smoke, '$this->database->execute('),
    'Smoke operation must use only status, readOnly and fetchAll'
);
$assertTrue(
    str_contains($smoke, 'RuntimePrimaryStagingEvidenceV4Verifier::MANIFEST_VERSION')
        && str_contains($smoke, 'RuntimePrimaryStagingSelectorEvidence::CONTRACT_VERSION')
        && str_contains($smoke, 'RuntimePrimaryStagingRequestSessionConfig::CONTRACT_VERSION')
        && str_contains($smoke, "'request_session_evidence_fingerprint'")
        && str_contains($smoke, "'database_identity_fingerprint'"),
    'Smoke operation must require exact lifecycle v4, selector/session contracts and fingerprints'
);
$assertTrue(
    str_contains($smoke, 'RuntimePrimaryEntrypointBridgeGuard::legacyJsonBridgeAllowed() !== false')
        && substr_count($smoke, 'legacyJsonBridgeAllowed() !== false') === 2
        && str_contains($smoke, 'lost legacy bridge suppression after finalization'),
    'Smoke operation must verify legacy bridge suppression before and after finalization'
);
$assertTrue(
    str_contains($smoke, "(int)\$context['state_revision'] !== (int)\$before['state_revision']")
        && str_contains($smoke, 'context no longer matches current DB-primary state')
        && str_contains($smoke, "'context_state_matched' => true")
        && str_contains($smoke, "'lifecycle_v4_verified' => true"),
    'Smoke operation must bind immutable request context to exact current state'
);
$assertTrue(
    str_contains($smoke, "(int)(\$finalization['worker_tick_count'] ?? -1) !== 0")
        && str_contains($smoke, "(int)(\$finalization['final_state_revision'] ?? 0)")
        && str_contains($smoke, "\$before !== \$after")
        && str_contains($smoke, 'changed DB-primary state or outbox'),
    'Smoke operation must require zero worker ticks and exact before/after equality'
);
$assertTrue(
    str_contains($smoke, 'unset($GLOBALS[\'mgw_api_db_primary_finalization_report\'])')
        && str_contains($smoke, 'finalizer as the first success hook')
        && str_contains($smoke, 'data filters changed the sentinel payload'),
    'Smoke operation must reject stale reports, wrong hook order and filter mutation'
);
$assertTrue(
    str_contains($overlay, 'requires JSON as the persistent default storage driver')
        && str_contains($overlay, 'JSON rollback data directory is unavailable or unsafe')
        && str_contains($overlay, '!str_starts_with($rawDataDir, \'/\')')
        && str_contains($overlay, '!hash_equals($canonicalDataDir, $rawDataDir)')
        && str_contains($overlay, 'must be canonical and symlink-free')
        && str_contains($overlay, 'must be outside the checkout')
        && str_contains($overlay, "\$overlay['storage_driver'] = 'json';")
        && str_contains($overlay, "'json_default_verified' => true")
        && str_contains($overlay, "'rollback_data_dir_external' => true")
        && str_contains($overlay, "'rollback_data_dir_canonical' => true"),
    'Overlay must require an absolute canonical symlink-free external JSON rollback source'
);
$assertTrue(
    str_contains($overlay, 'persistent selector latch to be disabled')
        && str_contains($overlay, 'persistent request-session latch to be disabled')
        && str_contains($overlay, 'persistent activation approval to be disabled')
        && str_contains($overlay, 'RuntimePrimaryStagingEvidenceV4Gate('),
    'Overlay must start from disabled persistent latches and exact evidence v4'
);
$assertTrue(
    str_contains($overlay, "'max_revision_delta' => 1")
        && str_contains($overlay, "'max_worker_ticks' => 1")
        && str_contains($overlay, "'allowed_entrypoints' => ['api']")
        && str_contains($overlay, "'persistent_config_changed' => false"),
    'Overlay must use an API-only one-revision in-memory session'
);
$assertTrue(
    !str_contains($overlay, 'file_put_contents(')
        && !str_contains($overlay, 'rename(')
        && !str_contains($overlay, 'copy(')
        && !str_contains($smoke, 'file_put_contents(')
        && !str_contains($smoke, 'rename('),
    'Smoke and overlay must not write persistent files'
);
$assertTrue(
    str_contains($cli, "\$_SERVER['SCRIPT_FILENAME'] = \$projectRoot . '/bot/api.php';")
        && str_contains($cli, 'StorageFactory::createJson(')
        && str_contains($cli, 'RuntimePrimaryStagingApiReadOnlySmoke(')
        && str_contains($cli, "'json_default_verified'")
        && str_contains($cli, "'rollback_data_dir_external'")
        && !str_contains($cli, "require \$projectRoot . '/bot/api.php'"),
    'CLI must exercise lazy API routing and expose rollback-source proof without executing HTTP route'
);
$assertTrue(
    !str_contains($smoke, 'WebhookHandler')
        && !str_contains($overlay, 'WebhookHandler')
        && !str_contains($cli, 'WebhookHandler')
        && !str_contains($smoke, 'production-cutover.php')
        && !str_contains($cli, 'production-cutover.php')
        && !str_contains($cli, 'crontab'),
    'Read-only smoke layer must not touch webhook, production cutover or Cron'
);

fwrite(STDOUT, "RuntimePrimaryStagingApiReadOnlySmokeContractTest passed: {$assertions} assertions.\n");
