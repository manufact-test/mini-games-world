<?php
declare(strict_types=1);

final class ManagedMigrationController
{
    /** @var null|callable():array */
    private $productionBackup;

    public function __construct(
        private MigrationRunner $runner,
        private ManagedMigrationConfig $policy,
        ?callable $productionBackup = null,
        private ?int $now = null
    ) {
        $this->productionBackup = $productionBackup;
    }

    public function inspect(): array
    {
        $status = $this->runner->status();
        $status['plan_fingerprint'] = self::fingerprint($status['pending'] ?? []);
        $status['managed'] = $this->policy->safeSummary();
        return $status;
    }

    public function run(): array
    {
        $before = $this->inspect();
        $pendingCount = (int)($before['pending_count'] ?? 0);
        if ($pendingCount === 0) {
            return [
                'ok' => true,
                'action' => 'noop',
                'executed_count' => 0,
                'plan_fingerprint' => (string)$before['plan_fingerprint'],
                'before' => $before,
                'after' => $before,
            ];
        }

        if (!$this->policy->enabled()) {
            throw new RuntimeException('Managed migrations are disabled for this environment.');
        }

        $environment = $this->policy->environment();
        if (!in_array($environment, ['staging', 'production'], true)) {
            throw new RuntimeException('Managed migrations may run only in staging or production.');
        }
        if ($environment === 'staging' && !$this->policy->autoRunStaging()) {
            throw new RuntimeException('Automatic staging migrations are disabled.');
        }

        $fingerprint = (string)$before['plan_fingerprint'];
        $dryRun = $this->runner->migrate(true);
        $dryFingerprint = self::fingerprint($dryRun['pending'] ?? []);
        if (!hash_equals($fingerprint, $dryFingerprint)) {
            throw new RuntimeException('Migration plan changed between status and dry-run.');
        }

        $backup = null;
        if ($environment === 'production') {
            $this->assertProductionApproval($fingerprint);
            if ($this->policy->requireProductionBackup()) {
                if (!is_callable($this->productionBackup)) {
                    throw new RuntimeException('Production migration backup callback is not configured.');
                }
                $backup = ($this->productionBackup)();
                if (!is_array($backup) || ($backup['ok'] ?? false) !== true) {
                    throw new RuntimeException('Production backup did not complete successfully.');
                }
                if ($this->policy->requireProductionExternalCopy()
                    && ($backup['external_copy']['copied'] ?? false) !== true) {
                    throw new RuntimeException('Production external backup copy did not complete successfully.');
                }
            }
        }

        $migrated = $this->runner->migrate(false);
        $after = $this->inspect();
        if ((int)($after['pending_count'] ?? -1) !== 0) {
            throw new RuntimeException('Database schema is not current after managed migration.');
        }

        return [
            'ok' => true,
            'action' => 'migrated',
            'environment' => $environment,
            'plan_fingerprint' => $fingerprint,
            'dry_run_pending_count' => (int)($dryRun['pending_count'] ?? 0),
            'executed_count' => (int)($migrated['executed_count'] ?? 0),
            'executed' => $migrated['executed'] ?? [],
            'backup' => $backup === null ? null : [
                'ok' => true,
                'backup_id' => $backup['backup_id'] ?? null,
                'snapshot_sha256' => $backup['snapshot_sha256'] ?? null,
                'external_copy' => [
                    'copied' => (bool)($backup['external_copy']['copied'] ?? false),
                ],
            ],
            'before' => $before,
            'after' => $after,
        ];
    }

    public static function fingerprint(array $pending): string
    {
        $items = [];
        foreach ($pending as $migration) {
            if (!is_array($migration)) continue;
            $items[] = [
                'version' => (string)($migration['version'] ?? ''),
                'checksum' => strtolower((string)($migration['checksum'] ?? '')),
                'transactional' => (bool)($migration['transactional'] ?? false),
            ];
        }
        usort($items, static fn(array $a, array $b): int => strcmp($a['version'], $b['version']));
        return hash('sha256', json_encode($items, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function assertProductionApproval(string $fingerprint): void
    {
        if (!$this->policy->productionCliAllowed()) {
            throw new RuntimeException('Production managed migrations require private database migration approval.');
        }

        $approved = $this->policy->productionApprovalFingerprint();
        if ($approved === '' || !hash_equals($approved, $fingerprint)) {
            throw new RuntimeException('Production migration plan is not explicitly approved.');
        }

        $now = $this->now ?? time();
        if (!$this->policy->productionApprovalIsCurrent($now)) {
            throw new RuntimeException('Production migration approval is missing or expired.');
        }
    }
}
