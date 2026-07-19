<?php
declare(strict_types=1);

final class LegacyEconomyDeltaImportService
{
    private const ASSETS = [
        'match_coin' => 'balance_match',
        'gold_coin' => 'balance_gold',
    ];

    public function __construct(
        private DatabaseConnectionInterface $database,
        private LedgerWriteService $ledger,
        private LedgerIntegrityVerifier $integrity
    ) {}

    public function preview(): array
    {
        $plan = $this->buildPlan();
        return $this->publicPlan($plan, true);
    }

    public function run(): array
    {
        $plan = $this->buildPlan();
        if ($plan['blocking_reasons'] !== []) {
            throw new RuntimeException(
                'Legacy economy delta is not ready: ' . implode('; ', $plan['blocking_reasons'])
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
                    $item['legacy_user_id'],
                    $item['asset_code'],
                    (int)$item['database_amount'],
                    (int)$item['source_amount']
                ),
                'account_ref' => $item['account_ref'],
                'mgw_id' => $item['mgw_id'],
                'legacy_user_id' => $item['legacy_user_id'],
                'asset_code' => $item['asset_code'],
                'available_delta' => $delta,
                'category' => 'legacy_cutover_delta',
                'source_type' => 'legacy_json_cutover',
                'source_ref' => 'economy_snapshot:' . $plan['source_fingerprint'],
                'metadata' => [
                    'source_fingerprint' => $plan['source_fingerprint'],
                    'database_amount' => (int)$item['database_amount'],
                    'source_amount' => (int)$item['source_amount'],
                    'reason' => 'frozen_json_final_delta',
                ],
            ]);
            if (!empty($result['replayed'])) $replayed++;
            else $applied++;
            if ($delta > 0) $credited += $delta;
            else $debited += abs($delta);
        }

        $verification = $this->buildPlan();
        if ($verification['blocking_reasons'] !== [] || $verification['planned_delta_count'] !== 0) {
            throw new RuntimeException('Legacy economy delta did not converge to the frozen JSON balances.');
        }
        foreach ($verification['items'] as $item) {
            $integrity = $this->integrity->verifyAccountAsset($item['account_ref'], $item['asset_code']);
            if (empty($integrity['ok'])) {
                throw new RuntimeException('Ledger integrity verification failed after economy delta import.');
            }
        }

        return [
            'ok' => true,
            'action' => 'run',
            'source_fingerprint' => $plan['source_fingerprint'],
            'source_user_count' => $plan['source_user_count'],
            'source_asset_count' => $plan['source_asset_count'],
            'planned_delta_count' => $plan['planned_delta_count'],
            'applied_delta_count' => $applied,
            'replayed_delta_count' => $replayed,
            'credited_total' => $credited,
            'debited_total' => $debited,
            'source_totals' => $verification['source_totals'],
            'database_totals' => $verification['database_totals'],
            'reconciled' => true,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function buildPlan(): array
    {
        $rows = $this->database->fetchAll(
            "SELECT entity_key, payload_json, payload_sha256
             FROM mgw_legacy_realtime_shadow
             WHERE entity_type = 'economy_user_balance'
             ORDER BY entity_key"
        );
        $ownerships = $this->ownershipMap();
        $items = [];
        $blocking = [];
        $fingerprintParts = [];
        $expectedScopes = [];
        $sourceTotals = ['match_coin' => 0, 'gold_coin' => 0];
        $databaseTotals = ['match_coin' => 0, 'gold_coin' => 0];
        $plannedDeltaCount = 0;

        if ($rows === []) $blocking[] = 'No economy balance shadow rows were found.';

        foreach ($rows as $row) {
            $legacyUserId = trim((string)($row['entity_key'] ?? ''));
            if ($legacyUserId === '') {
                $blocking[] = 'Economy balance shadow contains an empty entity key.';
                continue;
            }
            $payloadJson = (string)($row['payload_json'] ?? '');
            try {
                $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $blocking[] = 'Economy balance shadow contains invalid JSON.';
                continue;
            }
            if (!is_array($payload)) {
                $blocking[] = 'Economy balance shadow payload is not an object.';
                continue;
            }
            $canonical = LedgerIntegrity::canonicalJson($payload);
            $actualHash = hash('sha256', $canonical);
            $storedHash = strtolower(trim((string)($row['payload_sha256'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/', $storedHash) !== 1 || !hash_equals($storedHash, $actualHash)) {
                $blocking[] = 'Economy balance shadow hash verification failed.';
                continue;
            }
            if (trim((string)($payload['legacy_user_id'] ?? '')) !== $legacyUserId) {
                $blocking[] = 'Economy balance shadow identity does not match its entity key.';
                continue;
            }

            $ownership = $ownerships[$legacyUserId] ?? null;
            if (!is_array($ownership)) {
                $blocking[] = 'An economy balance has no active account ownership.';
                continue;
            }

            foreach (self::ASSETS as $assetCode => $field) {
                try {
                    $sourceAmount = $this->nonNegativeInteger($payload[$field] ?? null, $field);
                } catch (Throwable $error) {
                    $blocking[] = $error->getMessage();
                    continue;
                }
                $accountRef = $ownership['account_ref'];
                $scope = $accountRef . "\0" . $assetCode;
                $expectedScopes[$scope] = true;
                $balanceRows = $this->database->fetchAll(
                    'SELECT account_ref, mgw_id, legacy_user_id, asset_code, available_amount, reserved_amount '
                    . 'FROM mgw_balances WHERE account_ref = :account_ref AND asset_code = :asset_code',
                    ['account_ref' => $accountRef, 'asset_code' => $assetCode]
                );
                if (count($balanceRows) !== 1) {
                    $blocking[] = 'Expected exactly one database balance for every economy asset.';
                    continue;
                }
                $balance = $balanceRows[0];
                $rowMgwId = $this->nullable($balance['mgw_id'] ?? null);
                $acceptedMgwIds = str_starts_with($accountRef, 'legacy:')
                    ? [null, $ownership['mgw_id']]
                    : [$ownership['mgw_id']];
                if (!in_array($rowMgwId, $acceptedMgwIds, true)
                    || $this->nullable($balance['legacy_user_id'] ?? null) !== $legacyUserId) {
                    $blocking[] = 'Database balance ownership does not match the active account map.';
                    continue;
                }
                $available = (int)($balance['available_amount'] ?? -1);
                $reserved = (int)($balance['reserved_amount'] ?? -1);
                if ($available < 0 || $reserved < 0) {
                    $blocking[] = 'Database balance contains an invalid negative state.';
                    continue;
                }
                if ($reserved !== 0) {
                    $blocking[] = 'Economy delta requires every reserved balance to be zero.';
                    continue;
                }

                $delta = $sourceAmount - $available;
                if ($delta !== 0) $plannedDeltaCount++;
                $sourceTotals[$assetCode] += $sourceAmount;
                $databaseTotals[$assetCode] += $available;
                $fingerprintParts[] = $legacyUserId . "\0" . $assetCode . "\0" . $sourceAmount . "\0" . $storedHash;
                $items[] = [
                    'legacy_user_id' => $legacyUserId,
                    'account_ref' => $accountRef,
                    'mgw_id' => $ownership['mgw_id'],
                    'asset_code' => $assetCode,
                    'source_amount' => $sourceAmount,
                    'database_amount' => $available,
                    'delta' => $delta,
                ];
            }
        }

        foreach ($this->database->fetchAll('SELECT account_ref, asset_code FROM mgw_balances') as $row) {
            $scope = (string)($row['account_ref'] ?? '') . "\0" . (string)($row['asset_code'] ?? '');
            if (!isset($expectedScopes[$scope])) {
                $blocking[] = 'Database contains a balance outside the frozen economy source.';
                break;
            }
        }

        sort($fingerprintParts, SORT_STRING);
        return [
            'source_fingerprint' => hash('sha256', implode("\n", $fingerprintParts)),
            'source_user_count' => count($rows),
            'source_asset_count' => count($items),
            'source_totals' => $sourceTotals,
            'database_totals' => $databaseTotals,
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
            'source_asset_count' => $plan['source_asset_count'],
            'source_totals' => $plan['source_totals'],
            'database_totals' => $plan['database_totals'],
            'planned_delta_count' => $plan['planned_delta_count'],
            'reconciled' => $plan['blocking_reasons'] === [] && $plan['planned_delta_count'] === 0,
            'blocking_reasons' => $plan['blocking_reasons'],
            'sensitive_identifiers_exposed' => false,
        ];
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
                throw new RuntimeException('Legacy economy user has multiple active ownership rows.');
            }
            $map[$legacyUserId] = ['account_ref' => $accountRef, 'mgw_id' => $mgwId];
        }
        return $map;
    }

    private function operationKey(
        string $fingerprint,
        string $legacyUserId,
        string $assetCode,
        int $databaseAmount,
        int $sourceAmount
    ): string {
        return 'legacy_delta:v1:' . substr(
            hash('sha256', implode('|', [
                $fingerprint,
                $legacyUserId,
                $assetCode,
                (string)$databaseAmount,
                (string)$sourceAmount,
            ])),
            0,
            48
        );
    }

    private function nonNegativeInteger(mixed $value, string $field): int
    {
        if (is_int($value)) $number = $value;
        elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) $number = (int)$value;
        elseif (is_float($value) && floor($value) === $value && $value >= 0) $number = (int)$value;
        else throw new RuntimeException('Invalid economy balance field: ' . $field . '.');
        if ($number < 0) throw new RuntimeException('Negative economy balance field: ' . $field . '.');
        return $number;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }
}
