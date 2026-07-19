<?php
declare(strict_types=1);

final class WeeklyBonusRuntimeBridge
{
    private RuntimeStorageRouter $router;
    private ?RuntimeWeeklyBonusRepository $repository;

    public function __construct(
        private array $config,
        ?RuntimeStorageRouter $router = null,
        ?RuntimeWeeklyBonusRepository $repository = null
    ) {
        $this->router = $router ?? new RuntimeStorageRouter($config);
        $this->repository = $repository;
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

        $realtime = (new RealtimeRuntimeBridge($this->config, $this->router))->synchronizeCurrentJson();
        $result = $this->repository()->synchronizeCurrentJson();

        $storage = StorageFactory::create($this->config);
        if ($storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
            throw new RuntimeException('Weekly bonus bridge requires JSON rollback storage.');
        }
        $snapshot = $storage->readOnly(static fn(array $data): array => $data);
        if (!is_array($snapshot)) throw new RuntimeException('Weekly bonus bridge could not read JSON state.');

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
    }

    public function normalizeApiData(array $data, string $action): array
    {
        if (!$this->enabled() || !isset($data['weekly_match']) || !is_array($data['weekly_match'])) {
            return $data;
        }

        $legacyUserId = trim((string)($data['user']['id'] ?? ''));
        if ($legacyUserId === '') return $data;
        $data['weekly_match'] = $this->repository()->statusForLegacyUser($legacyUserId);
        return $data;
    }

    private function repository(): RuntimeWeeklyBonusRepository
    {
        return $this->repository ??= new RuntimeWeeklyBonusRepository($this->config, $this->router);
    }
}
