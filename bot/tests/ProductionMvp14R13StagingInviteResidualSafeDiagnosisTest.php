<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = file_get_contents($root . '/bot/services/StagingTestInviteResidualRecoveryService.php');
$endpoint = file_get_contents($root . '/bot/staging-test-auth.php');
$probe = file_get_contents($root . '/e2e/staging/invite-residual-diagnostic.spec.mjs');
foreach ([$service, $endpoint, $probe] as $source) {
    if (!is_string($source)) throw new RuntimeException('Missing safe residual diagnosis source.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$diagnoseMethodPosition = strpos($service, 'public function diagnose(array $server): array');
$reconcileMethodPosition = strpos($service, 'public function reconcile(array $server): array');
$diagnoseSource = $diagnoseMethodPosition !== false && $reconcileMethodPosition !== false
    ? substr($service, $diagnoseMethodPosition, $reconcileMethodPosition - $diagnoseMethodPosition)
    : '';

$assert($diagnoseMethodPosition !== false
    && str_contains($service, '$this->inspect($snapshot, $this->database(), false)')
    && str_contains($diagnoseSource, "'read_only' => true")
    && str_contains($diagnoseSource, "'production_changed' => false")
    && str_contains($diagnoseSource, "'live_payments_used' => false"),
    'The diagnosis must be an exact-host read-only staging operation with explicit safety evidence.');

$allowedCodes = [
    'invite_identity_incomplete',
    'invite_identity_partial_conflict',
    'invite_not_test_players',
    'invite_unsafe_status',
    'invite_attached_to_match',
    'invite_referenced_by_match',
    'notification_not_test_players',
    'notification_still_in_json',
    'residual_limit_exceeded',
];
foreach ($allowedCodes as $code) {
    $assert(str_contains($service, "'{$code}'"), 'Missing safe blocker code: ' . $code);
}

$diagnosePosition = strpos($endpoint, "if (\$action === 'diagnose_invite_residuals')");
$verifyPosition = strpos($endpoint, 'verifyAndConsume($providedCredential)', $diagnosePosition ?: 0);
$callPosition = strpos($endpoint, '$residualService()->diagnose($_SERVER)', $diagnosePosition ?: 0);
$assert($diagnosePosition !== false
    && $verifyPosition !== false
    && $callPosition !== false
    && $diagnosePosition < $verifyPosition
    && $verifyPosition < $callPosition
    && str_contains($endpoint, "array_key_exists('slot', \$payload)"),
    'The read-only diagnosis must require a consumed GitHub OIDC token and reject player selectors.');

$assert(str_contains($probe, "data: { action: 'diagnose_invite_residuals' }")
    && str_contains($probe, 'MGW_SAFE_INVITE_RESIDUAL_DIAGNOSIS')
    && str_contains($probe, 'candidate_count')
    && str_contains($probe, 'blocker_codes')
    && !str_contains($probe, 'console.log(oidcToken)')
    && !str_contains($probe, 'console.log(payload)'),
    'The runner must print only the bounded aggregate diagnosis and never credentials or the full response.');

$assert(!str_contains($diagnoseSource, "'private_candidates' =>")
    && !str_contains($endpoint, 'private_candidates')
    && !str_contains($probe, 'private_candidates'),
    'Private residual rows may remain internal but must never cross the public diagnosis boundary.');

$assert(str_contains($endpoint, "'error' => 'test_auth_unavailable'")
    && !str_contains($endpoint, '$error->getMessage()'),
    'All non-diagnostic endpoint failures must remain generic.');

$assert(!str_contains($service, 'lemonchiffon-gerbil-545102.hostingersite.com')
    && !str_contains($endpoint, 'lemonchiffon-gerbil-545102.hostingersite.com')
    && !str_contains($probe, 'lemonchiffon-gerbil-545102.hostingersite.com')
    && str_contains($service, 'seashell-okapi-889488.hostingersite.com'),
    'The diagnostic path must remain isolated to the exact staging host.');

fwrite(STDOUT, "ProductionMvp14R13StagingInviteResidualSafeDiagnosisTest: {$assertions} assertions passed\n");
