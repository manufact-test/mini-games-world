<?php
declare(strict_types=1);

final class ProductionRuntimePrimaryContract
{
    public const CONTRACT_VERSION = 'v2-guarded-atomic-production-entrypoints';

    public static function inspect(string $projectRoot): array
    {
        $projectRoot = self::canonicalDirectory($projectRoot);
        $sources = [];
        foreach ([
            'api' => 'bot/api.php',
            'webhook' => 'bot/webhook.php',
            'webhook_handler' => 'bot/handlers/WebhookHandler.php',
            'storage_factory' => 'bot/storage/StorageFactory.php',
            'bridge_guard' => 'bot/runtime/RuntimePrimaryEntrypointBridgeGuard.php',
            'coordinator' => 'bot/runtime/ProductionPrimaryRuntimeCoordinator.php',
            'activation' => 'bot/runtime/ProductionPrimaryRuntimeActivationContract.php',
            'bootstrap' => 'bot/runtime/ProductionPrimaryEntrypointBootstrap.php',
            'context' => 'bot/runtime/ProductionPrimaryEntrypointStorageContext.php',
            'atomic_storage' => 'bot/runtime/ProductionPrimaryAtomicStorageAdapter.php',
            'projector_factory' => 'bot/runtime/ProductionPrimaryProjectorFactory.php',
            'rollback_export_cli' => 'ops/runtime/run-production-primary-rollback-export.php',
            'live_rollback_cli' => 'ops/runtime/run-production-primary-live-rollback.php',
        ] as $name => $relative) {
            $path = $projectRoot . '/' . $relative;
            $raw = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
            $sources[$name] = is_string($raw) ? $raw : '';
        }

        $checks = [];
        $checks['all_sources_present'] = !in_array('', $sources, true);
        $checks['coordinator_versioned'] = str_contains(
            $sources['coordinator'],
            "public const CONTRACT_VERSION = 'v2-db-primary-atomic-entrypoint-wiring'"
        );
        $checks['direct_execution_disabled'] = str_contains(
            $sources['coordinator'],
            'public const EXECUTION_ENABLED = false'
        ) && str_contains(
            $sources['coordinator'],
            'public const ENTRYPOINT_WIRING_ENABLED = true'
        ) && str_contains(
            $sources['coordinator'],
            'Direct production API execution is forbidden'
        ) && str_contains(
            $sources['coordinator'],
            'Direct production webhook execution is forbidden'
        );
        $checks['activation_requires_completed_state'] = str_contains(
            $sources['bootstrap'],
            "(\$activation['state'] ?? '') !== 'completed'"
        ) && str_contains(
            $sources['bootstrap'],
            "(\$activation['json_write_block_active'] ?? true) !== false"
        );
        $checks['activation_build_exact'] = str_contains(
            $sources['activation'],
            "public const ACTIVATION_BUILD = 'v103-mvp14-production-cutover'"
        );
        $checks['bootstrap_database_identity_bound'] = str_contains(
            $sources['bootstrap'],
            'Production DB-primary database identity does not match activation evidence.'
        ) && str_contains(
            $sources['bootstrap'],
            "(int)\$database->fetchValue('SELECT 1') !== 1"
        );
        $checks['atomic_request_transaction'] = str_contains(
            $sources['atomic_storage'],
            'return $this->database->transaction(function ('
        ) && str_contains(
            $sources['atomic_storage'],
            '$baseline = $this->captureLockedBaseline($data);'
        ) && str_contains(
            $sources['atomic_storage'],
            '$tick = $this->worker->runOnce();'
        ) && str_contains(
            $sources['atomic_storage'],
            "$final = \$this->captureAndAudit('final');"
        );
        $checks['all_module_projection_required'] = str_contains(
            $sources['atomic_storage'],
            'Production atomic projection did not complete the exact state revision.'
        ) && str_contains(
            $sources['projector_factory'],
            'Production projector factory requires all nine modules.'
        );
        $checks['storage_factory_wires_real_entrypoints'] = str_contains(
            $sources['storage_factory'],
            "'api.php' => 'api'"
        ) && str_contains(
            $sources['storage_factory'],
            "'webhook.php' => 'webhook'"
        ) && str_contains(
            $sources['storage_factory'],
            'ProductionPrimaryEntrypointBootstrap::installIfEnabled('
        ) && str_contains(
            $sources['storage_factory'],
            'ProductionPrimaryEntrypointStorageContext::storage()'
        );
        $checks['api_uses_guarded_storage_factory'] = str_contains(
            $sources['api'],
            'StorageFactory::createJson('
        ) && !str_contains($sources['api'], 'new JsonStorageAdapter(');
        $checks['webhook_installs_context_early'] = str_contains(
            $sources['webhook'],
            '$requestStorage = StorageFactory::createJson('
        ) && str_contains(
            $sources['webhook'],
            'http_response_code($productionDbPrimaryRequested ? 503 : 200)'
        ) && !str_contains($sources['webhook'], 'new JsonStorageAdapter(');
        $checks['webhook_handler_uses_guarded_storage_factory'] = str_contains(
            $sources['webhook_handler'],
            'StorageFactory::createJson('
        ) && !str_contains($sources['webhook_handler'], 'new JsonStorageAdapter(');
        $checks['legacy_bridges_suppressed'] = str_contains(
            $sources['bridge_guard'],
            "ProductionPrimaryEntrypointStorageContext"
        ) && str_contains(
            $sources['bridge_guard'],
            '!ProductionPrimaryEntrypointStorageContext::installed()'
        );
        $checks['request_context_completed_only'] = str_contains(
            $sources['context'],
            "(\$activationReport['state'] ?? '') !== 'completed'"
        ) && str_contains(
            $sources['context'],
            'ProductionPrimaryAtomicStorageAdapter'
        );
        $checks['verified_rollback_commands_present'] = str_contains(
            $sources['rollback_export_cli'],
            'ProductionPrimaryRollbackExportBootstrap'
        ) && str_contains(
            $sources['live_rollback_cli'],
            'ProductionPrimaryLiveRollbackBootstrap'
        );

        $blockers = [];
        foreach ($checks as $name => $passed) {
            if ($passed !== true) {
                $blockers[] = 'production runtime primary contract failed: ' . $name;
            }
        }
        ksort($checks, SORT_STRING);
        sort($blockers, SORT_STRING);

        $fingerprints = [];
        foreach ($sources as $name => $source) {
            $fingerprints[$name] = $source !== '' ? hash('sha256', $source) : '';
        }
        ksort($fingerprints, SORT_STRING);

        return [
            'ready' => $blockers === [],
            'contract_version' => self::CONTRACT_VERSION,
            'contract_fingerprint' => hash(
                'sha256',
                self::canonicalJson([
                    'checks' => $checks,
                    'source_fingerprints' => $fingerprints,
                ])
            ),
            'checks' => $checks,
            'source_fingerprints' => $fingerprints,
            'blockers' => $blockers,
            'application_entrypoints_changed' => false,
            'database_contacted' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private static function canonicalDirectory(string $path): string
    {
        if ($path === '' || str_contains($path, '\\') || !str_starts_with($path, '/')
            || is_link($path) || !is_dir($path)) {
            throw new InvalidArgumentException('Production runtime primary project root is invalid.');
        }
        $canonical = realpath($path);
        if (!is_string($canonical) || !hash_equals($path, $canonical)) {
            throw new InvalidArgumentException('Production runtime primary project root is not canonical.');
        }
        return $canonical;
    }

    private static function canonicalJson(array $value): string
    {
        $canonicalize = static function (mixed $item) use (&$canonicalize): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            foreach ($item as $key => $child) $item[$key] = $canonicalize($child);
            return $item;
        };
        return json_encode(
            $canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
