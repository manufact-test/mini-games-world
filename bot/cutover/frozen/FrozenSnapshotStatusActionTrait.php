<?php
declare(strict_types=1);

trait FrozenSnapshotStatusActionTrait
{
    public function status(): array
    {
        $environment = $this->assertSafeEnvironment();
        $state = $this->readState();
        if ($state === []) {
            $state = [
                'ok' => true,
                'report_type' => 'mvp-14.8.4-frozen-snapshot-rehearsal',
                'environment' => $environment,
                'state' => 'absent',
                'production_changed' => false,
                'sensitive_identifiers_exposed' => false,
            ];
        }
        $state['action'] = 'status';
        $state['idempotent'] = true;
        $state['generated_at_utc'] = $this->nowUtc();
        return $this->withFingerprint($state);
    }
}
