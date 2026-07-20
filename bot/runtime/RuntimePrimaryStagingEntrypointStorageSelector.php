<?php
declare(strict_types=1);

final class RuntimePrimaryStagingEntrypointStorageSelector
{
    public function __construct(
        private string $projectRoot,
        private array $config,
        private string $configFile,
        private string $entrypoint
    ) {
        $this->projectRoot = rtrim(str_replace('\\', '/', trim($this->projectRoot)), '/');
        $this->configFile = str_replace('\\', '/', trim($this->configFile));
        $this->entrypoint = strtolower(trim($this->entrypoint));
        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            throw new InvalidArgumentException('Entrypoint storage selector project root is unavailable.');
        }
        if (!in_array($this->entrypoint, ['api', 'webhook'], true)) {
            throw new InvalidArgumentException('Entrypoint storage selector supports only api or webhook.');
        }
    }

    public function installIfEnabled(): bool
    {
        $selector = RuntimePrimaryStagingEntrypointSelectorConfig::fromApplicationConfig($this->config);
        $environment = strtolower(trim((string)($this->config['environment'] ?? '')));

        if ($environment !== 'staging') {
            if ($selector->enabled()) {
                throw new RuntimeException('DB-primary entrypoint selector cannot be enabled outside staging.');
            }
            return false;
        }
        if (!$selector->enabledFor($this->entrypoint)) {
            return false;
        }

        RuntimePrimaryPrivateConfigGuard::assertExternal(
            $this->configFile,
            $this->projectRoot
        );
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('DB-primary entrypoint selector requires an enabled staging database.');
        }
        $database = PdoConnectionFactory::create($databaseConfig);
        $jsonStorage = new JsonStorageAdapter((string)($this->config['data_dir'] ?? ''));
        $projector = (new RuntimePrimaryRepositoryProjectorFactory(
            $this->config,
            $database
        ))->create();
        $resolution = (new RuntimePrimaryStagingStorageResolver(
            $this->projectRoot,
            $this->config,
            $this->configFile,
            $jsonStorage,
            $database,
            $projector
        ))->resolve();
        $resolutionReport = $resolution->safeReport();
        if (($resolutionReport['evidence_manifest_version'] ?? '')
            !== RuntimePrimaryStagingEvidenceV3Verifier::MANIFEST_VERSION) {
            throw new RuntimeException('Real staging entrypoint routing requires selector-aware evidence v3.');
        }

        RuntimePrimaryEntrypointStorageContext::install(
            $resolution->storage(),
            $this->entrypoint,
            $resolutionReport
        );
        return true;
    }
}
