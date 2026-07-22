<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$cli = file_get_contents(
    $projectRoot . '/ops/ci/verify-current-portable-focused-suite-evidence.php'
);
if (!is_string($cli)) {
    throw new RuntimeException('Current portable evidence verifier CLI source is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$argumentRead = strpos($cli, '$value = substr($argument, strlen($matchedPrefix));');
$backslashGuard = strpos($cli, "str_contains(\$value, '\\\\')");
$classLoad = strpos($cli, 'require_once $projectRoot');
$errorHandler = strpos($cli, 'set_error_handler(static function');
$verification = strpos($cli, 'RuntimePrimaryCurrentPortableCiEvidenceVerifier(');
$restoreHandler = strpos($cli, 'restore_error_handler();');

$assertTrue(
    $argumentRead !== false
        && $backslashGuard !== false
        && $classLoad !== false
        && $argumentRead < $backslashGuard
        && $backslashGuard < $classLoad,
    'CLI must reject backslash paths before loading the verifier class'
);
$assertTrue(
    !str_contains($cli, "str_replace('\\\\', '/', \$value)")
        && !str_contains($cli, 'trim(substr($argument')
        && !str_contains($cli, 'strtolower($value)')
        && str_contains($cli, 'Evidence directory must not contain backslashes.'),
    'CLI must not normalize verifier argument values'
);
$assertTrue(
    $errorHandler !== false
        && $verification !== false
        && $restoreHandler !== false
        && $errorHandler < $verification
        && $verification < $restoreHandler,
    'CLI must install and restore its warning shield around evidence verification'
);
$assertTrue(
    str_contains($cli, 'Current portable CI evidence filesystem operation failed.')
        && str_contains($cli, 'Suppressed current portable CI verifier warning.')
        && str_contains($cli, 'error_reporting() & $severity'),
    'CLI warning shield must emit only generic path-free messages'
);
$assertTrue(
    str_contains($cli, '~/(?:home|var|tmp|srv|opt)/')
        && str_contains($cli, "'[private-path]'")
        && str_contains($cli, "'sensitive_identifiers_exposed' => false"),
    'CLI must retain final private-path sanitization and safe failure fields'
);
$assertTrue(
    !str_contains($cli, 'file_put_contents(')
        && !str_contains($cli, 'curl ')
        && !str_contains($cli, 'mysql ')
        && !str_contains($cli, 'ssh ')
        && !str_contains($cli, 'DATABASE_URL')
        && !str_contains($cli, 'DB_PASSWORD'),
    'CLI safety layer must remain offline and read-only'
);

fwrite(
    STDOUT,
    "RuntimePrimaryCurrentPortableCiEvidenceVerifierCliSafetyContractTest passed: {$assertions} assertions.\n"
);
