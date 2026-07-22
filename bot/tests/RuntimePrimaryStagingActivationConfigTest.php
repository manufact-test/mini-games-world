<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/database/DatabaseConfig.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingActivationConfig.php';

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

$now = strtotime('2026-07-20T18:00:00+00:00');
$private = '/home/example/private';
$commit = str_repeat('a', 40);
$databaseArray = [
    'database' => [
        'enabled' => true,
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'mgw_stage',
        'user' => 'stage_user',
        'password' => 'private-password',
        'charset' => 'utf8mb4',
    ],
];
$databaseConfig = DatabaseConfig::fromApplicationConfig($databaseArray);
$databaseFingerprint = $databaseConfig->identityFingerprint();
$base = $databaseArray + [
    'staging_db_primary_activation' => [
        'enabled' => true,
        'expected_database_identity_fingerprint' => $databaseFingerprint,
        'expected_repository_commit' => $commit,
        'evidence_file' => $private . '/evidence.json',
        'expected_evidence_fingerprint' => str_repeat('b', 64),
        'approval_expires_at_utc' => '2026-07-20T18:20:00+00:00',
    ],
];

$policy = RuntimePrimaryStagingActivationConfig::fromApplicationConfig($base);
$policy->assertApproved($databaseConfig, $commit, $private, $now);
$assertTrue($policy->evidenceFile() === $private . '/evidence.json', 'Policy must preserve the private evidence file');
$assertTrue($policy->expectedEvidenceFingerprint() === str_repeat('b', 64), 'Policy must preserve the exact evidence fingerprint');
$assertTrue(($policy->safeSummary()['max_approval_seconds'] ?? 0) === 1800, 'Activation approval must be limited to 30 minutes');

$zulu = $base;
$zulu['staging_db_primary_activation']['approval_expires_at_utc'] = '2026-07-20T18:20:00Z';
RuntimePrimaryStagingActivationConfig::fromApplicationConfig($zulu)
    ->assertApproved($databaseConfig, $commit, $private, $now);
$assertions++;

$disabled = $base;
$disabled['staging_db_primary_activation']['enabled'] = false;
$assertThrows(
    static fn() => RuntimePrimaryStagingActivationConfig::fromApplicationConfig($disabled)
        ->assertApproved($databaseConfig, $commit, $private, $now),
    'approval is disabled'
);

$otherDatabase = $base;
$otherDatabase['staging_db_primary_activation']['expected_database_identity_fingerprint'] = str_repeat('c', 64);
$assertThrows(
    static fn() => RuntimePrimaryStagingActivationConfig::fromApplicationConfig($otherDatabase)
        ->assertApproved($databaseConfig, $commit, $private, $now),
    'does not match the staging activation approval'
);
foreach ([strtoupper($databaseFingerprint), ' ' . $databaseFingerprint, $databaseFingerprint . ' '] as $malformed) {
    $changed = $base;
    $changed['staging_db_primary_activation']['expected_database_identity_fingerprint'] = $malformed;
    $assertThrows(
        static fn() => RuntimePrimaryStagingActivationConfig::fromApplicationConfig($changed)
            ->assertApproved($databaseConfig, $commit, $private, $now),
        'fingerprint is invalid'
    );
}

$otherCommit = $base;
$otherCommit['staging_db_primary_activation']['expected_repository_commit'] = str_repeat('d', 40);
$assertThrows(
    static fn() => RuntimePrimaryStagingActivationConfig::fromApplicationConfig($otherCommit)
        ->assertApproved($databaseConfig, $commit, $private, $now),
    'checkout does not match'
);
foreach ([strtoupper($commit), ' ' . $commit, $commit . ' '] as $malformed) {
    $changed = $base;
    $changed['staging_db_primary_activation']['expected_repository_commit'] = $malformed;
    $assertThrows(
        static fn() => RuntimePrimaryStagingActivationConfig::fromApplicationConfig($changed)
            ->assertApproved($databaseConfig, $commit, $private, $now),
        'repository commit is invalid'
    );
}
$assertThrows(
    static fn() => $policy->assertApproved($databaseConfig, strtoupper($commit), $private, $now),
    'current staging repository commit is invalid'
);
$assertThrows(
    static fn() => $policy->assertApproved($databaseConfig, $commit . ' ', $private, $now),
    'current staging repository commit is invalid'
);

