<?php
declare(strict_types=1);

final class RuntimePrimaryStagingStorageResolution
{
    public function __construct(
        private DatabasePrimaryStateStorageAdapter $storage,
        private array $readiness,
        private array $storageStatus
    ) {
        if ($this->storage->driver() !== DatabasePrimaryStateStorageAdapter::DRIVER) {
            throw new RuntimeException('Resolved staging storage is not DB-primary.');
        }
        if (($this->readiness['activation_allowed'] ?? false) !== true
            || ($this->readiness['read_only_audit'] ?? false) !== true
            || ($this->readiness['drift_check_passed'] ?? false) !== true) {
            throw new RuntimeException('Resolved staging storage is missing activation readiness evidence.');
        }
        if (($this->storageStatus['ok'] ?? false) !== true
            || ($this->storageStatus['driver'] ?? '') !== DatabasePrimaryStateStorageAdapter::DRIVER) {
            throw new RuntimeException('Resolved staging storage status is unhealthy.');
        }
        if ((int)($this->readiness['state_revision'] ?? 0) !== (int)($this->storageStatus['revision'] ?? 0)
            || !hash_equals(
                strtolower(trim((string)($this->readiness['state_sha256'] ?? ''))),
                strtolower(trim((string)($this->storageStatus['state_sha256'] ?? '')))
            )) {
            throw new RuntimeException('Resolved staging storage no longer matches activation readiness.');
        }
        if (($this->storageStatus['projection_outbox_enabled'] ?? false) !== true) {
            throw new RuntimeException('Resolved staging storage does not have the projection outbox enabled.');
        }
    }

    public function storage(): DatabasePrimaryStateStorageAdapter
    {
        return $this->storage;
    }

    public function safeReport(): array
    {
        return [
            'ok' => true,
            'report_type' => 'mvp-14.8.6i-staging-storage-resolution',
            'action' => 'staging_db_primary_storage_resolved',
            'resolved' => true,
            'environment' => 'staging',
            'storage_driver' => DatabasePrimaryStateStorageAdapter::DRIVER,
            'rollback_driver' => 'json',
            'state_revision' => (int)($this->storageStatus['revision'] ?? 0),
            'state_sha256' => strtolower(trim((string)($this->storageStatus['state_sha256'] ?? ''))),
            'repository_commit' => strtolower(trim((string)($this->readiness['repository_commit'] ?? ''))),
            'database_identity_fingerprint' => strtolower(trim((string)($this->readiness['database_identity_fingerprint'] ?? ''))),
            'evidence_fingerprint' => strtolower(trim((string)($this->readiness['evidence_fingerprint'] ?? ''))),
            'all_module_fingerprint' => strtolower(trim((string)($this->readiness['all_module_fingerprint'] ?? ''))),
            'projection_outbox_enabled' => true,
            'read_only_readiness_audit' => true,
            'drift_check_passed' => true,
            'application_entrypoint_routed' => false,
            'application_entrypoints_changed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => gmdate(DATE_ATOM),
        ];
    }
}
