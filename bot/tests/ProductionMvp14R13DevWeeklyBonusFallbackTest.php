<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$bridge = file_get_contents($root . '/bot/weekly/WeeklyBonusRuntimeBridge.php');
$repository = file_get_contents($root . '/bot/weekly/RuntimeWeeklyBonusRepository.php');
$auth = file_get_contents($root . '/bot/services/StagingTestAuthService.php');
if (!is_string($bridge) || !is_string($repository) || !is_string($auth)) {
    throw new RuntimeException('Missing weekly fallback source.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($repository, "if (!is_array(\$user) || !empty(\$user['is_dev_user'])) continue;"),
    'Weekly DB projection must continue to exclude development users intentionally.');
$assert(str_contains($auth, "'is_dev_user' => true")
    && str_contains($auth, "'is_staging_test_user' => true"),
    'Fixed staging test players must remain explicitly development-only identities.');

$assert(str_contains($bridge, "if (\$error->getMessage() !== 'Weekly bonus DB state is missing or ambiguous.')"),
    'Only the exact missing-state condition may enter the development fallback.');
$assert(str_contains($bridge, "if (!is_array(\$user) || empty(\$user['is_dev_user']))")
    && str_contains($bridge, 'return null;'),
    'Real users must fail closed instead of using JSON when their DB weekly state is missing.');
$assert(str_contains($bridge, 'SELECT COUNT(*) FROM mgw_runtime_weekly_bonus_state WHERE legacy_user_id = :legacy_user_id')
    && str_contains($bridge, 'if ($rowCount !== 0)'),
    'Fallback must be allowed only when the intentionally excluded user has zero DB weekly rows.');
$assert(str_contains($bridge, "throw new RuntimeException('Excluded development weekly bonus DB state is unexpectedly present.')"),
    'Ambiguous, duplicate or stale development DB state must remain a hard failure.');
$assert(str_contains($bridge, 'new WeeklyMatchEconomyService($this->config)')
    && str_contains($bridge, '->status($snapshot, $user)'),
    'The fallback must calculate the same read-only status from the rollback snapshot.');
$assert(!str_contains($bridge, 'is_staging_test_user'),
    'The intentional dev-user fallback must not depend on one hard-coded test-player marker.');

// No generic catch-and-ignore is allowed around DB status replacement.
$assert(!preg_match('/catch\s*\(Throwable[^)]*\)\s*\{\s*\$data\[\'weekly_match\'\]\s*=\s*\$data\[\'weekly_match\'\]/s', $bridge),
    'Weekly DB failures must not be silently ignored.');

fwrite(STDOUT, "ProductionMvp14R13DevWeeklyBonusFallbackTest: {$assertions} assertions passed\n");
