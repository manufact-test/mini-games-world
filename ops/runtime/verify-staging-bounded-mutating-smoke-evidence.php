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
    'expected_read_only_report' => '',
    'expected_receipt' => '',
    'expected_approval' => '',
    'max_age' => '1800',
];
$prefixes = [
    '--report=' => 'report',
    '--expected-commit=' => 'expected_commit',
    '--expected-database-identity=' => 'expected_database_identity',
    '--expected-read-only-report-sha256=' => 'expected_read_only_report',
    '--expected-receipt-sha256=' => 'expected_receipt',
    '--expected-approval-id=' => 'expected_approval',
    '--max-age-seconds=' => 'max_age',
];
$seen = [];
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
        fwrite(STDERR, "Unknown bounded mutating smoke verifier argument.\n");
        exit(2);
    }
    if (isset($seen[$matchedName])) {
        fwrite(STDERR, "Bounded mutating smoke verifier option may be specified only once: {$matchedPrefix}\n");
        exit(2);
    }
    $seen[$matchedName] = true;
    $values[$matchedName] = substr($argument, strlen($matchedPrefix));
}
foreach ([
    'report', 'expected_commit', 'expected_database_identity',
    'expected_read_only_report', 'expected_receipt', 'expected_approval',
] as $required) {
    if (!isset($seen[$required]) || $values[$required] === '') {
        fwrite(STDERR, "Missing bounded mutating smoke verifier option: {$required}.\n");
        exit(2);
    }
}
if (!str_starts_with($values['report'], '/')
    || str_contains($values['report'], '\\')
    || str_ends_with($values['report'], '/')) {
    fwrite(STDERR, "Bounded mutating smoke verifier report path must be exact absolute Linux.\n");
    exit(2);
}
if (preg_match('/^[a-f0-9]{40}$/', $values['expected_commit']) !== 1) {
    fwrite(STDERR, "Bounded mutating smoke verifier expected commit is invalid.\n");
    exit(2);
}
foreach ([
    'expected_database_identity', 'expected_read_only_report',
    'expected_receipt', 'expected_approval',
] as $field) {
    if (preg_match('/^[a-f0-9]{64}$/', $values[$field]) !== 1) {
        fwrite(STDERR, "Bounded mutating smoke verifier expected SHA is invalid: {$field}.\n");
        exit(2);
    }
}
if ($values['max_age'] === '' || preg_match('/^\d+$/', $values['max_age']) !== 1) {
    fwrite(STDERR, "Bounded mutating smoke verifier maximum age must be an integer.\n");
    exit(2);
}
$maximumAge = (int)$values['max_age'];
if ($maximumAge < 60 || $maximumAge > 3600) {
    fwrite(STDERR, "Bounded mutating smoke verifier maximum age is outside safe bounds.\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingMutatingSmokeApproval.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingBoundedMutatingSmokeEvidenceVerifier.php';

try {
    $report = (new RuntimePrimaryStagingBoundedMutatingSmokeEvidenceVerifier(
        $values['report'],
        $values['expected_commit'],
        $values['expected_database_identity'],
        $values['expected_read_only_report'],
        $values['expected_receipt'],
        $values['expected_approval'],
        $maximumAge
    ))->verify();
    fwrite(STDOUT, json_encode(
        $report,
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
    $response = [
        'ok' => false,
        'action' => 'staging_bounded_mutating_smoke_evidence_blocked_or_failed',
        'error_class' => get_class($error),
        'error_message' => $message,
        'rollback_verified' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ];
    fwrite(STDOUT, json_encode(
        $response,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
    exit(1);
}
