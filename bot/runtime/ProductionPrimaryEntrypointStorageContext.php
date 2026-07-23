<?php
declare(strict_types=1);

final class ProductionPrimaryEntrypointStorageContext
{
    private static ?ProductionPrimaryAtomicStorageAdapter $storage = null;
    private static string $entrypoint = '';
    private static array $activationReport = [];

    public static function install(
        ProductionPrimaryAtomicStorageAdapter $storage,
        string $entrypoint,
        array $activationReport
    ): void {
        if (!in_array($entrypoint, ['api', 'webhook'], true)) {
            throw new InvalidArgumentException(
                'Production DB-primary entrypoint context supports only API and webhook.'
            );
        }
        if ($storage->driver() !== 'database') {
            throw new RuntimeException(
                'Production entrypoint context accepts only atomic DB-primary storage.'
            );
        }
        if (($activationReport['ready'] ?? false) !== true
            || ($activationReport['state'] ?? '') !== 'completed'
            || ($activationReport['contract_version'] ?? '')
                !== ProductionPrimaryRuntimeActivationContract::CONTRACT_VERSION
            || ($activationReport['activation_build'] ?? '')
                !== ProductionPrimaryRuntimeActivationContract::ACTIVATION_BUILD
            || ($activationReport['json_write_block_active'] ?? true) !== false
            || ($activationReport['production_changed'] ?? true) !== false) {
            throw new RuntimeException(
                'Production entrypoint context is missing a completed activation contract.'
            );
        }
        foreach ([
            'database_identity_fingerprint',
            'activation_plan_fingerprint',
            'activation_source_fingerprint',
            'contract_fingerprint',
        ] as $field) {
            $value = $activationReport[$field] ?? null;
            if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
                throw new RuntimeException(
                    'Production entrypoint context activation fingerprint is invalid: ' . $field . '.'
                );
            }
        }
        if (count((array)($activationReport['enabled_modules'] ?? [])) !== 9) {
            throw new RuntimeException(
                'Production entrypoint context requires all nine DB-primary modules.'
            );
        }

        if (self::$storage !== null) {
            if (self::$storage !== $storage || !hash_equals(self::$entrypoint, $entrypoint)) {
                throw new RuntimeException(
                    'Production entrypoint context is already installed for another request.'
                );
            }
            return;
        }

        self::$storage = $storage;
        self::$entrypoint = $entrypoint;
        self::$activationReport = $activationReport;
    }

    public static function installed(): bool
    {
        return self::$storage !== null;
    }

    public static function storage(): ProductionPrimaryAtomicStorageAdapter
    {
        if (self::$storage === null) {
            throw new RuntimeException(
                'Production DB-primary entrypoint storage context is not installed.'
            );
        }
        return self::$storage;
    }

    public static function storageOrNull(): ?ProductionPrimaryAtomicStorageAdapter
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
                'atomic_projection' => false,
                'legacy_json_bridges_suppressed' => false,
                'rollback_requires_fresh_db_export' => true,
                'production_changed' => false,
            ];
        }

        return [
            'installed' => true,
            'entrypoint' => self::$entrypoint,
            'storage_driver' => self::$storage->driver(),
            'atomic_contract_version' => ProductionPrimaryAtomicStorageAdapter::CONTRACT_VERSION,
            'activation_contract_version' => (string)(
                self::$activationReport['contract_version'] ?? ''
            ),
            'activation_contract_fingerprint' => (string)(
                self::$activationReport['contract_fingerprint'] ?? ''
            ),
            'activation_state' => (string)(self::$activationReport['state'] ?? ''),
            'enabled_modules' => (array)(self::$activationReport['enabled_modules'] ?? []),
            'atomic_projection' => true,
            'legacy_json_bridges_suppressed' => true,
            'application_entrypoint_routed' => true,
            'rollback_driver' => 'json',
            'rollback_requires_fresh_db_export' => true,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }
}
