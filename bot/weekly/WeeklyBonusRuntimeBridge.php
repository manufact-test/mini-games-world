<?php
declare(strict_types=1);

final class WeeklyBonusRuntimeBridge
{
    private RuntimeStorageRouter $router;
    private ?RuntimeWeeklyBonusRepository $repository;
    private ?StorageAdapterInterface $storage;

    public function __construct(
        private array $config,
        ?RuntimeStorageRouter $router = null,
        ?RuntimeWeeklyBonusRepository $repository = null,
        ?StorageAdapterInterface $storage = null
    ) {
        $this->router = $router ?? new RuntimeStorageRouter($config);
        $this->repository = $repository;
        $this->storage = $storage;
    }

    public function enabled(): bool
    {
        return $this->router->routeFor('weekly_bonus') === RuntimeStorageRouter::DRIVER_DATABASE;
    }

    public function shouldAttachToCurrentRequest(array $server): bool
    {
        if (!$this->enabled()) return false;
        $script = trim((string)($server['SCRIPT_FILENAME'] ?? $server['PHP_SELF'] ?? ''));
        return $script !== '' && basename($script) === 'api.php';
    }

    public function shouldSynchronizeApiAction(string $action): bool
    {
        return $this->enabled();
    }

    /**
     * Forced synchronization owner used by operations, audits and compatibility
     * tooling. This method intentionally always performs the full frozen-snapshot
     * projection and never consults the API dirty marker.
     */
    public function synchronizeCurrentJson(): ?array
    {
        if (!$this->enabled()) return null;

        $storage = $this->storage ??= StorageFactory::create($this->config);
        $this->assertProjectionStorage($storage);
        return $this->synchronizeWithStorage($storage, false);
    }

    /**
     * API-only synchronization owner. Runtime projection serializes with other
     * projectors but no longer owns the gameplay writer barrier while external
     * DB I/O/parity runs. The dirty generation is acknowledged only if no newer
     * JSON writer published after the captured projection snapshot.
     */
    public function synchronizeCurrentJsonIfDirty(): ?array
    {
        if (!$this->enabled()) return null;

        $storage = $this->storage ??= StorageFactory::create($this->config);
        $this->assertProjectionStorage($storage);

        // Non-JSON compatibility adapters used by narrow tools may not expose
        // the durable marker. Preserve historical fail-closed behavior by doing
        // a forced projection rather than silently skipping one.
        if (!$storage instanceof ProjectionDirtyStorageInterface) {
            return $this->synchronizeWithStorage($storage, false);
        }

        $dirty = $storage->runtimeProjectionDirty();
        if (!$dirty) {
            return $this->cleanProjectionResult('skip_clean');
        }

        return $this->synchronizeWithStorage($storage, true);
    }

