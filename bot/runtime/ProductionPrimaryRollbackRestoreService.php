<?php
declare(strict_types=1);

final class ProductionPrimaryRollbackRestoreService
{
    public function __construct(
        private BackupManager $backupManager,
        private ProductionPrimaryRollbackExportVerifier $verifier
    ) {}

    public function restoreIsolated(string $exportDir, string $targetDir): array
    {
        $verifiedExport = $this->verifier->verify($exportDir);
        $expectedRevision = (int)($verifiedExport['state_revision'] ?? 0);
        $expectedStateSha = (string)($verifiedExport['state_sha256'] ?? '');
        if ($expectedRevision < 1
            || preg_match('/\A[a-f0-9]{64}\z/', $expectedStateSha) !== 1) {
            throw new RuntimeException('Rollback export identity is unavailable for restore.');
        }

        if ($targetDir === ''
            || str_contains($targetDir, '\\')
            || !str_starts_with($targetDir, '/')
            || ($targetDir !== '/' && str_ends_with($targetDir, '/'))
            || is_link($targetDir)) {
            throw new RuntimeException('Rollback restore target must be an exact absolute path.');
        }

        $restored = $this->backupManager->restore($exportDir, $targetDir);
        if (($restored['ok'] ?? false) !== true
            || ($restored['target_ready'] ?? false) !== true) {
            throw new RuntimeException('BackupManager did not prepare the isolated rollback target.');
        }

        $canonical = realpath($targetDir);
        if (!is_string($canonical) || !hash_equals($targetDir, $canonical) || !is_dir($canonical)) {
            throw new RuntimeException('Rollback restore target was not finalized canonically.');
        }
        if (!chmod($canonical, 0700)) {
            throw new RuntimeException('Rollback restore target permissions could not be secured.');
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($canonical, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->isLink()) {
                throw new RuntimeException('Rollback restore target contains an unsafe entry.');
            }
            if (!chmod($item->getPathname(), 0600)) {
                throw new RuntimeException('Rollback restore file permissions could not be secured.');
            }
        }

        $verifiedTarget = $this->verifier->verifyRestoredDataDirectory(
            $canonical,
            $expectedRevision,
            $expectedStateSha
        );

        return [
            'ok' => true,
            'action' => 'production_rollback_export_restored_isolated',
            'backup_id' => (string)($verifiedExport['backup_id'] ?? ''),
            'snapshot_sha256' => (string)($verifiedExport['snapshot_sha256'] ?? ''),
            'state_revision' => $expectedRevision,
            'state_sha256' => $expectedStateSha,
            'data_files' => (int)($verifiedTarget['data_files'] ?? 0),
            'target_ready' => true,
            'target_live' => false,
            'live_json_changed' => false,
            'database_contacted' => false,
            'database_write_executed' => false,
            'persistent_config_changed' => false,
            'webhook_changed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }
}
