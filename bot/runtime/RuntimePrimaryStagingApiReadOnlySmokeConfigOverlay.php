<?php
declare(strict_types=1);

final class RuntimePrimaryStagingApiReadOnlySmokeConfigOverlay
{
    private const MIN_TTL_SECONDS = 60;
    private const MAX_TTL_SECONDS = 600;

    public function __construct(
        private string $projectRoot,
        private array $baseConfig,
        private string $configFile,
        private string $evidenceFile,
        private int $ttlSeconds = 300
    ) {
        $this->projectRoot = rtrim(str_replace('\\', '/', trim($this->projectRoot)), '/');
        $this->configFile = str_replace('\\', '/', trim($this->configFile));
        $this->evidenceFile = str_replace('\\', '/', trim($this->evidenceFile));
        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            throw new InvalidArgumentException('Read-only API smoke project root is unavailable.');
        }
        if ($this->ttlSeconds < self::MIN_TTL_SECONDS
            || $this->ttlSeconds > self::MAX_TTL_SECONDS) {
            throw new InvalidArgumentException('Read-only API smoke TTL must be between 60 and 600 seconds.');
        }
    }

    public function build(int $now): array
    {
        if (strtolower(trim((string)($this->baseConfig['environment'] ?? ''))) !== 'staging') {
            throw new RuntimeException('Read-only API smoke config overlay is staging-only.');
        }
        if (RuntimePrimaryStagingEntrypointSelectorConfig::fromApplicationConfig(
            $this->baseConfig
        )->enabled()) {
            throw new RuntimeException('Read-only API smoke requires the persistent selector latch to be disabled.');
        }
        if (RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig(
            $this->baseConfig
        )->enabled()) {
            throw new RuntimeException('Read-only API smoke requires the persistent request-session latch to be disabled.');
        }
        $activationSummary = RuntimePrimaryStagingActivationConfig::fromApplicationConfig(
            $this->baseConfig
        )->safeSummary();
        if (($activationSummary['enabled'] ?? false) === true) {
            throw new RuntimeException('Read-only API smoke requires persistent activation approval to be disabled.');
        }

        $private = RuntimePrimaryPrivateConfigGuard::assertExternal(
            $this->configFile,
            $this->projectRoot
        );
        $privateDir = (string)($private['private_dir'] ?? '');
        $loaded = (new RuntimePrimaryStagingActivationEvidenceLoader(
            $this->projectRoot,
            $privateDir
        ))->load($this->evidenceFile);
        $manifest = is_array($loaded['manifest'] ?? null)
            ? $loaded['manifest']
            : [];
        if (($manifest['manifest_version'] ?? '')
            !== RuntimePrimaryStagingEvidenceV4Verifier::MANIFEST_VERSION) {
            throw new RuntimeException('Read-only API smoke requires lifecycle evidence v4.');
        }

        $verification = (new RuntimePrimaryStagingEvidenceV4Gate(
            $this->projectRoot
        ))->verify($manifest);
        if (($verification['ok'] ?? false) !== true) {
            throw new RuntimeException(
                'Read-only API smoke evidence v4 is invalid: '
                . implode('; ', array_map('strval', (array)($verification['blockers'] ?? [])))
            );
        }

        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->baseConfig);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Read-only API smoke requires an enabled staging database.');
        }
        $databaseIdentity = strtolower(trim($databaseConfig->identityFingerprint()));
        $evidenceDatabaseIdentity = strtolower(trim((string)(
            $verification['database_identity_fingerprint'] ?? ''
        )));
        if (preg_match('/^[a-f0-9]{64}$/', $databaseIdentity) !== 1
            || !hash_equals($databaseIdentity, $evidenceDatabaseIdentity)) {
            throw new RuntimeException('Read-only API smoke evidence belongs to a different database identity.');
        }

        $currentCommit = RuntimePrimaryRepositoryCommitResolver::resolve($this->projectRoot);
        $evidenceCommit = strtolower(trim((string)(
            $verification['repository_commit'] ?? ''
        )));
        if (preg_match('/^[a-f0-9]{40}$/', $currentCommit) !== 1
            || !hash_equals($currentCommit, $evidenceCommit)) {
            throw new RuntimeException('Read-only API smoke evidence belongs to a different checkout.');
        }

        $evidenceFingerprint = strtolower(trim((string)(
            $verification['evidence_fingerprint'] ?? ''
        )));
        if (preg_match('/^[a-f0-9]{64}$/', $evidenceFingerprint) !== 1) {
            throw new RuntimeException('Read-only API smoke evidence fingerprint is invalid.');
        }
        $baseline = is_array(
            $manifest['request_session_evidence']['baseline'] ?? null
        ) ? $manifest['request_session_evidence']['baseline'] : [];
        $baselineRevision = (int)($baseline['state_revision'] ?? 0);
        $baselineSha = strtolower(trim((string)($baseline['state_sha256'] ?? '')));
        if ($baselineRevision < 1
            || preg_match('/^[a-f0-9]{64}$/', $baselineSha) !== 1) {
            throw new RuntimeException('Read-only API smoke lifecycle baseline is invalid.');
        }

        $canonicalEvidence = realpath($this->evidenceFile);
        if (!is_string($canonicalEvidence)) {
            throw new RuntimeException('Read-only API smoke evidence canonical path is unavailable.');
        }
        $canonicalEvidence = str_replace('\\', '/', $canonicalEvidence);
        if (!hash_equals($privateDir, rtrim(dirname($canonicalEvidence), '/'))) {
            throw new RuntimeException('Read-only API smoke evidence escaped the verified private directory.');
        }

        $expiresAtUtc = gmdate(DATE_ATOM, $now + $this->ttlSeconds);
        $overlay = $this->baseConfig;
        $overlay['staging_db_primary_activation'] = [
            'enabled' => true,
            'expected_database_identity_fingerprint' => $databaseIdentity,
            'expected_repository_commit' => $currentCommit,
            'evidence_file' => $canonicalEvidence,
            'expected_evidence_fingerprint' => $evidenceFingerprint,
            'approval_expires_at_utc' => $expiresAtUtc,
        ];
        $overlay['staging_db_primary_entrypoint_selector'] = [
            'enabled' => true,
            'contract_version' => RuntimePrimaryStagingEntrypointSelectorConfig::CONTRACT_VERSION,
            'allowed_entrypoints' => ['api'],
        ];
        $overlay['staging_db_primary_request_session'] = [
            'enabled' => true,
            'contract_version' => RuntimePrimaryStagingRequestSessionConfig::CONTRACT_VERSION,
            'allowed_entrypoints' => ['api'],
            'baseline_revision' => $baselineRevision,
            'max_revision_delta' => 1,
            'max_worker_ticks' => 1,
            'lease_seconds' => 60,
            'expires_at_utc' => $expiresAtUtc,
        ];

        RuntimePrimaryStagingActivationConfig::fromApplicationConfig($overlay)->assertApproved(
            $databaseConfig,
            $currentCommit,
            $privateDir,
            $now
        );
        RuntimePrimaryStagingEntrypointSelectorConfig::fromApplicationConfig(
            $overlay
        );
        RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig(
            $overlay
        )->assertEnabledForApi(
            $baselineRevision,
            $baselineRevision,
            $now
        );

        return [
            'config' => $overlay,
            'report' => [
                'ok' => true,
                'action' => 'read_only_api_smoke_overlay_built',
                'evidence_manifest_version' => RuntimePrimaryStagingEvidenceV4Verifier::MANIFEST_VERSION,
                'evidence_fingerprint' => $evidenceFingerprint,
                'database_identity_fingerprint' => $databaseIdentity,
                'repository_commit' => $currentCommit,
                'baseline_state_revision' => $baselineRevision,
                'baseline_state_sha256' => $baselineSha,
                'ttl_seconds' => $this->ttlSeconds,
                'expires_at_utc' => $expiresAtUtc,
                'persistent_config_changed' => false,
                'selector_enabled_in_memory_only' => true,
                'request_session_enabled_in_memory_only' => true,
                'activation_enabled_in_memory_only' => true,
                'api_only' => true,
                'webhook_allowed' => false,
                'cron_changed' => false,
                'production_changed' => false,
                'sensitive_identifiers_exposed' => false,
            ],
        ];
    }
}
