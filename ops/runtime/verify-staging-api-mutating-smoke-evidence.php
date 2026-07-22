<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$values = [
    'report' => '',
    'expected_commit' => '',
    'expected_database_identity' => '',
    'expected_receipt' => '',
    'expected_rollback_report' => '',
    'expected_lifecycle_evidence' => '',
    'max_age' => '1800',
];
$prefixes = [
    '--report=' => 'report',
    '--expected-commit=' => 'expected_commit',
    '--expected-database-identity=' => 'expected_database_identity',
    '--expected-receipt-sha256=' => 'expected_receipt',
    '--expected-rollback-report-sha256=' => 'expected_rollback_report',
    '--expected-lifecycle-evidence-fingerprint=' => 'expected_lifecycle_evidence',
    '--max-age-seconds=' => 'max_age',
];
$seen = [];
foreach (array_slice($argv ?? [], 1) as $argument) {
    $matched = '';
    $prefixMatched = '';
    foreach ($prefixes as $prefix => $name) {
        if (!str_starts_with($argument, $prefix)) continue;
        $matched = $name;
        $prefixMatched = $prefix;
        break;
    }
    if ($matched === '') {
        fwrite(STDERR, "Unknown staging API mutating smoke verifier argument.\n");
        exit(2);
    }
    if (isset($seen[$matched])) {
        fwrite(STDERR, "Staging API mutating smoke verifier option may be specified only once: {$prefixMatched}\n");
        exit(2);
    }
    $seen[$matched] = true;
    $values[$matched] = substr($argument, strlen($prefixMatched));
}
foreach ([
    'report', 'expected_commit', 'expected_database_identity', 'expected_receipt',
    'expected_rollback_report', 'expected_lifecycle_evidence',
] as $required) {
    if (!isset($seen[$required]) || $values[$required] === '') {
        fwrite(STDERR, "Missing staging API mutating smoke verifier option: {$required}.\n");
        exit(2);
    }
}
if (!str_starts_with($values['report'], '/') || str_contains($values['report'], '\\')
    || str_ends_with($values['report'], '/')) {
    fwrite(STDERR, "Staging API mutating smoke verifier report path must be exact absolute Linux.\n");
    exit(2);
}
if (preg_match('/\A[a-f0-9]{40}\z/', $values['expected_commit']) !== 1) {
    fwrite(STDERR, "Staging API mutating smoke verifier expected commit is invalid.\n");
    exit(2);
}
foreach ([
    'expected_database_identity', 'expected_receipt', 'expected_rollback_report',
    'expected_lifecycle_evidence',
] as $field) {
    if (preg_match('/\A[a-f0-9]{64}\z/', $values[$field]) !== 1) {
        fwrite(STDERR, "Staging API mutating smoke verifier expected SHA is invalid: {$field}.\n");
        exit(2);
    }
}
if ($values['max_age'] === '' || preg_match('/\A\d+\z/', $values['max_age']) !== 1) {
    fwrite(STDERR, "Staging API mutating smoke verifier maximum age must be an integer.\n");
    exit(2);
}
$maximumAge = (int)$values['max_age'];
if ($maximumAge < 60 || $maximumAge > 3600) {
    fwrite(STDERR, "Staging API mutating smoke verifier maximum age is outside safe bounds.\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiMutatingSmokeEvidenceVerifier.php';

try {
    $verified = (new RuntimePrimaryStagingApiMutatingSmokeEvidenceVerifier(
        $values['report'],
        $values['expected_commit'],
        $values['expected_database_identity'],
        $values['expected_receipt'],
        $values['expected_rollback_report'],
        $values['expected_lifecycle_evidence'],
        $maximumAge
    ))->verify();
    fwrite(STDOUT, json_encode(
        $verified,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    $message = preg_replace(
        "~/(?:home|var|tmp|srv|opt)/[^\\s'\"]+~",
        '[private-path]',
        trim($error->getMessage())
    ) ?? trim($error->getMessage());
    $message = function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500);
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'action' => 'staging_api_mutating_smoke_evidence_blocked_or_failed',
        'error_class' => get_class($error),
        'error_message' => $message,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(1);
}
