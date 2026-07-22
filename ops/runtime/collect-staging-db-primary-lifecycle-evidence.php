<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$outputPath = '';
$maxEvents = 20;
$seenOutput = false;
$seenMaxEvents = false;
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (str_starts_with($argument, '--output=')) {
        if ($seenOutput) {
            fwrite(STDERR, "--output may be specified only once.\n");
            exit(2);
        }
        $seenOutput = true;
        $outputPath = substr($argument, strlen('--output='));
        if (str_contains($outputPath, '\\')) {
            fwrite(STDERR, "--output must not contain backslashes.\n");
            exit(2);
        }
        continue;
    }
    if (str_starts_with($argument, '--max-events=')) {
        if ($seenMaxEvents) {
            fwrite(STDERR, "--max-events may be specified only once.\n");
            exit(2);
        }
        $seenMaxEvents = true;
        $raw = substr($argument, strlen('--max-events='));
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            fwrite(STDERR, "--max-events must be an integer.\n");
            exit(2);
        }
        $maxEvents = (int)$raw;
        continue;
    }
    fwrite(STDERR, "Unknown lifecycle evidence collector argument.\n");
    exit(2);
}
if (!$seenOutput || $outputPath === '') {
    fwrite(STDERR, "Lifecycle evidence collection requires --output=/absolute/private/path.json.\n");
    exit(2);
}
if ($maxEvents < 1 || $maxEvents > 100) {
    fwrite(STDERR, "--max-events must be between 1 and 100.\n");
    exit(2);
}

set_error_handler(static function (int $severity): never {
    if (!(error_reporting() & $severity)) {
        throw new ErrorException('Suppressed lifecycle evidence collector warning.', 0, $severity);
    }
    throw new RuntimeException('Lifecycle evidence filesystem operation failed.');
});

