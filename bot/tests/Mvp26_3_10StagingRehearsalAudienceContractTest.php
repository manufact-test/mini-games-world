<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$api = file_get_contents($root . '/bot/api.php');
$storage = file_get_contents($root . '/bot/storage/StorageFactory.php');
if (!is_string($api) || !is_string($storage)) {
    throw new RuntimeException('MVP-26.3.10 source files are unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$auth = strpos($api, '$tgUser = $auth->getUserFromRequest($payload);');
$audience = strpos($api, "$GLOBALS['mgw_staging_db_primary_authenticated_eligible']");
$storageCreate = strpos($api, '$db = StorageFactory::createJson(');

$assert($auth !== false, 'api.php must authenticate the request explicitly.');
$assert($audience !== false, 'api.php must publish authenticated staging rehearsal eligibility.');
$assert($storageCreate !== false, 'api.php must create runtime storage.');
$assert(
    $auth < $audience && $audience < $storageCreate,
    'Authentication and audience classification must happen before staging runtime storage selection.'
);
$assert(
    str_contains($api, "!empty($tgUser['is_staging_test_user'])"),
    'Only positively authenticated staging test users may be marked rehearsal-eligible.'
);

$guard = strpos($storage, "$GLOBALS['mgw_staging_db_primary_authenticated_eligible'] ?? null");
$return = strpos($storage, 'if ($audienceEligible === false)');
$assert($guard !== false && $return !== false && $guard < $return, 'StorageFactory must consume the authenticated audience gate.');
$assert(
    str_contains($storage, "$environment === 'staging' && $script === 'api.php'"),
    'Audience gate must remain staging API-only.'
);
$assert(
    str_contains($storage, 'if ($audienceEligible === false) {')
        && str_contains($storage, 'return;'),
    'Ordinary authenticated staging users must fail open to live JSON instead of DB-primary rehearsal.'
);

fwrite(STDOUT, "Mvp26_3_10StagingRehearsalAudienceContractTest: {$assertions} assertions passed\n");
