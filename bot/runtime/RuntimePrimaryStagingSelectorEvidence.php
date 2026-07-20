<?php
declare(strict_types=1);

final class RuntimePrimaryStagingSelectorEvidence
{
    public const CONTRACT_VERSION = 'v1-guarded-staging-entrypoint-selector';

    public static function inspect(string $projectRoot): array
    {
        $projectRoot = rtrim(str_replace('\\', '/', trim($projectRoot)), '/');
        if ($projectRoot === '' || !is_dir($projectRoot)) {
            throw new InvalidArgumentException('Selector evidence project root is unavailable.');
        }

        $paths = [
            'api' => $projectRoot . '/bot/api.php',
            'webhook_handler' => $projectRoot . '/bot/handlers/WebhookHandler.php',
            'bootstrap' => $projectRoot . '/bot/core/bootstrap.php',
            'storage_factory' => $projectRoot . '/bot/storage/StorageFactory.php',
            'selector' => $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointStorageSelector.php',
            'selector_config' => $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointSelectorConfig.php',
            'storage_context' => $projectRoot . '/bot/runtime/RuntimePrimaryEntrypointStorageContext.php',
        ];
        $sources = [];
        $sourceText = [];
        $blockers = [];
        foreach ($paths as $name => $path) {
            $source = is_file($path) ? file_get_contents($path) : false;
            if (!is_string($source)) {
                $sources[$name] = '';
                $sourceText[$name] = '';
                $blockers[] = 'Selector evidence source is unavailable: ' . $name . '.';
                continue;
            }
            $sources[$name] = hash('sha256', $source);
            $sourceText[$name] = $source;
        }
        ksort($sources, SORT_STRING);

        $checks = [
            'api_json_factory_present' => str_contains($sourceText['api'] ?? '', 'StorageFactory::createJson('),
            'webhook_json_factory_present' => str_contains($sourceText['webhook_handler'] ?? '', 'StorageFactory::createJson('),
            'bootstrap_selector_loader_present' => str_contains(
                $sourceText['bootstrap'] ?? '',
                'RuntimePrimaryStagingEntrypointBootstrap.php'
            ),
            'bootstrap_selector_install_present' => str_contains(
                $sourceText['bootstrap'] ?? '',
                'installIfEnabled()'
            ),
            'bootstrap_entrypoints_bounded' => str_contains(
                $sourceText['bootstrap'] ?? '',
                "['api.php', 'webhook.php']"
            ),
            'storage_factory_context_override_present' => str_contains(
                $sourceText['storage_factory'] ?? '',
                'RuntimePrimaryEntrypointStorageContext::installed()'
            ) && str_contains(
                $sourceText['storage_factory'] ?? '',
                'RuntimePrimaryEntrypointStorageContext::storage()'
            ),
            'selector_staging_guard_present' => str_contains(
                $sourceText['selector'] ?? '',
                "if (\$environment !== 'staging')"
            ),
            'selector_v3_requirement_present' => str_contains(
                $sourceText['selector'] ?? '',
                'RuntimePrimaryStagingEvidenceV3Verifier::MANIFEST_VERSION'
            ),
            'selector_resolution_guard_present' => str_contains(
                $sourceText['selector'] ?? '',
                'RuntimePrimaryStagingStorageResolver('
            ) && str_contains(
                $sourceText['selector'] ?? '',
                'RuntimePrimaryEntrypointStorageContext::install('
            ),
            'selector_contract_version_present' => str_contains(
                $sourceText['selector_config'] ?? '',
                RuntimePrimaryStagingEntrypointSelectorConfig::CONTRACT_VERSION
            ),
            'selector_disabled_default_present' => str_contains(
                $sourceText['selector_config'] ?? '',
                "\$settings['enabled'] ?? false"
            ),
            'context_database_only_present' => str_contains(
                $sourceText['storage_context'] ?? '',
                "if (\$storage->driver() !== 'database')"
            ),
            'context_immutable_present' => str_contains(
                $sourceText['storage_context'] ?? '',
                'already installed for another storage or entrypoint'
            ),
        ];
        ksort($checks, SORT_STRING);
        foreach ($checks as $name => $passed) {
            if ($passed !== true) {
                $blockers[] = 'Selector evidence check failed: ' . $name . '.';
            }
        }
        $blockers = array_values(array_unique($blockers));

        return [
            'ready' => $blockers === [],
            'contract_version' => self::CONTRACT_VERSION,
            'checks' => $checks,
            'sources' => $sources,
            'blockers' => $blockers,
            'selector_enabled_by_evidence' => false,
            'default_storage_driver' => 'json',
            'staging_selector_available' => true,
            'production_selector_allowed' => false,
            'application_entrypoints_changed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }
}
