<?php
declare(strict_types=1);

final class RuntimeNotificationBridgeCoordinator
{
    private ?DatabaseConnectionInterface $connection;

    public function __construct(
        private array $config,
        private RuntimeStorageRouter $router,
        ?DatabaseConnectionInterface $database = null
    ) {
        $this->connection = $database;
    }

    public function synchronizeAndList(
        array $jsonData,
        string|int $legacyUserId,
        ?string $authenticatedMgwId = null
    ): array {
        $legacyUserId = trim((string)$legacyUserId);
        if ($legacyUserId === '') {
            throw new InvalidArgumentException('Notification bridge requires a legacy user ID.');
        }

        $database = $this->database();
        $lockName = null;
        if ($database->driver() === 'mysql') {
            $lockName = $this->synchronizationLockName($legacyUserId);
            $acquired = $database->fetchValue(
                'SELECT GET_LOCK(:lock_name, 10)',
                ['lock_name' => $lockName]
            );
            if ((int)$acquired !== 1) {
                throw new RuntimeException('Notification DB synchronization lock is unavailable.');
            }
        }

        try {
            return $database->transaction(function (DatabaseConnectionInterface $transaction) use (
                $jsonData,
                $legacyUserId,
                $authenticatedMgwId
            ): array {
                $repository = new RuntimeNotificationRepository(
                    $this->config,
                    $this->router,
                    $transaction
                );
                return $repository->synchronizeAndList(
                    $jsonData,
                    $legacyUserId,
                    $authenticatedMgwId
                );
            });
        } finally {
            if ($lockName !== null) {
                try {
                    $database->fetchValue(
                        'SELECT RELEASE_LOCK(:lock_name)',
                        ['lock_name' => $lockName]
                    );
                } catch (Throwable $error) {
                    error_log(
                        'Mini Games World notification DB lock release failed: '
                        . $error->getMessage()
                    );
                }
            }
        }
    }

    private function database(): DatabaseConnectionInterface
    {
        if ($this->connection !== null) return $this->connection;
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Notification bridge requires an enabled database.');
        }
        return $this->connection = PdoConnectionFactory::create($databaseConfig);
    }

    private function synchronizationLockName(string $legacyUserId): string
    {
        $scope = trim((string)($this->config['environment'] ?? ''))
            . '|'
            . trim((string)($this->config['database']['name'] ?? ''))
            . '|'
            . $legacyUserId;
        return 'mgw_notifications_sync_' . substr(hash('sha256', $scope), 0, 38);
    }
}
