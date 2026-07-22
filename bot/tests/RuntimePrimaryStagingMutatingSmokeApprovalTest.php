<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/database/DatabaseConfig.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingMutatingSmokeApproval.php';

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

$config = DatabaseConfig::fromApplicationConfig([
    'database' => [
        'enabled' => true,
        'driver' => 'mysql',
        'host' => 'stage-db.internal',
        'port' => 3306,
        'name' => 'mgw_stage',
        'user' => 'stage_user',
        'password' => 'not-a-real-secret',
        'charset' => 'utf8mb4',
    ],
]);
$databaseIdentity = $config->identityFingerprint();
$commit = str_repeat('a', 40);
$reportSha = str_repeat('b', 64);
$baselineRevision = 7;
$baselineSha = str_repeat('c', 64);
$challenge = str_repeat('d', 64);
$now = 1784736000;

$validPayload = static function () use (
    $databaseIdentity,
    $commit,
    $reportSha,
    $baselineRevision,
    $baselineSha,
    $challenge,
    $now
): array {
    return [
        'approval_id' => str_repeat('e', 64),
        'challenge_sha256' => hash('sha256', $challenge),
        'contract_version' => RuntimePrimaryStagingMutatingSmokeApproval::CONTRACT_VERSION,
        'cron_change_allowed' => false,
        'enabled' => true,
        'expected_baseline_state_revision' => $baselineRevision,
        'expected_baseline_state_sha256' => $baselineSha,
        'expected_database_identity_fingerprint' => $databaseIdentity,
        'expected_read_only_report_sha256' => $reportSha,
        'expected_repository_commit' => $commit,
        'expires_at_utc' => gmdate(DATE_ATOM, $now + 300),
        'max_state_writes' => 1,
        'production_allowed' => false,
        'rollback_required' => true,
        'webhook_allowed' => false,
    ];
};

$approval = RuntimePrimaryStagingMutatingSmokeApproval::fromArray($validPayload());
$approval->assertApproved(
    $config,
    $commit,
    $reportSha,
    $baselineRevision,
    $baselineSha,
    $challenge,
    $now
);
$summary = $approval->safeSummary();
$assertTrue(($summary['enabled'] ?? false) === true, 'Valid approval must remain enabled');
$assertTrue(($summary['rollback_required'] ?? false) === true, 'Valid approval must require rollback');
$assertTrue(($summary['max_state_writes'] ?? 0) === 1, 'Valid approval must allow exactly one state write');
$assertTrue(($summary['production_allowed'] ?? true) === false, 'Valid approval must forbid production');
$assertTrue($approval->approvalId() === str_repeat('e', 64), 'Approval ID must remain exact');

$extra = $validPayload();
$extra['unexpected'] = true;
$assertThrows(
    static fn() => RuntimePrimaryStagingMutatingSmokeApproval::fromArray($extra),
    'schema is invalid'
);

$wrongType = $validPayload();
$wrongType['enabled'] = 1;
$assertThrows(
    static fn() => RuntimePrimaryStagingMutatingSmokeApproval::fromArray($wrongType),
    'must be boolean'
);

$wrongWriteCount = $validPayload();
$wrongWriteCount['max_state_writes'] = 2;
$assertThrows(
    static function () use (
        $wrongWriteCount,
        $config,
        $commit,
        $reportSha,
        $baselineRevision,
        $baselineSha,
        $challenge,
        $now
    ): void {
        RuntimePrimaryStagingMutatingSmokeApproval::fromArray($wrongWriteCount)->assertApproved(
            $config,
            $commit,
            $reportSha,
            $baselineRevision,
            $baselineSha,
            $challenge,
            $now
        );
    },
    'exactly one temporary state write'
);

foreach (['webhook_allowed', 'cron_change_allowed', 'production_allowed'] as $field) {
    $unsafe = $validPayload();
    $unsafe[$field] = true;
    $assertThrows(
        static function () use (
            $unsafe,
            $config,
            $commit,
            $reportSha,
            $baselineRevision,
            $baselineSha,
            $challenge,
            $now
        ): void {
            RuntimePrimaryStagingMutatingSmokeApproval::fromArray($unsafe)->assertApproved(
                $config,
                $commit,
                $reportSha,
                $baselineRevision,
                $baselineSha,
                $challenge,
                $now
            );
        },
        'forbidden safety permission'
    );
}

$noRollback = $validPayload();
$noRollback['rollback_required'] = false;
$assertThrows(
    static function () use (
        $noRollback,
        $config,
        $commit,
        $reportSha,
        $baselineRevision,
        $baselineSha,
        $challenge,
        $now
    ): void {
        RuntimePrimaryStagingMutatingSmokeApproval::fromArray($noRollback)->assertApproved(
            $config,
            $commit,
            $reportSha,
            $baselineRevision,
            $baselineSha,
            $challenge,
            $now
        );
    },
    'must require rollback'
);

$expired = $validPayload();
$expired['expires_at_utc'] = gmdate(DATE_ATOM, $now);
$assertThrows(
    static function () use (
        $expired,
        $config,
        $commit,
        $reportSha,
        $baselineRevision,
        $baselineSha,
        $challenge,
        $now
    ): void {
        RuntimePrimaryStagingMutatingSmokeApproval::fromArray($expired)->assertApproved(
            $config,
            $commit,
            $reportSha,
            $baselineRevision,
            $baselineSha,
            $challenge,
            $now
        );
    },
    'expired'
);

