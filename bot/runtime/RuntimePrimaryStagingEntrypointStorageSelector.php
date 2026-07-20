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

        $private = RuntimePrimaryPrivateConfigGuard::assertExternal(
            $this->configFile,
            $this->projectRoot
        );
        $privateDir = (string)$private['private_dir'];
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('DB-primary entrypoint selector requires an enabled staging database.');
        }
        $selectorEvidence = $this->verifySelectorEvidenceV3(
            $databaseConfig,
            $privateDir
        );

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
        $resolutionReport['evidence_manifest_version'] = RuntimePrimaryStagingEvidenceV3Verifier::MANIFEST_VERSION;
        $resolutionReport['selector_contract_version'] = RuntimePrimaryStagingSelectorEvidence::CONTRACT_VERSION;
        $resolutionReport['selector_evidence_fingerprint'] = (string)(
            $selectorEvidence['selector_evidence_fingerprint'] ?? ''
        );

        RuntimePrimaryEntrypointStorageContext::install(
            $resolution->storage(),
            $this->entrypoint,
            $resolutionReport
        );
        return true;
    }

    private function verifySelectorEvidenceV3(
        DatabaseConfig $databaseConfig,
        string $privateDir
    ): array {
        $currentCommit = RuntimePrimaryRepositoryCommitResolver::resolve($this->projectRoot);
        $policy = RuntimePrimaryStagingActivationConfig::fromApplicationConfig($this->config);
        $policy->assertApproved(
            $databaseConfig,
            $currentCommit,
            $privateDir,
            time()
        );
        $loaded = (new RuntimePrimaryStagingActivationEvidenceLoader(
            $this->projectRoot,
            $privateDir
        ))->load($policy->evidenceFile());
        $manifest = is_array($loaded['manifest'] ?? null) ? $loaded['manifest'] : [];
        if (($manifest['manifest_version'] ?? '') !== RuntimePrimaryStagingEvidenceV3Verifier::MANIFEST_VERSION) {
            throw new RuntimeException('Real staging entrypoint routing requires selector-aware evidence v3.');
        }

        $report = (new RuntimePrimaryStagingEvidenceV3Gate($this->projectRoot))->verify($manifest);
        if (($report['ok'] ?? false) !== true) {
            throw new RuntimeException(
                'Selector-aware staging evidence v3 is invalid: '
                . implode('; ', array_map('strval', (array)($report['blockers'] ?? [])))
            );
        }
        $fingerprint = strtolower(trim((string)($report['evidence_fingerprint'] ?? '')));
        if (!hash_equals($policy->expectedEvidenceFingerprint(), $fingerprint)) {
            throw new RuntimeException('Selector-aware staging evidence fingerprint does not match the approval.');
        }
        if (!hash_equals(
            strtolower(trim($databaseConfig->identityFingerprint())),
            strtolower(trim((string)($report['database_identity_fingerprint'] ?? '')))
        )) {
            throw new RuntimeException('Selector-aware staging evidence belongs to a different database identity.');
        }

        return $report;
    }
}
