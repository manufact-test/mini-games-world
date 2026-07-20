<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$outputPath = '';
$maxEvents = 20;
foreach (array_slice($argv ?? [], 1) as $argument) {
    $argument = trim((string)$argument);
    if (str_starts_with($argument, '--output=')) {
        if ($outputPath !== '') {
            fwrite(STDERR, "--output may be specified only once.\n");
            exit(2);
        }
        $outputPath = trim(substr($argument, strlen('--output=')));
        continue;
    }
    if (str_starts_with($argument, '--max-events=')) {
        $raw = trim(substr($argument, strlen('--max-events=')));
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            fwrite(STDERR, "--max-events must be an integer between 1 and 100.\n");
            exit(2);
        }
        $maxEvents = (int)$raw;
        continue;
    }
    fwrite(STDERR, "Unknown selector evidence collector argument.\n");
    exit(2);
}
if ($outputPath === '') {
    fwrite(STDERR, "Selector-aware staging evidence collection requires --output=/absolute/private/path.json.\n");
    exit(2);
}
if ($maxEvents < 1 || $maxEvents > 100) {
    fwrite(STDERR, "--max-events must be between 1 and 100.\n");
    exit(2);
}

$projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceWriter.php';
$writer = new RuntimePrimaryStagingEvidenceWriter($projectRoot);
try {
    $validatedOutput = $writer->validateOutputPath($outputPath);
    $outputPath = (string)$validatedOutput['output_path'];
} catch (Throwable $error) {
    fwrite(STDERR, "Invalid private output path.\n");
    exit(2);
}

require $projectRoot . '/bot/core/bootstrap.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryPrivateConfigGuard.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionOutboxSchemaInstaller.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionOutboxWriter.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionBootstrap.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionWorker.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryRehearsalBackendInterface.php';
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
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceGate.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryJsonEvidence.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingConcurrencyProbe.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceSourceInterface.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceSource.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceCollector.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingSelectorEvidenceCollector.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceApproval.php';

$environment = strtolower(trim((string)($config['environment'] ?? '')));
if ($environment !== 'staging') {
    fwrite(STDERR, "Selector-aware DB-primary evidence collection is staging-only.\n");
    exit(2);
}

try {
    $private = RuntimePrimaryPrivateConfigGuard::assertExternal(
        (string)($configFile ?? ''),
        $projectRoot
    );
    $privateDir = (string)$private['private_dir'];
} catch (Throwable $error) {
    fwrite(STDERR, trim($error->getMessage()) . PHP_EOL);
    exit(2);
}

$databaseConfig = DatabaseConfig::fromApplicationConfig($config);
if (!$databaseConfig->enabled()) {
    fwrite(STDERR, "Selector-aware evidence collection requires an enabled staging database.\n");
    exit(2);
}
try {
    $currentCommit = RuntimePrimaryRepositoryCommitResolver::resolve($projectRoot);
    RuntimePrimaryStagingEvidenceApproval::fromConfig($config)->assertApproved(
        $databaseConfig,
        $currentCommit,
        time()
    );
} catch (Throwable $error) {
    fwrite(STDERR, trim($error->getMessage()) . PHP_EOL);
    exit(2);
}

$lockPath = $privateDir . '/runtime-primary-rehearsal.lock';
if (is_link($lockPath)) {
    fwrite(STDERR, "Staging evidence rehearsal lock must not be a symbolic link.\n");
    exit(2);
}
$lockHandle = fopen($lockPath, 'c+');
if (!is_resource($lockHandle)) {
    fwrite(STDERR, "Staging evidence rehearsal lock is unavailable.\n");
    exit(2);
}
@chmod($lockPath, 0600);
clearstatcache(true, $lockPath);
if (is_link($lockPath)) {
    fclose($lockHandle);
    fwrite(STDERR, "Staging evidence rehearsal lock became a symbolic link.\n");
    exit(2);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fclose($lockHandle);
    fwrite(STDERR, "Another DB-primary rehearsal or evidence collection is already running.\n");
    exit(2);
}

