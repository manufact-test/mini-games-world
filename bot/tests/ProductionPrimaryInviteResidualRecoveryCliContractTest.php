<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$cliPath = $root . '/ops/runtime/recover-production-primary-invite-residual.php';
$servicePath = $root . '/bot/runtime/ProductionPrimaryInviteResidualRecoveryService.php';
$cli = file_get_contents($cliPath);
$service = file_get_contents($servicePath);
if (!is_string($cli) || $cli === '' || !is_string($service) || $service === '') {
    throw new RuntimeException('Production invite residual recovery sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$containsAll = static function (string $source, array $needles): bool {
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) return false;
    }
    return true;
};

$assertTrue(
    $containsAll($cli, [
        "PHP_SAPI !== 'cli'",
        "PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80400",
        "--expected-commit=",
        "--expected-plan-fingerprint=",
        "--receipt=",
        "ProductionPrimaryRuntimeActivationContract(",
        "['state'] ?? '') !== 'completed'",
        'database_identity_fingerprint',
        "flock(\$lockHandle, LOCK_EX | LOCK_NB)",
    ]),
    'Recovery CLI must remain CLI-only, PHP-bound, commit-bound, activation-bound and locked'
);
$assertTrue(
    strpos($cli, 'writePrivateRecoveryReceipt($receiptPath, $preimageReceipt);')
        < strpos($cli, '$service->run($options[\'expected_plan_fingerprint\'])'),
    'Private preimage receipt must be durable before recovery mutation'
);
$assertTrue(
    $containsAll($cli, [
        "unset(\$report['private_preimage']);",
        "'sensitive_identifiers_exposed' => false",
        "fopen(\$path, 'x')",
        "chmod(\$path, 0600)",
        "str_starts_with(\$parent . '/', \$privateReal . '/')",
        "!str_starts_with(\$parent . '/', \$projectReal . '/')",
    ]),
    'Preview must hide the private preimage and receipts must be no-clobber private files'
);
$assertTrue(
    $containsAll($service, [
        'FOR UPDATE',
        'DELETE FROM mgw_notifications',
        'DELETE FROM mgw_invite_events',
        'DELETE FROM mgw_invites',
        '$this->auditor->auditOnly(',
        'post-delete all-module audit failed',
        "'primary_state_changed' => false",
    ]),
    'Recovery execution must lock, delete only the exact residual contour and audit before commit'
);
$assertTrue(
    !str_contains($cli, 'production-cutover.php')
        && !str_contains($cli, 'production-release.php')
        && !str_contains($cli, 'production-rollback.php')
        && !str_contains($cli, 'crontab')
        && !str_contains($service, 'file_put_contents(')
        && !str_contains($service, 'StorageFactory::createJson('),
    'Recovery must not invoke cutover, release, rollback, Cron or JSON storage mutation'
);

fwrite(STDOUT, "ProductionPrimaryInviteResidualRecoveryCliContractTest: {$assertions} assertions passed\n");
