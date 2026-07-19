<?php
declare(strict_types=1);

trait FrozenSnapshotPrepareActionTrait
{
    public function prepare(string $build, callable $reconciliation): array
    {
        $environment = $this->assertSafeEnvironment();
        $seal = $this->sealControl->status();
        if (($seal['frozen_snapshot']['ready'] ?? false) !== true) {
            $reason = implode('; ', array_map('strval', (array)($seal['frozen_snapshot']['blockers'] ?? [])));
            throw new RuntimeException('Frozen snapshot preconditions failed' . ($reason !== '' ? ': ' . $reason : '.'));
        }
        $rehearsalId = trim((string)($seal['freeze']['rehearsal_id'] ?? ''));
        if ($rehearsalId === '') throw new RuntimeException('Frozen snapshot rehearsal ID is missing.');
        $existing = $this->readState();
        if (($existing['state'] ?? '') === 'completed' && (string)($existing['rehearsal_id'] ?? '') === $rehearsalId) {
            $existing['action'] = 'prepare_noop';
            $existing['idempotent'] = true;
            $existing['generated_at_utc'] = $this->nowUtc();
            return $this->withFingerprint($existing);
        }

        $created = $this->backupManager->create($environment, $build);
        $primaryPath = $this->backupManager->latestSnapshot($this->primaryRoot);
        $externalPath = $this->backupManager->latestSnapshot($this->externalRoot);
        $primary = $this->backupManager->verify($primaryPath);
        $external = $this->backupManager->verify($externalPath);
        $pair = $this->verifyPair($environment, $build, $primary, $external);

        $final = $reconciliation();
        if (!is_array($final)) throw new RuntimeException('Final reconciliation must return an array.');
        $finalOk = ($final['ok'] ?? false) === true
            && ($final['count_parity_complete'] ?? false) === true
            && (array)($final['blocking_reasons'] ?? []) === []
            && (array)($final['migration_gaps'] ?? []) === [];

        $backupId = (string)($primary['backup_id'] ?? $created['backup_id'] ?? '');
        if ($backupId === '') throw new RuntimeException('Frozen snapshot backup ID is missing.');
        $target = $this->restoreRoot . '/restore-' . $this->safeName($backupId);
        if (file_exists($target)) throw new RuntimeException('Frozen snapshot restore target already exists.');

        $restore = [];
        $primaryData = [];
        $externalData = [];
        $restoredData = [];
        $restoreParity = false;
        try {
            $restore = $this->backupManager->restore($externalPath, $target);
            $primaryData = $this->dataFingerprint($primaryPath . '/data');
            $externalData = $this->dataFingerprint($externalPath . '/data');
            $restoredData = $this->dataFingerprint($target);
            $restoreParity = hash_equals($primaryData['fingerprint'], $externalData['fingerprint'])
                && hash_equals($primaryData['fingerprint'], $restoredData['fingerprint']);
        } finally {
            if (file_exists($target)) $this->removeRestoreTarget($target);
        }

        $restoreRemoved = !file_exists($target);
        $snapshotOk = ($pair['same_verified_snapshot'] ?? false) === true
            && ($restore['target_ready'] ?? false) === true
            && $restoreParity && $restoreRemoved;

        $modules = $this->router->enabledModules();
        sort($modules, SORT_STRING);
        $missing = array_values(array_diff(self::REQUIRED_MODULES, $modules));
        $report = $this->buildFrozenReport(
            $environment, $rehearsalId, $seal, $pair, $primaryData,
            $externalData, $restoredData, $restore, $restoreParity,
            $restoreRemoved, $snapshotOk, $final, $finalOk, $modules, $missing
        );
        $report = $this->withFingerprint($report);
        $this->writeState($report);
        return $report;
    }
}
