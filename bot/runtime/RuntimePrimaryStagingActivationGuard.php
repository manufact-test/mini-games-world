<?php
declare(strict_types=1);

final class RuntimePrimaryStagingActivationGuard
{
    private const MAX_EVIDENCE_AGE_SECONDS = 21600;
    private const FUTURE_TOLERANCE_SECONDS = 300;
    private const MODULES = [
        'accounts', 'realtime', 'economy', 'notifications', 'invites',
        'history', 'shop', 'payments', 'weekly_bonus',
    ];

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
            throw new InvalidArgumentException('Staging activation project root is unavailable.');
        }
        if ($this->jsonStorage->driver() !== 'json') {
            throw new RuntimeException('Staging activation guard requires the JSON rollback driver.');
        }
        if ($this->database->driver() !== 'mysql') {
            throw new RuntimeException('Staging activation guard requires MySQL/MariaDB.');
        }
    }

    public function assertReady(): array
    {
        $environment = strtolower(trim((string)($this->config['environment'] ?? '')));
        if ($environment !== 'staging') {
            throw new RuntimeException('DB-primary activation guard is staging-only.');
        }
        $this->assertProjectionOutboxEnabled();

        $private = RuntimePrimaryPrivateConfigGuard::assertExternal(
            $this->configFile,
            $this->projectRoot
        );
        $privateDir = (string)$private['private_dir'];
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Staging activation guard requires an enabled database configuration.');
        }
        $databaseIdentity = strtolower(trim($databaseConfig->identityFingerprint()));
        if (preg_match('/^[a-f0-9]{64}$/', $databaseIdentity) !== 1) {
            throw new RuntimeException('Current staging database identity fingerprint is unavailable.');
        }
        $currentCommit = RuntimePrimaryRepositoryCommitResolver::resolve($this->projectRoot);
        $policy = RuntimePrimaryStagingActivationConfig::fromApplicationConfig($this->config);
        $policy->assertApproved(
            $databaseConfig,
            $currentCommit,
            $privateDir,
            $this->timestamp()
        );

        $loaded = (new RuntimePrimaryStagingActivationEvidenceLoader(
            $this->projectRoot,
            $privateDir
        ))->load($policy->evidenceFile());
        $manifest = is_array($loaded['manifest'] ?? null) ? $loaded['manifest'] : [];
        if ($manifest === []) {
            throw new RuntimeException('Staging activation evidence manifest is empty.');
        }
        $evidence = (new RuntimePrimaryStagingEvidenceV2Gate($this->projectRoot))->verify($manifest);
        if (($evidence['ok'] ?? false) !== true) {
            throw new RuntimeException(
                'Staging activation evidence is not valid: '
                . implode('; ', array_map('strval', (array)($evidence['blockers'] ?? [])))
            );
        }
        $evidenceFingerprint = strtolower(trim((string)($evidence['evidence_fingerprint'] ?? '')));
        if (!hash_equals($policy->expectedEvidenceFingerprint(), $evidenceFingerprint)) {
            throw new RuntimeException('Staging activation evidence fingerprint does not match the approval.');
        }
        $manifestDatabaseIdentity = strtolower(trim((string)($manifest['database']['identity_fingerprint'] ?? '')));
        if (!hash_equals($databaseIdentity, $manifestDatabaseIdentity)) {
            throw new RuntimeException('Staging activation evidence was collected from a different database identity.');
        }
        $this->assertEvidenceFresh((string)($manifest['generated_at_utc'] ?? ''));

        $jsonEvidence = RuntimePrimaryJsonEvidence::capture($this->jsonStorage);
        $source = is_array($manifest['source_snapshot'] ?? null) ? $manifest['source_snapshot'] : [];
        $this->assertJsonMatchesEvidence($source, $jsonEvidence);
        $snapshot = $this->jsonStorage->readOnly(static fn(array $data): array => $data);
        if (!is_array($snapshot)) {
            throw new RuntimeException('Current JSON rollback snapshot is unavailable.');
        }

        $schemas = (new RuntimePrimaryStagingSchemaInspector($this->database))->inspect();
        $this->assertSchemaMatches($manifest, $schemas, 'state');
        $this->assertSchemaMatches($manifest, $schemas, 'outbox');

        $first = is_array($manifest['first_rehearsal'] ?? null) ? $manifest['first_rehearsal'] : [];
        $targetRevision = (int)($first['target_revision'] ?? 0);
        $targetSha = strtolower(trim((string)($first['target_sha256'] ?? '')));
        if ($targetRevision < 1 || preg_match('/^[a-f0-9]{64}$/', $targetSha) !== 1) {
            throw new RuntimeException('Staging activation evidence target revision or fingerprint is invalid.');
        }

        $state = (new DatabasePrimaryStateStorageAdapter($this->database))->status();
        $this->assertStateMatches($state, $targetRevision, $targetSha);
        $event = $this->targetEvent($targetRevision);
        $this->assertCompletedEvent($event, $targetSha);
        $queue = $this->queueStatus($targetRevision);

        $audit = $this->projector->auditOnly($snapshot, $targetRevision, $targetSha);
        if (($audit['ok'] ?? false) !== true
            || ($audit['parity_ok'] ?? false) !== true
            || ($audit['read_only'] ?? false) !== true
            || ($audit['projected_modules'] ?? []) !== self::MODULES) {
            throw new RuntimeException('Current all-module staging audit did not prove read-only parity.');
        }

        $postJsonEvidence = RuntimePrimaryJsonEvidence::capture($this->jsonStorage);
        if (!hash_equals((string)$jsonEvidence['sha256'], (string)$postJsonEvidence['sha256'])
            || !hash_equals(
                (string)$jsonEvidence['inventory_fingerprint'],
                (string)$postJsonEvidence['inventory_fingerprint']
            )) {
            throw new RuntimeException('JSON rollback source changed during the staging activation audit.');
        }
        $postState = (new DatabasePrimaryStateStorageAdapter($this->database))->status();
        $this->assertStateMatches($postState, $targetRevision, $targetSha);
        $postEvent = $this->targetEvent($targetRevision);
        $this->assertCompletedEvent($postEvent, $targetSha);
        $postQueue = $this->queueStatus($targetRevision);
        if ($postQueue !== $queue) {
            throw new RuntimeException('Projection queue changed during the staging activation audit.');
        }

        return [
            'ok' => true,
            'report_type' => 'mvp-14.8.6h-staging-activation-readiness',
            'action' => 'staging_activation_ready',
            'activation_allowed' => true,
            'environment' => 'staging',
            'repository_commit' => $currentCommit,
            'database_identity_fingerprint' => $databaseIdentity,
            'evidence_fingerprint' => $evidenceFingerprint,
            'evidence_file_sha256' => (string)($loaded['file_sha256'] ?? ''),
            'evidence_manifest_version' => (string)($manifest['manifest_version'] ?? ''),
            'evidence_age_seconds' => $this->timestamp() - (int)strtotime((string)$manifest['generated_at_utc']),
            'state_revision' => $targetRevision,
            'state_sha256' => $targetSha,
            'storage_driver' => 'database',
            'rollback_driver' => 'json',
            'projection_outbox_enabled' => true,
            'schemas' => [
                'state_fingerprint' => (string)$schemas['state']['schema_fingerprint'],
                'outbox_fingerprint' => (string)$schemas['outbox']['schema_fingerprint'],
            ],
            'projection_event' => [
                'status' => 'completed',
                'attempt_count' => (int)$event['attempt_count'],
                'projection_version' => RuntimePrimaryProjectionOutboxWriter::PROJECTION_VERSION,
            ],
            'queue' => $queue,
            'projected_modules' => self::MODULES,
            'all_module_fingerprint' => (string)($audit['all_module_fingerprint'] ?? ''),
            'read_only_audit' => true,
            'drift_check_passed' => true,
            'private_config_external' => true,
            'application_entrypoints_changed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => gmdate(DATE_ATOM, $this->timestamp()),
        ];
    }

    private function assertProjectionOutboxEnabled(): void
    {
        if (!array_key_exists('runtime_primary_projection_outbox', $this->config)
            || !is_array($this->config['runtime_primary_projection_outbox'])
            || ($this->config['runtime_primary_projection_outbox']['enabled'] ?? null) !== true) {
            throw new RuntimeException('Staging activation requires runtime_primary_projection_outbox.enabled to be boolean true.');
        }
    }

    private function assertJsonMatchesEvidence(array $source, array $jsonEvidence): void
    {
        if (!hash_equals(
            strtolower(trim((string)($source['before_sha256'] ?? ''))),
            strtolower(trim((string)($jsonEvidence['sha256'] ?? '')))
        )) {
            throw new RuntimeException('Current JSON rollback source does not match staging activation evidence.');
        }
        if (!hash_equals(
            strtolower(trim((string)($source['inventory_fingerprint'] ?? ''))),
            strtolower(trim((string)($jsonEvidence['inventory_fingerprint'] ?? '')))
        )) {
            throw new RuntimeException('Current JSON inventory does not match staging activation evidence.');
        }
    }

    private function assertStateMatches(array $state, int $revision, string $sha): void
    {
        if ((int)($state['revision'] ?? 0) !== $revision
            || !hash_equals($sha, strtolower(trim((string)($state['state_sha256'] ?? ''))))) {
            throw new RuntimeException('Current DB-primary state does not match the evidenced target revision.');
        }
    }

    private function assertCompletedEvent(array $event, string $sha): void
    {
        if (($event['status'] ?? '') !== 'completed'
            || ($event['projection_version'] ?? '') !== RuntimePrimaryProjectionOutboxWriter::PROJECTION_VERSION
            || !hash_equals($sha, (string)($event['state_sha256'] ?? ''))
            || (int)($event['attempt_count'] ?? 0) < 1
            || trim((string)($event['lease_token'] ?? '')) !== ''
            || trim((string)($event['lease_expires_at_utc'] ?? '')) !== ''
            || trim((string)($event['last_error'] ?? '')) !== '') {
            throw new RuntimeException('Current projection event does not match a clean completed evidenced revision.');
        }
    }

    private function targetEvent(int $revision): array
    {
        $rows = $this->database->fetchAll(
            'SELECT state_revision, state_sha256, projection_version, status,
                    attempt_count, lease_token, lease_expires_at_utc, last_error
             FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . '
             WHERE state_revision = :state_revision',
            ['state_revision' => $revision]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Evidenced staging projection event is missing or ambiguous.');
        }
        return [
            'state_revision' => (int)($rows[0]['state_revision'] ?? 0),
            'state_sha256' => strtolower(trim((string)($rows[0]['state_sha256'] ?? ''))),
            'projection_version' => (string)($rows[0]['projection_version'] ?? ''),
            'status' => strtolower(trim((string)($rows[0]['status'] ?? ''))),
            'attempt_count' => max(0, (int)($rows[0]['attempt_count'] ?? 0)),
            'lease_token' => (string)($rows[0]['lease_token'] ?? ''),
            'lease_expires_at_utc' => (string)($rows[0]['lease_expires_at_utc'] ?? ''),
            'last_error' => (string)($rows[0]['last_error'] ?? ''),
        ];
    }

    private function queueStatus(int $targetRevision): array
    {
        $rows = $this->database->fetchAll(
            'SELECT status, COUNT(*) AS event_count,
                    MIN(state_revision) AS min_revision,
                    MAX(state_revision) AS max_revision
             FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . '
             GROUP BY status ORDER BY status'
        );
        $completedCount = 0;
        $completedMin = 0;
        $completedMax = 0;
        foreach ($rows as $row) {
            $status = strtolower(trim((string)($row['status'] ?? '')));
            $count = max(0, (int)($row['event_count'] ?? 0));
            if ($status !== 'completed' && $count > 0) {
                throw new RuntimeException('Staging projection queue contains a non-completed event: ' . $status . '.');
            }
            if ($status === 'completed') {
                $completedCount = $count;
                $completedMin = max(0, (int)($row['min_revision'] ?? 0));
                $completedMax = max(0, (int)($row['max_revision'] ?? 0));
            }
        }
        if ($completedCount !== $targetRevision || $completedMin !== 1 || $completedMax !== $targetRevision) {
            throw new RuntimeException('Staging projection queue is not a contiguous completed revision chain.');
        }
        return [
            'completed_event_count' => $completedCount,
            'min_revision' => $completedMin,
            'max_revision' => $completedMax,
            'pending_event_count' => 0,
            'processing_event_count' => 0,
            'failed_event_count' => 0,
        ];
    }

    private function assertSchemaMatches(array $manifest, array $schemas, string $name): void
    {
        $expected = strtolower(trim((string)($manifest['schemas'][$name]['schema_fingerprint'] ?? '')));
        $actual = strtolower(trim((string)($schemas[$name]['schema_fingerprint'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $expected) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $actual) !== 1
            || !hash_equals($expected, $actual)) {
            throw new RuntimeException('Current staging schema does not match evidence: ' . $name . '.');
        }
    }

    private function assertEvidenceFresh(string $generatedAt): void
    {
        if ($generatedAt === '' || preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $generatedAt) !== 1) {
            throw new RuntimeException('Staging activation evidence timestamp is invalid.');
        }
        $timestamp = strtotime($generatedAt);
        if ($timestamp === false) {
            throw new RuntimeException('Staging activation evidence timestamp is invalid.');
        }
        $age = $this->timestamp() - $timestamp;
        if ($age < -self::FUTURE_TOLERANCE_SECONDS) {
            throw new RuntimeException('Staging activation evidence timestamp is unexpectedly in the future.');
        }
        if ($age > self::MAX_EVIDENCE_AGE_SECONDS) {
            throw new RuntimeException('Staging activation evidence is older than six hours.');
        }
    }

    private function timestamp(): int
    {
        return $this->now ?? time();
    }
}
