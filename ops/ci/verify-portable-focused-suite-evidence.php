<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$values = [
    'directory' => '',
    'commit' => '',
];
$seen = [];
$prefixes = [
    '--evidence-dir=' => 'directory',
    '--expected-commit=' => 'commit',
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
        fwrite(STDERR, "Unknown portable CI evidence verification argument.\n");
        exit(2);
    }
    if (isset($seen[$matchedName])) {
        fwrite(STDERR, "Portable CI verifier option may be specified only once: {$matchedPrefix}\n");
        exit(2);
    }
    $seen[$matchedName] = true;
    $value = substr($argument, strlen($matchedPrefix));
    if ($matchedName === 'directory') {
        $value = str_replace('\\', '/', $value);
    }
    $values[$matchedName] = $value;
}

foreach (['directory', 'commit'] as $required) {
    if (!isset($seen[$required]) || $values[$required] === '') {
        fwrite(STDERR, "Missing required portable CI evidence verifier option: {$required}.\n");
        exit(2);
    }
}

$evidenceDirectory = $values['directory'];
$expectedCommit = $values['commit'];
if (!str_starts_with($evidenceDirectory, '/')) {
    fwrite(STDERR, "Portable CI evidence verification requires --evidence-dir=/absolute/path.\n");
    exit(2);
}
if (preg_match('/^[a-f0-9]{40}$/', $expectedCommit) !== 1) {
    fwrite(STDERR, "Portable CI evidence verification requires --expected-commit=<40 lowercase hex>.\n");
    exit(2);
}

$projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
require_once $projectRoot . '/bot/runtime/RuntimePrimaryPortableCiEvidenceVerifier.php';

try {
    $report = (new RuntimePrimaryPortableCiEvidenceVerifier(
        $evidenceDirectory,
        $expectedCommit
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
        'action' => 'portable_ci_evidence_verification_failed',
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
