<?php
declare(strict_types=1);

final class FinalDeltaRehearsalOrchestrator
{
    private Closure $freeze;
    private Closure $freezeStatus;
    private Closure $sealStatus;
    private Closure $seal;
    private Closure $deltaRun;
    private Closure $snapshotPrepare;
    private Closure $release;
    private Closure $emergencyRelease;

    public function __construct(
        callable $freeze,
        callable $freezeStatus,
        callable $sealStatus,
        callable $seal,
        callable $deltaRun,
        callable $snapshotPrepare,
        callable $release,
        callable $emergencyRelease,
        private string $stateFile
    ) {
        $this->freeze = Closure::fromCallable($freeze);
        $this->freezeStatus = Closure::fromCallable($freezeStatus);
        $this->sealStatus = Closure::fromCallable($sealStatus);
        $this->seal = Closure::fromCallable($seal);
        $this->deltaRun = Closure::fromCallable($deltaRun);
        $this->snapshotPrepare = Closure::fromCallable($snapshotPrepare);
        $this->release = Closure::fromCallable($release);
        $this->emergencyRelease = Closure::fromCallable($emergencyRelease);
        $this->stateFile = trim($this->stateFile);
        if ($this->stateFile === '') {
            throw new InvalidArgumentException('One-shot rehearsal state file is required.');
        }
    }

    public function status(): array
    {
        $state = $this->readState();
        if ($state === []) {
            return $this->withFingerprint($this->baseReport('not_started', true) + [
                'complete' => false,
                'idempotent' => true,
            ]);
        }
        $state['action'] = 'status';
        $state['idempotent'] = true;
        $state['generated_at_utc'] = $this->nowUtc();
        return $this->withFingerprint($state);
    }

    public function run(bool $retryFailed = false, bool $reset = false): array
    {
        if ($reset && is_file($this->stateFile) && !unlink($this->stateFile)) {
            throw new RuntimeException('Could not reset one-shot rehearsal state.');
        }

        $existing = $this->readState();
        $existingState = (string)($existing['state'] ?? '');
        if ($existingState === 'completed') {
            $existing['action'] = 'run_noop';
            $existing['idempotent'] = true;
            $existing['generated_at_utc'] = $this->nowUtc();
            return $this->withFingerprint($existing);
        }
        if ($existingState === 'failed' && !$retryFailed) {
            $existing['action'] = 'failed_noop';
            $existing['idempotent'] = true;
            $existing['generated_at_utc'] = $this->nowUtc();
            return $this->withFingerprint($existing);
        }

        $step = 'initialize';
        $drainReport = [];
        $sealReport = [];
        $deltaReport = [];
        $snapshotReport = [];
        $releaseReport = [];
        $finalStatus = [];

        try {
            $step = 'freeze';
            $initialSealStatus = ($this->sealStatus)();
            $alreadySealed = $this->isSealed($initialSealStatus);
            if (!$alreadySealed) {
                ($this->freeze)();
            }

            $step = 'drain';
            $drainReport = $alreadySealed
                ? $initialSealStatus
                : ($this->freezeStatus)();
            if (empty($drainReport['drain']['ready'])) {
                return $this->persistWaiting($drainReport);
            }

            $step = 'seal';
            if ($alreadySealed) {
                $sealReport = $initialSealStatus;
            } else {
                $preSealStatus = ($this->sealStatus)();
                if (isset($preSealStatus['drain']) && empty($preSealStatus['drain']['ready'])) {
                    return $this->persistWaiting($preSealStatus);
                }
                $sealReport = $this->isSealed($preSealStatus)
                    ? $preSealStatus
                    : ($this->seal)();
            }
            if (empty($sealReport['frozen_snapshot']['ready'])
                || empty($sealReport['control_consistency']['ok'])) {
                throw new RuntimeException('The sealed snapshot is not ready for final reconciliation.');
            }

            $step = 'final_delta';
            $deltaReport = ($this->deltaRun)();
            if (empty($deltaReport['ok'])) {
                throw new RuntimeException('Final JSON to database delta reconciliation failed.');
            }

            $step = 'frozen_snapshot';
            $snapshotReport = ($this->snapshotPrepare)();
            if (empty($snapshotReport['ok'])
                || empty($snapshotReport['final_reconciliation']['ok'])
                || empty($snapshotReport['restore_rehearsal']['ok'])) {
                throw new RuntimeException('Frozen snapshot and isolated restore verification failed.');
            }

            $step = 'release';
            $releaseReport = ($this->release)();
            $finalStatus = ($this->sealStatus)();
            if (!empty($finalStatus['freeze']['active'])
                || !empty($finalStatus['freeze']['storage_write_block_active'])
                || empty($finalStatus['control_consistency']['ok'])) {
                throw new RuntimeException('The rehearsal write barrier was not released cleanly.');
            }

            $report = $this->baseReport('completed', true) + [
                'complete' => true,
                'action' => 'run',
                'idempotent' => false,
                'current_step' => 'completed',
                'drain' => $this->compactDrain($drainReport),
                'seal' => $this->compactSeal($sealReport),
                'final_delta' => $deltaReport,
                'frozen_snapshot' => $snapshotReport,
                'release' => $this->compactRelease($releaseReport, $finalStatus),
                'completed_at_utc' => $this->nowUtc(),
            ];
            $report = $this->withFingerprint($report);
            $this->writeState($report);
            return $report;
        } catch (Throwable $error) {
            $recovery = [];
            $recoveryError = '';
            try {
                $recovery = ($this->emergencyRelease)();
            } catch (Throwable $recoveryFailure) {
                $recoveryError = $recoveryFailure->getMessage();
            }

            $report = $this->baseReport('failed', false) + [
                'complete' => false,
                'action' => 'run',
                'idempotent' => false,
                'failed_step' => $step,
                'error_class' => get_class($error),
                'error_message' => $error->getMessage(),
                'recovery' => [
                    'attempted' => true,
                    'ok' => $recoveryError === '' && !empty($recovery['ok']),
                    'write_block_active' => (bool)($recovery['freeze']['storage_write_block_active'] ?? false),
                    'control_consistent' => (bool)($recovery['control_consistency']['ok'] ?? false),
                    'error_message' => $recoveryError,
                ],
                'failed_at_utc' => $this->nowUtc(),
            ];
            $report = $this->withFingerprint($report);
            $this->writeState($report);
            return $report;
        }
    }