$outsideEvidence = $base;
$outsideEvidence['staging_db_primary_activation']['evidence_file'] = '/home/example/other/evidence.json';
$assertThrows(
    static fn() => RuntimePrimaryStagingActivationConfig::fromApplicationConfig($outsideEvidence)
        ->assertApproved($databaseConfig, $commit, $private, $now),
    'verified private directory'
);
foreach ([
    ' ' . $private . '/evidence.json',
    $private . '/evidence.json ',
    str_replace('/', '\\', $private . '/evidence.json'),
    $private . '/evidence.json/',
] as $malformedPath) {
    $changed = $base;
    $changed['staging_db_primary_activation']['evidence_file'] = $malformedPath;
    $assertThrows(
        static fn() => RuntimePrimaryStagingActivationConfig::fromApplicationConfig($changed)
            ->assertApproved($databaseConfig, $commit, $private, $now),
        'exact absolute linux file path'
    );
}
foreach ([' ' . $private, $private . ' ', str_replace('/', '\\', $private), $private . '/'] as $malformedPrivate) {
    $assertThrows(
        static fn() => $policy->assertApproved($databaseConfig, $commit, $malformedPrivate, $now),
        'exact absolute linux path'
    );
}

foreach ([strtoupper(str_repeat('b', 64)), ' ' . str_repeat('b', 64)] as $malformedFingerprint) {
    $changed = $base;
    $changed['staging_db_primary_activation']['expected_evidence_fingerprint'] = $malformedFingerprint;
    $assertThrows(
        static fn() => RuntimePrimaryStagingActivationConfig::fromApplicationConfig($changed)
            ->assertApproved($databaseConfig, $commit, $private, $now),
        'evidence fingerprint is invalid'
    );
}

$expired = $base;
$expired['staging_db_primary_activation']['approval_expires_at_utc'] = '2026-07-20T17:59:59+00:00';
$assertThrows(
    static fn() => RuntimePrimaryStagingActivationConfig::fromApplicationConfig($expired)
        ->assertApproved($databaseConfig, $commit, $private, $now),
    'is expired'
);

$tooLong = $base;
$tooLong['staging_db_primary_activation']['approval_expires_at_utc'] = '2026-07-20T18:31:00+00:00';
$assertThrows(
    static fn() => RuntimePrimaryStagingActivationConfig::fromApplicationConfig($tooLong)
        ->assertApproved($databaseConfig, $commit, $private, $now),
    'at most 30 minutes'
);

foreach ([
    '2026-07-20 18:20:00',
    ' 2026-07-20T18:20:00+00:00',
    '2026-07-20T18:20:00+00:00 ',
    '2026-02-30T18:20:00+00:00',
    '2026-07-20T18:20+00:00',
] as $malformedExpiry) {
    $changed = $base;
    $changed['staging_db_primary_activation']['approval_expires_at_utc'] = $malformedExpiry;
    $assertThrows(
        static fn() => RuntimePrimaryStagingActivationConfig::fromApplicationConfig($changed)
            ->assertApproved($databaseConfig, $commit, $private, $now),
        str_contains($malformedExpiry, '02-30') ? 'expiry is invalid' : 'exact iso-8601 seconds'
    );
}

foreach (['true', 1, null] as $ambiguous) {
    $changed = $base;
    $changed['staging_db_primary_activation']['enabled'] = $ambiguous;
    $assertThrows(
        static fn() => RuntimePrimaryStagingActivationConfig::fromApplicationConfig($changed),
        'strict boolean'
    );
}
foreach (['expected_database_identity_fingerprint', 'expected_repository_commit', 'evidence_file', 'expected_evidence_fingerprint', 'approval_expires_at_utc'] as $field) {
    $changed = $base;
    $changed['staging_db_primary_activation'][$field] = 123;
    $assertThrows(
        static fn() => RuntimePrimaryStagingActivationConfig::fromApplicationConfig($changed),
        'must be a string value'
    );
}

$malformedBlock = $base;
$malformedBlock['staging_db_primary_activation'] = 'enabled';
$assertThrows(
    static fn() => RuntimePrimaryStagingActivationConfig::fromApplicationConfig($malformedBlock),
    'configuration array'
);
$assertThrows(
    static fn() => $policy->assertApproved($databaseConfig, $commit, $private, 0),
    'verification time is invalid'
);

fwrite(STDOUT, "RuntimePrimaryStagingActivationConfigTest passed: {$assertions} assertions.\n");
