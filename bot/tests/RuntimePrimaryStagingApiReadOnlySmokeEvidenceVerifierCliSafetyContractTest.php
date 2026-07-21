<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$cli = file_get_contents(
    $projectRoot . '/ops/runtime/verify-staging-db-primary-api-read-only-smoke-evidence.php'
);
if (!is_string($cli)) {
    throw new RuntimeException('Read-only smoke evidence verifier CLI source is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$argumentRead = strpos($cli, '$value = substr($argument, strlen($matchedPrefix));');
$backslashGuard = strpos($cli, "str_contains(\$value, '\\\\')");
$errorHandler = strpos($cli, 'set_error_handler(static function');
$canonicalCheck = strpos($cli, 'hash_equals($reportPath, $canonicalReport)');
$modeCheck = strpos($cli, '($reportMode & 0777) !== 0600');
$parentCheck = strpos($cli, '($parentMode & 0022) !== 0');
$classLoad = strpos($cli, "require_once \$projectRoot");
$verifyCall = strpos($cli, 'RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifier(');
$restoreHandler = strpos($cli, 'restore_error_handler();');

$assertTrue(
    $argumentRead !== false && $backslashGuard !== false
        && $argumentRead < $backslashGuard
        && !str_contains($cli, "str_replace('\\\\', '/', \$value)")
        && !str_contains($cli, 'trim(substr($argument')
        && !str_contains($cli, 'strtolower($value)'),
    'Verifier CLI must preserve option bytes and reject backslash report paths'
);
$assertTrue(
    $errorHandler !== false && $canonicalCheck !== false
        && $modeCheck !== false && $parentCheck !== false
        && $classLoad !== false && $verifyCall !== false && $restoreHandler !== false
        && $errorHandler < $canonicalCheck
        && $canonicalCheck < $modeCheck
        && $modeCheck < $parentCheck
        && $parentCheck < $classLoad
        && $classLoad < $verifyCall
        && $verifyCall < $restoreHandler,
    'Verifier CLI must secure exact canonical report evidence before class loading'
);
$assertTrue(
    str_contains($cli, 'Read-only API smoke report must have exact mode 0600.')
        && str_contains($cli, 'report directory must not be group/world writable.')
        && str_contains($cli, 'report must use its exact canonical path.'),
    'Verifier CLI must require private exact report filesystem properties'
);
$assertTrue(
    str_contains($cli, 'Read-only smoke evidence filesystem operation failed.')
        && str_contains($cli, 'Suppressed read-only smoke evidence verifier warning.')
        && str_contains($cli, 'error_reporting() & $severity'),
    'Verifier CLI must convert filesystem warnings to generic path-free errors'
);
$assertTrue(
    str_contains($cli, '~/(?:home|var|tmp|srv|opt)/')
        && str_contains($cli, "'[private-path]'")
        && str_contains($cli, "'sensitive_identifiers_exposed' => false"),
    'Verifier CLI must sanitize private paths and preserve safe failure flags'
);
$assertTrue(
    !str_contains($cli, 'file_put_contents(')
        && !str_contains($cli, 'StorageFactory')
        && !str_contains($cli, 'PdoConnectionFactory')
        && !str_contains($cli, 'WebhookHandler')
        && !str_contains($cli, 'crontab')
        && !str_contains($cli, 'production-cutover.php'),
    'Verifier CLI must remain offline, read-only and infrastructure-neutral'
);

fwrite(
    STDOUT,
    "RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifierCliSafetyContractTest passed: {$assertions} assertions.\n"
);