    private function persistWaiting(array $drainReport): array
    {
        $waiting = $this->baseReport('waiting_for_drain', true) + [
            'complete' => false,
            'action' => 'run',
            'idempotent' => false,
            'current_step' => 'drain',
            'drain' => $this->compactDrain($drainReport),
        ];
        $waiting = $this->withFingerprint($waiting);
        $this->writeState($waiting);
        return $waiting;
    }

    private function isSealed(array $status): bool
    {
        return !empty($status['freeze']['active'])
            && !empty($status['freeze']['sealed'])
            && !empty($status['freeze']['storage_write_block_active'])
            && !empty($status['control_consistency']['ok']);
    }

    private function compactDrain(array $report): array
    {
        return [
            'ready' => (bool)($report['drain']['ready'] ?? false),
            'active_games' => (int)($report['drain']['active_games'] ?? 0),
            'queue_entries' => (int)($report['drain']['queue_entries'] ?? 0),
            'open_invites' => (int)($report['drain']['open_invites'] ?? 0),
            'searching_users' => (int)($report['drain']['searching_users'] ?? 0),
            'playing_users' => (int)($report['drain']['playing_users'] ?? 0),
            'blockers' => array_values((array)($report['drain']['blockers'] ?? [])),
        ];
    }

    private function compactSeal(array $report): array
    {
        return [
            'active' => (bool)($report['freeze']['active'] ?? false),
            'sealed' => (bool)($report['freeze']['sealed'] ?? false),
            'storage_write_block_active' => (bool)($report['freeze']['storage_write_block_active'] ?? false),
            'frozen_snapshot_ready' => (bool)($report['frozen_snapshot']['ready'] ?? false),
            'control_consistent' => (bool)($report['control_consistency']['ok'] ?? false),
        ];
    }

    private function compactRelease(array $release, array $status): array
    {
        return [
            'ok' => !empty($release['ok']),
            'active' => (bool)($status['freeze']['active'] ?? true),
            'storage_write_block_active' => (bool)($status['freeze']['storage_write_block_active'] ?? true),
            'control_consistent' => (bool)($status['control_consistency']['ok'] ?? false),
        ];
    }

    private function baseReport(string $state, bool $ok): array
    {
        return [
            'ok' => $ok,
            'report_type' => 'mvp-14.8.4c-final-delta-one-shot',
            'state' => $state,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => $this->nowUtc(),
        ];
    }

    private function readState(): array
    {
        if (!is_file($this->stateFile)) return [];
        $raw = file_get_contents($this->stateFile);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('One-shot rehearsal state file is unreadable.');
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('One-shot rehearsal state file is invalid.');
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('One-shot rehearsal state file has an invalid root.');
        }
        return $decoded;
    }

    private function writeState(array $state): void
    {
        $directory = dirname($this->stateFile);
        if (!is_dir($directory)) {
            throw new RuntimeException('One-shot rehearsal state directory is unavailable.');
        }
        $temporary = $this->stateFile . '.tmp-' . bin2hex(random_bytes(6));
        $json = json_encode(
            $state,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new RuntimeException('Could not write one-shot rehearsal state.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $this->stateFile)) {
            @unlink($temporary);
            throw new RuntimeException('Could not publish one-shot rehearsal state.');
        }
        @chmod($this->stateFile, 0600);
    }

    private function withFingerprint(array $report): array
    {
        unset($report['report_fingerprint']);
        $report['report_fingerprint'] = hash('sha256', LedgerIntegrity::canonicalJson($report));
        return $report;
    }

    private function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
