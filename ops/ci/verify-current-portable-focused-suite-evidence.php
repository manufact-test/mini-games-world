<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$values = [
    'evidence_dir' => '',
    'commit' => '',
    'age' => '604800',
];
$seen = [];
$prefixes = [
    '--evidence-dir=' => 'evidence_dir',
    '--expected-commit=' => 'commit',
    '--max-age-seconds=' => 'age',
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
        fwrite(STDERR, "Unknown current portable CI evidence verifier argument.\n");
        exit(2);
    }
    if (isset($seen[$matchedName])) {
        fwrite(STDERR, "Verifier option may be specified only once: {$matchedPrefix}\n");
        exit(2);
    }
    $seen[$matchedName] = true;
    $value = substr($argument, strlen($matchedPrefix));
    if ($matchedName === 'evidence_dir' && str_contains($value, '\\')) {
        fwrite(STDERR, "Evidence directory must not contain backslashes.\n");
        exit(2);
    }
    $values[$matchedName] = $value;
}

foreach (['evidence_dir', 'commit'] as $required) {
    if (!isset($seen[$required]) || $values[$required] === '') {
        fwrite(STDERR, "Missing required current portable CI verifier option: {$required}.\n");
        exit(2);
    }
}
if (!str_starts_with($values['evidence_dir'], '/')) {
    fwrite(STDERR, "Verifier requires --evidence-dir=/absolute/private/bundle.\n");
    exit(2);
}
if (preg_match('/^[a-f0-9]{40}$/', $values['commit']) !== 1) {
    fwrite(STDERR, "Verifier requires --expected-commit=<40 lowercase hex>.\n");
    exit(2);
}
if ($values['age'] === '' || preg_match('/^\d+$/', $values['age']) !== 1) {
    fwrite(STDERR, "--max-age-seconds must be a non-negative integer.\n");
    exit(2);
}
$maximumAgeSeconds = (int)$values['age'];
if ($maximumAgeSeconds < 60 || $maximumAgeSeconds > 604_800) {
    fwrite(STDERR, "--max-age-seconds must be between 60 and 604800.\n");
    exit(2);
}

$projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
require_once $projectRoot . '/bot/runtime/RuntimePrimaryCurrentPortableCiEvidenceVerifier.php';

set_error_handler(static function (int $severity): never {
    if (!(error_reporting() & $severity)) {
        throw new ErrorException('Suppressed current portable CI verifier warning.', 0, $severity);
    }
    throw new RuntimeException('Current portable CI evidence filesystem operation failed.');
});

$exitCode = 1;
$output = [];
try {
    $output = (new RuntimePrimaryCurrentPortableCiEvidenceVerifier(
        $values['evidence_dir'],
        $values['commit'],
        $maximumAgeSeconds
    ))->verify();
    $exitCode = 0;
} catch (Throwable $error) {
    $message = preg_replace(
        "~/(?:home|var|tmp|srv|opt)/[^\\s'\"]+~",
        '[private-path]',
        trim($error->getMessage())
    ) ?? trim($error->getMessage());
    $message = function_exists('mb_substr')
        ? mb_substr($message, 0, 500)
        : substr($message, 0, 500);
    $output = [
        'ok' => false,
        'action' => 'current_portable_ci_evidence_verification_failed',
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
    ];
} finally {
    restore_error_handler();
}

fwrite(STDOUT, json_encode(
    $output,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
) . PHP_EOL);
exit($exitCode);
