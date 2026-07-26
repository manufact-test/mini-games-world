<?php
declare(strict_types=1);

/**
 * Production DB-primary state is the active invite lifecycle authority. The
 * staging repository intentionally only upserts source rows, so an expired or
 * replaced invite removed from the compatibility state can otherwise remain as
 * a DB-only row and block every later invite mutation.
 *
 * Unreferenced DB-only rows are pruned inside the existing atomic transaction.
 * Rows still referenced by normalized matches are retained as historical data
 * and hidden only from active invite parity comparisons.
 */
final class ProductionRuntimeInvitesModuleProjector implements RuntimePrimaryModuleProjectorInterface
{
    private array $projectionConfig;
    private RuntimeStorageRouter $router;

    public function __construct(
        array $config,
        private DatabaseConnectionInterface $database
    ) {
        if (($config['environment'] ?? null) !== 'production') {
            throw new RuntimeException('Production invite projector requires production config.');
        }
        if ($this->database->driver() !== 'mysql') {
            throw new RuntimeException('Production invite projector requires MySQL/MariaDB.');
        }

        $this->projectionConfig = $config;
        $this->projectionConfig['environment'] = 'staging';
        $this->projectionConfig['storage_driver'] = RuntimeStorageRouter::DRIVER_JSON;
        if (!isset($this->projectionConfig['feature_flags'])
            || !is_array($this->projectionConfig['feature_flags'])) {
            $this->projectionConfig['feature_flags'] = [];
        }
        $this->projectionConfig['feature_flags']['database_runtime'] = [
            'enabled' => true,
            'modules' => [
                'accounts' => true,
                'notifications' => true,
                'invites' => true,
            ],
        ];
        $this->router = new RuntimeStorageRouter($this->projectionConfig);
    }

    public function module(): string
    {
        return 'invites';
    }

    public function project(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        $stateSha256 = $this->assertSnapshot($snapshot, $stateRevision, $stateSha256);
        $reconciliation = $this->reconcileDatabaseOnlyRows($snapshot, true);
        $repository = $this->repository($reconciliation['historical_invite_ids']);
        $project = $repository->synchronize($snapshot);
        $audit = $repository->auditParity($snapshot);

        return $this->report(
            $audit,
            $stateRevision,
            $stateSha256,
            false,
            [
                'project' => $project,
                'pruned_invite_rows' => $reconciliation['invite_rows'],
                'pruned_invite_event_rows' => $reconciliation['invite_event_rows'],
                'preserved_historical_invite_rows' => count(
                    $reconciliation['historical_invite_ids']
                ),
            ]
        );
    }

    public function audit(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        $stateSha256 = $this->assertSnapshot($snapshot, $stateRevision, $stateSha256);
        $reconciliation = $this->reconcileDatabaseOnlyRows($snapshot, false);

        return $this->report(
            $this->repository(
                $reconciliation['historical_invite_ids']
            )->auditParity($snapshot),
            $stateRevision,
            $stateSha256,
            true,
            [
                'preserved_historical_invite_rows' => count(
                    $reconciliation['historical_invite_ids']
                ),
            ]
        );
    }

    /**
     * @param list<string> $historicalInviteIds
     */
    private function repository(array $historicalInviteIds): RuntimeInviteRepository
    {
        $database = $historicalInviteIds === []
            ? $this->database
            : new ProductionInviteProjectionDatabaseView(
                $this->database,
                $historicalInviteIds
            );

        return new RuntimeInviteRepository(
            $this->projectionConfig,
            $this->router,
            $database
        );
    }

    private function reconcileDatabaseOnlyRows(array $snapshot, bool $deleteUnreferenced): array
    {
        $sourceIds = [];
        foreach ((array)($snapshot['invites'] ?? []) as $invite) {
            if (!is_array($invite)) {
                throw new RuntimeException('Production invite source row is invalid.');
            }
            $inviteId = trim((string)($invite['id'] ?? ''));
            if ($inviteId === '' || isset($sourceIds[$inviteId])) {
                throw new RuntimeException('Production invite source ID is missing or duplicated.');
            }
            $sourceIds[$inviteId] = true;
        }

        $deletedInvites = 0;
        $deletedEvents = 0;
        $historicalInviteIds = [];

        foreach ($this->database->fetchAll(
            'SELECT invite_id FROM mgw_invites ORDER BY invite_id'
        ) as $row) {
            $inviteId = trim((string)($row['invite_id'] ?? ''));
            if ($inviteId === '') {
                throw new RuntimeException('Production invite database contains an invalid ID.');
            }
            if (isset($sourceIds[$inviteId])) {
                continue;
            }

            $relatedMatches = (int)$this->database->fetchValue(
                'SELECT COUNT(*) FROM mgw_matches WHERE invite_id = :invite_id',
                ['invite_id' => $inviteId]
            );
            if ($relatedMatches > 0) {
                $historicalInviteIds[] = $inviteId;
                continue;
            }

            if (!$deleteUnreferenced) {
                continue;
            }

            $deletedEvents += $this->database->execute(
                'DELETE FROM mgw_invite_events WHERE invite_id = :invite_id',
                ['invite_id' => $inviteId]
            );
            $deleted = $this->database->execute(
                'DELETE FROM mgw_invites WHERE invite_id = :invite_id',
                ['invite_id' => $inviteId]
            );
            if ($deleted !== 1) {
                throw new RuntimeException(
                    'Production DB-only invite pruning did not delete exactly one row.'
                );
            }
            $deletedInvites++;
        }

        sort($historicalInviteIds, SORT_STRING);

        return [
            'invite_rows' => $deletedInvites,
            'invite_event_rows' => $deletedEvents,
            'historical_invite_ids' => $historicalInviteIds,
        ];
    }

