<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$reportFile = '';
$expectedCommit = '';
$expectedDatabaseIdentity = '';
$expectedEvidenceFingerprint = '';
$maximumAgeSeconds = 3600;
$expectedHookCount = 5;
$expectedFilterCount = 2;

foreach (array_slice($argv ?? [], 1) as $argument) {
    $matched = false;
    foreach ([
        '--report=' => 'report',
        '--expected-commit=' => 'commit',
        '--expected-database-identity=' => 'database',
        '--expected-evidence-fingerprint=' => 'evidence',
        '--max-age-seconds=' => 'age',
        '--expected-bootstrap-hooks=' => 'hooks',
        '--expected-bootstrap-filters=' => 'filters',
    ] as $prefix => $name) {
        if (!str_starts_with($argument, $prefix)) continue;
        $value = trim(substr($argument, strlen($prefix)));
        if ($name === 'report') {
            if ($reportFile !== '') {
                fwrite(STDERR, "--report may be specified only once.\n");
                exit(2);
            }
            $reportFile = str_replace('\\', '/', $value);
        } elseif ($name === 'commit') {
            if ($expectedCommit !== '') {
                fwrite(STDERR, "--expected-commit may be specified only once.\n");
                exit(2);
            }
            $expectedCommit = strtolower($value);
        } elseif ($name === 'database') {
            if ($expectedDatabaseIdentity !== '') {
                fwrite(STDERR, "--expected-database-identity may be specified only once.\n");
                exit(2);
            }
            $expectedDatabaseIdentity = strtolower($value);
        } elseif ($name === 'evidence') {
            if ($expectedEvidenceFingerprint !== '') {
                fwrite(STDERR, "--expected-evidence-fingerprint may be specified only once.\n");
                exit(2);
            }
            $expectedEvidenceFingerprint = strtolower($value);
        } else {
            if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
                fwrite(STDERR, "Numeric verifier options must be non-negative integers.\n");
                exit(2);
            }
            if ($name === 'age') $maximumAgeSeconds = (int)$value;
            elseif ($name === 'hooks') $expectedHookCount = (int)$value;
            elseif ($name === 'filters') $expectedFilterCount = (int)$value;
        }
        $matched = true;
        break;
    }
    if (!$matched) {
        fwrite(STDERR, "Unknown read-only smoke evidence verifier argument.\n");
        exit(2);
    }
}

if ($reportFile === '' || !str_starts_with($reportFile, '/')) {
    fwrite(STDERR, "Verifier requires --report=/absolute/private/report.json.\n");
    exit(2);
}
if (preg_match('/^[a-f0-9]{40}$/', $expectedCommit) !== 1) {
    fwrite(STDERR, "Verifier requires --expected-commit=<40 lowercase hex>.\n");
    exit(2);
}
foreach ([
    '--expected-database-identity' => $expectedDatabaseIdentity,
    '--expected-evidence-fingerprint' => $expectedEvidenceFingerprint,
] as $label => $value) {
    if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
        fwrite(STDERR, "Verifier requires {$label}=<64 lowercase hex>.\n");
        exit(2);
    }
}

$projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifier.php';

try {
    $report = (new RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifier(
        $reportFile,
        $expectedCommit,
        $expectedDatabaseIdentity,
        $expectedEvidenceFingerprint,
        $maximumAgeSeconds,
        $expectedHookCount,
        $expectedFilterCount
    ))->verify();
    fwrite(STDOUT, json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    $message = preg_replace(
        "~/(?:home|var|tmp|srv|opt)/[^\\s'\"]+~",
        '[private-path]',
        trim($error->getMessage())
    ) ?? trim($error->getMessage());
    $message = function_exists('mb_substr')
        ? mb_substr($message, 0, 500)
        : substr($message, 0, 500);
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'action' => 'staging_api_read_only_smoke_evidence_verification_failed',
        'error_class' => get_class($error),
        'error_message' => $message,
        'live_database_contacted' => false,
        'private_config_required' => false,
        'application_entrypoints_changed' => false,
        'cron_changed' => false,
        'deployment_performed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'verified_at_utc' => gmdate(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
