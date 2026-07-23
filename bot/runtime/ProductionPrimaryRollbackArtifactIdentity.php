<?php
declare(strict_types=1);

final class ProductionPrimaryRollbackArtifactIdentity
{
    private const MAX_METADATA_BYTES = 262_144;

    public function __construct(
        private ProductionPrimaryRollbackExportVerifier $verifier
    ) {}

    public function inspect(string $exportDir): array
    {
        $verified = $this->verifier->verify($exportDir);
        $canonical = realpath($exportDir);
        if (!is_string($canonical) || !hash_equals($exportDir, $canonical)) {
            throw new RuntimeException('Rollback artifact path is not canonical.');
        }

        $metadataPath = $canonical . '/rollback.json';
        clearstatcache(true, $metadataPath);
        $size = filesize($metadataPath);
        if (!is_int($size) || $size < 2 || $size > self::MAX_METADATA_BYTES) {
            throw new RuntimeException('Rollback artifact metadata size is invalid.');
        }
        $raw = file_get_contents($metadataPath);
        if (!is_string($raw) || strlen($raw) !== $size) {
            throw new RuntimeException('Rollback artifact metadata could not be read exactly.');
        }
        try {
            $metadata = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Rollback artifact metadata is invalid JSON.', 0, $error);
        }
        if (!is_array($metadata) || array_is_list($metadata)) {
            throw new RuntimeException('Rollback artifact metadata must be an object.');
        }

        $requestId = $this->exactRequestId($metadata['request_id'] ?? null);
        $databaseIdentity = $this->exactSha($metadata['database_identity_fingerprint'] ?? null);
        $plan = $this->exactSha($metadata['activation_plan_fingerprint'] ?? null);
        $source = $this->exactSha($metadata['activation_source_fingerprint'] ?? null);
        $authorization = $this->exactSha($metadata['authorization_fingerprint'] ?? null);
        $allModule = $this->exactSha($metadata['all_module_fingerprint'] ?? null);
        $stateSha = $this->exactSha($metadata['state_sha256'] ?? null);
        $revision = filter_var($metadata['state_revision'] ?? null, FILTER_VALIDATE_INT);

        if ($requestId === ''
            || $databaseIdentity === ''
            || $plan === ''
            || $source === ''
            || $authorization === ''
            || $allModule === ''
            || $stateSha === ''
            || !is_int($revision)
            || $revision < 1) {
            throw new RuntimeException('Rollback artifact identity is incomplete.');
        }
        if (($metadata['source'] ?? null) !== 'database_primary'
            || ($metadata['environment'] ?? null) !== 'production'
            || ($metadata['build'] ?? null) !== ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD
            || ($metadata['contract_version'] ?? null)
                !== ProductionPrimaryRollbackExportGate::CONTRACT_VERSION) {
            throw new RuntimeException('Rollback artifact identity contract is invalid.');
        }
        if (($verified['state_revision'] ?? null) !== $revision
            || ($verified['state_sha256'] ?? null) !== $stateSha
            || ($verified['all_module_fingerprint'] ?? null) !== $allModule) {
            throw new RuntimeException('Rollback artifact verifier identity does not match metadata.');
        }

        return [
            'ok' => true,
            'export_directory_fingerprint' => hash('sha256', $canonical),
            'backup_id' => (string)($verified['backup_id'] ?? ''),
            'snapshot_sha256' => (string)($verified['snapshot_sha256'] ?? ''),
            'request_id' => $requestId,
            'state_revision' => $revision,
            'state_sha256' => $stateSha,
            'database_identity_fingerprint' => $databaseIdentity,
            'activation_plan_fingerprint' => $plan,
            'activation_source_fingerprint' => $source,
            'authorization_fingerprint' => $authorization,
            'all_module_fingerprint' => $allModule,
            'backup_manager_compatible' => ($verified['backup_manager_compatible'] ?? false) === true,
            'isolated_restore_required' => ($verified['isolated_restore_required'] ?? false) === true,
            'verified_files' => (int)($verified['verified_files'] ?? 0),
            'live_json_changed' => false,
            'database_contacted' => false,
            'database_write_executed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function exactSha(mixed $value): string
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1
            ? $value
            : '';
    }

    private function exactRequestId(mixed $value): string
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{32}\z/', $value) === 1
            ? $value
            : '';
    }
}
