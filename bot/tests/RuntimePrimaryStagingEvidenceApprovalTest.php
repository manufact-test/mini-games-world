<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/database/DatabaseConfig.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceApproval.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $messagePart) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($messagePart))) return;
        throw new RuntimeException('Unexpected exception: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected exception was not thrown.');
};

$now = strtotime('2026-07-20T16:00:00+00:00');
$commit = str_repeat('a', 40);
$database = DatabaseConfig::fromApplicationConfig([
    'database' => [
        'enabled' => true,
        'driver' => 'mysql',
        'host' => 'staging-db.internal',
        'port' => 3306,
        'database' => 'mgw_staging_isolated',
        'username' => 'mgw_staging_user',
        'password' => 'not-in-fingerprint',
        'charset' => 'utf8mb4',
    ],
]);
$fingerprint = $database->identityFingerprint();
$validConfig = [
    'staging_db_primary_evidence' => [
        'enabled' => true,
        'expected_database_identity_fingerprint' => $fingerprint,
        'expected_repository_commit' => $commit,
        'approval_expires_at_utc' => '2026-07-20T17:00:00+00:00',
    ],
];
$approval = RuntimePrimaryStagingEvidenceApproval::fromConfig($validConfig);
$approval->assertApproved($database, $commit, $now);
$assertTrue($approval->safeSummary()['enabled'] === true, 'Valid approval must remain enabled');
$assertTrue($approval->safeSummary()['database_identity_fingerprint_configured'] === true, 'Valid DB fingerprint must be summarized');
$assertTrue($approval->safeSummary()['repository_commit_configured'] === true, 'Valid commit must be summarized');

$disabled = $validConfig;
$disabled['staging_db_primary_evidence']['enabled'] = false;
$assertThrows(
    static fn() => RuntimePrimaryStagingEvidenceApproval::fromConfig($disabled)->assertApproved($database, $commit, $now),
    'approval is disabled'
);

$wrongDatabase = $validConfig;
$wrongDatabase['staging_db_primary_evidence']['expected_database_identity_fingerprint'] = str_repeat('b', 64);
$assertThrows(
    static fn() => RuntimePrimaryStagingEvidenceApproval::fromConfig($wrongDatabase)->assertApproved($database, $commit, $now),
    'does not match the explicitly approved staging database identity'
);

$wrongCommit = $validConfig;
$wrongCommit['staging_db_primary_evidence']['expected_repository_commit'] = str_repeat('b', 40);
$assertThrows(
    static fn() => RuntimePrimaryStagingEvidenceApproval::fromConfig($wrongCommit)->assertApproved($database, $commit, $now),
    'does not match the explicitly approved staging repository commit'
);

$expired = $validConfig;
$expired['staging_db_primary_evidence']['approval_expires_at_utc'] = '2026-07-20T15:59:59+00:00';
$assertThrows(
    static fn() => RuntimePrimaryStagingEvidenceApproval::fromConfig($expired)->assertApproved($database, $commit, $now),
    'approval is expired'
);

$tooLong = $validConfig;
$tooLong['staging_db_primary_evidence']['approval_expires_at_utc'] = '2026-07-20T18:00:01+00:00';
$assertThrows(
    static fn() => RuntimePrimaryStagingEvidenceApproval::fromConfig($tooLong)->assertApproved($database, $commit, $now),
    'at most two hours'
);

$noOffset = $validConfig;
$noOffset['staging_db_primary_evidence']['approval_expires_at_utc'] = '2026-07-20 17:00:00';
$assertThrows(
    static fn() => RuntimePrimaryStagingEvidenceApproval::fromConfig($noOffset)->assertApproved($database, $commit, $now),
    'explicit utc offset'
);

$ambiguousBoolean = $validConfig;
$ambiguousBoolean['staging_db_primary_evidence']['enabled'] = 'tru';
$assertThrows(
    static fn() => RuntimePrimaryStagingEvidenceApproval::fromConfig($ambiguousBoolean),
    'strict boolean'
);

$malformedSection = ['staging_db_primary_evidence' => 'enabled'];
$assertThrows(
    static fn() => RuntimePrimaryStagingEvidenceApproval::fromConfig($malformedSection),
    'must be a configuration array'
);

$disabledDatabase = DatabaseConfig::fromApplicationConfig(['database' => ['enabled' => false]]);
$assertThrows(
    static fn() => $approval->assertApproved($disabledDatabase, $commit, $now),
    'requires an enabled database'
);

$passwordChanged = DatabaseConfig::fromApplicationConfig([
    'database' => [
        'enabled' => true,
        'driver' => 'mysql',
        'host' => 'staging-db.internal',
        'port' => 3306,
        'database' => 'mgw_staging_isolated',
        'username' => 'mgw_staging_user',
        'password' => 'rotated-secret',
        'charset' => 'utf8mb4',
    ],
]);
$assertTrue(
    hash_equals($fingerprint, $passwordChanged->identityFingerprint()),
    'Database identity fingerprint must not include the password'
);

fwrite(STDOUT, "RuntimePrimaryStagingEvidenceApprovalTest passed: {$assertions} assertions.\n");
