<?php
declare(strict_types=1);

final class StagingJsonDbFinalReconciliationService
{
    public function __construct(
        private DatabaseConnectionInterface $database,
        private LegacyRealtimeShadowSyncService $realtimeShadow,
        private LegacyEconomyShadowSyncService $economyShadow,
        private LegacyOpeningBalanceImportService $openingBalances,
        private LegacyFinancialArchiveImportService $financialArchive
    ) {}

    public function report(): array
    {
        $report = (new StagingJsonDbReconciliationService(
            $this->database,
            $this->realtimeShadow,
            $this->economyShadow,
            $this->openingBalances,
            $this->financialArchive
        ))->report();

        $integrity = new LedgerIntegrityVerifier($this->database);
        $economy = (new LegacyEconomyRuntimeReconciliationService(
            $this->database,
            new LegacyEconomyDeltaImportService(
                $this->database,
                new LedgerWriteService($this->database),
                $integrity
            ),
            $integrity
        ))->preview();
        $report['opening_balances'] = [
            'status' => (string)($economy['status'] ?? ''),
            'ready' => !empty($economy['ready']),
            'source_user_count' => (int)($economy['source_user_count'] ?? 0),
            'source_asset_count' => (int)($economy['source_asset_count'] ?? 0),
            'source_totals' => $economy['source_totals'] ?? [],
            'database_totals' => $economy['database_totals'] ?? [],
            'unchanged_count' => (int)($economy['unchanged_count'] ?? 0),
            'conflict_count' => (int)($economy['conflict_count'] ?? 0),
            'unmanaged_balance_count' => (int)($economy['unmanaged_balance_count'] ?? 0),
            'unmanaged_ledger_count' => (int)($economy['unmanaged_ledger_count'] ?? 0),
            'planned_delta_count' => (int)($economy['planned_delta_count'] ?? 0),
            'integrity_failure_count' => (int)($economy['integrity_failure_count'] ?? 0),
            'ledger_entry_count' => (int)($economy['ledger_entry_count'] ?? 0),
            'active_reservation_count' => (int)($economy['active_reservation_count'] ?? 0),
        ];

        $blocking = [];
        foreach ((array)($report['blocking_reasons'] ?? []) as $reason) {
            $reason = (string)$reason;
            if (!str_starts_with($reason, 'opening_balances:')) {
                $blocking[] = $reason;
            }
        }
        foreach ((array)($economy['blocking_reasons'] ?? []) as $reason) {
            $blocking[] = 'opening_balances: ' . (string)$reason;
        }
        $report['blocking_reasons'] = array_values(array_unique($blocking));

        $gaps = array_values((array)($report['migration_gaps'] ?? []));
        $report['ok'] = $report['blocking_reasons'] === [];
        $report['ready_for_next_import_step'] = $report['ok'];
        $report['count_parity_complete'] = $report['ok'] && $gaps === [];
        $report['report_fingerprint'] = hash('sha256', LedgerIntegrity::canonicalJson([
            'source_fingerprints' => $report['source_fingerprints'] ?? [],
            'shadow_checks' => $report['shadow_checks'] ?? [],
            'opening_balances' => $report['opening_balances'],
            'financial_archive' => $report['financial_archive'] ?? [],
            'account_mapping' => $report['account_mapping'] ?? [],
            'normalized_targets' => $report['normalized_targets'] ?? [],
            'blocking_reasons' => $report['blocking_reasons'],
            'migration_gaps' => $gaps,
        ]));

        return $report;
    }
}
