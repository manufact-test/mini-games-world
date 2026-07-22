<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/database/DatabaseConnectionInterface.php';
require_once $projectRoot . '/bot/accounts/MgwIdGenerator.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStateSchemaInstaller.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionOutboxSchemaInstaller.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionWorkerInterface.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiMutatingSmokeIdentityCleanup.php';

final class ApiMutatingSmokeCleanupFakeDatabase implements DatabaseConnectionInterface
{
    public array $users = [];
    public array $identities = [];
    public array $ownership = [];
    public array $devices = [];
    public array $sessions = [];
    public array $balances = [];
    public array $ledger = [];
    public array $idempotency = [];
    public array $reservations = [];
    public array $outbox = [];
    public array $log = [];
    public int $currentRevision = 12;

    public function driver(): string
    {
        return 'mysql';
    }

    public function execute(string $sql, array $parameters = []): int
    {
        $sql = $this->normalize($sql);
        $mgwId = (string)($parameters['mgw_id'] ?? '');
        $legacy = (string)($parameters['legacy_user_id'] ?? '');
        $accountRef = (string)($parameters['account_ref'] ?? '');

        if (str_starts_with($sql, 'UPDATE ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE)) {
            $revision = (int)($parameters['state_revision'] ?? 0);
            $expected = (string)($parameters['expected_status'] ?? '');
            foreach ($this->outbox as &$row) {
                if ((int)($row['state_revision'] ?? 0) !== $revision
                    || (string)($row['status'] ?? '') !== $expected) {
                    continue;
                }
                $row['status'] = 'pending';
                $this->log[] = 'reset:' . $revision;
                unset($row);
                return 1;
            }
            unset($row);
            return 0;
        }
        if (str_starts_with($sql, 'DELETE FROM mgw_ledger_entries')) {
            $this->log[] = 'delete:ledger';
            return $this->deleteWhere($this->ledger, static fn(array $row): bool =>
                (string)($row['account_ref'] ?? '') === $accountRef
                && (string)($row['mgw_id'] ?? '') === $mgwId
                && (string)($row['legacy_user_id'] ?? '') === $legacy
            );
        }
        if (str_starts_with($sql, 'DELETE FROM mgw_idempotency_keys')) {
            $this->log[] = 'delete:idempotency';
            return $this->deleteWhere($this->idempotency, static fn(array $row): bool =>
                (string)($row['owner_ref'] ?? '') === $accountRef
            );
        }
        if (str_starts_with($sql, 'DELETE FROM mgw_balances')) {
            $this->log[] = 'delete:balances';
            return $this->deleteWhere($this->balances, static fn(array $row): bool =>
                (string)($row['account_ref'] ?? '') === $accountRef
                && (string)($row['mgw_id'] ?? '') === $mgwId
                && (string)($row['legacy_user_id'] ?? '') === $legacy
            );
        }
        if (str_starts_with($sql, 'DELETE FROM mgw_sessions')) {
            $this->log[] = 'delete:sessions';
            $hash = (string)($parameters['session_key_hash'] ?? '');
            return $this->deleteWhere($this->sessions, static fn(array $row): bool =>
                (string)($row['mgw_id'] ?? '') === $mgwId
                && (string)($row['session_key_hash'] ?? '') === $hash
            );
        }
        if (str_starts_with($sql, 'DELETE FROM mgw_devices')) {
            $this->log[] = 'delete:devices';
            return $this->deleteWhere($this->devices, static fn(array $row): bool =>
                (string)($row['mgw_id'] ?? '') === $mgwId
            );
        }
        if (str_starts_with($sql, 'DELETE FROM mgw_account_ownership')) {
            $this->log[] = 'delete:ownership';
            return $this->deleteWhere($this->ownership, static fn(array $row): bool =>
                (string)($row['mgw_id'] ?? '') === $mgwId
                && (string)($row['legacy_user_id'] ?? '') === $legacy
                && (string)($row['account_ref'] ?? '') === $accountRef
            );
        }
        if (str_starts_with($sql, 'DELETE FROM mgw_identities')) {
            $this->log[] = 'delete:identities';
            return $this->deleteWhere($this->identities, static fn(array $row): bool =>
                (string)($row['mgw_id'] ?? '') === $mgwId
            );
        }
        if (str_starts_with($sql, 'DELETE FROM mgw_users')) {
            $this->log[] = 'delete:users';
            return $this->deleteWhere($this->users, static fn(array $row): bool =>
                (string)($row['mgw_id'] ?? '') === $mgwId
            );
        }

        throw new RuntimeException('Unexpected fake cleanup execute SQL: ' . $sql);
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        $sql = $this->normalize($sql);
        $mgwId = (string)($parameters['mgw_id'] ?? '');
        $legacy = (string)($parameters['legacy_user_id'] ?? '');
        $accountRef = (string)($parameters['account_ref'] ?? '');

        if (str_contains($sql, 'FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE)) {
            $current = (int)($parameters['current_revision'] ?? 0);
            $rows = array_values(array_filter($this->outbox, static fn(array $row): bool =>
                (string)($row['status'] ?? '') !== 'completed'
                && (int)($row['state_revision'] ?? 0) < $current
            ));
            usort($rows, static fn(array $a, array $b): int =>
                (int)$a['state_revision'] <=> (int)$b['state_revision']
            );
            return $rows === [] ? [] : [$rows[0]];
        }
        if (str_contains($sql, 'FROM mgw_identities')
            && str_contains($sql, 'provider = :provider')
            && str_contains($sql, 'provider_subject = :subject')) {
            $provider = (string)($parameters['provider'] ?? '');
            $subject = (string)($parameters['subject'] ?? '');
            return array_values(array_filter($this->identities, static fn(array $row): bool =>
                (string)($row['provider'] ?? '') === $provider
                && (string)($row['provider_subject'] ?? '') === $subject
            ));
        }
        if (str_contains($sql, 'FROM mgw_account_ownership')) {
            return $this->matchingIdentityRows($this->ownership, $mgwId, $legacy, $accountRef);
        }
        if (str_contains($sql, 'FROM mgw_reservations')) {
            return $this->matchingIdentityRows($this->reservations, $mgwId, $legacy, $accountRef);
        }
        if (str_contains($sql, 'FROM mgw_balances')) {
            $rows = $this->matchingIdentityRows($this->balances, $mgwId, $legacy, $accountRef);
            usort($rows, static fn(array $a, array $b): int =>
                strcmp((string)($a['asset_code'] ?? ''), (string)($b['asset_code'] ?? ''))
            );
            return $rows;
        }
        if (str_contains($sql, 'FROM mgw_ledger_entries')) {
            return $this->matchingIdentityRows($this->ledger, $mgwId, $legacy, $accountRef);
        }
        if (str_contains($sql, 'FROM mgw_idempotency_keys')) {
            return array_values(array_filter($this->idempotency, static fn(array $row): bool =>
                (string)($row['owner_ref'] ?? '') === $accountRef
            ));
        }

        throw new RuntimeException('Unexpected fake cleanup fetchAll SQL: ' . $sql);
    }

    public function fetchValue(string $sql, array $parameters = []): mixed
    {
        $sql = $this->normalize($sql);
        if (str_contains($sql, 'FROM ' . RuntimePrimaryStateSchemaInstaller::TABLE)) {
            return $this->currentRevision;
        }

        $mgwId = (string)($parameters['mgw_id'] ?? '');
        $legacy = (string)($parameters['legacy_user_id'] ?? '');
        $accountRef = (string)($parameters['account_ref'] ?? '');
        $sessionHash = (string)($parameters['session_key_hash'] ?? '');
        $provider = (string)($parameters['provider'] ?? '');
        $subject = (string)($parameters['subject'] ?? '');

        if (str_contains($sql, 'FROM mgw_users')) {
            return count(array_filter($this->users, static fn(array $row): bool =>
                (string)($row['mgw_id'] ?? '') === $mgwId
            ));
        }
        if (str_contains($sql, 'FROM mgw_identities')) {
            return count(array_filter($this->identities, static fn(array $row): bool =>
                ($mgwId !== '' && (string)($row['mgw_id'] ?? '') === $mgwId)
                || ($provider !== '' && $subject !== ''
                    && (string)($row['provider'] ?? '') === $provider
                    && (string)($row['provider_subject'] ?? '') === $subject)
            ));
        }
        if (str_contains($sql, 'FROM mgw_account_ownership')) {
            return count($this->matchingIdentityRows($this->ownership, $mgwId, $legacy, $accountRef));
        }
        if (str_contains($sql, 'FROM mgw_devices')) {
            return count(array_filter($this->devices, static fn(array $row): bool =>
                (string)($row['mgw_id'] ?? '') === $mgwId
            ));
        }
        if (str_contains($sql, 'FROM mgw_sessions')) {
            return count(array_filter($this->sessions, static fn(array $row): bool =>
                ($mgwId !== '' && (string)($row['mgw_id'] ?? '') === $mgwId)
                || ($sessionHash !== '' && (string)($row['session_key_hash'] ?? '') === $sessionHash)
            ));
        }
        if (str_contains($sql, 'FROM mgw_balances')) {
            return count($this->matchingIdentityRows($this->balances, $mgwId, $legacy, $accountRef));
        }
        if (str_contains($sql, 'FROM mgw_ledger_entries')) {
            return count($this->matchingIdentityRows($this->ledger, $mgwId, $legacy, $accountRef));
        }
        if (str_contains($sql, 'FROM mgw_idempotency_keys')) {
            return count(array_filter($this->idempotency, static fn(array $row): bool =>
                (string)($row['owner_ref'] ?? '') === $accountRef
            ));
        }
        if (str_contains($sql, 'FROM mgw_reservations')) {
            return count($this->matchingIdentityRows($this->reservations, $mgwId, $legacy, $accountRef));
        }

        throw new RuntimeException('Unexpected fake cleanup fetchValue SQL: ' . $sql);
    }

    public function transaction(callable $callback): mixed
    {
        $snapshot = serialize([
            $this->users, $this->identities, $this->ownership, $this->devices,
            $this->sessions, $this->balances, $this->ledger, $this->idempotency,
            $this->reservations, $this->outbox, $this->log,
        ]);
        try {
            return $callback($this);
        } catch (Throwable $error) {
            [
                $this->users, $this->identities, $this->ownership, $this->devices,
                $this->sessions, $this->balances, $this->ledger, $this->idempotency,
                $this->reservations, $this->outbox, $this->log,
            ] = unserialize($snapshot, ['allowed_classes' => false]);
            throw $error;
        }
    }

    public function completeOutboxRevision(int $revision): void
    {
        foreach ($this->outbox as &$row) {
            if ((int)($row['state_revision'] ?? 0) !== $revision) continue;
            $row['status'] = 'completed';
            $this->log[] = 'project:' . $revision;
            unset($row);
            return;
        }
        unset($row);
        throw new RuntimeException('Fake projection revision was not found.');
    }

    private function matchingIdentityRows(
        array $rows,
        string $mgwId,
        string $legacy,
        string $accountRef
    ): array {
        return array_values(array_filter($rows, static fn(array $row): bool =>
            ($mgwId !== '' && (string)($row['mgw_id'] ?? '') === $mgwId)
            || ($legacy !== '' && (string)($row['legacy_user_id'] ?? '') === $legacy)
            || ($accountRef !== '' && (string)($row['account_ref'] ?? '') === $accountRef)
        ));
    }

    private function deleteWhere(array &$rows, callable $predicate): int
    {
        $before = count($rows);
        $rows = array_values(array_filter($rows, static fn(array $row): bool => !$predicate($row)));
        return $before - count($rows);
    }

    private function normalize(string $sql): string
    {
        return preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
    }
}

final class ApiMutatingSmokeCleanupFakeWorker implements RuntimePrimaryProjectionWorkerInterface
{
    public function __construct(private ApiMutatingSmokeCleanupFakeDatabase $database) {}

    public function runOnce(): array
    {
        $rows = array_values(array_filter($this->database->outbox, fn(array $row): bool =>
            (string)($row['status'] ?? '') !== 'completed'
            && (int)($row['state_revision'] ?? 0) < $this->database->currentRevision
        ));
        usort($rows, static fn(array $a, array $b): int =>
            (int)$a['state_revision'] <=> (int)$b['state_revision']
        );
        if ($rows === []) {
            return ['ok' => true, 'action' => 'projection_noop', 'claimed' => false];
        }
        $revision = (int)$rows[0]['state_revision'];
        $this->database->completeOutboxRevision($revision);
        return [
            'ok' => true,
            'action' => 'projection_completed',
            'claimed' => true,
            'state_revision' => $revision,
            'parity_ok' => true,
        ];
    }
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . '.');
    }
};

