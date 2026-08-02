<?php
declare(strict_types=1);

final class WeeklyBonusRuntimeBridge
{
    private const STAGING_TEST_HOST = 'seashell-okapi-889488.hostingersite.com';
    private const STAGING_TEST_USER_IDS = [
        'stg_test_player_a',
        'stg_test_player_b',
    ];

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

        // TEST PLAYER A/B are deliberately development users: weekly grants and
        // their DB mirror both exclude development accounts. Their bootstrap has
        // already calculated a safe read-only JSON status, so replacing it with a
        // DB row that must not exist would break the staging browser harness.
        // Keep this exception pinned to the exact isolated staging host and two
        // fixed identities. Every real/unknown user remains DB-primary and fails
        // closed if its weekly state is absent or ambiguous.
        if ($this->isFixedStagingTestUser($legacyUserId)) {
            return $data;
        }

        $data['weekly_match'] = $this->repository()->statusForLegacyUser($legacyUserId);
        return $data;
    }

    private function isFixedStagingTestUser(string $legacyUserId): bool
    {
        if (strtolower(trim((string)($this->config['environment'] ?? ''))) !== 'staging') {
            return false;
        }
        $baseUrl = rtrim(trim((string)($this->config['base_url'] ?? '')), '/');
        $scheme = strtolower((string)(parse_url($baseUrl, PHP_URL_SCHEME) ?: ''));
        $host = strtolower((string)(parse_url($baseUrl, PHP_URL_HOST) ?: ''));
        if ($scheme !== 'https' || $host !== self::STAGING_TEST_HOST) {
            return false;
        }
        return in_array($legacyUserId, self::STAGING_TEST_USER_IDS, true);
    }

    private function repository(): RuntimeWeeklyBonusRepository
    {
        return $this->repository ??= new RuntimeWeeklyBonusRepository($this->config, $this->router);
    }
}