    private function synchronizeWithStorage(
        StorageAdapterInterface $storage,
        bool $onlyIfDirty
    ): array {
        if (!$storage instanceof ExclusiveSnapshotStorageInterface) {
            throw new RuntimeException('Weekly bonus bridge requires exclusive JSON snapshot support.');
        }

        $project = function (callable $callback) use ($storage, $onlyIfDirty): mixed {
            if ($onlyIfDirty && $storage instanceof ProjectionSnapshotStorageInterface) {
                return $storage->projectionReadOnly($callback);
            }
            return $storage->exclusiveReadOnly($callback);
        };

        return $project(function (array $snapshot) use (
            $storage,
            $onlyIfDirty
        ): array {
            // A previous serialized projector may already have satisfied this
            // dirty generation. Re-check after acquiring the projection owner.
            if ($onlyIfDirty
                && $storage instanceof ProjectionDirtyStorageInterface
                && !$storage->runtimeProjectionDirty()) {
                return $this->cleanProjectionResult('skip_coalesced');
            }

            $realtime = (new RealtimeRuntimeBridge($this->config, $this->router, $storage))
                ->synchronizeCurrentJson();
            $result = $this->repository()->synchronizeCurrentJson();

            $notificationRepository = new RuntimeNotificationRepository($this->config, $this->router);
            $auditedUsers = 0;
            $sourceCount = 0;
            $databaseCount = 0;
            foreach (is_array($snapshot['users'] ?? null) ? $snapshot['users'] : [] as $key => $user) {
                if (!is_array($user) || !empty($user['is_dev_user'])) continue;
                $legacyUserId = trim((string)($user['id'] ?? $key));
                if ($legacyUserId === '') continue;
                $sync = $notificationRepository->synchronizeAndList($snapshot, $legacyUserId);
                $auditedUsers++;
                $sourceCount += (int)($sync['summary']['source_count'] ?? 0);
                $databaseCount += (int)($sync['summary']['database_count'] ?? 0);
            }

            $result['runtime_realtime'] = is_array($realtime) ? [
                'game_source_count' => (int)($realtime['games']['source_count'] ?? 0),
                'game_database_count' => (int)($realtime['games']['database_count'] ?? 0),
                'queue_source_count' => (int)($realtime['queue']['source_count'] ?? 0),
                'queue_database_count' => (int)($realtime['queue']['database_count'] ?? 0),
                'parity' => !empty($realtime['parity']),
            ] : null;
            $result['notifications'] = [
                'ok' => $sourceCount === $databaseCount,
                'audited_user_count' => $auditedUsers,
                'source_count' => $sourceCount,
                'database_count' => $databaseCount,
            ];
            if ($sourceCount !== $databaseCount) {
                throw new RuntimeException('Weekly bonus notification runtime parity failed.');
            }

            // Clearing remains the final side effect. ProjectionSnapshot storage
            // performs compare-and-clear against the generation captured with
            // this snapshot, so a concurrent newer JSON writer keeps dirty=true.
            if ($onlyIfDirty && $storage instanceof ProjectionDirtyStorageInterface) {
                $storage->clearRuntimeProjectionDirty();
                $pending = $storage->runtimeProjectionDirty();
                $result['projection_dirty_cleared'] = !$pending;
                $result['projection_dirty_pending_newer_write'] = $pending;
            }

            return $result;
        });
    }

    public function normalizeApiData(array $data, string $action): array
    {
        if (!$this->enabled() || !isset($data['weekly_match']) || !is_array($data['weekly_match'])) {
            return $data;
        }

        $legacyUserId = trim((string)($data['user']['id'] ?? ''));
        if ($legacyUserId === '') return $data;

        try {
            $data['weekly_match'] = $this->repository()->statusForLegacyUser($legacyUserId);
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'Weekly bonus DB state is missing or ambiguous.') {
                throw $error;
            }

            $fallback = $this->statusForExcludedDevelopmentUser($legacyUserId);
            if ($fallback === null) {
                throw $error;
            }
            $data['weekly_match'] = $fallback;
        }
        return $data;
    }

    private function statusForExcludedDevelopmentUser(string $legacyUserId): ?array
    {
        $storage = $this->storage ??= StorageFactory::create($this->config);
        if ($storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
            throw new RuntimeException('Weekly bonus development fallback requires JSON rollback storage.');
        }
        $snapshot = $storage->readOnly(static fn(array $data): array => $data);
        if (!is_array($snapshot)) {
            throw new RuntimeException('Weekly bonus development fallback could not read JSON state.');
        }

        $user = $snapshot['users'][$legacyUserId] ?? null;
        if (!is_array($user) || (string)($user['id'] ?? $legacyUserId) !== $legacyUserId) {
            $user = null;
            foreach (is_array($snapshot['users'] ?? null) ? $snapshot['users'] : [] as $candidate) {
                if (is_array($candidate) && (string)($candidate['id'] ?? '') === $legacyUserId) {
                    $user = $candidate;
                    break;
                }
            }
        }
        if (!is_array($user) || empty($user['is_dev_user'])) {
            return null;
        }

        return (new WeeklyMatchEconomyService($this->config))->status($snapshot, $user);
    }

    private function repository(): RuntimeWeeklyBonusRepository
    {
        if ($this->repository !== null) return $this->repository;

        $storage = $this->storage ??= StorageFactory::create($this->config);
        return $this->repository = new RuntimeWeeklyBonusRepository(
            $this->config,
            $this->router,
            $storage
        );
    }

    private function assertProjectionStorage(StorageAdapterInterface $storage): void
    {
        if ($storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
            throw new RuntimeException('Weekly bonus bridge requires JSON rollback storage.');
        }
        if (!$storage instanceof ExclusiveSnapshotStorageInterface) {
            throw new RuntimeException('Weekly bonus bridge requires exclusive JSON snapshot support.');
        }
    }

    private function cleanProjectionResult(string $action): array
    {
        return [
            'ok' => true,
            'action' => $action,
            'storage_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'projection_dirty' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }
}
