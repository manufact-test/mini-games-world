<?php
declare(strict_types=1);

/**
 * Production DB-primary state is the exact invite lifecycle authority. The
 * staging repository intentionally only upserts source rows, so a terminal or
 * expired invite removed from the compatibility state can otherwise remain as
 * a DB-only row and block every later invite mutation. This projector prunes
 * only rows absent from the locked source snapshot and refuses to remove any
 * invite that is still referenced by a normalized match.
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
        $prune = $this->pruneDatabaseOnlyRows($snapshot);
        $repository = $this->repository();
        $project = $repository->synchronize($snapshot);
        $audit = $repository->auditParity($snapshot);

        return $this->report(
            $audit,
            $stateRevision,
            $stateSha256,
            false,
            [
                'project' => $project,
                'pruned_invite_rows' => $prune['invite_rows'],
                'pruned_invite_event_rows' => $prune['invite_event_rows'],
            ]
        );
    }

    public function audit(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        $stateSha256 = $this->assertSnapshot($snapshot, $stateRevision, $stateSha256);

        return $this->report(
            $this->repository()->auditParity($snapshot),
            $stateRevision,
            $stateSha256,
            true
        );
    }

    private function repository(): RuntimeInviteRepository
    {
        return new RuntimeInviteRepository(
            $this->projectionConfig,
            $this->router,
            $this->database
        );
    }

    private function pruneDatabaseOnlyRows(array $snapshot): array
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
        foreach ($this->database->fetchAll(
            'SELECT invite_id FROM mgw_invites ORDER BY invite_id'
        ) as $row) {
            $inviteId = trim((string)($row['invite_id'] ?? ''));
            if ($inviteId === '' || isset($sourceIds[$inviteId])) {
                continue;
            }

            $relatedMatches = (int)$this->database->fetchValue(
                'SELECT COUNT(*) FROM mgw_matches WHERE invite_id = :invite_id',
                ['invite_id' => $inviteId]
            );
            if ($relatedMatches !== 0) {
                throw new RuntimeException(
                    'Production DB-only invite is still referenced by a normalized match.'
                );
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

        return [
            'invite_rows' => $deletedInvites,
            'invite_event_rows' => $deletedEvents,
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
