<?php
declare(strict_types=1);

final class RuntimeHistoryService
{
    private ?RuntimeHistoryRepository $repository = null;

    public function __construct(
        private array $config,
        private RuntimeStorageRouter $router,
        private HistoryService $jsonHistory,
        private ?DatabaseConnectionInterface $database = null
    ) {}

    public function userHistory(array $jsonSnapshot, string $legacyUserId, int $limit = 24): array
    {
        if (!$this->router->enabled()
            || $this->router->routeFor('history') !== RuntimeStorageRouter::DRIVER_DATABASE) {
            return $this->jsonHistory->userHistory($jsonSnapshot, $legacyUserId, $limit);
        }

        $result = $this->repository()->synchronizeAndRead($jsonSnapshot, $legacyUserId, $limit);
        return $result['history'];
    }

    public function auditParity(array $jsonSnapshot): array
    {
        return $this->repository()->auditParity($jsonSnapshot);
    }

    private function repository(): RuntimeHistoryRepository
    {
        if ($this->repository !== null) return $this->repository;
        $database = $this->database;
        if ($database === null) {
            $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
            if (!$databaseConfig->enabled()) {
                throw new RuntimeException('History DB runtime requires an enabled database.');
            }
            $database = PdoConnectionFactory::create($databaseConfig);
        }
        return $this->repository = new RuntimeHistoryRepository(
            $this->config,
            $this->router,
            $database,
            $this->jsonHistory
        );
    }
}