$provider = 'telegram';
$subject = '912345678901234567';
$sessionId = str_repeat('a', 64);
$sessionHash = hash('sha256', 'session|' . $sessionId);
$mgwId = 'MGW-0123456789ABCDEF';
$accountRef = 'legacy:' . $subject;
$openingKey = 'runtime_opening:v1:' . str_repeat('b', 48);

$seedFull = static function (ApiMutatingSmokeCleanupFakeDatabase $database) use (
    $mgwId,
    $subject,
    $sessionHash,
    $accountRef,
    $openingKey
): void {
    $database->users = [['mgw_id' => $mgwId]];
    $database->identities = [
        ['mgw_id' => $mgwId, 'provider' => 'telegram', 'provider_subject' => $subject],
        ['mgw_id' => $mgwId, 'provider' => 'legacy_import', 'provider_subject' => $subject],
    ];
    $database->ownership = [[
        'mgw_id' => $mgwId,
        'legacy_user_id' => $subject,
        'account_ref' => $accountRef,
    ]];
    $database->devices = [['mgw_id' => $mgwId]];
    $database->sessions = [['mgw_id' => $mgwId, 'session_key_hash' => $sessionHash]];
    $database->balances = [
        [
            'account_ref' => $accountRef,
            'mgw_id' => $mgwId,
            'legacy_user_id' => $subject,
            'asset_code' => 'gold_coin',
            'reserved_amount' => 0,
        ],
        [
            'account_ref' => $accountRef,
            'mgw_id' => $mgwId,
            'legacy_user_id' => $subject,
            'asset_code' => 'match_coin',
            'reserved_amount' => 0,
        ],
    ];
    $database->ledger = [[
        'idempotency_key' => $openingKey,
        'account_ref' => $accountRef,
        'mgw_id' => $mgwId,
        'legacy_user_id' => $subject,
        'asset_code' => 'match_coin',
        'category' => 'legacy_runtime_opening',
        'source_type' => 'legacy_json_runtime',
        'reservation_id' => null,
    ]];
    $database->idempotency = [[
        'operation_key' => $openingKey,
        'operation_type' => 'available_delta',
        'owner_ref' => $accountRef,
        'status' => 'completed',
    ]];
};

