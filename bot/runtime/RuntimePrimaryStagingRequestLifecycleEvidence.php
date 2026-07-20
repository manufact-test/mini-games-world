<?php
declare(strict_types=1);

final class RuntimePrimaryStagingRequestLifecycleEvidence
{
    public const CONTRACT_VERSION = 'v1-api-only-staging-request-lifecycle';

    public static function inspect(string $projectRoot, array $baseline): array
    {
        $projectRoot = rtrim(str_replace('\\', '/', trim($projectRoot)), '/');
        if ($projectRoot === '' || !is_dir($projectRoot)) {
            throw new InvalidArgumentException('Request lifecycle evidence project root is unavailable.');
        }
        $baseline = self::normalizeBaseline($baseline);

        $paths = [
            'api' => $projectRoot . '/bot/api.php',
            'response_helper' => $projectRoot . '/bot/helpers/response.php',
            'selector_bootstrap' => $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointBootstrap.php',
            'selector' => $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointStorageSelector.php',
            'selector_config' => $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointSelectorConfig.php',
            'storage_context' => $projectRoot . '/bot/runtime/RuntimePrimaryEntrypointStorageContext.php',
            'api_session_coordinator' => $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiSessionCoordinator.php',
            'api_finalization_hook' => $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiRequestFinalizationHook.php',
            'request_session_config' => $projectRoot . '/bot/runtime/RuntimePrimaryStagingRequestSessionConfig.php',
            'request_finalizer' => $projectRoot . '/bot/runtime/RuntimePrimaryStagingRequestFinalizer.php',
            'request_readiness' => $projectRoot . '/bot/runtime/RuntimePrimaryStagingRequestSessionReadiness.php',
            'worker_interface' => $projectRoot . '/bot/runtime/RuntimePrimaryProjectionWorkerInterface.php',
            'auditor_interface' => $projectRoot . '/bot/runtime/RuntimePrimaryProjectionAuditorInterface.php',
            'worker_adapter' => $projectRoot . '/bot/runtime/RuntimePrimaryProjectionWorkerAdapter.php',
            'auditor_adapter' => $projectRoot . '/bot/runtime/RuntimePrimaryProjectionAuditorAdapter.php',
            'projection_worker' => $projectRoot . '/bot/runtime/RuntimePrimaryProjectionWorker.php',
            'all_module_projector' => $projectRoot . '/bot/runtime/RuntimePrimaryAllModuleProjector.php',
        ];
        $sources = [];
        $text = [];
        $blockers = [];
        foreach ($paths as $name => $path) {
            $source = is_file($path) ? file_get_contents($path) : false;
            if (!is_string($source)) {
                $sources[$name] = '';
                $text[$name] = '';
                $blockers[] = 'Request lifecycle evidence source is unavailable: ' . $name . '.';
                continue;
            }
            $sources[$name] = hash('sha256', $source);
            $text[$name] = $source;
        }
        ksort($sources, SORT_STRING);

        $response = $text['response_helper'] ?? '';
        $api = $text['api'] ?? '';
        $coordinator = $text['api_session_coordinator'] ?? '';
        $hook = $text['api_finalization_hook'] ?? '';
        $session = $text['request_session_config'] ?? '';
        $finalizer = $text['request_finalizer'] ?? '';
        $readiness = $text['request_readiness'] ?? '';
        $context = $text['storage_context'] ?? '';
        $selector = $text['selector'] ?? '';
        $selectorConfig = $text['selector_config'] ?? '';
        $selectorBootstrap = $text['selector_bootstrap'] ?? '';

        $hookRead = strpos($response, "$hooks = $GLOBALS['mgw_api_success_hooks'] ?? [];");
        $hookRun = strpos($response, 'foreach ($hooks as $hook)');
        $filterRead = strpos($response, "$filters = $GLOBALS['mgw_api_data_filters'] ?? [];");
        $responseSend = strpos($response, "api_response(['ok' => true, 'data' => $data]);");
        $evidenceGate = strpos($coordinator, 'RuntimePrimaryStagingEvidenceV4Gate(');
        $dbOpen = strpos($coordinator, 'PdoConnectionFactory::create($databaseConfig)');
        $readinessRun = strpos($coordinator, 'RuntimePrimaryStagingRequestSessionReadiness(');
        $contextInstall = strpos($coordinator, 'RuntimePrimaryEntrypointStorageContext::install(');
        $hookPrepend = strpos($coordinator, 'array_unshift($hooks, $hook)');

        $checks = [
            'api_success_inside_error_boundary' => str_contains($api, 'api_ok($result);')
                && str_contains($api, '} catch (Throwable $e) {')
                && strpos($api, 'api_ok($result);') < strpos($api, '} catch (Throwable $e) {'),
            'success_hooks_before_filters_and_response' => $hookRead !== false
                && $hookRun !== false
                && $filterRead !== false
                && $responseSend !== false
                && $hookRead < $hookRun
                && $hookRun < $filterRead
                && $filterRead < $responseSend,
            'coordinator_v4_before_database' => $evidenceGate !== false
                && $dbOpen !== false
                && $evidenceGate < $dbOpen,
            'coordinator_readiness_before_context' => $readinessRun !== false
                && $contextInstall !== false
                && $readinessRun < $contextInstall,
            'coordinator_context_before_finalizer_registration' => $contextInstall !== false
                && $hookPrepend !== false
                && $contextInstall < $hookPrepend,
            'coordinator_finalizer_registered_first' => str_contains(
                $coordinator,
                'array_unshift($hooks, $hook)'
            ) && str_contains(
                $coordinator,
                'DB-primary API request finalizer was not registered first'
            ),
            'coordinator_api_only_output' => str_contains($coordinator, "'entrypoint' => 'api'")
                && str_contains($coordinator, "'webhook_allowed' => false")
                && str_contains($coordinator, "'production_changed' => false"),
            'finalization_hook_once_only' => str_contains($hook, 'was invoked more than once')
                && str_contains($hook, 'lost its guarded storage context')
                && str_contains($hook, 'projection_event_status')
                && str_contains($hook, "'webhook_allowed' => false"),
            'session_contract_api_only' => str_contains(
                $session,
                RuntimePrimaryStagingRequestSessionConfig::CONTRACT_VERSION
            ) && str_contains($session, 'supports only API')
                && str_contains($session, "'webhook_allowed' => false")
                && str_contains($session, 'max revision delta must be between 1 and 20')
                && str_contains($session, 'expiry is more than 30 minutes away'),
            'finalizer_bounded_worker_and_current_audit' => str_contains(
                $finalizer,
                'count($ticks) >= $this->session->maxWorkerTicks()'
            ) && str_contains(
                $finalizer,
                "($tick['action'] ?? '') !== 'projection_completed'"
            ) && str_contains(
                $finalizer,
                'queueStatus($currentRevision)'
            ) && str_contains(
                $finalizer,
                'auditOnly($snapshot, $currentRevision, $currentSha)'
            ),
            'finalizer_fail_closed_output' => str_contains(
                $finalizer,
                "'projection_event_status' => 'completed'"
            ) && str_contains(
                $finalizer,
                "'legacy_json_bridges_suppressed' => true"
            ) && str_contains(
                $finalizer,
                "'webhook_allowed' => false"
            ) && str_contains(
                $finalizer,
                "'production_changed' => false"
            ),
            'readiness_fixed_json_and_current_db' => substr_count(
                $readiness,
                'RuntimePrimaryJsonEvidence::capture($this->jsonStorage)'
            ) === 2 && str_contains(
                $readiness,
                'eventForRevision($baselineRevision)'
            ) && str_contains(
                $readiness,
                'eventForRevision($currentRevision)'
            ) && str_contains(
                $readiness,
                'auditOnly($snapshot, $currentRevision, $currentSha)'
            ),
            'context_lifecycle_v4_and_finalizer' => str_contains(
                $context,
                'requires lifecycle evidence v4'
            ) && str_contains(
                $context,
                'request_finalizer_registered'
            ) && str_contains(
                $context,
                'dynamic_session_readiness'
            ) && str_contains(
                $context,
                "if ($entrypoint !== 'api')"
            ),
            'selector_and_config_api_only' => str_contains(
                $selector,
                'DB-primary webhook routing is not allowed'
            ) && str_contains(
                $selector,
                'RuntimePrimaryStagingApiSessionCoordinator('
            ) && str_contains(
                $selectorConfig,
                RuntimePrimaryStagingEntrypointSelectorConfig::CONTRACT_VERSION
            ) && str_contains(
                $selectorConfig,
                'must allow exactly API'
            ),
            'selector_bootstrap_loads_lifecycle' => str_contains(
                $selectorBootstrap,
                'RuntimePrimaryStagingEvidenceV4Verifier.php'
            ) && str_contains(
                $selectorBootstrap,
                'RuntimePrimaryStagingRequestFinalizer.php'
            ) && str_contains(
                $selectorBootstrap,
                'RuntimePrimaryStagingApiSessionCoordinator.php'
            ),
        ];
        ksort($checks, SORT_STRING);
        foreach ($checks as $name => $passed) {
            if ($passed !== true) {
                $blockers[] = 'Request lifecycle evidence check failed: ' . $name . '.';
            }
        }
        $blockers = array_values(array_unique($blockers));

        return [
            'ready' => $blockers === [],
            'contract_version' => self::CONTRACT_VERSION,
            'baseline' => $baseline,
            'checks' => $checks,
            'sources' => $sources,
            'blockers' => $blockers,
            'session_enabled_by_evidence' => false,
            'finalizer_registered_by_evidence' => false,
            'api_only' => true,
            'webhook_allowed' => false,
            'production_allowed' => false,
            'application_entrypoints_changed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private static function normalizeBaseline(array $baseline): array
    {
        $revision = (int)($baseline['state_revision'] ?? 0);
        if ($revision < 1) {
            throw new RuntimeException('Request lifecycle evidence baseline revision must be positive.');
        }
        $normalized = ['state_revision' => $revision];
        foreach ([
            'state_sha256',
            'json_sha256',
            'inventory_fingerprint',
        ] as $field) {
            $value = strtolower(trim((string)($baseline[$field] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
                throw new RuntimeException('Request lifecycle evidence baseline field is invalid: ' . $field . '.');
            }
            $normalized[$field] = $value;
        }
        return $normalized;
    }
}
