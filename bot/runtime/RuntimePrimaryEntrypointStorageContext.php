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
        if (!in_array($entrypoint, ['api', 'webhook'], true)) {
            throw new InvalidArgumentException('Entrypoint storage context supports only api or webhook.');
        }
        if ($storage->driver() !== 'database') {
            throw new RuntimeException('Entrypoint storage context accepts only guarded DB-primary storage.');
        }
        if (($resolutionReport['resolved'] ?? false) !== true
            || ($resolutionReport['application_entrypoint_routed'] ?? true) !== false
            || ($resolutionReport['projection_outbox_enabled'] ?? false) !== true
            || ($resolutionReport['read_only_readiness_audit'] ?? false) !== true
            || ($resolutionReport['drift_check_passed'] ?? false) !== true) {
            throw new RuntimeException('Entrypoint storage context is missing guarded resolution evidence.');
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
            ];
        }
        return [
            'installed' => true,
            'entrypoint' => self::$entrypoint,
            'storage_driver' => self::$storage->driver(),
            'state_revision' => (int)(self::$resolutionReport['state_revision'] ?? 0),
            'state_sha256' => strtolower(trim((string)(self::$resolutionReport['state_sha256'] ?? ''))),
            'database_identity_fingerprint' => strtolower(trim((string)(self::$resolutionReport['database_identity_fingerprint'] ?? ''))),
            'evidence_fingerprint' => strtolower(trim((string)(self::$resolutionReport['evidence_fingerprint'] ?? ''))),
            'projection_outbox_enabled' => true,
            'application_entrypoint_routed' => true,
            'application_entrypoints_changed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }
}
