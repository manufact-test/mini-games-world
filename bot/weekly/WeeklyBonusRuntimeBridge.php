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

    public function synchronizeCurrentJson(): ?array
    {
        if (!$this->enabled()) return null;

        $storage = $this->storage ??= StorageFactory::create($this->config);
        if ($storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
            throw new RuntimeException('Weekly bonus bridge requires JSON rollback storage.');
        }
        if (!$storage instanceof ExclusiveSnapshotStorageInterface) {
            throw new RuntimeException('Weekly bonus bridge requires exclusive JSON snapshot support.');
        }

        return $storage->exclusiveReadOnly(function (array $snapshot) use ($storage): array {
            $realtime = (new RealtimeRuntimeBridge($this->config, $this->router, $storage))->synchronizeCurrentJson();
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

        // The API success hook synchronizes and audits the weekly projection
        // before response filters run. Development users are intentionally
        // excluded by that projection. Reusing the already-created repository
        // above avoids opening a second PDO connection solely to prove the same
        // exclusion again; any stale/extra DB row has already failed parity.
        return (new WeeklyMatchEconomyService($this->config))->status($snapshot, $user);
    }

    private function repository(): RuntimeWeeklyBonusRepository
    {
        // Dependency injection must remain self-contained: callers that provide
        // an already-verified repository do not need StorageFactory or another
        // storage selection pass merely to read through that repository.
        if ($this->repository !== null) return $this->repository;

        $storage = $this->storage ??= StorageFactory::create($this->config);
        return $this->repository = new RuntimeWeeklyBonusRepository(
            $this->config,
            $this->router,
            $storage
        );
    }
}