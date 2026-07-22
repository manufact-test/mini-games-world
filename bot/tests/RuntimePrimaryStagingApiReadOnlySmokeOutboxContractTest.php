<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$smoke = file_get_contents(
    $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiReadOnlySmoke.php'
);
$worker = file_get_contents(
    $projectRoot . '/bot/runtime/RuntimePrimaryProjectionWorker.php'
);
$writer = file_get_contents(
    $projectRoot . '/bot/runtime/RuntimePrimaryProjectionOutboxWriter.php'
);
$projector = file_get_contents(
    $projectRoot . '/bot/runtime/RuntimePrimaryAllModuleProjector.php'
);
if (!is_string($smoke) || !is_string($worker)
    || !is_string($writer) || !is_string($projector)) {
    throw new RuntimeException('Read-only smoke outbox contract sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(
    str_contains(
        $projector,
        "public const CONTRACT_VERSION = 'v1-normalized-all-modules';"
    ) && str_contains(
        $writer,
        "public const PROJECTION_VERSION = 'v1-normalized-all-modules';"
    ),
    'Projector and outbox writer must share the exact normalized module contract'
);
$assertTrue(
    str_contains($worker, '$attemptCount = (int)$row[\'attempt_count\'] + 1;')
        && str_contains($worker, "attempt_count = :attempt_count")
        && str_contains($worker, "'attempt_count' => \$attemptCount"),
    'Worker claim must increment and persist attempt count before projection'
);
$assertTrue(
    str_contains($worker, "SET status = 'completed'")
        && str_contains($worker, "lease_token = ''")
        && str_contains($worker, "lease_expires_at_utc = ''")
        && str_contains($worker, "last_error = ''"),
    'Worker completion must clear lease and error fields'
);
$assertTrue(
    str_contains(
        $smoke,
        "(int)(\$row['attempt_count'] ?? 0) < 1"
    ) && str_contains(
        $smoke,
        '$projectionVersion !== RuntimePrimaryAllModuleProjector::CONTRACT_VERSION'
    ),
    'Read-only smoke must require a real worker attempt and current projector version'
);
$assertTrue(
    str_contains($smoke, "\$leaseToken !== ''")
        && str_contains($smoke, "\$leaseExpiresAt !== ''")
        && str_contains($smoke, "\$lastError !== ''")
        && str_contains($smoke, "'completed_events_lease_free' => true"),
    'Read-only smoke must reject completed events that retain lease or error state'
);
$assertTrue(
    str_contains(
        $smoke,
        "'projection_contract_version' => RuntimePrimaryAllModuleProjector::CONTRACT_VERSION"
    ),
    'Read-only smoke must expose the exact current projection contract'
);

fwrite(STDOUT, "RuntimePrimaryStagingApiReadOnlySmokeOutboxContractTest passed: {$assertions} assertions.\n");
