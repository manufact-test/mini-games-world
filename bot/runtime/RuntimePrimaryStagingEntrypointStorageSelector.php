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
        $selector = RuntimePrimaryStagingEntrypointSelectorConfig::fromApplicationConfig(
            $this->config
        );
        $environment = strtolower(trim((string)($this->config['environment'] ?? '')));

        if ($environment !== 'staging') {
            if ($selector->enabled()) {
                throw new RuntimeException('DB-primary entrypoint selector cannot be enabled outside staging.');
            }
            return false;
        }
        if ($this->entrypoint === 'webhook') {
            if ($selector->enabled()) {
                throw new RuntimeException('DB-primary webhook routing is not allowed in the API-only staging session.');
            }
            return false;
        }
        if (!$selector->enabledFor('api')) {
            return false;
        }

        $report = (new RuntimePrimaryStagingApiSessionCoordinator(
            $this->projectRoot,
            $this->config,
            $this->configFile
        ))->install();
        if (($report['ok'] ?? false) !== true
            || ($report['entrypoint'] ?? '') !== 'api'
            || ($report['storage_driver'] ?? '') !== 'database'
            || ($report['request_finalizer_registered_first'] ?? false) !== true
            || ($report['dynamic_session_readiness'] ?? false) !== true
            || ($report['legacy_json_bridges_suppressed'] ?? false) !== true
            || ($report['webhook_allowed'] ?? true) !== false
            || ($report['production_changed'] ?? true) !== false) {
            throw new RuntimeException('DB-primary API session coordinator returned an incomplete install contract.');
        }
        return true;
    }
}
