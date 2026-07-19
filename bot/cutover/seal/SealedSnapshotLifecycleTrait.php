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
        $environment = $this->assertSafeEnvironment();
        $control = $this->readControl();
        $wasActive = in_array((string)($control['state'] ?? ''), ['frozen', 'sealed'], true);
        if ($control === []) {
            $control = [
                'schema_version' => 2,
                'environment' => $environment,
                'rehearsal_id' => 'rehearsal_' . bin2hex(random_bytes(8)),
                'started_at_utc' => null,
                'sealed_at_utc' => null,
            ];
        }
        $control['state'] = 'released';
        $control['released_at_utc'] = $this->nowUtc();
        $control['release_reason'] = mb_substr(trim(preg_replace('/\s+/u', ' ', $reason) ?? ''), 0, 200);
        $this->writeControl($control);
        $this->removeWriteBlock();
        $report = $this->status();
        $report['action'] = $wasActive ? 'release' : 'release_noop';
        $report['idempotent'] = !$wasActive;
        return $this->withFingerprint($report);
    }
}
