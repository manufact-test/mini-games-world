<?php
declare(strict_types=1);

final class RuntimePrimaryStateSchemaInstaller
{
    public const TABLE = 'mgw_runtime_primary_state';
}
final class RuntimePrimaryProjectionOutboxSchemaInstaller
{
    public const TABLE = 'mgw_runtime_primary_projection_outbox';
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryEntrypointEvidence.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryRepositoryCommitResolver.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceVerifier.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV2Verifier.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV2Gate.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointSelectorConfig.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingSelectorEvidence.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV3Verifier.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV3Gate.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingRequestSessionConfig.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingRequestLifecycleEvidence.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV4Verifier.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV4Gate.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceSourceInterface.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceCollector.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingSelectorEvidenceCollector.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingLifecycleEvidenceCollector.php';

final class RuntimePrimaryStagingLifecycleEvidenceCollectorTestSource implements RuntimePrimaryStagingEvidenceSourceInterface
{
    public int $captureCalls = 0;
    public int $rehearsalCalls = 0;
    private array $rehearsals;

    public function __construct(private string $projectRoot, private string $commit)
    {
        $sha = str_repeat('1', 64);
        $modules = [
            'accounts', 'realtime', 'economy', 'notifications', 'invites',
            'history', 'shop', 'payments', 'weekly_bonus',
        ];
        $common = [
            'ok' => true,
            'action' => 'rehearsal_completed',
            'schemas' => [
                'state_schema' => [
                    'table' => RuntimePrimaryStateSchemaInstaller::TABLE,
                    'engine' => 'innodb',
                    'schema_fingerprint' => str_repeat('3', 64),
                ],
                'outbox_schema' => [
                    'table' => RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE,
                    'engine' => 'innodb',
                    'schema_fingerprint' => str_repeat('4', 64),
                ],
            ],
            'target_event' => [
                'present' => true,
                'state_revision' => 1,
                'state_sha256' => $sha,
                'projection_version' => 'v1-normalized-all-modules',
                'status' => 'completed',
                'attempt_count' => 1,
                'lease_expires_at_utc' => '',
                'last_error' => '',
            ],
            'target_event_completed' => true,
            'status_healthy' => true,
            'parity_completed' => true,
            'application_entrypoints_changed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
        $this->rehearsals = [
            $common + [
                'snapshot' => [
                    'action' => 'snapshot_initialized',
                    'state_revision' => 1,
                    'state_sha256' => $sha,
                ],
                'worker_tick_count' => 1,
                'worker_ticks' => [[
                    'action' => 'projection_completed',
                    'state_revision' => 1,
                    'state_sha256' => $sha,
                    'projected_modules' => $modules,
                    'parity_ok' => true,
                ]],
            ],
            $common + [
                'snapshot' => [
                    'action' => 'snapshot_unchanged',
                    'state_revision' => 1,
                    'state_sha256' => $sha,
                ],
                'worker_tick_count' => 0,
                'worker_ticks' => [],
            ],
        ];
    }

    public function repositoryCommit(): string { return $this->commit; }
    public function phpEvidence(): array
    {
        return ['version' => '8.3.25', 'version_id' => 80325, 'sapi' => 'cli'];
    }
    public function databaseEvidence(): array
    {
        return [
            'driver' => 'mysql',
            'server_version' => '10.11.13-MariaDB',
            'identity_fingerprint' => str_repeat('5', 64),
        ];
    }
    public function captureJsonEvidence(): array
    {
        $this->captureCalls++;
        return [
            'sha256' => str_repeat('1', 64),
            'inventory_fingerprint' => str_repeat('2', 64),
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }
    public function runRehearsal(): array
    {
        $index = $this->rehearsalCalls++;
        return $this->rehearsals[$index] ?? end($this->rehearsals);
    }
    public function concurrencyEvidence(): array
    {
        return [
            'cli_lock' => [
                'ok' => true,
                'second_exit_code' => 2,
                'second_result' => 'rehearsal_lock_blocked',
            ],
            'worker_lease' => [
                'ok' => true,
                'state_revision' => 1,
                'first_claimed' => true,
                'second_action' => 'projection_busy',
                'second_state_revision' => 1,
                'lease_seconds' => 120,
            ],
        ];
    }
    public function entrypointEvidence(): array
    {
        return RuntimePrimaryEntrypointEvidence::inspect($this->projectRoot);
    }
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$commit = str_repeat('a', 40);
putenv('MGW_REHEARSAL_COMMIT_SHA=' . $commit);
try {
    $source = new RuntimePrimaryStagingLifecycleEvidenceCollectorTestSource($projectRoot, $commit);
    $result = (new RuntimePrimaryStagingLifecycleEvidenceCollector(
        $projectRoot,
        $source
    ))->collect();
    $manifest = (array)($result['manifest'] ?? []);
    $assertTrue(($result['ok'] ?? false) === true, 'Lifecycle collector must pass');
    $assertTrue(($result['verification']['ok'] ?? false) === true, 'Lifecycle manifest must pass v4 gate');
    $assertTrue(
        ($manifest['manifest_version'] ?? '') === RuntimePrimaryStagingEvidenceV4Verifier::MANIFEST_VERSION,
        'Lifecycle collector must emit v4'
    );
    $assertTrue(
        ($manifest['request_session_evidence']['ready'] ?? false) === true,
        'V4 manifest must contain ready request lifecycle evidence'
    );
    $assertTrue(
        ($result['baseline_state_revision'] ?? 0) === 1
            && ($result['baseline_state_sha256'] ?? '') === str_repeat('1', 64),
        'Lifecycle collector must bind exact rehearsal baseline'
    );
    $assertTrue(
        preg_match('/^[a-f0-9]{64}$/', (string)(
            $result['request_session_evidence_fingerprint'] ?? ''
        )) === 1,
        'Lifecycle collector must expose lifecycle fingerprint'
    );
    $assertTrue($source->captureCalls === 3, 'Lifecycle wrapper must preserve exactly three JSON captures');
    $assertTrue($source->rehearsalCalls === 2, 'Lifecycle wrapper must preserve exactly two rehearsals');
    $assertTrue(count((array)($result['projected_modules'] ?? [])) === 9, 'Lifecycle wrapper must preserve all nine modules');
    $assertTrue(($result['session_enabled_by_evidence'] ?? true) === false, 'Evidence alone must not enable session');
    $assertTrue(($result['finalizer_registered_by_evidence'] ?? true) === false, 'Evidence alone must not register finalizer');

    $tampered = $manifest;
    $tampered['request_session_evidence']['sources']['api_session_coordinator'] = str_repeat('0', 64);
    $report = (new RuntimePrimaryStagingEvidenceV4Gate($projectRoot))->verify($tampered);
    $assertTrue(($report['ok'] ?? true) === false, 'Tampered lifecycle manifest must fail v4 gate');
} finally {
    putenv('MGW_REHEARSAL_COMMIT_SHA');
}

fwrite(STDOUT, "RuntimePrimaryStagingLifecycleEvidenceCollectorTest passed: {$assertions} assertions.\n");
