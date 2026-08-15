<?php
declare(strict_types=1);

/**
 * Post-cutover runtime synchronizer for the single MGW balance.
 * Legacy Match/Gold DB rows remain immutable history; only mgw_coin follows the
 * current users[*].balance value after MVP-15.3.
 */
final class UnifiedEconomyRuntimeSyncService
{
    public function __construct(
        private DatabaseConnectionInterface $database,
        private LedgerWriteService $ledger,
        private LedgerIntegrityVerifier $integrity
    ) {}

    public function preview(array $snapshot): array
    {
        return $this->publicPlan($this->buildPlan($snapshot), true);
    }

    public function run(array $snapshot): array
    {
        $plan = $this->buildPlan($snapshot);
        if ($plan['blocking_reasons'] !== []) {
            throw new RuntimeException(
                'Unified economy runtime sync is not ready: ' . implode('; ', $plan['blocking_reasons'])
            );
        }

        $applied = 0;
        $replayed = 0;
        $credited = 0;
        $debited = 0;
        foreach ($plan['items'] as $item) {
            $delta = (int)$item['delta'];
            if ($delta === 0) continue;

            $result = $this->ledger->postAvailableDelta([
                'operation_key' => $this->operationKey(
                    $plan['source_fingerprint'],
                    (string)$item['legacy_user_id'],
                    (int)$item['database_version'],
                    (int)$item['database_amount'],
                    (int)$item['source_amount']
                ),
                'account_ref' => $item['account_ref'],
                'mgw_id' => $item['mgw_id'],
                'legacy_user_id' => $item['legacy_user_id'],
                'asset_code' => UnifiedBalanceMigrationRule::TARGET_ASSET,
                'available_delta' => $delta,
                'category' => 'unified_runtime_sync',
                'source_type' => 'runtime_primary_state',
                'source_ref' => 'balance_snapshot:' . $plan['source_fingerprint'],
                'metadata' => [
                    'source_fingerprint' => $plan['source_fingerprint'],
                    'database_version' => (int)$item['database_version'],
                    'database_amount' => (int)$item['database_amount'],
                    'source_amount' => (int)$item['source_amount'],
                    'target_asset' => UnifiedBalanceMigrationRule::TARGET_ASSET,
                ],
            ]);
            if (!empty($result['replayed'])) $replayed++;
            else $applied++;
            if ($delta > 0) $credited += $delta;
            else $debited += abs($delta);
        }

        $verification = $this->buildPlan($snapshot);
        if ($verification['blocking_reasons'] !== [] || $verification['planned_delta_count'] !== 0) {
            throw new RuntimeException('Unified economy runtime sync did not converge to the canonical balance.');
        }
        foreach ($verification['items'] as $item) {
            if ((int)$item['source_amount'] === 0 && (int)$item['database_amount'] === 0) continue;
            $integrity = $this->integrity->verifyAccountAsset(
                (string)$item['account_ref'],
                UnifiedBalanceMigrationRule::TARGET_ASSET
            );
            if (($integrity['ok'] ?? false) !== true) {
                throw new RuntimeException('Unified balance ledger integrity verification failed after runtime sync.');
            }
        }

        return [
            'ok' => true,
            'action' => 'run',
            'source_fingerprint' => $verification['source_fingerprint'],
            'source_user_count' => $verification['source_user_count'],
            'source_total' => $verification['source_total'],
            'database_total' => $verification['database_total'],
            'planned_delta_count' => $plan['planned_delta_count'],
            'applied_delta_count' => $applied,
            'replayed_delta_count' => $replayed,
            'credited_total' => $credited,
            'debited_total' => $debited,
            'reconciled' => true,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function buildPlan(array $snapshot): array
    {
        $ownerships = $this->ownershipMap();
        $users = is_array($snapshot['users'] ?? null) ? $snapshot['users'] : [];
        $items = [];
        $blocking = [];
        $expectedAccounts = [];
        $fingerprintParts = [];
        $sourceTotal = 0;
        $databaseTotal = 0;
        $plannedDeltaCount = 0;
        $sourceUsers = 0;

        foreach ($users as $key => $user) {
            if (!is_array($user) || !empty($user['is_dev_user'])) continue;
            $legacyUserId = trim((string)($user['id'] ?? $key));
            if ($legacyUserId === '') {
                $blocking[] = 'Unified runtime balance contains an empty legacy user id.';
                continue;
            }
            $ownership = $ownerships[$legacyUserId] ?? null;
            if (!is_array($ownership)) {
                $blocking[] = 'A unified runtime balance has no active account ownership.';
                continue;
            }

            try {
                $sourceAmount = $this->canonicalAmount($user);
            } catch (Throwable $error) {
                $blocking[] = $error->getMessage();
                continue;
            }

            $sourceUsers++;
            $accountRef = (string)$ownership['account_ref'];
            $expectedAccounts[$accountRef] = true;
            $rows = $this->database->fetchAll(
                'SELECT account_ref, mgw_id, legacy_user_id, available_amount, reserved_amount, version
                 FROM mgw_balances
                 WHERE account_ref = :account_ref AND asset_code = :asset_code',
                ['account_ref' => $accountRef, 'asset_code' => UnifiedBalanceMigrationRule::TARGET_ASSET]
            );
            if (count($rows) > 1) {
                $blocking[] = 'Unified target balance is ambiguous for an account.';
                continue;
            }

            $databaseAmount = 0;
            $databaseVersion = 0;
            if ($rows !== []) {
                $row = $rows[0];
                $acceptedMgwIds = str_starts_with($accountRef, 'legacy:')
                    ? [null, $ownership['mgw_id']]
                    : [$ownership['mgw_id']];
                if (!in_array($this->nullable($row['mgw_id'] ?? null), $acceptedMgwIds, true)
                    || $this->nullable($row['legacy_user_id'] ?? null) !== $legacyUserId) {
                    $blocking[] = 'Unified target balance ownership does not match the active account map.';
                    continue;
                }
                $databaseAmount = (int)($row['available_amount'] ?? -1);
                $reserved = (int)($row['reserved_amount'] ?? -1);
                $databaseVersion = (int)($row['version'] ?? -1);
                if ($databaseAmount < 0 || $reserved < 0 || $databaseVersion < 0) {
                    $blocking[] = 'Unified target balance contains an invalid state.';
                    continue;
                }
                if ($reserved !== 0) {
                    $blocking[] = 'Unified runtime sync requires zero reserved balance before MVP-15.5.';
                    continue;
                }
            }

            $delta = $sourceAmount - $databaseAmount;
            if ($delta !== 0) $plannedDeltaCount++;
            $sourceTotal += $sourceAmount;
            $databaseTotal += $databaseAmount;
            $fingerprintParts[] = $legacyUserId . "\0" . $sourceAmount;
            $items[] = [
                'legacy_user_id' => $legacyUserId,
                'account_ref' => $accountRef,
                'mgw_id' => $ownership['mgw_id'],
                'source_amount' => $sourceAmount,
                'database_amount' => $databaseAmount,
                'database_version' => $databaseVersion,
                'delta' => $delta,
            ];
        }

        foreach ($this->database->fetchAll(
            'SELECT account_ref FROM mgw_balances WHERE asset_code = :asset_code',
            ['asset_code' => UnifiedBalanceMigrationRule::TARGET_ASSET]
        ) as $row) {
            $accountRef = trim((string)($row['account_ref'] ?? ''));
            if ($accountRef !== '' && !isset($expectedAccounts[$accountRef])) {
                $blocking[] = 'Database contains an unmanaged unified balance.';
                break;
            }
        }

        sort($fingerprintParts, SORT_STRING);
        return [
            'source_fingerprint' => hash('sha256', implode("\n", $fingerprintParts)),
            'source_user_count' => $sourceUsers,
            'source_total' => $sourceTotal,
            'database_total' => $databaseTotal,
            'planned_delta_count' => $plannedDeltaCount,
            'blocking_reasons' => array_values(array_unique($blocking)),
            'items' => $items,
        ];
    }

    private function publicPlan(array $plan, bool $readOnly): array
    {
        return [
            'ok' => $plan['blocking_reasons'] === [],
            'ready' => $plan['blocking_reasons'] === [],
            'read_only' => $readOnly,
            'source_fingerprint' => $plan['source_fingerprint'],
            'source_user_count' => $plan['source_user_count'],
            'source_total' => $plan['source_total'],
            'database_total' => $plan['database_total'],
            'planned_delta_count' => $plan['planned_delta_count'],
            'reconciled' => $plan['blocking_reasons'] === [] && $plan['planned_delta_count'] === 0,
            'blocking_reasons' => $plan['blocking_reasons'],
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function canonicalAmount(array $user): int
    {
        if (array_key_exists(UnifiedBalanceRuntimeState::FIELD, $user)) {
            return $this->nonNegativeInteger($user[UnifiedBalanceRuntimeState::FIELD], UnifiedBalanceRuntimeState::FIELD);
        }
        $match = $this->nonNegativeInteger($user['balance_match'] ?? 0, 'balance_match');
        $gold = $this->nonNegativeInteger($user['balance_gold'] ?? 0, 'balance_gold');
        if ($match > PHP_INT_MAX - $gold) {
            throw new RuntimeException('Unified runtime fallback balance would overflow integer range.');
        }
        return $match + $gold;
    }

    private function ownershipMap(): array
    {
        $map = [];
        foreach ($this->database->fetchAll(
            'SELECT account_ref, mgw_id, legacy_user_id, ownership_status FROM mgw_account_ownership'
        ) as $row) {
            $legacyUserId = trim((string)($row['legacy_user_id'] ?? ''));
            $accountRef = trim((string)($row['account_ref'] ?? ''));
            $mgwId = trim((string)($row['mgw_id'] ?? ''));
            $status = trim((string)($row['ownership_status'] ?? ''));
            if ($legacyUserId === '' || $accountRef === '' || $mgwId === '' || $status !== 'active') continue;
            if (isset($map[$legacyUserId])) {
                throw new RuntimeException('Unified economy user has multiple active ownership rows.');
            }
            $map[$legacyUserId] = ['account_ref' => $accountRef, 'mgw_id' => $mgwId];
        }
        return $map;
    }

    private function operationKey(
        string $fingerprint,
        string $legacyUserId,
        int $databaseVersion,
        int $databaseAmount,
        int $sourceAmount
    ): string {
        return 'unified_runtime:v1:' . substr(hash('sha256', implode('|', [
            $fingerprint,
            $legacyUserId,
            (string)$databaseVersion,
            (string)$databaseAmount,
            (string)$sourceAmount,
        ])), 0, 48);
    }

    private function nonNegativeInteger(mixed $value, string $field): int
    {
        if (is_int($value)) $number = $value;
        elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) $number = (int)$value;
        elseif (is_float($value) && floor($value) === $value && $value >= 0) $number = (int)$value;
        else throw new RuntimeException('Invalid unified balance field: ' . $field . '.');
        if ($number < 0) throw new RuntimeException('Negative unified balance field: ' . $field . '.');
        return $number;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }
}
