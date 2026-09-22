<?php
declare(strict_types=1);

final class HiddenSkillRuntimeBridge
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
        return in_array(strtolower(trim($action)), [
            'game_action',
            'make_move',
            'leave_game',
            'game_state',
            'start_search',
        ], true);
    }

    public function processProjectedMatches(int $limit = 100): ?array
    {
        if (!$this->enabled()) return null;
        return $this->service()->processPendingFinishedMatches($limit);
    }

    public function skillBandForUser(string $mgwId, string $gameType): string
    {
        if (!$this->enabled()) return MatchmakingQueue::DEFAULT_SKILL_BAND;

        // Consume already-projected terminal matches before the next search so
        // immediate rematch/search uses the latest hidden skill without exposing
        // the score or letting the client choose a band.
        $service = $this->service();
        $service->processPendingFinishedMatches(100);
        return $service->skillBandForUser($mgwId, $gameType);
    }

    private function service(): HiddenSkillService
    {
        return new HiddenSkillService($this->database());
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
