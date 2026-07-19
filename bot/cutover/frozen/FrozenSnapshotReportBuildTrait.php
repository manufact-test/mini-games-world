<?php
declare(strict_types=1);

trait FrozenSnapshotReportBuildTrait
{
    private function buildFrozenReport(
        string $environment,
        string $rehearsalId,
        array $seal,
        array $pair,
        array $primaryData,
        array $externalData,
        array $restoredData,
        array $restore,
        bool $restoreParity,
        bool $restoreRemoved,
        bool $snapshotOk,
        array $final,
        bool $finalOk,
        array $modules,
        array $missing
    ): array {
        $blockers = [];
        if (!$snapshotOk) $blockers[] = 'frozen JSON snapshot rehearsal is not clean';
        if (!$finalOk) $blockers[] = 'final JSON to DB reconciliation is not clean';
        if (!$this->router->enabled()) $blockers[] = 'database runtime router is disabled';
        if ($missing !== []) $blockers[] = 'required database runtime modules are not enabled';
        return [
            'ok' => $snapshotOk && $finalOk,
            'report_type' => 'mvp-14.8.4-frozen-snapshot-rehearsal',
            'environment' => $environment,
            'state' => 'completed',
            'action' => 'prepare',
            'idempotent' => false,
            'rehearsal_id' => $rehearsalId,
            'freeze' => [
                'active' => (bool)($seal['freeze']['active'] ?? false),
                'sealed' => (bool)($seal['freeze']['sealed'] ?? false),
                'storage_write_block_active' => (bool)($seal['freeze']['storage_write_block_active'] ?? false),
            ],
            'backup_pair' => $pair,
            'data_snapshot' => [
                'file_count' => $primaryData['file_count'],
                'bytes' => $primaryData['bytes'],
                'fingerprint' => $primaryData['fingerprint'],
                'primary_external_equal' => hash_equals($primaryData['fingerprint'], $externalData['fingerprint']),
                'external_restore_equal' => hash_equals($externalData['fingerprint'], $restoredData['fingerprint']),
            ],
            'restore_rehearsal' => [
                'ok' => $restoreParity && $restoreRemoved,
                'target_ready' => (bool)($restore['target_ready'] ?? false),
                'restored_files' => (int)($restore['restored_files'] ?? 0),
                'exact_snapshot_restored' => $restoreParity,
                'temporary_target_removed' => $restoreRemoved,
                'live_data_target_used' => false,
            ],
            'final_reconciliation' => [
                'ok' => $finalOk,
                'read_only' => (bool)($final['read_only'] ?? true),
                'count_parity_complete' => (bool)($final['count_parity_complete'] ?? false),
                'report_fingerprint' => (string)($final['report_fingerprint'] ?? ''),
                'blocking_reasons' => array_values((array)($final['blocking_reasons'] ?? [])),
                'migration_gaps' => array_values((array)($final['migration_gaps'] ?? [])),
            ],
            'database_runtime' => [
                'enabled' => $this->router->enabled(),
                'enabled_modules' => $modules,
                'missing_modules' => $missing,
            ],
            'switch_rehearsal' => [
                'ready' => $blockers === [],
                'blockers' => $blockers,
                'production_switch_performed' => false,
                'production_allowed' => false,
            ],
            'storage_driver' => 'json',
            'rollback_driver' => 'json',
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'completed_at_utc' => $this->nowUtc(),
            'generated_at_utc' => $this->nowUtc(),
        ];
    }
}
