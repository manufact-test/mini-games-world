<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/bot/staging-test-auth.php');
if (!is_string($source)) throw new RuntimeException('Cannot read staging-test-auth.php');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$issueStart = strpos($source, "if (\$action === 'issue') {");
$revokeStart = strpos($source, "if (\$action === 'revoke') {");
$assert($issueStart !== false && $revokeStart !== false && $revokeStart > $issueStart, 'Issue action boundaries are unavailable.');
$issueBlock = substr($source, (int)$issueStart, (int)$revokeStart - (int)$issueStart);

$assert(
    !str_contains($issueBlock, '$residualService()->reconcile($_SERVER)'),
    'Issuing an A/B test session must not reconcile or depend on real-user invite residuals.'
);
$assert(
    str_contains($source, "if (\$action === 'reconcile_invite_residuals')")
        && str_contains($source, '$result = $residualService()->reconcile($_SERVER);'),
    'Explicit OIDC residual recovery must remain available as a separate operation.'
);
$assert(
    str_contains($source, "if (\$action === 'reset_test_players')")
        && str_contains($source, '$staleRecovery = $staleOrphanService()->reconcile($_SERVER);'),
    'The controlled pre-suite staging reset path must retain its guarded orphan recovery.'
);
$assert(
    str_contains($issueBlock, "'invite_residual_recovery' => null"),
    'Test session projection must state that per-issue residual recovery did not run.'
);

fwrite(STDOUT, "StagingTestAuthIsolationFromRealInviteResidualsContractTest: {$assertions} assertions passed\n");
