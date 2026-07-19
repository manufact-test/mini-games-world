<?php
declare(strict_types=1);

final class StagingJsonDbFinalDeltaService
{
    public function __construct(
        private LegacyRealtimeShadowSyncService $realtimeShadow,
        private LegacyEconomyShadowSyncService $economyShadow,
        private LegacyFinancialArchiveDeltaService $financialArchive,
        private LegacyEconomyDeltaImportService $economyDelta,
        private StagingJsonDbFinalReconciliationService $reconciliation
    ) {}

    public function preview(): array
    {
        $realtime = $this->realtimeShadow->preview();
        $economyShadow = $this->economyShadow->preview();
        $archive = $this->financialArchive->preview();
        $economyDelta = $this->economyDelta->preview();
        $reconciliation = $this->reconciliation->report();
        $preconditionBlockers = [];
        foreach ((array)($archive['blocking_reasons'] ?? []) as $reason) {
            $preconditionBlockers[] = 'financial_archive: ' . (string)$reason;
        }
        foreach ((array)($economyDelta['blocking_reasons'] ?? []) as $reason) {
            $preconditionBlockers[] = 'economy_delta: ' . (string)$reason;
        }
        $ready = !empty($archive['ready'])
            && !empty($economyDelta['ready'])
            && $preconditionBlockers === [];

        return [
            'ok' => $ready,
            'ready' => $ready,
            'read_only' => true,
            'action' => 'preview',
            'realtime_shadow_delta_count' => $this->shadowDeltaCount($realtime),
            'economy_shadow_delta_count' => $this->shadowDeltaCount($economyShadow),
            'financial_archive_ready' => !empty($archive['ready']),
            'financial_archive_planned_count' => (int)($archive['planned_create_total'] ?? 0),
            'economy_delta_ready' => !empty($economyDelta['ready']),
            'economy_planned_delta_count' => (int)($economyDelta['planned_delta_count'] ?? 0),
            'precondition_blocking_reasons' => array_values(array_unique($preconditionBlockers)),
            'reconciliation_ok' => !empty($reconciliation['ok']),
            'count_parity_complete' => !empty($reconciliation['count_parity_complete']),
            'blocking_reasons' => $reconciliation['blocking_reasons'] ?? [],
            'migration_gaps' => $reconciliation['migration_gaps'] ?? [],
            'report_fingerprint' => hash('sha256', LedgerIntegrity::canonicalJson([
                'realtime' => $realtime['source_fingerprint'] ?? '',
                'economy' => $economyShadow['source_fingerprint'] ?? '',
                'archive' => $archive['source_fingerprint'] ?? '',
                'economy_delta' => $economyDelta['source_fingerprint'] ?? '',
                'preconditions' => $preconditionBlockers,
                'reconciliation' => $reconciliation['report_fingerprint'] ?? '',
            ])),
            'sensitive_identifiers_exposed' => false,
        ];
    }

    public function run(): array
    {
        $realtime = $this->realtimeShadow->run();
        $economyShadow = $this->economyShadow->run();
        $archive = $this->financialArchive->run();
        $economyDelta = $this->economyDelta->run();
        $reconciliation = $this->reconciliation->report();

        if (empty($reconciliation['ok']) || empty($reconciliation['count_parity_complete'])) {
            throw new RuntimeException(
                'Final JSON to database reconciliation remains blocked: '
                . implode('; ', array_merge(
                    (array)($reconciliation['blocking_reasons'] ?? []),
                    (array)($reconciliation['migration_gaps'] ?? [])
                ))
            );
        }

        return [
            'ok' => true,
            'read_only' => false,
            'action' => 'run',
            'realtime_shadow' => $this->compactShadow($realtime),
            'economy_shadow' => $this->compactShadow($economyShadow),
            'financial_archive' => [
                'metadata_advanced' => !empty($archive['metadata_advanced']),
                'created_counts' => $archive['created_counts'] ?? [],
                'unchanged_counts' => $archive['unchanged_counts'] ?? [],
            ],
            'economy_delta' => [
                'source_user_count' => (int)($economyDelta['source_user_count'] ?? 0),
                'source_asset_count' => (int)($economyDelta['source_asset_count'] ?? 0),
                'planned_delta_count' => (int)($economyDelta['planned_delta_count'] ?? 0),
                'applied_delta_count' => (int)($economyDelta['applied_delta_count'] ?? 0),
                'replayed_delta_count' => (int)($economyDelta['replayed_delta_count'] ?? 0),
                'credited_total' => (int)($economyDelta['credited_total'] ?? 0),
                'debited_total' => (int)($economyDelta['debited_total'] ?? 0),
                'source_totals' => $economyDelta['source_totals'] ?? [],
                'database_totals' => $economyDelta['database_totals'] ?? [],
                'reconciled' => !empty($economyDelta['reconciled']),
            ],
            'reconciliation' => [
                'ok' => true,
                'count_parity_complete' => true,
                'blocking_reasons' => [],
                'migration_gaps' => [],
                'report_fingerprint' => (string)($reconciliation['report_fingerprint'] ?? ''),
            ],
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function shadowDeltaCount(array $report): int
    {
        $total = 0;
        foreach ((array)($report['sections'] ?? []) as $section) {
            if (!is_array($section)) continue;
            foreach (['inserted_count', 'updated_count', 'deleted_count'] as $field) {
                $total += max(0, (int)($section[$field] ?? 0));
            }
        }
        return $total;
    }

    private function compactShadow(array $report): array
    {
        return [
            'source_fingerprint' => (string)($report['source_fingerprint'] ?? ''),
            'sections' => $report['sections'] ?? [],
        ];
    }
}