    private function report(
        array $audit,
        int $stateRevision,
        string $stateSha256,
        bool $readOnly,
        array $extraSummary = []
    ): array {
        $sourceFingerprint = strtolower(trim((string)($audit['source_fingerprint'] ?? '')));
        $databaseFingerprint = strtolower(trim((string)($audit['database_fingerprint'] ?? '')));
        $blockers = array_values(array_filter(
            array_map('strval', (array)($audit['blockers'] ?? [])),
            static fn(string $value): bool => trim($value) !== ''
        ));
        $ok = ($audit['ok'] ?? false) === true
            && hash_equals($sourceFingerprint, $databaseFingerprint)
            && $blockers === [];

        return [
            'ok' => $ok,
            'parity' => $ok,
            'read_only' => $readOnly,
            'module' => 'invites',
            'state_revision' => $stateRevision,
            'state_sha256' => $stateSha256,
            'source_fingerprint' => $sourceFingerprint,
            'database_fingerprint' => $databaseFingerprint,
            'summary' => [
                'source_count' => (int)($audit['source_count'] ?? 0),
                'database_count' => (int)($audit['database_count'] ?? 0),
            ] + $extraSummary,
            'blockers' => $blockers,
        ];
    }

    private function assertSnapshot(array $snapshot, int $stateRevision, string $stateSha256): string
    {
        if ($stateRevision < 1) {
            throw new InvalidArgumentException('Production invite projection revision must be positive.');
        }
        $stateSha256 = strtolower(trim($stateSha256));
        if (preg_match('/\A[a-f0-9]{64}\z/', $stateSha256) !== 1) {
            throw new InvalidArgumentException('Production invite projection fingerprint must be SHA-256.');
        }
        $actual = hash('sha256', $this->canonicalJson($snapshot));
        if (!hash_equals($stateSha256, $actual)) {
            throw new RuntimeException('Production invite projection snapshot fingerprint mismatch.');
        }
        return $stateSha256;
    }

    private function canonicalJson(array $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }
}

/**
 * Read/write view used only by the production invite projector. It delegates
 * every operation to the real connection, except that match-referenced DB-only
 * invite rows are omitted from active lifecycle parity enumeration.
 */
final class ProductionInviteProjectionDatabaseView implements DatabaseConnectionInterface
{
    /** @var array<string, true> */
    private array $historicalIds = [];

    /**
     * @param list<string> $historicalInviteIds
     */
    public function __construct(
        private DatabaseConnectionInterface $database,
        array $historicalInviteIds
    ) {
        foreach ($historicalInviteIds as $inviteId) {
            $inviteId = trim((string)$inviteId);
            if ($inviteId === '' || isset($this->historicalIds[$inviteId])) {
                throw new InvalidArgumentException(
                    'Historical invite IDs must be unique and non-empty.'
                );
            }
            $this->historicalIds[$inviteId] = true;
        }
    }

    public function driver(): string
    {
        return $this->database->driver();
    }

    public function execute(string $sql, array $parameters = []): int
    {
        return $this->database->execute($sql, $parameters);
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        $rows = $this->database->fetchAll($sql, $parameters);
        if ($parameters !== []
            || !str_contains($sql, 'FROM mgw_invites ORDER BY invite_id')) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            fn(array $row): bool => !isset(
                $this->historicalIds[trim((string)($row['invite_id'] ?? ''))]
            )
        ));
    }

    public function fetchValue(string $sql, array $parameters = []): mixed
    {
        return $this->database->fetchValue($sql, $parameters);
    }

    public function transaction(callable $callback): mixed
    {
        return $this->database->transaction(
            fn(DatabaseConnectionInterface $database): mixed => $callback($this)
        );
    }
}