$cleanupAll = static function (
    ApiMutatingSmokeCleanupFakeDatabase $database,
    RuntimePrimaryStagingApiMutatingSmokeIdentityCleanup $cleanup
) use ($assertSame, $provider, $subject, $sessionId, $mgwId): array {
    $assertSame($mgwId, $cleanup->resolveCreatedMgwId($provider, $subject, $subject), 'Cleanup MGW ID resolution failed.');
    $removed = $cleanup->removeMappingsBeforeCleanupProjection(
        $provider,
        $subject,
        $subject,
        $sessionId,
        $mgwId
    );
    $userRemoved = $cleanup->removeOrphanUserAfterCleanupProjection($mgwId);
    $assertSame(true, $cleanup->assertAbsent(
        $provider,
        $subject,
        $subject,
        $sessionId,
        $mgwId
    )['ok'] ?? false, 'Cleanup absence proof failed.');
    return [$removed, $userRemoved];
};

$full = new ApiMutatingSmokeCleanupFakeDatabase();
$seedFull($full);
[$removed, $userRemoved] = $cleanupAll(
    $full,
    new RuntimePrimaryStagingApiMutatingSmokeIdentityCleanup($full)
);
$assertSame(2, $removed['economy_balance_rows_deleted'] ?? null, 'Full cleanup balance count mismatch.');
$assertSame(1, $removed['economy_ledger_rows_deleted'] ?? null, 'Full cleanup ledger count mismatch.');
$assertSame(1, $removed['economy_idempotency_rows_deleted'] ?? null, 'Full cleanup idempotency count mismatch.');
$assertSame(true, $removed['economy_reservation_rows_verified_zero'] ?? null, 'Full cleanup reservation proof mismatch.');
$assertSame(1, $removed['session_rows_deleted'] ?? null, 'Full cleanup session count mismatch.');
$assertSame(1, $removed['device_rows_deleted'] ?? null, 'Full cleanup device count mismatch.');
$assertSame(1, $removed['ownership_rows_deleted'] ?? null, 'Full cleanup ownership count mismatch.');
$assertSame(2, $removed['identity_rows_deleted'] ?? null, 'Full cleanup identity count mismatch.');
$assertSame(1, $userRemoved['user_rows_deleted'] ?? null, 'Full cleanup user count mismatch.');
$ledgerIndex = array_search('delete:ledger', $full->log, true);
$mappingIndex = array_search('delete:sessions', $full->log, true);
$assertSame(true, is_int($ledgerIndex) && is_int($mappingIndex) && $ledgerIndex < $mappingIndex, 'Economy rows were not deleted before mappings.');

