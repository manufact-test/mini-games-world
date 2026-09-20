<?php
declare(strict_types=1);

final class PerGameRatingRuntimeBridge
{
    private RuntimeStorageRouter $router;
    private ?DatabaseConnectionInterface $database;

    public function __construct(
        private array $config,
        ?RuntimeStorageRouter $router = null,
        ?DatabaseConnectionInterface $database = null
    ) {
        $this->router = $router ?? new RuntimeStorageRouter($config);
        $this->database = $database;
    }

    public function enabled(): bool
    {
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) return false;

        return $this->router->routeFor('accounts') === RuntimeStorageRouter::DRIVER_DATABASE
            && $this->router->routeFor('realtime') === RuntimeStorageRouter::DRIVER_DATABASE;
    }

    public function shouldAttachToCurrentRequest(array $server): bool
    {
        if (!$this->enabled()) return false;
        $script = trim((string)($server['SCRIPT_FILENAME'] ?? $server['PHP_SELF'] ?? ''));
        return $script !== '' && basename($script) === 'api.php';
    }

    public function shouldProcessApiAction(string $action): bool
    {
        if (!$this->enabled()) return false;
        if (trim($action) === '') $action = (string)($GLOBALS['action'] ?? '');

        // Gameplay/search actions deliberately stay on their established
        // latency-critical JSON path. The existing realtime/weekly projection
        // catches up on the next non-critical request, then this hook consumes
        // the normalized DB result.
        return !in_array(strtolower(trim($action)), [
            'start_search',
            'leave_search',
            'game_state',
            'game_action',
            'make_move',
            'leave_game',
        ], true);
    }

    public function processProjectedMatches(int $limit = 100): ?array
    {
        if (!$this->enabled()) return null;

        // Advance the calendar first, but do not close/award the previous
        // season until every finished match has been projected by finish time.
        $this->reconcileSeasonLifecycle(false);
        $summary = $this->service()->processPendingFinishedMatches($limit);
        $this->reconcileSeasonLifecycle(true);
        return $summary;
    }

    public function snapshotForProfile(string $mgwId): array
    {
        if (!$this->enabled()) {
            throw new RuntimeException('MVP-20.1 rating runtime is unavailable.');
        }

        // Profile is a read boundary outside api.php. Refresh the established
        // realtime projection first so a just-finished match is visible without
        // waiting for an unrelated API call. If the app is later DB-primary,
        // the legacy bridge guard prevents a second JSON owner.
        if (RuntimePrimaryEntrypointBridgeGuard::legacyJsonBridgeAllowed()) {
            $repository = new RuntimeRealtimeRepository(
                $this->config,
                $this->router,
                $this->database()
            );
            (new RealtimeRuntimeBridge(
                $this->config,
                $this->router,
                null,
                $repository
            ))->synchronizeCurrentJson();
        }

        $this->reconcileSeasonLifecycle(false);
        $service = $this->service();
        $service->processPendingFinishedMatches(200);
        $this->reconcileSeasonLifecycle(true);
        return $service->snapshot($mgwId);
    }

    private function reconcileSeasonLifecycle(bool $allowCompletion): void
    {
        if (!class_exists('SeasonLifecycleService')) return;
        (new SeasonLifecycleService($this->database()))->reconcile(null, $allowCompletion);
    }

    private function service(): PerGameRatingService
    {
        return new PerGameRatingService($this->database());
    }

    private function database(): DatabaseConnectionInterface
    {
        if ($this->database !== null) return $this->database;
        $this->database = PdoConnectionFactory::create(
            DatabaseConfig::fromApplicationConfig($this->config)
        );
        return $this->database;
    }
}