$lockHandle = null;
$outputWritten = false;
$exitCode = 1;
$response = [];
try {
    $projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceWriter.php';
    $writer = new RuntimePrimaryStagingEvidenceWriter($projectRoot);
    $writer->validateOutputPath($outputPath);

    require $projectRoot . '/bot/core/bootstrap.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingRehearsalBackendInterface.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingRehearsalBackend.php';
    require_once $projectRoot . '/bot/runtime/StagingPrimaryRehearsalOperation.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryEntrypointEvidence.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryRepositoryCommitResolver.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceVerifier.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV2Verifier.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV2Gate.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointSelectorConfig.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingSelectorEvidence.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV3Verifier.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV3Gate.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionWorkerInterface.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionAuditorInterface.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionBootstrap.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionWorkerAdapter.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionAuditorAdapter.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingRequestSessionConfig.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingRequestFinalizer.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingRequestSessionReadiness.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiRequestFinalizationHook.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiSessionCoordinator.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingRequestLifecycleEvidence.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV4Verifier.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV4Gate.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceGate.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryJsonEvidence.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingConcurrencyProbe.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceApproval.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryPrivateConfigGuard.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceSourceInterface.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceSource.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceCollector.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingSelectorEvidenceCollector.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingLifecycleEvidenceCollector.php';

    $environment = strtolower(trim((string)($config['environment'] ?? '')));
    if ($environment !== 'staging') {
        throw new RuntimeException('DB-primary lifecycle evidence collection is staging-only.');
    }

    $private = RuntimePrimaryPrivateConfigGuard::assertExternal(
        (string)($configFile ?? ''),
        $projectRoot
    );
    $privateDir = (string)$private['private_dir'];
    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        throw new RuntimeException('Lifecycle evidence collection requires an enabled staging database.');
    }
    $currentCommit = RuntimePrimaryRepositoryCommitResolver::resolve($projectRoot);
    RuntimePrimaryStagingEvidenceApproval::fromConfig($config)->assertApproved(
        $databaseConfig,
        $currentCommit,
        time()
    );

    $lockPath = $privateDir . '/runtime-primary-rehearsal.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('Staging evidence rehearsal lock must not be a symbolic link.');
    }
    $lockHandle = fopen($lockPath, 'c+');
    if (!is_resource($lockHandle)) {
        throw new RuntimeException('Staging evidence rehearsal lock is unavailable.');
    }
    if (!chmod($lockPath, 0600)) {
        throw new RuntimeException('Staging evidence rehearsal lock permissions could not be secured.');
    }
    clearstatcache(true, $lockPath);
    $lockMode = fileperms($lockPath);
    if (is_link($lockPath)
        || !is_int($lockMode)
        || ($lockMode & 0777) !== 0600) {
        throw new RuntimeException('Staging evidence rehearsal lock must have exact mode 0600.');
    }
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another DB-primary rehearsal or evidence collection is already running.');
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $leaseDatabase = PdoConnectionFactory::create($databaseConfig);
    $jsonStorage = StorageFactory::createJson((string)($config['data_dir'] ?? ''));
    if ($jsonStorage->driver() !== 'json') {
        throw new RuntimeException('Lifecycle evidence collection requires the JSON rollback driver.');
    }

    $backend = new RuntimePrimaryStagingRehearsalBackend(
        $projectRoot,
        $config,
        (string)($configFile ?? ''),
        $database,
        $jsonStorage
    );
    $rehearsal = new StagingPrimaryRehearsalOperation($backend);
    $concurrency = new RuntimePrimaryStagingConcurrencyProbe(
        $database,
        $leaseDatabase,
        $privateDir . '/runtime-primary-cli-lock-probe.lock'
    );
    $source = new RuntimePrimaryStagingEvidenceSource(
        $projectRoot,
        $jsonStorage,
        $database,
        $rehearsal,
        $concurrency,
        $maxEvents
    );
    $collected = (new RuntimePrimaryStagingLifecycleEvidenceCollector(
        $projectRoot,
        $source
    ))->collect();
    $manifest = is_array($collected['manifest'] ?? null)
        ? $collected['manifest']
        : [];
    if ($manifest === []) {
        throw new RuntimeException('Lifecycle evidence collector returned an empty manifest.');
    }

    $written = $writer->write($outputPath, $manifest);
    $outputWritten = true;
    $storedRaw = file_get_contents($outputPath);
    if (!is_string($storedRaw)) {
        throw new RuntimeException('Written lifecycle evidence could not be read.');
    }
    $stored = json_decode(
        $storedRaw,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (!is_array($stored) || array_is_list($stored)) {
        throw new RuntimeException('Written lifecycle evidence is not a JSON object.');
    }
    $verification = (new RuntimePrimaryStagingEvidenceV4Gate(
        $projectRoot
    ))->verify($stored);
    if (($verification['ok'] ?? false) !== true) {
        throw new RuntimeException(
            'Written lifecycle evidence failed verification: '
            . implode('; ', array_map('strval', (array)($verification['blockers'] ?? [])))
        );
    }

    $response = [
        'ok' => true,
        'report_type' => 'mvp-14.8.6m-staging-lifecycle-evidence-collection',
        'action' => 'api_lifecycle_evidence_v4_collected',
        'manifest_version' => RuntimePrimaryStagingEvidenceV4Verifier::MANIFEST_VERSION,
        'repository_commit' => (string)($collected['repository_commit'] ?? ''),
        'database_identity_fingerprint' => (string)(
            $collected['database_identity_fingerprint'] ?? ''
        ),
        'baseline_state_revision' => (int)(
            $collected['baseline_state_revision'] ?? 0
        ),
        'baseline_state_sha256' => (string)(
            $collected['baseline_state_sha256'] ?? ''
        ),
        'manifest_fingerprint' => (string)(
            $verification['evidence_fingerprint'] ?? ''
        ),
        'selector_evidence_fingerprint' => (string)(
            $verification['selector_evidence_fingerprint'] ?? ''
        ),
        'request_session_evidence_fingerprint' => (string)(
            $verification['request_session_evidence_fingerprint'] ?? ''
        ),
        'projected_modules' => array_values((array)(
            $collected['projected_modules'] ?? []
        )),
        'file_sha256' => (string)($written['file_sha256'] ?? ''),
        'bytes' => (int)($written['bytes'] ?? 0),
        'permissions' => (string)($written['permissions'] ?? ''),
        'publish_mode' => (string)($written['publish_mode'] ?? ''),
        'session_enabled_by_evidence' => false,
        'finalizer_registered_by_evidence' => false,
        'private_config_external' => true,
        'path_exposed' => false,
        'application_entrypoints_changed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ];
    $exitCode = 0;
} catch (Throwable $error) {
    $cleanupFailed = false;
    if ($outputWritten && is_file($outputPath) && !is_link($outputPath)) {
        try {
            if (!unlink($outputPath)) {
                $cleanupFailed = true;
            }
        } catch (Throwable) {
            $cleanupFailed = true;
        }
    }
    $message = preg_replace(
        "~/(?:home|var|tmp|srv|opt)/[^\\s'\"]+~",
        '[private-path]',
        trim($error->getMessage())
    ) ?? trim($error->getMessage());
    if ($cleanupFailed) {
        $message = 'Lifecycle evidence failed and unverified output cleanup also failed.';
    }
    $message = function_exists('mb_substr')
        ? mb_substr($message, 0, 500)
        : substr($message, 0, 500);
    $response = [
        'ok' => false,
        'report_type' => 'mvp-14.8.6m-staging-lifecycle-evidence-collection',
        'action' => 'api_lifecycle_evidence_v4_blocked_or_failed',
        'error_class' => get_class($error),
        'error_message' => $message,
        'path_exposed' => false,
        'session_enabled_by_evidence' => false,
        'finalizer_registered_by_evidence' => false,
        'application_entrypoints_changed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ];
} finally {
    if (is_resource($lockHandle)) {
        try {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        } catch (Throwable) {
            // Process exit is the final lock-release fallback; never expose the private lock path.
        }
    }
    restore_error_handler();
}

fwrite(STDOUT, json_encode(
    $response,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
) . PHP_EOL);
exit($exitCode);