$partial = new ApiMutatingSmokeCleanupFakeDatabase();
$partial->users = [['mgw_id' => $mgwId]];
$partial->identities = [[
    'mgw_id' => $mgwId,
    'provider' => 'telegram',
    'provider_subject' => $subject,
]];
[$partialRemoved, $partialUserRemoved] = $cleanupAll(
    $partial,
    new RuntimePrimaryStagingApiMutatingSmokeIdentityCleanup($partial)
);
$assertSame(0, $partialRemoved['economy_balance_rows_deleted'] ?? null, 'Partial cleanup balance count mismatch.');
$assertSame(0, $partialRemoved['economy_ledger_rows_deleted'] ?? null, 'Partial cleanup ledger count mismatch.');
$assertSame(0, $partialRemoved['economy_idempotency_rows_deleted'] ?? null, 'Partial cleanup idempotency count mismatch.');
$assertSame(1, $partialRemoved['identity_rows_deleted'] ?? null, 'Partial cleanup identity count mismatch.');
$assertSame(1, $partialUserRemoved['user_rows_deleted'] ?? null, 'Partial cleanup user count mismatch.');

$incident = new ApiMutatingSmokeCleanupFakeDatabase();
$seedFull($incident);
$incident->identities = [];
$incident->ownership = [];
$incident->devices = [];
$incident->sessions = [];
$incidentCleanup = new RuntimePrimaryStagingApiMutatingSmokeIdentityCleanup($incident);
$incidentProof = $incidentCleanup->recoverOrphanEconomyArtifacts($subject, $mgwId);
$assertSame(true, $incidentProof['recovery_orphan_verified'] ?? null, 'Incident orphan proof mismatch.');
$assertSame(2, $incidentProof['economy_balance_rows_deleted'] ?? null, 'Incident balance cleanup mismatch.');
$assertSame(1, $incidentProof['economy_ledger_rows_deleted'] ?? null, 'Incident ledger cleanup mismatch.');
$assertSame(1, $incidentProof['economy_idempotency_rows_deleted'] ?? null, 'Incident idempotency cleanup mismatch.');
$assertSame(1, $incidentCleanup->removeOrphanUserAfterCleanupProjection($mgwId)['user_rows_deleted'] ?? null, 'Incident orphan user cleanup mismatch.');

