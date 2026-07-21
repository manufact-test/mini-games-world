<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$values = [
    'report' => '',
    'commit' => '',
    'database' => '',
    'evidence' => '',
    'age' => '3600',
    'hooks' => '5',
    'filters' => '2',
];
$seen = [];
$prefixes = [
    '--report=' => 'report',
    '--expected-commit=' => 'commit',
    '--expected-database-identity=' => 'database',
    '--expected-evidence-fingerprint=' => 'evidence',
    '--max-age-seconds=' => 'age',
    '--expected-bootstrap-hooks=' => 'hooks',
    '--expected-bootstrap-filters=' => 'filters',
];

foreach (array_slice($argv ?? [], 1) as $argument) {
    $matchedName = '';
    $matchedPrefix = '';
    foreach ($prefixes as $prefix => $name) {
        if (!str_starts_with($argument, $prefix)) continue;
        $matchedName = $name;
        $matchedPrefix = $prefix;
        break;
    }
    if ($matchedName === '') {
        fwrite(STDERR, "Unknown read-only smoke evidence verifier argument.\n");
        exit(2);
    }
    if (isset($seen[$matchedName])) {
        fwrite(STDERR, "Verifier option may be specified only once: {$matchedPrefix}\n");
        exit(2);
    }
    $seen[$matchedName] = true;
    $value = substr($argument, strlen($matchedPrefix));
    if ($matchedName === 'report') {
        $value = str_replace('\\', '/', $value);
    }
    $values[$matchedName] = $value;
}

foreach (['report', 'commit', 'database', 'evidence'] as $required) {
    if (!isset($seen[$required]) || $values[$required] === '') {
        fwrite(STDERR, "Missing required read-only smoke evidence verifier option: {$required}.\n");
        exit(2);
    }
}

$reportFile = $values['report'];
$expectedCommit = $values['commit'];
$expectedDatabaseIdentity = $values['database'];
$expectedEvidenceFingerprint = $values['evidence'];

if (!str_starts_with($reportFile, '/')) {
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
foreach (['age', 'hooks', 'filters'] as $numeric) {
    if ($values[$numeric] === '' || preg_match('/^\d+$/', $values[$numeric]) !== 1) {
        fwrite(STDERR, "Numeric verifier options must be non-negative integers.\n");
        exit(2);
    }
}

$maximumAgeSeconds = (int)$values['age'];
$expectedHookCount = (int)$values['hooks'];
$expectedFilterCount = (int)$values['filters'];
if ($maximumAgeSeconds < 60 || $maximumAgeSeconds > 86_400) {
    fwrite(STDERR, "--max-age-seconds must be between 60 and 86400.\n");
    exit(2);
}
if ($expectedHookCount < 1 || $expectedHookCount > 32) {
    fwrite(STDERR, "--expected-bootstrap-hooks must be between 1 and 32.\n");
    exit(2);
}
if ($expectedFilterCount < 0 || $expectedFilterCount > 32) {
    fwrite(STDERR, "--expected-bootstrap-filters must be between 0 and 32.\n");
    exit(2);
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
