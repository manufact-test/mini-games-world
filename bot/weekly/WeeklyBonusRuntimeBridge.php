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
        return $this->repository()->synchronizeCurrentJson();
    }

    public function normalizeApiData(array $data, string $action): array
    {
        if (!$this->enabled() || !isset($data['weekly_match']) || !is_array($data['weekly_match'])) {
            return $data;
        }
        $action = strtolower(trim($action));
        if (!in_array($action, ['bootstrap', 'weekly_match_status'], true)) return $data;

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
