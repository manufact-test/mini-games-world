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
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceGate.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceSourceInterface.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceCollector.php';

final class RuntimePrimaryStagingEvidenceCollectorTestSource implements RuntimePrimaryStagingEvidenceSourceInterface
{
    public int $captureIndex = 0;
    public int $rehearsalIndex = 0;
    public array $captures;
    public array $rehearsals;
    public array $concurrency;

    public function __construct(private string $projectRoot, private string $commit)
    {
        $sha = str_repeat('1', 64);
        $inventory = str_repeat('2', 64);
        $this->captures = array_fill(0, 3, [
            'sha256' => $sha,
            'inventory_fingerprint' => $inventory,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ]);
        $modules = [
            'accounts', 'realtime', 'economy', 'notifications', 'invites',
            'history', 'shop', 'payments', 'weekly_bonus',
        ];
        $base = [
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
                'status' => 'completed',
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
            $base + [
                'snapshot' => [
                    'action' => 'snapshot_initialized',
                    'state_revision' => 1,
                    'state_sha256' => $sha,
                ],
                'worker_tick_count' => 1,
                'worker_ticks' => [[
                    'ok' => true,
                    'action' => 'projection_completed',
                    'projected_modules' => $modules,
                ]],
            ],
            $base + [
                'snapshot' => [
                    'action' => 'snapshot_unchanged',
                    'state_revision' => 1,
                    'state_sha256' => $sha,
                ],
                'worker_tick_count' => 0,
                'worker_ticks' => [],
            ],
        ];
        $this->concurrency = [
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

    public function repositoryCommit(): string { return $this->commit; }
    public function phpEvidence(): array
    {
        return ['version' => '8.3.25', 'version_id' => 80325, 'sapi' => 'cli'];
    }
    public function databaseEvidence(): array
    {
        return ['driver' => 'mysql', 'server_version' => '10.11.13-MariaDB'];
    }
    public function captureJsonEvidence(): array
    {
        $capture = $this->captures[$this->captureIndex] ?? end($this->captures);
        $this->captureIndex++;
        return $capture;
    }
    public function runRehearsal(): array
    {
        $report = $this->rehearsals[$this->rehearsalIndex] ?? end($this->rehearsals);
        $this->rehearsalIndex++;
        return $report;
    }
    public function concurrencyEvidence(): array { return $this->concurrency; }
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
$commit = str_repeat('a', 40);
putenv('MGW_REHEARSAL_COMMIT_SHA=' . $commit);
try {
    $source = new RuntimePrimaryStagingEvidenceCollectorTestSource($projectRoot, $commit);
    $result = (new RuntimePrimaryStagingEvidenceCollector($projectRoot, $source))->collect();
    $assertTrue(($result['ok'] ?? false) === true, 'Valid automated evidence collection must pass');
    $assertTrue(($result['verification']['ok'] ?? false) === true, 'Collected manifest must pass the strict gate');
    $assertTrue(($result['manifest']['first_rehearsal']['worker_tick_count'] ?? 0) === 1, 'First rehearsal tick count must remain explicit');
    $assertTrue(($result['manifest']['repeated_rehearsal']['worker_tick_count'] ?? -1) === 0, 'Repeated rehearsal must remain idempotent');
    $assertTrue(count((array)($result['projected_modules'] ?? [])) === 9, 'Collector must preserve all nine modules');

    $changed = new RuntimePrimaryStagingEvidenceCollectorTestSource($projectRoot, $commit);
    $changed->captures[2]['sha256'] = str_repeat('9', 64);
    $assertThrows(
        static fn() => (new RuntimePrimaryStagingEvidenceCollector($projectRoot, $changed))->collect(),
        'json rollback source or inventory changed'
    );

    $missingModule = new RuntimePrimaryStagingEvidenceCollectorTestSource($projectRoot, $commit);
    array_pop($missingModule->rehearsals[0]['worker_ticks'][0]['projected_modules']);
    $assertThrows(
        static fn() => (new RuntimePrimaryStagingEvidenceCollector($projectRoot, $missingModule))->collect(),
        'did not prove all nine projected modules'
    );

    $missingSafety = new RuntimePrimaryStagingEvidenceCollectorTestSource($projectRoot, $commit);
    unset($missingSafety->rehearsals[0]['cron_changed']);
    $assertThrows(
        static fn() => (new RuntimePrimaryStagingEvidenceCollector($projectRoot, $missingSafety))->collect(),
        'omitted its safety flag: cron_changed'
    );

    $repeatWrite = new RuntimePrimaryStagingEvidenceCollectorTestSource($projectRoot, $commit);
    $repeatWrite->rehearsals[1]['worker_tick_count'] = 1;
    $assertThrows(
        static fn() => (new RuntimePrimaryStagingEvidenceCollector($projectRoot, $repeatWrite))->collect(),
        'zero worker ticks'
    );

    $weakConcurrency = new RuntimePrimaryStagingEvidenceCollectorTestSource($projectRoot, $commit);
    $weakConcurrency->concurrency['worker_lease']['second_action'] = 'projection_completed';
    $assertThrows(
        static fn() => (new RuntimePrimaryStagingEvidenceCollector($projectRoot, $weakConcurrency))->collect(),
        'lease concurrency evidence is incomplete'
    );
} finally {
    putenv('MGW_REHEARSAL_COMMIT_SHA');
}

fwrite(STDOUT, "RuntimePrimaryStagingEvidenceCollectorTest passed: {$assertions} assertions.\n");