$tooLong = $validPayload();
$tooLong['expires_at_utc'] = gmdate(
    DATE_ATOM,
    $now + RuntimePrimaryStagingMutatingSmokeApproval::MAX_APPROVAL_SECONDS + 1
);
$assertThrows(
    static function () use (
        $tooLong,
        $config,
        $commit,
        $reportSha,
        $baselineRevision,
        $baselineSha,
        $challenge,
        $now
    ): void {
        RuntimePrimaryStagingMutatingSmokeApproval::fromArray($tooLong)->assertApproved(
            $config,
            $commit,
            $reportSha,
            $baselineRevision,
            $baselineSha,
            $challenge,
            $now
        );
    },
    'at most ten minutes'
);

$assertThrows(
    static fn() => $approval->assertApproved(
        $config,
        str_repeat('f', 40),
        $reportSha,
        $baselineRevision,
        $baselineSha,
        $challenge,
        $now
    ),
    'checkout does not match'
);
$assertThrows(
    static fn() => $approval->assertApproved(
        $config,
        $commit,
        str_repeat('f', 64),
        $baselineRevision,
        $baselineSha,
        $challenge,
        $now
    ),
    'report does not match'
);
$assertThrows(
    static fn() => $approval->assertApproved(
        $config,
        $commit,
        $reportSha,
        $baselineRevision + 1,
        $baselineSha,
        $challenge,
        $now
    ),
    'baseline revision does not match'
);
$assertThrows(
    static fn() => $approval->assertApproved(
        $config,
        $commit,
        $reportSha,
        $baselineRevision,
        str_repeat('f', 64),
        $challenge,
        $now
    ),
    'baseline fingerprint does not match'
);
$assertThrows(
    static fn() => $approval->assertApproved(
        $config,
        $commit,
        $reportSha,
        $baselineRevision,
        $baselineSha,
        str_repeat('f', 64),
        $now
    ),
    'challenge does not match'
);
$assertThrows(
    static fn() => $approval->assertApproved(
        $config,
        $commit,
        $reportSha,
        $baselineRevision,
        $baselineSha,
        strtoupper($challenge),
        $now
    ),
    'exact lowercase hexadecimal'
);

$assertThrows(
    static fn() => $approval->assertApproved(
        $config,
        $commit . "\n",
        $reportSha,
        $baselineRevision,
        $baselineSha,
        $challenge,
        $now
    ),
    'current staging repository commit is invalid'
);
$assertThrows(
    static fn() => $approval->assertApproved(
        $config,
        $commit,
        $reportSha . "\n",
        $baselineRevision,
        $baselineSha,
        $challenge,
        $now
    ),
    'current read-only smoke report fingerprint is invalid'
);
$assertThrows(
    static fn() => $approval->assertApproved(
        $config,
        $commit,
        $reportSha,
        $baselineRevision,
        $baselineSha . "\n",
        $challenge,
        $now
    ),
    'current db-primary baseline fingerprint is invalid'
);
$assertThrows(
    static fn() => $approval->assertApproved(
        $config,
        $commit,
        $reportSha,
        $baselineRevision,
        $baselineSha,
        $challenge . "\n",
        $now
    ),
    'exact lowercase hexadecimal'
);

$approvalIdWithNewline = $validPayload();
$approvalIdWithNewline['approval_id'] .= "\n";
$assertThrows(
    static function () use (
        $approvalIdWithNewline,
        $config,
        $commit,
        $reportSha,
        $baselineRevision,
        $baselineSha,
        $challenge,
        $now
    ): void {
        RuntimePrimaryStagingMutatingSmokeApproval::fromArray($approvalIdWithNewline)->assertApproved(
            $config,
            $commit,
            $reportSha,
            $baselineRevision,
            $baselineSha,
            $challenge,
            $now
        );
    },
    'approval id is invalid'
);

$approvalCommitWithNewline = $validPayload();
$approvalCommitWithNewline['expected_repository_commit'] .= "\n";
$assertThrows(
    static function () use (
        $approvalCommitWithNewline,
        $config,
        $commit,
        $reportSha,
        $baselineRevision,
        $baselineSha,
        $challenge,
        $now
    ): void {
        RuntimePrimaryStagingMutatingSmokeApproval::fromArray($approvalCommitWithNewline)->assertApproved(
            $config,
            $commit,
            $reportSha,
            $baselineRevision,
            $baselineSha,
            $challenge,
            $now
        );
    },
    'approved staging repository commit is invalid'
);

$expiryWithNewline = $validPayload();
$expiryWithNewline['expires_at_utc'] .= "\n";
$assertThrows(
    static function () use (
        $expiryWithNewline,
        $config,
        $commit,
        $reportSha,
        $baselineRevision,
        $baselineSha,
        $challenge,
        $now
    ): void {
        RuntimePrimaryStagingMutatingSmokeApproval::fromArray($expiryWithNewline)->assertApproved(
            $config,
            $commit,
            $reportSha,
            $baselineRevision,
            $baselineSha,
            $challenge,
            $now
        );
    },
    'expiry must use exact iso-8601'
);

fwrite(
    STDOUT,
    "RuntimePrimaryStagingMutatingSmokeApprovalTest passed: {$assertions} assertions.\n"
);
