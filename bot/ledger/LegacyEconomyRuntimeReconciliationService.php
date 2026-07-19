<?php
declare(strict_types=1);

final class LegacyEconomyRuntimeReconciliationService
{
    public function __construct(
        private DatabaseConnectionInterface $database,
        private LegacyEconomyDeltaImportService $delta,
        private LedgerIntegrityVerifier $integrity
    ) {}

    public function preview(): array
    {
        $delta = $this->delta->preview();
        $blocking = array_values(array_map('strval', (array)($delta['blocking_reasons'] ?? [])));
        $pendingDeltaCount = (int)($delta['planned_delta_count'] ?? 0);
        if ($pendingDeltaCount > 0) {
            $blocking[] = 'Frozen JSON economy still has unapplied database balance deltas.';
        }

        $balanceRows = $this->database->fetchAll(
            'SELECT account_ref, asset_code FROM mgw_balances ORDER BY account_ref, asset_code'
        );
        $integrityFailures = 0;
        $ledgerEntryCount = 0;
        foreach ($balanceRows as $row) {
            $result = $this->integrity->verifyAccountAsset(
                (string)($row['account_ref'] ?? ''),
                (string)($row['asset_code'] ?? '')
            );
            $ledgerEntryCount += (int)($result['entry_count'] ?? 0);
            if (empty($result['ok'])) $integrityFailures++;
        }
        if ($integrityFailures > 0) {
            $blocking[] = 'Immutable ledger integrity verification failed for one or more balances.';
        }

        $activeReservations = (int)$this->database->fetchValue(
            "SELECT COUNT(*) FROM mgw_reservations WHERE status = 'active'"
        );
        if ($activeReservations > 0) {
            $blocking[] = 'Active economy reservations remain during frozen reconciliation.';
        }

        $blocking = array_values(array_unique($blocking));
        $ready = $blocking === [];
        return [
            'ok' => $ready,
            'ready' => $ready,
            'status' => $ready ? 'runtime_reconciled' : 'delta_required',
            'source_fingerprint' => (string)($delta['source_fingerprint'] ?? ''),
            'source_user_count' => (int)($delta['source_user_count'] ?? 0),
            'source_asset_count' => (int)($delta['source_asset_count'] ?? 0),
            'source_totals' => $delta['source_totals'] ?? [],
            'database_totals' => $delta['database_totals'] ?? [],
            'unchanged_count' => $ready ? (int)($delta['source_asset_count'] ?? 0) : 0,
            'conflict_count' => $pendingDeltaCount + $integrityFailures,
            'unmanaged_balance_count' => 0,
            'unmanaged_ledger_count' => 0,
            'planned_delta_count' => $pendingDeltaCount,
            'integrity_failure_count' => $integrityFailures,
            'ledger_entry_count' => $ledgerEntryCount,
            'active_reservation_count' => $activeReservations,
            'blocking_reasons' => $blocking,
            'sensitive_identifiers_exposed' => false,
        ];
    }
}
