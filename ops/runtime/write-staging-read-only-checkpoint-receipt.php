<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$values = [
    'report' => '',
    'output' => '',
    'expected_commit' => '',
    'expected_database_identity' => '',
    'expected_evidence_fingerprint' => '',
    'max_age' => '900',
];
$prefixes = [
    '--report=' => 'report',
    '--output=' => 'output',
    '--expected-commit=' => 'expected_commit',
    '--expected-database-identity=' => 'expected_database_identity',
    '--expected-evidence-fingerprint=' => 'expected_evidence_fingerprint',
    '--max-age-seconds=' => 'max_age',
];
$seen = [];
foreach (array_slice($argv ?? [], 1) as $argument) {
    $matchedPrefix = '';
    $matchedName = '';
    foreach ($prefixes as $prefix => $name) {
        if (!str_starts_with($argument, $prefix)) continue;
        $matchedPrefix = $prefix;
        $matchedName = $name;
        break;
    }
    if ($matchedName === '') {
        fwrite(STDERR, "Unknown read-only checkpoint receipt argument.\n");
        exit(2);
    }
    if (isset($seen[$matchedName])) {
        fwrite(STDERR, "Read-only checkpoint receipt option may be specified only once: {$matchedPrefix}\n");
        exit(2);
    }
    $seen[$matchedName] = true;
    $values[$matchedName] = substr($argument, strlen($matchedPrefix));
}
foreach ([
    'report', 'output', 'expected_commit',
    'expected_database_identity', 'expected_evidence_fingerprint',
] as $required) {
    if (!isset($seen[$required]) || $values[$required] === '') {
        fwrite(STDERR, "Missing required read-only checkpoint receipt option: {$required}.\n");
        exit(2);
    }
}
foreach (['report', 'output'] as $pathField) {
    $path = $values[$pathField];
    if (!str_starts_with($path, '/') || str_contains($path, '\\') || str_ends_with($path, '/')) {
        fwrite(STDERR, "Read-only checkpoint receipt path must be exact absolute Linux: {$pathField}.\n");
        exit(2);
    }
}
if (preg_match('/^[a-f0-9]{40}$/', $values['expected_commit']) !== 1) {
    fwrite(STDERR, "Read-only checkpoint receipt expected commit must be lowercase SHA-1.\n");
    exit(2);
}
foreach (['expected_database_identity', 'expected_evidence_fingerprint'] as $shaField) {
    if (preg_match('/^[a-f0-9]{64}$/', $values[$shaField]) !== 1) {
        fwrite(STDERR, "Read-only checkpoint receipt expected fingerprint is invalid: {$shaField}.\n");
        exit(2);
    }
}
if ($values['max_age'] === '' || preg_match('/^\d+$/', $values['max_age']) !== 1) {
    fwrite(STDERR, "Read-only checkpoint receipt maximum age must be an integer.\n");
    exit(2);
}
$maximumAge = (int)$values['max_age'];
if ($maximumAge < 60 || $maximumAge > 3600) {
    fwrite(STDERR, "Read-only checkpoint receipt maximum age must be between 60 and 3600 seconds.\n");
    exit(2);
}

umask(0077);
$projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifier.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingReadOnlyCheckpointReceipt.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceWriter.php';

try {
    $verified = (new RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifier(
        $values['report'],
        $values['expected_commit'],
        $values['expected_database_identity'],
        $values['expected_evidence_fingerprint'],
        $maximumAge
    ))->verify();
    $receipt = RuntimePrimaryStagingReadOnlyCheckpointReceipt::build(
        $verified,
        $values['report'],
        time()
    );
    $written = (new RuntimePrimaryStagingEvidenceWriter($projectRoot))->write(
        $values['output'],
        $receipt
    );
    if (($written['ok'] ?? false) !== true
        || ($written['written'] ?? false) !== true
        || ($written['permissions'] ?? '') !== '0600'
        || ($written['publish_mode'] ?? '') !== 'atomic_no_clobber_link') {
        throw new RuntimeException('Read-only checkpoint receipt publication proof is incomplete.');
    }
    $result = [
        'ok' => true,
        'action' => RuntimePrimaryStagingReadOnlyCheckpointReceipt::ACTION,
        'contract_version' => RuntimePrimaryStagingReadOnlyCheckpointReceipt::CONTRACT_VERSION,
        'repository_commit' => $verified['repository_commit'],
        'database_identity_fingerprint' => $verified['database_identity_fingerprint'],
        'evidence_fingerprint' => $verified['evidence_fingerprint'],
        'read_only_report_sha256' => $verified['report_sha256'],
        'state_revision' => $verified['state_revision'],
        'state_sha256' => $verified['state_sha256'],
        'receipt_file_sha256' => $written['file_sha256'],
        'receipt_permissions' => '0600',
        'mutating_smoke_authorized' => false,
        'persistent_config_changed' => false,
        'webhook_allowed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ];
    fwrite(STDOUT, json_encode(
        $result,
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
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
