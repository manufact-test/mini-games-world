<?php
declare(strict_types=1);

final class RuntimePrimaryStagingApiSessionCoordinator
{
    public function __construct(
        private string $projectRoot,
        private array $config,
        private string $configFile
    ) {
        $this->projectRoot = rtrim(str_replace('\\', '/', trim($this->projectRoot)), '/');
        $this->configFile = str_replace('\\', '/', trim($this->configFile));
        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            throw new InvalidArgumentException('Staging API session coordinator project root is unavailable.');
        }
    }

    public function install(): array
    {
        if (strtolower(trim((string)($this->config['environment'] ?? ''))) !== 'staging') {
            throw new RuntimeException('DB-primary API request session is staging-only.');
        }
        if (RuntimePrimaryEntrypointStorageContext::installed()) {
            throw new RuntimeException('DB-primary request storage context is already installed.');
        }
        if (isset($GLOBALS['mgw_api_db_primary_finalization_hook'])) {
            throw new RuntimeException('DB-primary API request finalizer is already registered.');
        }

        $private = RuntimePrimaryPrivateConfigGuard::assertExternal(
            $this->configFile,
            $this->projectRoot
        );
        $privateDir = (string)$private['private_dir'];
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('DB-primary API request session requires an enabled staging database.');
        }
        $databaseIdentity = strtolower(trim($databaseConfig->identityFingerprint()));
        if (preg_match('/^[a-f0-9]{64}$/', $databaseIdentity) !== 1) {
            throw new RuntimeException('DB-primary API request session database identity is unavailable.');
        }
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
        if (($manifest['manifest_version'] ?? '') !== RuntimePrimaryStagingEvidenceV4Verifier::MANIFEST_VERSION) {
            throw new RuntimeException('DB-primary API request session requires lifecycle evidence v4.');
        }
        $evidence = (new RuntimePrimaryStagingEvidenceV4Gate(
            $this->projectRoot
        ))->verify($manifest);
        if (($evidence['ok'] ?? false) !== true) {
            throw new RuntimeException(
                'DB-primary API request session evidence v4 is invalid: '
                . implode('; ', array_map('strval', (array)($evidence['blockers'] ?? [])))
            );
        }
        $evidenceFingerprint = strtolower(trim((string)(
            $evidence['evidence_fingerprint'] ?? ''
        )));
        if (!hash_equals($policy->expectedEvidenceFingerprint(), $evidenceFingerprint)) {
            throw new RuntimeException('DB-primary API request session evidence fingerprint does not match approval.');
        }
        if (!hash_equals(
            $databaseIdentity,
            strtolower(trim((string)($evidence['database_identity_fingerprint'] ?? '')))
        )) {
            throw new RuntimeException('DB-primary API request session evidence belongs to a different database.');
        }

        $session = RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig(
            $this->config
        );
        $baseline = $this->baselineFromManifest($manifest);
        $database = PdoConnectionFactory::create($databaseConfig);
        $jsonStorage = new JsonStorageAdapter((string)($this->config['data_dir'] ?? ''));
        $projector = (new RuntimePrimaryRepositoryProjectorFactory(
            $this->config,
            $database
        ))->create();
        $auditor = new RuntimePrimaryProjectionAuditorAdapter($projector);
        $storage = new DatabasePrimaryStateStorageAdapter(
            $database,
            new RuntimePrimaryProjectionOutboxWriter()
        );

        $readiness = (new RuntimePrimaryStagingRequestSessionReadiness(
            $jsonStorage,
            $database,
            $storage,
            $auditor,
            $session
        ))->assertReady($baseline);
        $currentRevision = (int)($readiness['current_state_revision'] ?? 0);
        $currentSha = strtolower(trim((string)(
            $readiness['current_state_sha256'] ?? ''
        )));
        $session->assertEnabledForApi(
            (int)$baseline['state_revision'],
            $currentRevision,
            time()
        );

        $resolutionReport = [
            'resolved' => true,
            'storage_driver' => 'database',
            'application_entrypoint_routed' => false,
            'projection_outbox_enabled' => true,
            'read_only_readiness_audit' => true,
            'drift_check_passed' => true,
            'evidence_manifest_version' => RuntimePrimaryStagingEvidenceV4Verifier::MANIFEST_VERSION,
            'evidence_fingerprint' => $evidenceFingerprint,
            'selector_contract_version' => RuntimePrimaryStagingSelectorEvidence::CONTRACT_VERSION,
            'selector_evidence_fingerprint' => strtolower(trim((string)(
                $evidence['selector_evidence_fingerprint'] ?? ''
            ))),
            'request_session_contract_version' => RuntimePrimaryStagingRequestSessionConfig::CONTRACT_VERSION,
            'request_session_evidence_fingerprint' => strtolower(trim((string)(
                $evidence['request_session_evidence_fingerprint'] ?? ''
            ))),
            'database_identity_fingerprint' => $databaseIdentity,
            'baseline_state_revision' => (int)$baseline['state_revision'],
            'baseline_state_sha256' => (string)$baseline['state_sha256'],
            'state_revision' => $currentRevision,
            'state_sha256' => $currentSha,
            'maximum_state_revision' => $session->maximumRevision(),
            'remaining_session_revisions' => $session->remainingRevisions($currentRevision),
            'session_expires_at_utc' => $session->expiresAtUtc(),
            'dynamic_session_readiness' => true,
            'request_finalizer_registered' => true,
            'legacy_json_bridges_suppressed' => true,
            'private_config_external' => true,
            'webhook_allowed' => false,
            'production_changed' => false,
        ];

        $worker = new RuntimePrimaryProjectionWorkerAdapter(
            new RuntimePrimaryProjectionWorker(
                $database,
                $projector,
                $session->leaseSeconds()
            )
        );
        $finalizer = new RuntimePrimaryStagingRequestFinalizer(
            $database,
            $worker,
            $auditor,
            $session
        );
        $hook = new RuntimePrimaryStagingApiRequestFinalizationHook(
            $storage,
            $finalizer,
            $resolutionReport
        );
        $hooks = $GLOBALS['mgw_api_success_hooks'] ?? [];
        if (!is_array($hooks)) {
            throw new RuntimeException('API success hook registry is invalid.');
        }
        array_unshift($hooks, $hook);
        if (($hooks[0] ?? null) !== $hook) {
            throw new RuntimeException('DB-primary API request finalizer was not prepared first.');
        }

        RuntimePrimaryEntrypointStorageContext::install(
            $storage,
            'api',
            $resolutionReport
        );
        $GLOBALS['mgw_api_success_hooks'] = $hooks;
        $GLOBALS['mgw_api_db_primary_finalization_hook'] = $hook;

        return [
            'ok' => true,
            'action' => 'staging_api_session_installed',
            'entrypoint' => 'api',
            'storage_driver' => 'database',
            'evidence_manifest_version' => RuntimePrimaryStagingEvidenceV4Verifier::MANIFEST_VERSION,
            'baseline_state_revision' => (int)$baseline['state_revision'],
            'current_state_revision' => $currentRevision,
            'maximum_state_revision' => $session->maximumRevision(),
            'remaining_session_revisions' => $session->remainingRevisions($currentRevision),
            'request_finalizer_registered_first' => true,
            'dynamic_session_readiness' => true,
            'legacy_json_bridges_suppressed' => true,
            'webhook_allowed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function baselineFromManifest(array $manifest): array
    {
        $baseline = is_array(
            $manifest['request_session_evidence']['baseline'] ?? null
        ) ? $manifest['request_session_evidence']['baseline'] : [];
        $revision = (int)($baseline['state_revision'] ?? 0);
        if ($revision < 1) {
            throw new RuntimeException('Lifecycle evidence v4 baseline revision is invalid.');
        }
        $result = ['state_revision' => $revision];
        foreach ([
            'state_sha256',
            'json_sha256',
            'inventory_fingerprint',
        ] as $field) {
            $value = strtolower(trim((string)($baseline[$field] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
                throw new RuntimeException('Lifecycle evidence v4 baseline field is invalid: ' . $field . '.');
            }
            $result[$field] = $value;
        }
        return $result;
    }
}
