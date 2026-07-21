<?php
declare(strict_types=1);

final class RuntimePrimaryStagingStorageResolver
{
    public function __construct(
        private string $projectRoot,
        private array $config,
        private string $configFile,
        private StorageAdapterInterface $jsonStorage,
        private DatabaseConnectionInterface $database,
        private RuntimePrimaryAllModuleProjector $projector,
        private ?int $now = null
    ) {
        $this->projectRoot = rtrim(str_replace('\\', '/', trim($this->projectRoot)), '/');
        $this->configFile = str_replace('\\', '/', trim($this->configFile));
        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            throw new InvalidArgumentException('Staging storage resolver project root is unavailable.');
        }
        if ($this->jsonStorage->driver() !== 'json') {
            throw new RuntimeException('Staging storage resolver requires the JSON rollback driver.');
        }
        if ($this->database->driver() !== 'mysql') {
            throw new RuntimeException('Staging storage resolver requires MySQL/MariaDB.');
        }
    }

    public function resolve(): RuntimePrimaryStagingStorageResolution
    {
        $environment = strtolower(trim((string)($this->config['environment'] ?? '')));
        if ($environment !== 'staging') {
            throw new RuntimeException('DB-primary storage resolution is staging-only.');
        }
        RuntimePrimaryStagingStorageResolverConfig::fromApplicationConfig($this->config)
            ->assertEnabled();

        $readiness = (new RuntimePrimaryStagingActivationGuard(
            $this->projectRoot,
            $this->config,
            $this->configFile,
            $this->jsonStorage,
            $this->database,
            $this->projector,
            $this->now
        ))->assertReady();
        if (($readiness['activation_allowed'] ?? false) !== true) {
            throw new RuntimeException('Staging activation readiness did not authorize storage resolution.');
        }

        $storage = new DatabasePrimaryStateStorageAdapter(
            $this->database,
            new RuntimePrimaryProjectionOutboxWriter()
        );
        $status = $storage->status();
        if ((int)($status['revision'] ?? 0) !== (int)($readiness['state_revision'] ?? 0)
            || !hash_equals(
                strtolower(trim((string)($status['state_sha256'] ?? ''))),
                strtolower(trim((string)($readiness['state_sha256'] ?? '')))
            )) {
            throw new RuntimeException('DB-primary storage changed after staging activation readiness.');
        }

        return new RuntimePrimaryStagingStorageResolution(
            $storage,
            $readiness,
            $status
        );
    }
}
