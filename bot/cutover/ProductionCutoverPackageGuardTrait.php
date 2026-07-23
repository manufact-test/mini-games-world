<?php
declare(strict_types=1);

trait ProductionCutoverPackageGuardTrait
{
    private function packageManifest(): array
    {
        return (new ProductionCutoverPackageManifest($this->projectRoot))->inspect();
    }

    private function assertEnvironmentAndBuild(): void
    {
        $this->assertPackageEnvironment();
        if (!$this->storage instanceof StorageAdapterInterface
            || !$this->database instanceof DatabaseConnectionInterface) {
            throw new RuntimeException('Production cutover execution dependencies are unavailable.');
        }
        if ($this->storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
            throw new RuntimeException(
                'Global JSON rollback storage must remain active during production cutover.'
            );
        }
        $manifest = $this->assertPackageIntegrity();
        $this->policy->assertPackage($manifest);
        $migrationStatus = (new MigrationRunner(
            $this->database,
            $this->projectRoot . '/bot/database/migrations'
        ))->status();
        if ((int)($migrationStatus['pending_count'] ?? -1) !== 0) {
            throw new RuntimeException('Production database schema has pending migrations.');
        }
    }

    private function assertControlEnvironmentAndBuild(): void
    {
        $this->assertPackageEnvironment();
        $this->assertPackageIntegrity();
    }

    private function assertPackageIntegrity(): array
    {
        $manifest = $this->packageManifest();
        if (($manifest['ready'] ?? false) !== true) {
            throw new RuntimeException(
                'Production cutover package integrity is blocked: '
                . implode('; ', array_map('strval', (array)($manifest['blockers'] ?? [])))
            );
        }
        return $manifest;
    }

    private function assertPackageEnvironment(): void
    {
        if (($this->config['environment'] ?? null) !== 'production') {
            throw new RuntimeException('Production cutover package requires the exact production environment.');
        }
        if (($this->config['storage_driver'] ?? null) !== RuntimeStorageRouter::DRIVER_JSON) {
            throw new RuntimeException('Production cutover package requires JSON as the global rollback driver.');
        }
        if (self::BUILD !== ProductionCutoverPackageManifest::BUILD
            || self::PACKAGE_VERSION !== ProductionCutoverPackageManifest::PACKAGE_VERSION) {
            throw new RuntimeException('Production cutover package constants are inconsistent.');
        }
    }
}
