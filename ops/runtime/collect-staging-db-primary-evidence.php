<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$arguments = array_slice($argv ?? [], 1);
$outputPath = '';
$maxEvents = 20;
foreach ($arguments as $argument) {
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
        $raw = substr($argument, strlen('--max-events='));
        if (preg_match('/^\d+$/', $raw) !== 1) {
            fwrite(STDERR, "--max-events must be an integer between 1 and 100.\n");
            exit(2);
        }
        $maxEvents = (int)$raw;
        continue;
    }
    fwrite(STDERR, "Unknown evidence collector argument: {$argument}\n");
    exit(2);
}
if ($outputPath === '') {
    fwrite(STDERR, "Usage: php collect-staging-db-primary-evidence.php --output=/absolute/private/evidence.json [--max-events=20]\n");
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
    fwrite(STDERR, trim($error->getMessage()) . PHP_EOL);
    exit(2);
}

require $projectRoot . '/bot/core/bootstrap.php';
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
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceGate.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryJsonEvidence.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingConcurrencyProbe.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceSourceInterface.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceSource.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceCollector.php';

$environment = strtolower(trim((string)($config['environment'] ?? '')));
if ($environment !== 'staging') {
    fwrite(STDERR, "Automated DB-primary evidence collection is staging-only.\n");
    exit(2);
}
$databaseConfig = DatabaseConfig::fromApplicationConfig($config);
if (!$databaseConfig->enabled()) {
    fwrite(STDERR, "Automated DB-primary evidence collection requires an enabled database.\n");
    exit(2);
}

$privateDir = rtrim(str_replace('\\', '/', dirname((string)$configFile)), '/');
if ($privateDir === '' || !is_dir($privateDir)) {
    fwrite(STDERR, "Private config directory is unavailable.\n");
    exit(2);
}
$rehearsalLockPath = $privateDir . '/runtime-primary-rehearsal.lock';
if (is_link($rehearsalLockPath)) {
    fwrite(STDERR, "Rehearsal lock file must not be a symbolic link.\n");
    exit(2);
}
$lockHandle = fopen($rehearsalLockPath, 'c+');
if (!is_resource($lockHandle)) {
    fwrite(STDERR, "Rehearsal lock file is unavailable.\n");
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
    $secondDatabase = PdoConnectionFactory::create($databaseConfig);
    $jsonStorage = StorageFactory::createJson((string)($config['data_dir'] ?? ''));
    $backend = new RuntimePrimaryStagingRehearsalBackend(
        $config,
        $jsonStorage,
        $database
    );
    $operation = new StagingPrimaryRehearsalOperation($config, $backend, $maxEvents);
    $concurrency = new RuntimePrimaryStagingConcurrencyProbe(
        $database,
        $secondDatabase,
        $privateDir . '/runtime-primary-cli-lock-probe.lock',
        120
    );
    $source = new RuntimePrimaryStagingEvidenceSource(
        $config,
        $projectRoot,
        $jsonStorage,
        $database,
        $operation,
        $concurrency
    );
    $collected = (new RuntimePrimaryStagingEvidenceCollector(
        $projectRoot,
        $source
    ))->collect();
    $manifest = is_array($collected['manifest'] ?? null) ? $collected['manifest'] : [];
    if ($manifest === []) {
        throw new RuntimeException('Collected staging evidence manifest is empty.');
    }
    $written = $writer->write($outputPath, $manifest);
    $outputWritten = true;

    $storedRaw = file_get_contents($outputPath);
    if (!is_string($storedRaw)) {
        throw new RuntimeException('Written staging evidence manifest is unreadable.');
    }
    $storedManifest = json_decode($storedRaw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($storedManifest) || array_is_list($storedManifest)) {
        throw new RuntimeException('Written staging evidence manifest is invalid.');
    }
    $storedVerification = (new RuntimePrimaryStagingEvidenceGate($projectRoot))->verify($storedManifest);
    if (($storedVerification['ok'] ?? false) !== true) {
        throw new RuntimeException(
            'Written staging evidence failed verification: '
            . implode('; ', array_map('strval', (array)($storedVerification['blockers'] ?? [])))
        );
    }

    $report = [
        'ok' => true,
        'report_type' => 'mvp-14.8.6g-staging-evidence-collection',
        'action' => 'evidence_collected_and_verified',
        'repository_commit' => (string)($collected['repository_commit'] ?? ''),
        'state_revision' => (int)($collected['state_revision'] ?? 0),
        'state_sha256' => (string)($collected['state_sha256'] ?? ''),
        'projected_modules' => array_values((array)($collected['projected_modules'] ?? [])),
        'manifest_fingerprint' => (string)($storedVerification['evidence_fingerprint'] ?? ''),
        'file_sha256' => (string)($written['file_sha256'] ?? ''),
        'bytes' => (int)($written['bytes'] ?? 0),
        'permissions' => (string)($written['permissions'] ?? ''),
        'path_exposed' => false,
        'application_entrypoints_changed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ];
    fwrite(STDOUT, json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    if ($outputWritten && is_file($outputPath)) @unlink($outputPath);
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
        'report_type' => 'mvp-14.8.6g-staging-evidence-collection',
        'action' => 'evidence_collection_failed',
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