$outputWritten = false;
try {
    $database = PdoConnectionFactory::create($databaseConfig);
    $leaseDatabase = PdoConnectionFactory::create($databaseConfig);
    $jsonStorage = StorageFactory::createJson((string)($config['data_dir'] ?? ''));
    if ($jsonStorage->driver() !== 'json') {
        throw new RuntimeException('Selector evidence collection requires the JSON rollback driver.');
    }

    $backend = new RuntimePrimaryStagingRehearsalBackend(
        $config,
        $jsonStorage,
        $database
    );
    $rehearsal = new StagingPrimaryRehearsalOperation(
        $config,
        $backend,
        $maxEvents
    );
    $concurrency = new RuntimePrimaryStagingConcurrencyProbe(
        $database,
        $leaseDatabase,
        $privateDir . '/runtime-primary-cli-lock-probe.lock',
        120
    );
    $source = new RuntimePrimaryStagingEvidenceSource(
        $config,
        $projectRoot,
        $jsonStorage,
        $database,
        $rehearsal,
        $concurrency
    );
    $collected = (new RuntimePrimaryStagingSelectorEvidenceCollector(
        $projectRoot,
        $source
    ))->collect();
    $manifest = is_array($collected['manifest'] ?? null) ? $collected['manifest'] : [];
    if ($manifest === []) {
        throw new RuntimeException('Selector-aware evidence collector returned an empty manifest.');
    }

    $written = $writer->write($outputPath, $manifest);
    $outputWritten = true;
    $storedRaw = file_get_contents($outputPath);
    if (!is_string($storedRaw)) {
        throw new RuntimeException('Written selector-aware evidence is unreadable.');
    }
    $stored = json_decode($storedRaw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($stored) || array_is_list($stored)) {
        throw new RuntimeException('Written selector-aware evidence is not a JSON object.');
    }
    $verification = (new RuntimePrimaryStagingEvidenceV3Gate($projectRoot))->verify($stored);
    if (($verification['ok'] ?? false) !== true) {
        throw new RuntimeException(
            'Written selector-aware staging evidence failed verification: '
            . implode('; ', array_map('strval', (array)($verification['blockers'] ?? [])))
        );
    }

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'report_type' => 'mvp-14.8.6k-staging-selector-evidence-collection',
        'action' => 'selector_evidence_v3_collected',
        'manifest_version' => RuntimePrimaryStagingEvidenceV3Verifier::MANIFEST_VERSION,
        'repository_commit' => (string)($collected['repository_commit'] ?? ''),
        'database_identity_fingerprint' => (string)($collected['database_identity_fingerprint'] ?? ''),
        'state_revision' => (int)($collected['state_revision'] ?? 0),
        'state_sha256' => (string)($collected['state_sha256'] ?? ''),
        'projected_modules' => array_values((array)($collected['projected_modules'] ?? [])),
        'manifest_fingerprint' => (string)($verification['evidence_fingerprint'] ?? ''),
        'selector_evidence_fingerprint' => (string)($verification['selector_evidence_fingerprint'] ?? ''),
        'file_sha256' => (string)($written['file_sha256'] ?? ''),
        'bytes' => (int)($written['bytes'] ?? 0),
        'permissions' => (string)($written['permissions'] ?? ''),
        'publish_mode' => (string)($written['publish_mode'] ?? ''),
        'private_config_external' => true,
        'path_exposed' => false,
        'application_entrypoints_changed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    if ($outputWritten && is_file($outputPath) && !is_link($outputPath)) {
        @unlink($outputPath);
    }
    $message = preg_replace(
        "~/(?:home|var|tmp|srv)/[^\\s'\"]+~",
        '[private-path]',
        trim($error->getMessage())
    ) ?? trim($error->getMessage());
    $message = function_exists('mb_substr')
        ? mb_substr($message, 0, 500)
        : substr($message, 0, 500);
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'report_type' => 'mvp-14.8.6k-staging-selector-evidence-collection',
        'action' => 'selector_evidence_v3_blocked_or_failed',
        'error_class' => get_class($error),
        'error_message' => $message,
        'path_exposed' => false,
        'application_entrypoints_changed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
