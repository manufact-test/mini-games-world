<?php
declare(strict_types=1);

require_once __DIR__ . '/RuntimePrimaryStagingEntrypointSelectorConfig.php';

final class RuntimePrimaryStagingSelectorEvidence
{
    public const CONTRACT_VERSION = 'v2-api-session-staging-entrypoint-selector';

    public static function inspect(string $projectRoot): array
    {
        $projectRoot = rtrim(str_replace('\\', '/', trim($projectRoot)), '/');
        if ($projectRoot === '' || !is_dir($projectRoot)) {
            throw new InvalidArgumentException('Selector evidence project root is unavailable.');
        }

        $paths = [
            'api' => $projectRoot . '/bot/api.php',
            'webhook_entrypoint' => $projectRoot . '/bot/webhook.php',
            'webhook_handler' => $projectRoot . '/bot/handlers/WebhookHandler.php',
            'runtime_admin_guard' => $projectRoot . '/bot/helpers/RuntimeAdminGuard.php',
            'payment_reject_guard' => $projectRoot . '/bot/helpers/AdminPaymentRejectGuard.php',
            'shop_notification_guard' => $projectRoot . '/bot/helpers/AdminShopOrderNotificationGuard.php',
            'shop_ui_guard' => $projectRoot . '/bot/helpers/AdminShopOrderUiGuard.php',
            'gold_topup_guard' => $projectRoot . '/bot/helpers/AdminGoldTopupNotificationGuard.php',
            'system_check_guard' => $projectRoot . '/bot/helpers/AdminSystemCheckGuard.php',
            'user_welcome_guard' => $projectRoot . '/bot/helpers/UserWelcomeGuard.php',
            'bootstrap' => $projectRoot . '/bot/core/bootstrap.php',
            'bridge_guard' => $projectRoot . '/bot/runtime/RuntimePrimaryEntrypointBridgeGuard.php',
            'storage_factory' => $projectRoot . '/bot/storage/StorageFactory.php',
            'selector_bootstrap' => $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointBootstrap.php',
            'selector' => $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointStorageSelector.php',
            'selector_config' => $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointSelectorConfig.php',
            'storage_context' => $projectRoot . '/bot/runtime/RuntimePrimaryEntrypointStorageContext.php',
            'api_session_coordinator' => $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiSessionCoordinator.php',
            'api_finalization_hook' => $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiRequestFinalizationHook.php',
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

        $bootstrap = $sourceText['bootstrap'] ?? '';
        $bridgeGuard = $sourceText['bridge_guard'] ?? '';
        $factory = $sourceText['storage_factory'] ?? '';
        $selector = $sourceText['selector'] ?? '';
        $selectorConfig = $sourceText['selector_config'] ?? '';
        $context = $sourceText['storage_context'] ?? '';
        $selectorBootstrap = $sourceText['selector_bootstrap'] ?? '';
        $coordinator = $sourceText['api_session_coordinator'] ?? '';
        $hook = $sourceText['api_finalization_hook'] ?? '';
        $requestSources = array_intersect_key($sourceText, array_fill_keys([
            'api',
            'webhook_entrypoint',
            'webhook_handler',
            'runtime_admin_guard',
            'payment_reject_guard',
            'shop_notification_guard',
            'shop_ui_guard',
            'gold_topup_guard',
            'system_check_guard',
            'user_welcome_guard',
        ], true));
        $directJsonConstructorAbsent = true;
        foreach ($requestSources as $requestSource) {
            if (str_contains($requestSource, 'new JsonStorageAdapter(')
                || str_contains($requestSource, 'new JsonDatabase(')) {
                $directJsonConstructorAbsent = false;
                break;
            }
        }

        $checks = [
            'api_json_factory_present' => str_contains($sourceText['api'] ?? '', 'StorageFactory::createJson('),
            'webhook_json_factory_present' => str_contains($sourceText['webhook_handler'] ?? '', 'StorageFactory::createJson('),
            'request_direct_json_constructor_absent' => $directJsonConstructorAbsent,
            'default_json_fallback_present' => str_contains($factory, 'return new JsonStorageAdapter($dataDir);'),
            'legacy_bridge_guard_contract_present' => str_contains(
                $bridgeGuard,
                "class_exists('RuntimePrimaryEntrypointStorageContext', false)"
            ) && str_contains(
                $bridgeGuard,
                'RuntimePrimaryEntrypointStorageContext::installed()'
            ),
            'legacy_bridge_hooks_suppressed_for_db_context' => str_contains(
                $bootstrap,
                'RuntimePrimaryEntrypointBridgeGuard.php'
            ) && substr_count(
                $bootstrap,
                'RuntimePrimaryEntrypointBridgeGuard::legacyJsonBridgeAllowed()'
            ) >= 8,
            'storage_factory_lazy_selector_present' => str_contains(
                $factory,
                'installGuardedEntrypointContextIfEligible()'
            ) && str_contains(
                $factory,
                'RuntimePrimaryStagingEntrypointBootstrap.php'
            ) && str_contains(
                $factory,
                'installIfEnabled()'
            ),
            'storage_factory_entrypoints_bounded' => str_contains($factory, "'api.php' => 'api'")
                && str_contains($factory, "'webhook.php' => 'webhook'")
                && str_contains($factory, "default => ''"),
            'storage_factory_context_override_present' => str_contains(
                $factory,
                'RuntimePrimaryEntrypointStorageContext::installed()'
            ) && str_contains(
                $factory,
                'RuntimePrimaryEntrypointStorageContext::storage()'
            ),
            'storage_factory_failure_sticky' => str_contains(
                $factory,
                'previously failed in this request'
            ) && str_contains(
                $factory,
                '$failures[$entrypoint] = $error'
            ),
            'selector_bootstrap_lifecycle_present' => str_contains(
                $selectorBootstrap,
                'RuntimePrimaryStagingEvidenceV4Verifier.php'
            ) && str_contains(
                $selectorBootstrap,
                'RuntimePrimaryStagingApiSessionCoordinator.php'
            ) && str_contains(
                $selectorBootstrap,
                'RuntimePrimaryStagingRequestFinalizer.php'
            ),
            'selector_staging_guard_present' => str_contains(
                $selector,
                "if (\$environment !== 'staging')"
            ),
            'selector_api_only_present' => str_contains(
                $selector,
                "if (\$this->entrypoint === 'webhook')"
            ) && str_contains(
                $selector,
                'DB-primary webhook routing is not allowed'
            ) && str_contains(
                $selector,
                'RuntimePrimaryStagingApiSessionCoordinator('
            ),
            'selector_config_api_only_present' => str_contains(
                $selectorConfig,
                RuntimePrimaryStagingEntrypointSelectorConfig::CONTRACT_VERSION
            ) && str_contains(
                $selectorConfig,
                'supports only API'
            ) && str_contains(
                $selectorConfig,
                "'webhook_allowed' => false"
            ),
            'selector_disabled_default_present' => str_contains(
                $selectorConfig,
                "array_key_exists('enabled', \$settings)"
            ) && str_contains(
                $selectorConfig,
                "? self::strictBool(\$settings['enabled']"
            ) && str_contains(
                $selectorConfig,
                ': false;'
            ),
            'selector_config_exact_types_present' => str_contains(
                $selectorConfig,
                'private static function strictBool('
            ) && str_contains(
                $selectorConfig,
                'private static function strictString('
            ) && str_contains(
                $selectorConfig,
                'allowed_entrypoints values must be strings'
            ),
            'selector_config_no_normalization_present' => !str_contains(
                $selectorConfig,
                'strtolower('
            ) && !str_contains(
                $selectorConfig,
                'trim('
            ),
            'selector_enabled_for_exact_present' => str_contains(
                $selectorConfig,
                "if (!in_array(\$entrypoint, ['api', 'webhook'], true))"
            ),
            'context_database_only_present' => str_contains(
                $context,
                "if (\$storage->driver() !== 'database')"
            ),
            'context_lifecycle_v4_only_present' => str_contains(
                $context,
                'requires lifecycle evidence v4'
            ) && str_contains(
                $context,
                'request_finalizer_registered'
            ) && str_contains(
                $context,
                'dynamic_session_readiness'
            ),
            'context_api_only_present' => str_contains(
                $context,
                "if (\$entrypoint !== 'api')"
            ),
            'context_immutable_present' => str_contains(
                $context,
                'already installed for another storage or entrypoint'
            ),
            'coordinator_v4_before_database_present' => strpos(
                $coordinator,
                'RuntimePrimaryStagingEvidenceV4Gate('
            ) !== false && strpos(
                $coordinator,
                'RuntimePrimaryStagingEvidenceV4Gate('
            ) < strpos(
                $coordinator,
                'PdoConnectionFactory::create($databaseConfig)'
            ),
            'coordinator_dynamic_readiness_present' => str_contains(
                $coordinator,
                'RuntimePrimaryStagingRequestSessionReadiness('
            ) && str_contains(
                $coordinator,
                'RuntimePrimaryStagingRequestFinalizer('
            ) && str_contains(
                $coordinator,
                'array_unshift($hooks, $hook)'
            ),
            'finalization_hook_once_only_present' => str_contains(
                $hook,
                'was invoked more than once'
            ) && str_contains(
                $hook,
                'RuntimePrimaryEntrypointStorageContext::storage() !== $this->storage'
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
            'api_only' => true,
            'webhook_allowed' => false,
            'production_selector_allowed' => false,
            'application_entrypoints_changed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }
}
