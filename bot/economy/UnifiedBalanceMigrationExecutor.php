<?php
declare(strict_types=1);

/**
 * Atomic, idempotent MVP-15.3 ledger migration.
 *
 * Source Match/Gold balances and their ledger history are never rewritten or
 * deleted here. New mgw_coin value is represented by compensating append-only
 * ledger credits, one per non-zero legacy source asset, with the approved rule
 * fingerprint and source breakdown in metadata.
 */
final class UnifiedBalanceMigrationExecutor
{
    public function __construct(
        private DatabaseConnectionInterface $database,
        private UnifiedBalanceMigrationRule $rule,
        private LedgerWriteService $ledger,
        private LedgerIntegrityVerifier $integrity
    ) {}

    public function run(): array
    {
        return $this->database->transaction(function (): array {
            $expected = $this->expectedAccounts(true);
            $existingTargetRows = $this->targetRows();

            if ($existingTargetRows !== []) {
                $this->assertCompletedReplay($expected, $existingTargetRows);
                return $this->result($expected, 0, $this->countExpectedEntries($expected), true);
            }

            $preview = (new UnifiedBalanceMigrationPlanner($this->database, $this->rule))->preview();
            if (($preview['ready'] ?? false) !== true) {
                throw new RuntimeException(
                    'Unified balance migration is not ready: '
                    . implode('; ', array_map('strval', (array)($preview['blockers'] ?? [])))
                );
            }

            $applied = 0;
            foreach ($expected as $accountRef => $account) {
                foreach ([UnifiedBalanceMigrationRule::MATCH_ASSET, UnifiedBalanceMigrationRule::GOLD_ASSET] as $sourceAsset) {
                    $sourceAmount = (int)($account['sources'][$sourceAsset] ?? 0);
                    if ($sourceAmount === 0) continue;
                    $converted = $this->rule->convert($sourceAsset, $sourceAmount);
                    if ($converted === 0) continue;

                    $result = $this->ledger->postAvailableDelta([
                        'operation_key' => $this->operationKey($accountRef, $sourceAsset, $sourceAmount),
                        'account_ref' => $accountRef,
                        'mgw_id' => $account['mgw_id'],
                        'legacy_user_id' => $account['legacy_user_id'],
                        'asset_code' => $this->rule->targetAsset(),
                        'available_delta' => $converted,
                        'category' => 'unified_balance_migration',
                        'source_type' => 'mvp15_3_unified_balance',
                        'source_ref' => $this->rule->version(),
                        'metadata' => [
                            'migration_version' => $this->rule->version(),
                            'rule_fingerprint' => $this->rule->fingerprint(),
                            'source_asset' => $sourceAsset,
                            'source_amount' => $sourceAmount,
                            'converted_amount' => $converted,
                            'target_asset' => $this->rule->targetAsset(),
                            'legacy_breakdown' => [
                                UnifiedBalanceMigrationRule::MATCH_ASSET => (int)$account['sources'][UnifiedBalanceMigrationRule::MATCH_ASSET],
                                UnifiedBalanceMigrationRule::GOLD_ASSET => (int)$account['sources'][UnifiedBalanceMigrationRule::GOLD_ASSET],
                            ],
                        ],
                    ]);
                    if (!empty($result['replayed'])) {
                        throw new RuntimeException('Unexpected partial unified balance replay inside a fresh atomic migration.');
                    }
                    $applied++;
                }
            }

            $targetRows = $this->targetRows();
            $this->assertCompletedReplay($expected, $targetRows);

            return $this->result($expected, $applied, 0, false);
        });
    }

    /** @return array<string,array<string,mixed>> */
    private function expectedAccounts(bool $lock): array
    {
        $sql = "SELECT account_ref, mgw_id, legacy_user_id, asset_code, available_amount, reserved_amount
                FROM mgw_balances
                WHERE asset_code IN ('match_coin', 'gold_coin')
                ORDER BY account_ref, asset_code";
        if ($lock && $this->database->driver() === 'mysql') $sql .= ' FOR UPDATE';
        $rows = $this->database->fetchAll($sql);

        $activeReservations = (int)$this->database->fetchValue(
            "SELECT COUNT(*) FROM mgw_reservations
             WHERE status = 'active' AND asset_code IN ('match_coin', 'gold_coin')"
        );
        if ($activeReservations !== 0) {
            throw new RuntimeException('Active Match/Gold reservations block unified balance migration.');
        }

        $accounts = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $accountRef = trim((string)($row['account_ref'] ?? ''));
            $asset = trim((string)($row['asset_code'] ?? ''));
            $available = (int)($row['available_amount'] ?? -1);
            $reserved = (int)($row['reserved_amount'] ?? -1);
            if ($accountRef === '' || $available < 0 || $reserved !== 0) {
                throw new RuntimeException('Legacy source balance is invalid or still reserved.');
            }
            if (!in_array($asset, [UnifiedBalanceMigrationRule::MATCH_ASSET, UnifiedBalanceMigrationRule::GOLD_ASSET], true)) {
                continue;
            }

            $mgwId = $this->nullable($row['mgw_id'] ?? null);
            $legacyUserId = $this->nullable($row['legacy_user_id'] ?? null);
            if (!isset($accounts[$accountRef])) {
                $accounts[$accountRef] = [
                    'mgw_id' => $mgwId,
                    'legacy_user_id' => $legacyUserId,
                    'sources' => [
                        UnifiedBalanceMigrationRule::MATCH_ASSET => 0,
                        UnifiedBalanceMigrationRule::GOLD_ASSET => 0,
                    ],
                ];
            } elseif ($accounts[$accountRef]['mgw_id'] !== $mgwId
                || $accounts[$accountRef]['legacy_user_id'] !== $legacyUserId) {
                throw new RuntimeException('Legacy balance identity differs between source assets.');
            }

            $accounts[$accountRef]['sources'][$asset] = $available;
        }

