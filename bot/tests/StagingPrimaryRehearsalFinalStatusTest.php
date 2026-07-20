<?php
declare(strict_types=1);

interface RuntimePrimaryRehearsalBackendInterface
{
    public function installSchemas(): array;
    public function synchronizeCurrentSnapshot(): array;
    public function runWorkerOnce(): array;
    public function status(): array;
    public function eventStatus(int $stateRevision): array;
}
final class StagingPrimaryRehearsalUnhealthyStatusBackend implements RuntimePrimaryRehearsalBackendInterface
{
    public function installSchemas(): array { return ['ok' => true]; }
    public function synchronizeCurrentSnapshot(): array
    {
        return [
            'ok' => true,
            'state_revision' => 1,
            'state_sha256' => str_repeat('a', 64),
        ];
    }
    public function runWorkerOnce(): array
    {
        throw new RuntimeException('Worker must not run when target is already completed.');
    }
    public function status(): array
    {
        return ['ok' => false, 'state_error' => 'aggregate status failed'];
    }
    public function eventStatus(int $stateRevision): array
    {
        return [
            'present' => true,
            'state_revision' => $stateRevision,
            'state_sha256' => str_repeat('a', 64),
            'status' => 'completed',
        ];
    }
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/StagingPrimaryRehearsalOperation.php';

$result = (new StagingPrimaryRehearsalOperation(
    ['environment' => 'staging'],
    new StagingPrimaryRehearsalUnhealthyStatusBackend()
))->rehearse();

if (($result['ok'] ?? true) !== false
    || ($result['action'] ?? '') !== 'rehearsal_incomplete'
    || ($result['target_event_completed'] ?? false) !== true
    || ($result['status_healthy'] ?? true) !== false
    || ($result['parity_completed'] ?? true) !== false) {
    throw new RuntimeException('Unhealthy final status must keep the rehearsal incomplete.');
}

fwrite(STDOUT, "StagingPrimaryRehearsalFinalStatusTest passed: 1 assertion.\n");
