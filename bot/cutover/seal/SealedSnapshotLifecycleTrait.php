<?php
declare(strict_types=1);

trait SealedSnapshotLifecycleTrait
{
    public function seal(): array
    {
        $this->assertSafeEnvironment();
        $control = $this->readControl();
        $state = (string)($control['state'] ?? '');
        if ($state === 'sealed') {
            $report = $this->status();
            $report['action'] = 'seal_noop';
            $report['idempotent'] = true;
            return $this->withFingerprint($report);
        }
        if ($state !== 'frozen') throw new RuntimeException('Freeze must be active before sealing.');
        $status = $this->status();
        if (empty($status['drain']['ready'])) {
            throw new RuntimeException('Games, queue, invitations and searching users must drain before sealing.');
        }

        $control['schema_version'] = 2;
        $control['state'] = 'sealed';
        $control['sealed_at_utc'] = $this->nowUtc();
        $this->writeControl($control);
        try {
            $this->activateWriteBlock($control);
        } catch (Throwable $error) {
            $control['state'] = 'frozen';
            $control['sealed_at_utc'] = null;
            $this->writeControl($control);
            throw $error;
        }
        $report = $this->status();
        $report['action'] = 'seal';
        $report['idempotent'] = false;
        return $this->withFingerprint($report);
    }

    public function release(string $reason = ''): array
    {
        return $this->releaseInternal($reason, false);
    }

    public function emergencyRelease(string $reason = ''): array
    {
        return $this->releaseInternal($reason, true);
    }

    private function releaseInternal(string $reason, bool $emergency): array
    {
        $environment = $this->assertSafeEnvironment();
        $controlReadRecovered = false;
        try {
            $control = $this->readControl();
        } catch (Throwable $error) {
            if (!$emergency) throw $error;
            $control = [];
            $controlReadRecovered = true;
        }

        $state = (string)($control['state'] ?? '');
        $wasActive = in_array($state, ['frozen', 'sealed'], true);
        $markerWasActive = is_file($this->writeBlockFile());

        // Remove the storage barrier first. If the following control write fails,
        // matchmaking remains frozen by the old control state, but JSON writes resume.
        $this->removeWriteBlock();

        if ($control === []) {
            $control = [
                'schema_version' => 2,
                'environment' => $environment,
                'rehearsal_id' => 'rehearsal_' . bin2hex(random_bytes(8)),
                'started_at_utc' => null,
                'sealed_at_utc' => null,
            ];
        }
        $control['schema_version'] = 2;
        $control['environment'] = $environment;
        $control['state'] = 'released';
        $control['released_at_utc'] = $this->nowUtc();
        $control['release_reason'] = mb_substr(trim(preg_replace('/\s+/u', ' ', $reason) ?? ''), 0, 200);
        $control['emergency_release'] = $emergency;
        $control['control_read_recovered'] = $controlReadRecovered;

        try {
            $this->writeControl($control);
        } catch (Throwable $error) {
            throw new RuntimeException(
                'JSON write block was removed, but the cutover control could not be updated. Keep matchmaking frozen and repair the private control file before continuing.',
                0,
                $error
            );
        }

        $report = $this->status();
        $report['action'] = $emergency ? 'emergency_release' : ($wasActive ? 'release' : 'release_noop');
        $report['idempotent'] = !$wasActive && !$markerWasActive && !$controlReadRecovered;
        $report['recovery'] = [
            'emergency' => $emergency,
            'write_block_was_active' => $markerWasActive,
            'write_block_removed' => !is_file($this->writeBlockFile()),
            'control_read_recovered' => $controlReadRecovered,
        ];
        return $this->withFingerprint($report);
    }
}
