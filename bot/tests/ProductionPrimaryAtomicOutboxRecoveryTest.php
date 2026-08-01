<?php
declare(strict_types=1);

// Reuse the production-atomic in-memory database, worker and auditor fixtures.
// The required test also proves the ordinary contiguous-outbox path first.
require __DIR__ . '/ProductionPrimaryAtomicStorageAdapterTest.php';

$recoveryAssertions = 0;
$assertRecovery = static function (bool $condition, string $message) use (&$recoveryAssertions): void {
    $recoveryAssertions++;
    if (!$condition) throw new RuntimeException($message);
};
$makeRecoveryStorage = static function (
    ProductionAtomicLatencyTestDatabase $database,
    ProductionAtomicLatencyTestWorker $worker,
    ProductionAtomicLatencyTestAuditor $auditor
): ProductionPrimaryAtomicStorageAdapter {
    return new ProductionPrimaryAtomicStorageAdapter(
        $database,
        new DatabasePrimaryStateStorageAdapter(
            $database,
            new RuntimePrimaryProjectionOutboxWriter()
        ),
        $worker,
        $auditor
    );
};

// This matches production immediately after the completed technical outbox was
// administratively truncated: primary state and normalized module projections
// remain valid, while completed delivery history is intentionally empty.
$database = new ProductionAtomicLatencyTestDatabase();
$database->events = [];
$worker = new ProductionAtomicLatencyTestWorker($database);
$auditor = new ProductionAtomicLatencyTestAuditor();
$storage = $makeRecoveryStorage($database, $worker, $auditor);

$value = $storage->transaction(static fn(array &$data): int => count($data['users'] ?? []));
$assertRecovery($value === 0, 'An empty completed outbox must not block a read-compatible bootstrap transaction.');
$assertRecovery((int)$database->state['revision'] === 1, 'A no-change recovery transaction must preserve primary revision.');
$assertRecovery($worker->calls === 0, 'A no-change recovery transaction must not manufacture an outbox event.');
$assertRecovery($auditor->calls === 1, 'Missing retained history must be replaced by one read-only full-module parity audit.');
$report = $storage->lastTransactionReport();
$assertRecovery(($report['baseline_projection_chain_verified'] ?? true) === false, 'Empty history must not be reported as a verified retained chain.');
$assertRecovery(($report['baseline_full_module_audit_executed'] ?? false) === true, 'Empty history must be accepted only after the parity audit succeeds.');

$result = $storage->transaction(static function (array &$data): string {
    $data['users']['recovered_user'] = ['id' => 'recovered_user', 'balance_match' => 50];
    return 'recovered';
});
$assertRecovery($result === 'recovered', 'The first real mutation after cleanup must commit normally.');
$assertRecovery((int)$database->state['revision'] === 2, 'Recovery mutation must advance exactly one state revision.');
$assertRecovery(($database->events[2]['status'] ?? '') === 'completed', 'Recovery mutation must create and finish the current projection event.');
$assertRecovery($worker->calls === 1, 'Recovery mutation must execute exactly one projection worker tick.');
$assertRecovery($auditor->calls === 2, 'The still-empty baseline must be audited before the first recovery mutation.');
$report = $storage->lastTransactionReport();
$assertRecovery(($report['baseline_full_module_audit_executed'] ?? false) === true, 'Recovery mutation must record its baseline audit.');
$assertRecovery(($report['final_full_module_audit_executed'] ?? true) === false, 'Completed current projection must provide final parity without a duplicate audit.');

$second = $storage->transaction(static fn(array &$data): int => count($data['users'] ?? []));
$assertRecovery($second === 1, 'A retained current event must support the next normal transaction.');
$assertRecovery($auditor->calls === 2, 'Once the current completed event exists, repeated full-module audits must stop.');
$assertRecovery(($storage->lastTransactionReport()['baseline_projection_chain_verified'] ?? false) === true, 'The new bounded retained tail must verify the current revision.');

fwrite(
    STDOUT,
    "ProductionPrimaryAtomicOutboxRecoveryTest passed: {$recoveryAssertions} assertions.\n"
);
