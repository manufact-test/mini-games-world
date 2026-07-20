<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$session = file_get_contents($projectRoot . '/bot/runtime/RuntimePrimaryStagingRequestSessionConfig.php');
$finalizer = file_get_contents($projectRoot . '/bot/runtime/RuntimePrimaryStagingRequestFinalizer.php');
$readiness = file_get_contents($projectRoot . '/bot/runtime/RuntimePrimaryStagingRequestSessionReadiness.php');
if (!is_string($session) || !is_string($finalizer) || !is_string($readiness)) {
    throw new RuntimeException('Staging request finalizer sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(
    str_contains($session, "public const CONTRACT_VERSION = 'v1-api-only-bounded-request-session'")
        && str_contains($session, "if (\$entrypoint !== 'api')")
        && str_contains($session, 'supports only API')
        && str_contains($session, "'webhook_allowed' => false")
        && str_contains($session, "'production_allowed' => false"),
    'Request session must remain exact-contract API-only and forbid webhook/production'
);
$assertTrue(
    str_contains($session, 'max revision delta must be between 1 and 20')
        && str_contains($session, 'max worker ticks must cover the revision delta and be at most 20')
        && str_contains($session, 'expiry is more than 30 minutes away'),
    'Request session must bound revisions, worker ticks and time'
);
$assertTrue(
    str_contains($finalizer, 'RuntimePrimaryProjectionWorkerInterface')
        && str_contains($finalizer, 'count($ticks) >= $this->session->maxWorkerTicks()')
        && str_contains($finalizer, "'action' => 'projection_completed'")
        && str_contains($finalizer, 'completed an unexpected revision'),
    'Finalizer must process only bounded exact completed worker revisions'
);
$assertTrue(
    str_contains($finalizer, 'queueStatus($currentRevision)')
        && str_contains($finalizer, 'not a contiguous completed revision chain')
        && str_contains($finalizer, 'auditOnly($snapshot, $currentRevision, $currentSha)')
        && str_contains($finalizer, 'state changed during request finalization'),
    'Finalizer must prove contiguous queue, current snapshot parity and no audit drift'
);
$assertTrue(
    str_contains($readiness, 'RuntimePrimaryJsonEvidence::capture($this->jsonStorage)')
        && str_contains($readiness, 'assertEnabledForApi(')
        && str_contains($readiness, 'eventForRevision($baselineRevision)')
        && str_contains($readiness, 'eventForRevision($currentRevision)')
        && str_contains($readiness, 'auditOnly($snapshot, $currentRevision, $currentSha)'),
    'Dynamic readiness must bind unchanged JSON baseline and audit current completed DB state'
);
$assertTrue(
    str_contains($readiness, "'json_rollback_source_unchanged' => true")
        && str_contains($readiness, "'webhook_allowed' => false")
        && str_contains($readiness, "'production_changed' => false")
        && str_contains($finalizer, "'json_rollback_source_changed' => false")
        && str_contains($finalizer, "'webhook_allowed' => false")
        && str_contains($finalizer, "'production_changed' => false"),
    'Readiness and finalizer reports must preserve rollback, webhook and production safety boundaries'
);
$assertTrue(
    !str_contains($finalizer, 'JsonStorageAdapter')
        && !str_contains($finalizer, 'JsonDatabase')
        && !str_contains($finalizer, 'bot/api.php')
        && !str_contains($finalizer, 'webhook.php')
        && !str_contains($finalizer, 'crontab')
        && !str_contains($readiness, 'crontab')
        && !str_contains($readiness, 'production-cutover.php'),
    'Core finalizer layer must not mutate JSON or route entrypoints/Cron/production'
);

fwrite(STDOUT, "RuntimePrimaryStagingRequestFinalizerContractTest passed: {$assertions} assertions.\n");
