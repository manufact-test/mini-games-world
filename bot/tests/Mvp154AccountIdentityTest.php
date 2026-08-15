<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/helpers/validators.php';
require $root . '/economy/UnifiedBalanceRuntimeState.php';
require $root . '/services/UserService.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};

$service = new UserService([
    'initial_match_coins' => 0,
    'initial_gold_coins' => 0,
]);
$db = ['users' => []];

$first = $service->ensureUser($db, [
    'id' => 'identity-user',
    'first_name' => 'Identity',
    'username' => 'identity',
    'mgw_id' => 'MGW-IDENTITY01',
    'mgw_account_ref' => 'legacy:identity-user',
    'mgw_identity_provider' => 'telegram',
]);
$assertSame('MGW-IDENTITY01', $first['mgw_id'], 'Verified MGW owner must be persisted');
$assertSame('legacy:identity-user', $first['mgw_account_ref'], 'Initial verified account_ref must be persisted');

$rotated = $service->ensureUser($db, [
    'id' => 'identity-user',
    'first_name' => 'Identity',
    'username' => 'identity',
    'mgw_id' => 'MGW-IDENTITY01',
    'mgw_account_ref' => 'account:merged-owner',
    'mgw_identity_provider' => 'telegram',
]);
$assertSame('MGW-IDENTITY01', $rotated['mgw_id'], 'Account-ref rotation must preserve the immutable MGW owner');
$assertSame('account:merged-owner', $rotated['mgw_account_ref'], 'Verified account_ref may rotate under the same MGW owner');

$conflictThrown = false;
try {
    $service->ensureUser($db, [
        'id' => 'identity-user',
        'first_name' => 'Identity',
        'username' => 'identity',
        'mgw_id' => 'MGW-DIFFERENT01',
        'mgw_account_ref' => 'account:merged-owner',
        'mgw_identity_provider' => 'telegram',
    ]);
} catch (RuntimeException) {
    $conflictThrown = true;
}
$assertSame(true, $conflictThrown, 'A different MGW owner must never replace the persisted provider-neutral owner');

fwrite(STDOUT, "Mvp154AccountIdentityTest passed: {$assertions} assertions.\n");