        return $accounts;
    }

    private function targetRows(): array
    {
        return $this->database->fetchAll(
            "SELECT account_ref, mgw_id, legacy_user_id, available_amount, reserved_amount
             FROM mgw_balances WHERE asset_code = 'mgw_coin' ORDER BY account_ref"
        );
    }

    private function assertCompletedReplay(array $expected, array $targetRows): void
    {
        $targets = [];
        foreach ($targetRows as $row) {
            if (!is_array($row)) continue;
            $accountRef = trim((string)($row['account_ref'] ?? ''));
            if ($accountRef === '' || isset($targets[$accountRef])) {
                throw new RuntimeException('Unified target balance rows are ambiguous.');
            }
            $targets[$accountRef] = $row;
        }

        foreach ($expected as $accountRef => $account) {
            $expectedAmount = 0;
            foreach ($account['sources'] as $sourceAsset => $sourceAmount) {
                $converted = $this->rule->convert((string)$sourceAsset, (int)$sourceAmount);
                if ($converted > PHP_INT_MAX - $expectedAmount) {
                    throw new RuntimeException('Unified target balance would overflow integer range.');
                }
                $expectedAmount += $converted;
            }

            if ($expectedAmount === 0 && !isset($targets[$accountRef])) continue;
            if (!isset($targets[$accountRef])) {
                throw new RuntimeException('Unified balance migration is incomplete for a legacy account.');
            }
            $target = $targets[$accountRef];
            if ((int)($target['available_amount'] ?? -1) !== $expectedAmount
                || (int)($target['reserved_amount'] ?? -1) !== 0
                || $this->nullable($target['mgw_id'] ?? null) !== $account['mgw_id']
                || $this->nullable($target['legacy_user_id'] ?? null) !== $account['legacy_user_id']) {
                throw new RuntimeException('Existing unified target balance does not match the approved migration.');
            }

            $integrity = $this->integrity->verifyAccountAsset($accountRef, $this->rule->targetAsset());
            if (($integrity['ok'] ?? false) !== true) {
                throw new RuntimeException('Unified target ledger integrity verification failed.');
            }

            foreach ($account['sources'] as $sourceAsset => $sourceAmount) {
                $sourceAmount = (int)$sourceAmount;
                if ($sourceAmount === 0) continue;
                $operationKey = $this->operationKey($accountRef, (string)$sourceAsset, $sourceAmount);
                $count = (int)$this->database->fetchValue(
                    'SELECT COUNT(*) FROM mgw_ledger_entries
                     WHERE idempotency_key = :idempotency_key
                       AND account_ref = :account_ref
                       AND asset_code = :asset_code
                       AND category = :category',
                    [
                        'idempotency_key' => $operationKey,
                        'account_ref' => $accountRef,
                        'asset_code' => $this->rule->targetAsset(),
                        'category' => 'unified_balance_migration',
                    ]
                );
                if ($count !== 1) {
                    throw new RuntimeException('Unified balance migration ledger evidence is incomplete.');
                }
            }
            unset($targets[$accountRef]);
        }

        if ($targets !== []) {
            throw new RuntimeException('Unexpected mgw_coin balances exist outside the approved legacy source set.');
        }
    }

    private function operationKey(string $accountRef, string $sourceAsset, int $sourceAmount): string
    {
        return 'unified_balance:v1:' . substr(hash('sha256', implode('|', [
            $this->rule->fingerprint(),
            $accountRef,
            $sourceAsset,
            (string)$sourceAmount,
        ])), 0, 48);
    }

    private function countExpectedEntries(array $expected): int
    {
        $count = 0;
        foreach ($expected as $account) {
            foreach ($account['sources'] as $amount) {
                if ((int)$amount !== 0) $count++;
            }
        }
        return $count;
    }

    private function result(array $expected, int $applied, int $replayed, bool $isReplay): array
    {
        $sourceMatch = 0;
        $sourceGold = 0;
        $target = 0;
        foreach ($expected as $account) {
            $match = (int)$account['sources'][UnifiedBalanceMigrationRule::MATCH_ASSET];
            $gold = (int)$account['sources'][UnifiedBalanceMigrationRule::GOLD_ASSET];
            $sourceMatch += $match;
            $sourceGold += $gold;
            $target += $this->rule->convert(UnifiedBalanceMigrationRule::MATCH_ASSET, $match)
                + $this->rule->convert(UnifiedBalanceMigrationRule::GOLD_ASSET, $gold);
        }

        return [
            'ok' => true,
            'migration_version' => $this->rule->version(),
            'rule_fingerprint' => $this->rule->fingerprint(),
            'source_account_count' => count($expected),
            'source_totals' => [
                UnifiedBalanceMigrationRule::MATCH_ASSET => $sourceMatch,
                UnifiedBalanceMigrationRule::GOLD_ASSET => $sourceGold,
            ],
            'target_total' => $target,
            'applied_ledger_entry_count' => $applied,
            'replayed_ledger_entry_count' => $replayed,
            'replayed' => $isReplay,
            'source_balances_preserved' => true,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }
}
