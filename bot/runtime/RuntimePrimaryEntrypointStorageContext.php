<?php
declare(strict_types=1);

final class RuntimePrimaryEntrypointStorageContext
{
    private static ?StorageAdapterInterface $storage = null;
    private static string $entrypoint = '';
    private static array $resolutionReport = [];

    public static function install(
        StorageAdapterInterface $storage,
        string $entrypoint,
        array $resolutionReport
    ): void {
        $entrypoint = strtolower(trim($entrypoint));
        if ($entrypoint !== 'api') {
            throw new InvalidArgumentException('Lifecycle DB-primary storage context supports only API.');
        }
        if ($storage->driver() !== 'database') {
            throw new RuntimeException('Entrypoint storage context accepts only guarded DB-primary storage.');
        }
        if (($resolutionReport['resolved'] ?? false) !== true
            || ($resolutionReport['application_entrypoint_routed'] ?? true) !== false
            || ($resolutionReport['projection_outbox_enabled'] ?? false) !== true
            || ($resolutionReport['read_only_readiness_audit'] ?? false) !== true
            || ($resolutionReport['drift_check_passed'] ?? false) !== true
            || ($resolutionReport['dynamic_session_readiness'] ?? false) !== true
            || ($resolutionReport['request_finalizer_registered'] ?? false) !== true
            || ($resolutionReport['legacy_json_bridges_suppressed'] ?? false) !== true
            || ($resolutionReport['webhook_allowed'] ?? true) !== false
            || ($resolutionReport['production_changed'] ?? true) !== false) {
            throw new RuntimeException('Entrypoint storage context is missing lifecycle resolution evidence.');
        }
        if (($resolutionReport['evidence_manifest_version'] ?? '')
            !== RuntimePrimaryStagingEvidenceV4Verifier::MANIFEST_VERSION) {
            throw new RuntimeException('Entrypoint storage context requires lifecycle evidence v4.');
        }
        if (($resolutionReport['selector_contract_version'] ?? '')
            !== RuntimePrimaryStagingSelectorEvidence::CONTRACT_VERSION) {
            throw new RuntimeException('Entrypoint storage context requires the exact selector evidence contract.');
        }
        if (($resolutionReport['request_session_contract_version'] ?? '')
            !== RuntimePrimaryStagingRequestSessionConfig::CONTRACT_VERSION) {
            throw new RuntimeException('Entrypoint storage context requires the exact request session contract.');
        }

        $baselineRevision = (int)($resolutionReport['baseline_state_revision'] ?? 0);
        $stateRevision = (int)($resolutionReport['state_revision'] ?? 0);
        $maximumRevision = (int)($resolutionReport['maximum_state_revision'] ?? 0);
        $remainingRevisions = (int)($resolutionReport['remaining_session_revisions'] ?? -1);
        if ($baselineRevision < 1
            || $stateRevision < $baselineRevision
            || $maximumRevision < $stateRevision
            || $remainingRevisions !== $maximumRevision - $stateRevision) {
            throw new RuntimeException('Entrypoint storage context request session revision bounds are invalid.');
        }
        $expiresAt = trim((string)($resolutionReport['session_expires_at_utc'] ?? ''));
        if ($expiresAt === ''
            || preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $expiresAt) !== 1
            || strtotime($expiresAt) === false) {
            throw new RuntimeException('Entrypoint storage context request session expiry is invalid.');
        }

        foreach ([
            'baseline_state_sha256' => 'baseline state fingerprint',
            'state_sha256' => 'state fingerprint',
            'database_identity_fingerprint' => 'database identity fingerprint',
            'evidence_fingerprint' => 'evidence fingerprint',
            'selector_evidence_fingerprint' => 'selector evidence fingerprint',
            'request_session_evidence_fingerprint' => 'request session evidence fingerprint',
        ] as $field => $label) {
            $fingerprint = strtolower(trim((string)($resolutionReport[$field] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
                throw new RuntimeException('Entrypoint storage context requires a valid ' . $label . '.');
            }
        }

        if (self::$storage !== null) {
            if (self::$storage !== $storage || !hash_equals(self::$entrypoint, $entrypoint)) {
                throw new RuntimeException('Entrypoint storage context is already installed for another storage or entrypoint.');
            }
            return;
        }

        self::$storage = $storage;
        self::$entrypoint = $entrypoint;
        self::$resolutionReport = $resolutionReport;
    }

    public static function installed(): bool
    {
        return self::$storage !== null;
    }

    public static function storage(): StorageAdapterInterface
    {
        if (self::$storage === null) {
            throw new RuntimeException('Entrypoint DB-primary storage context is not installed.');
        }
        return self::$storage;
    }

    public static function storageOrNull(): ?StorageAdapterInterface
    {
        return self::$storage;
    }

    public static function safeReport(): array
    {
        if (self::$storage === null) {
            return [
                'installed' => false,
                'entrypoint' => '',
                'storage_driver' => 'json',
                'application_entrypoint_routed' => false,
                'dynamic_session_readiness' => false,
                'request_finalizer_registered' => false,
                'legacy_json_bridges_suppressed' => false,
                'webhook_allowed' => false,
            ];
        }
        return [
            'installed' => true,
            'entrypoint' => self::$entrypoint,
            'storage_driver' => self::$storage->driver(),
            'baseline_state_revision' => (int)(self::$resolutionReport['baseline_state_revision'] ?? 0),
            'baseline_state_sha256' => strtolower(trim((string)(self::$resolutionReport['baseline_state_sha256'] ?? ''))),
            'state_revision' => (int)(self::$resolutionReport['state_revision'] ?? 0),
            'state_sha256' => strtolower(trim((string)(self::$resolutionReport['state_sha256'] ?? ''))),
            'maximum_state_revision' => (int)(self::$resolutionReport['maximum_state_revision'] ?? 0),
            'remaining_session_revisions' => (int)(self::$resolutionReport['remaining_session_revisions'] ?? 0),
            'session_expires_at_utc' => (string)(self::$resolutionReport['session_expires_at_utc'] ?? ''),
            'database_identity_fingerprint' => strtolower(trim((string)(self::$resolutionReport['database_identity_fingerprint'] ?? ''))),
            'evidence_fingerprint' => strtolower(trim((string)(self::$resolutionReport['evidence_fingerprint'] ?? ''))),
            'evidence_manifest_version' => (string)(self::$resolutionReport['evidence_manifest_version'] ?? ''),
            'selector_contract_version' => (string)(self::$resolutionReport['selector_contract_version'] ?? ''),
            'selector_evidence_fingerprint' => strtolower(trim((string)(
                self::$resolutionReport['selector_evidence_fingerprint'] ?? ''
            ))),
            'request_session_contract_version' => (string)(
                self::$resolutionReport['request_session_contract_version'] ?? ''
            ),
            'request_session_evidence_fingerprint' => strtolower(trim((string)(
                self::$resolutionReport['request_session_evidence_fingerprint'] ?? ''
            ))),
            'projection_outbox_enabled' => true,
            'dynamic_session_readiness' => true,
            'request_finalizer_registered' => true,
            'legacy_json_bridges_suppressed' => true,
            'application_entrypoint_routed' => true,
            'application_entrypoints_changed' => false,
            'webhook_allowed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }
}