$ordered = new ApiMutatingSmokeCleanupFakeDatabase();
$seedFull($ordered);
$ordered->outbox = [
    ['state_revision' => 11, 'status' => 'pending'],
    ['state_revision' => 12, 'status' => 'pending'],
];
$orderedCleanup = new RuntimePrimaryStagingApiMutatingSmokeIdentityCleanup(
    $ordered,
    static fn(DatabaseConnectionInterface $database): RuntimePrimaryProjectionWorkerInterface =>
        new ApiMutatingSmokeCleanupFakeWorker($database)
);
[$orderedRemoved] = $cleanupAll($ordered, $orderedCleanup);
$assertSame(1, $orderedRemoved['earlier_projection_events_completed'] ?? null, 'Earlier projection completion count mismatch.');
$assertSame('completed', $ordered->outbox[0]['status'] ?? null, 'Earlier API projection was not completed.');
$assertSame('pending', $ordered->outbox[1]['status'] ?? null, 'Current cleanup projection must remain pending.');
$projectIndex = array_search('project:11', $ordered->log, true);
$deleteIndex = array_search('delete:ledger', $ordered->log, true);
$assertSame(true, is_int($projectIndex) && is_int($deleteIndex) && $projectIndex < $deleteIndex, 'Economy rows were deleted before the earlier projection completed.');

fwrite(STDOUT, "RuntimePrimaryStagingApiMutatingSmokeIdentityCleanupTest passed: {$assertions} assertions.\n");
