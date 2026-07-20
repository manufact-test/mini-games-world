<?php
declare(strict_types=1);

final class RuntimePrimaryStagingEvidenceSource implements RuntimePrimaryStagingEvidenceSourceInterface
{
    public function __construct(
        private array $config,
        private string $projectRoot,
        private StorageAdapterInterface $jsonStorage,
        private DatabaseConnectionInterface $database,
        private StagingPrimaryRehearsalOperation $rehearsal,
        private RuntimePrimaryStagingConcurrencyProbe $concurrencyProbe
    ) {
        $environment = strtolower(trim((string)($this->config['environment'] ?? '')));
        if ($environment !== 'staging') {
            throw new RuntimeException('Automated DB-primary evidence collection is staging-only.');
        }
        $this->projectRoot = rtrim(str_replace('\\', '/', trim($this->projectRoot)), '/');
        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            throw new InvalidArgumentException('Staging evidence source project root is unavailable.');
        }
        if ($this->jsonStorage->driver() !== 'json') {
            throw new RuntimeException('Staging evidence source requires the JSON rollback driver.');
        }
        if ($this->database->driver() !== 'mysql') {
            throw new RuntimeException('Staging evidence source requires MySQL/MariaDB.');
        }
    }

    public function repositoryCommit(): string
    {
        return RuntimePrimaryRepositoryCommitResolver::resolve($this->projectRoot);
    }

    public function phpEvidence(): array
    {
        return [
            'version' => PHP_VERSION,
            'version_id' => PHP_VERSION_ID,
            'sapi' => PHP_SAPI,
        ];
    }

    public function databaseEvidence(): array
    {
        $version = trim((string)$this->database->fetchValue('SELECT VERSION()'));
        if ($version === '') {
            throw new RuntimeException('Staging database server version is unavailable.');
        }
        return [
            'driver' => $this->database->driver(),
            'server_version' => $version,
        ];
    }

    public function captureJsonEvidence(): array
    {
        return RuntimePrimaryJsonEvidence::capture($this->jsonStorage);
    }

    public function runRehearsal(): array
    {
        return $this->rehearsal->rehearse();
    }

    public function concurrencyEvidence(): array
    {
        return $this->concurrencyProbe->run();
    }

    public function entrypointEvidence(): array
    {
        return RuntimePrimaryEntrypointEvidence::inspect($this->projectRoot);
    }
}
