<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$path = $projectRoot . '/ops/runtime/verify-staging-db-primary-evidence.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('Staging evidence verifier CLI source is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$cliGuard = strpos($source, "if (PHP_SAPI !== 'cli')");
$pathRead = strpos($source, '$requestedPath =');
$assertTrue(
    $cliGuard !== false && $pathRead !== false && $cliGuard < $pathRead,
    'CLI-only guard must run before manifest path handling'
);
$assertTrue(
    str_contains($source, "count(\$arguments) !== 1")
        && str_contains($source, "str_starts_with((string)\$arguments[0], '--verify=')"),
    'Verifier CLI must accept exactly one explicit verify argument'
);
$assertTrue(
    str_contains($source, 'Evidence manifest path must be absolute.')
        && str_contains($source, 'Evidence manifest must not be a symbolic link.')
        && str_contains($source, 'Evidence manifest must remain outside the deployed project directory.')
        && str_contains($source, 'Evidence manifest size must be between 2 bytes and 512 KiB.')
        && str_contains($source, 'Evidence manifest must not be world-writable.'),
    'Verifier CLI must enforce private external manifest file safety'
);
$assertTrue(
    !str_contains($source, '/bot/core/bootstrap.php')
        && !str_contains($source, 'PdoConnectionFactory')
        && !str_contains($source, 'StorageFactory')
        && !str_contains($source, 'RuntimePrimaryProjectionWorker'),
    'Evidence verification must not bootstrap the application, open DB storage or run workers'
);
$assertTrue(
    str_contains($source, 'new RuntimePrimaryStagingEvidenceGate($projectRoot)')
        && str_contains($source, '->verify($manifest)'),
    'CLI must bind verification to the current repository checkout'
);
$assertTrue(
    str_contains($source, 'JSON_THROW_ON_ERROR')
        && str_contains($source, 'array_is_list($manifest)')
        && str_contains($source, 'manifest root must be a JSON object'),
    'Manifest decoding must fail closed on malformed or list JSON'
);
$assertTrue(
    str_contains($source, "'application_entrypoints_changed' => false")
        && str_contains($source, "'cron_changed' => false")
        && str_contains($source, "'production_changed' => false")
        && str_contains($source, "'sensitive_identifiers_exposed' => false"),
    'Verifier failure report must preserve the non-mutating safety contract'
);
$assertTrue(
    !str_contains($source, 'state_json')
        && !str_contains($source, 'telegram_id')
        && !str_contains($source, 'provider_subject')
        && !str_contains($source, 'payment_id'),
    'Verifier CLI must not print sensitive snapshot fields'
);

fwrite(STDOUT, "RuntimePrimaryStagingEvidenceCliContractTest passed: {$assertions} assertions.\n");
