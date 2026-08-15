<?php
declare(strict_types=1);

/**
 * Read-only preflight for the MVP-15.3 two-balances -> one migration.
 *
 * It never writes balances, reservations or ledger rows. The planner exists to
 * prove that an explicitly approved mapping can be applied without rounding,
 * hidden reservations or an already-started target migration.
 */
final class UnifiedBalanceMigrationPlanner
{
    public function __construct(
        private DatabaseConnectionInterface $database,
        private UnifiedBalanceMigrationRule $rule
    ) {}

    public function preview(): array
    {
        $sourceAssets = [
            UnifiedBalanceMigrationRule::MATCH_ASSET,
            UnifiedBalanceMigrationRule::GOLD_ASSET,
        ];
        $targetAsset = $this->rule->targetAsset();

        $balanceRows = $this->database->fetchAll(
            "SELECT account_ref, asset_code, available_amount, reserved_amount
             FROM mgw_balances
             WHERE asset_code IN ('match_coin', 'gold_coin', 'mgw_coin')
             ORDER BY account_ref, asset_code"
        );

        $sourceTotals = [];
        foreach ($sourceAssets as $asset) {
            $sourceTotals[$asset] = [
                'balance_row_count' => 0,
                'available_amount' => 0,
                'reserved_amount' => 0,
                'converted_available_amount' => 0,
                'converted_reserved_amount' => 0,
            ];
        }

        $accounts = [];
        $sourceBalanceRowCount = 0;
        $targetExistingRowCount = 0;
        $targetExistingAvailable = 0;
        $targetExistingReserved = 0;
        $conversionFailureCount = 0;
        $blockers = [];

        foreach ($balanceRows as $row) {
            if (!is_array($row)) continue;
            $accountRef = trim((string)($row['account_ref'] ?? ''));
            $asset = trim((string)($row['asset_code'] ?? ''));
            $available = (int)($row['available_amount'] ?? 0);
            $reserved = (int)($row['reserved_amount'] ?? 0);

            if ($accountRef === '' || $available < 0 || $reserved < 0) {
                $blockers[] = 'Balance rows contain invalid account or negative amount data.';
                continue;
            }

            if ($asset === $targetAsset) {
                $targetExistingRowCount++;
                $targetExistingAvailable = $this->safeAdd(
                    $targetExistingAvailable,
                    $available,
                    'existing target available total'
                );
                $targetExistingReserved = $this->safeAdd(
                    $targetExistingReserved,
                    $reserved,
                    'existing target reserved total'
                );
                continue;
            }

            if (!in_array($asset, $sourceAssets, true)) continue;

            $sourceBalanceRowCount++;
            $accounts[$accountRef] = true;
            $sourceTotals[$asset]['balance_row_count']++;
            $sourceTotals[$asset]['available_amount'] = $this->safeAdd(
                (int)$sourceTotals[$asset]['available_amount'],
                $available,
                $asset . ' available total'
            );
            $sourceTotals[$asset]['reserved_amount'] = $this->safeAdd(
                (int)$sourceTotals[$asset]['reserved_amount'],
                $reserved,
                $asset . ' reserved total'
            );

            try {
                $convertedAvailable = $this->rule->convert($asset, $available);
                $convertedReserved = $this->rule->convert($asset, $reserved);
                $sourceTotals[$asset]['converted_available_amount'] = $this->safeAdd(
                    (int)$sourceTotals[$asset]['converted_available_amount'],
                    $convertedAvailable,
                    $asset . ' converted available total'
                );
                $sourceTotals[$asset]['converted_reserved_amount'] = $this->safeAdd(
                    (int)$sourceTotals[$asset]['converted_reserved_amount'],
                    $convertedReserved,
                    $asset . ' converted reserved total'
                );
            } catch (RuntimeException $error) {
                $conversionFailureCount++;
            }
        }

        if ($conversionFailureCount > 0) {
            $blockers[] = 'Approved mapping cannot convert every source amount exactly; rounding would be required.';
        }

        $activeReservations = $this->activeReservations($sourceAssets);
        if ($activeReservations['count'] > 0) {
            $blockers[] = 'Active Match/Gold reservations must be resolved before unified balance migration.';
        }

        $sourceReservedTotal = 0;
        foreach ($sourceTotals as $totals) {
            $sourceReservedTotal = $this->safeAdd(
                $sourceReservedTotal,
                (int)$totals['reserved_amount'],
                'source reserved total'
            );
        }
        if ($sourceReservedTotal > 0) {
            $blockers[] = 'Source balances still contain reserved amounts; reservation ownership is not ready for cutover.';
        }

        if ($targetExistingRowCount > 0) {
            $blockers[] = 'Target mgw_coin balance rows already exist; partial or competing migration must be reconciled first.';
        }

        $plannedTargetAvailable = 0;
        $plannedTargetReserved = 0;
        foreach ($sourceTotals as $totals) {
            $plannedTargetAvailable = $this->safeAdd(
                $plannedTargetAvailable,
                (int)$totals['converted_available_amount'],
                'planned target available total'
            );
            $plannedTargetReserved = $this->safeAdd(
                $plannedTargetReserved,
                (int)$totals['converted_reserved_amount'],
                'planned target reserved total'
            );
        }

        $ledgerCounts = $this->ledgerCounts();
        $blockers = array_values(array_unique($blockers));

        $result = [
            'ready' => $blockers === [],
            'read_only' => true,
            'rule' => $this->rule->auditDescriptor(),
            'rule_fingerprint' => $this->rule->fingerprint(),
            'source_assets' => $sourceAssets,
            'target_asset' => $targetAsset,
            'source_account_count' => count($accounts),
            'source_balance_row_count' => $sourceBalanceRowCount,
            'source_totals' => $sourceTotals,
            'planned_target' => [
                'available_amount' => $plannedTargetAvailable,
                'reserved_amount' => $plannedTargetReserved,
            ],
            'active_source_reservations' => $activeReservations,
            'target_existing' => [
                'balance_row_count' => $targetExistingRowCount,
                'available_amount' => $targetExistingAvailable,
                'reserved_amount' => $targetExistingReserved,
            ],
            'ledger_entry_counts' => $ledgerCounts,
            'conversion_failure_count' => $conversionFailureCount,
            'blockers' => $blockers,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
        $result['plan_fingerprint'] = hash(
            'sha256',
            json_encode($this->canonicalize($result), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
        return $result;
    }

    private function activeReservations(array $sourceAssets): array
    {
        $rows = $this->database->fetchAll(
            "SELECT asset_code, COUNT(*) AS reservation_count, COALESCE(SUM(amount), 0) AS reserved_amount
             FROM mgw_reservations
             WHERE status = 'active' AND asset_code IN ('match_coin', 'gold_coin')
             GROUP BY asset_code
             ORDER BY asset_code"
        );

        $count = 0;
        $amount = 0;
        $byAsset = [];
        foreach ($sourceAssets as $asset) {
            $byAsset[$asset] = ['count' => 0, 'amount' => 0];
        }
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $asset = trim((string)($row['asset_code'] ?? ''));
            if (!isset($byAsset[$asset])) continue;
            $assetCount = max(0, (int)($row['reservation_count'] ?? 0));
            $assetAmount = max(0, (int)($row['reserved_amount'] ?? 0));
            $byAsset[$asset] = ['count' => $assetCount, 'amount' => $assetAmount];
            $count = $this->safeAdd($count, $assetCount, 'active reservation count');
            $amount = $this->safeAdd($amount, $assetAmount, 'active reservation amount');
        }

        return [
            'count' => $count,
            'amount' => $amount,
            'by_asset' => $byAsset,
        ];
    }

    private function ledgerCounts(): array
    {
        $rows = $this->database->fetchAll(
            "SELECT asset_code, COUNT(*) AS entry_count
             FROM mgw_ledger_entries
             WHERE asset_code IN ('match_coin', 'gold_coin', 'mgw_coin')
             GROUP BY asset_code
             ORDER BY asset_code"
        );

        $result = [
            UnifiedBalanceMigrationRule::MATCH_ASSET => 0,
            UnifiedBalanceMigrationRule::GOLD_ASSET => 0,
            UnifiedBalanceMigrationRule::TARGET_ASSET => 0,
        ];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $asset = trim((string)($row['asset_code'] ?? ''));
            if (array_key_exists($asset, $result)) {
                $result[$asset] = max(0, (int)($row['entry_count'] ?? 0));
            }
        }
        return $result;
    }

    private function safeAdd(int $left, int $right, string $label): int
    {
        if ($right > 0 && $left > PHP_INT_MAX - $right) {
            throw new RuntimeException('Unified balance planner overflow: ' . $label . '.');
        }
        if ($right < 0 && $left < PHP_INT_MIN - $right) {
            throw new RuntimeException('Unified balance planner underflow: ' . $label . '.');
        }
        return $left + $right;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
