<?php
declare(strict_types=1);

final class RuntimePrimaryStagingApiRequestFinalizationHook
{
    private bool $attempted = false;
    private array $report = [];

    public function __construct(
        private DatabasePrimaryStateStorageAdapter $storage,
        private RuntimePrimaryStagingRequestFinalizer $finalizer,
        private array $resolutionReport
    ) {}

    public function __invoke(): void
    {
        if ($this->attempted) {
            throw new RuntimeException('Staging API request finalizer was invoked more than once.');
        }
        $this->attempted = true;

        if (!RuntimePrimaryEntrypointStorageContext::installed()
            || RuntimePrimaryEntrypointStorageContext::storage() !== $this->storage) {
            throw new RuntimeException('Staging API request finalizer lost its guarded storage context.');
        }
        $context = RuntimePrimaryEntrypointStorageContext::safeReport();
        if (($context['entrypoint'] ?? '') !== 'api'
            || ($context['storage_driver'] ?? '') !== 'database'
            || ($context['request_finalizer_registered'] ?? false) !== true
            || ($context['dynamic_session_readiness'] ?? false) !== true
            || ($context['legacy_json_bridges_suppressed'] ?? false) !== true) {
            throw new RuntimeException('Staging API request finalizer context contract is incomplete.');
        }

        $report = $this->finalizer->finalize(
            $this->storage,
            $this->resolutionReport
        );
        if (($report['ok'] ?? false) !== true
            || ($report['api_only'] ?? false) !== true
            || ($report['projection_event_status'] ?? '') !== 'completed'
            || ($report['read_only_audit'] ?? false) !== true
            || ($report['state_unchanged_during_audit'] ?? false) !== true
            || ($report['legacy_json_bridges_suppressed'] ?? false) !== true
            || ($report['webhook_allowed'] ?? true) !== false
            || ($report['production_changed'] ?? true) !== false) {
            throw new RuntimeException('Staging API request finalizer returned an incomplete success contract.');
        }

        $this->report = $report;
        $GLOBALS['mgw_api_db_primary_finalization_report'] = $this->safeReport();
    }

    public function safeReport(): array
    {
        if ($this->report === []) {
            return [
                'attempted' => $this->attempted,
                'completed' => false,
                'api_only' => true,
                'webhook_allowed' => false,
                'production_changed' => false,
            ];
        }
        return [
            'attempted' => true,
            'completed' => true,
            'action' => (string)($this->report['action'] ?? ''),
            'baseline_state_revision' => (int)($this->report['baseline_state_revision'] ?? 0),
            'final_state_revision' => (int)($this->report['final_state_revision'] ?? 0),
            'final_state_sha256' => strtolower(trim((string)(
                $this->report['final_state_sha256'] ?? ''
            ))),
            'worker_tick_count' => max(0, (int)($this->report['worker_tick_count'] ?? 0)),
            'projection_event_status' => (string)(
                $this->report['projection_event_status'] ?? ''
            ),
            'remaining_session_revisions' => max(
                0,
                (int)($this->report['remaining_session_revisions'] ?? 0)
            ),
            'read_only_audit' => true,
            'legacy_json_bridges_suppressed' => true,
            'api_only' => true,
            'webhook_allowed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }
}
