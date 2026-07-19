<?php
declare(strict_types=1);

final class RuntimeEconomyBalanceBootstrapService
{
    private const ASSETS = [
        'match_coin' => 'balance_match',
        'gold_coin' => 'balance_gold',
    ];

    public function __construct(
        private DatabaseConnectionInterface $database,
        private LedgerWriteService $ledger
    ) {}

    public function ensureFromShadow(): array
    {
        $ownership = $this->ownershipMap();
        $created = 0;
        $unchanged = 0;
        $zeroBalances = 0;
        $credited = 0;

        $rows = $this->database->fetchAll(
            "SELECT entity_key, payload_json, payload_sha256
             FROM mgw_legacy_realtime_shadow
             WHERE entity_type = 'economy_user_balance'
             ORDER BY entity_key"
        );
        foreach ($rows as $row) {
            $legacyUserId = trim((string)($row['entity_key'] ?? ''));
            $payloadJson = (string)($row['payload_json'] ?? '');
            $payload = $this->verifiedPayload($legacyUserId, $payloadJson, (string)($row['payload_sha256'] ?? ''));
            $owner = $ownership[$legacyUserId] ?? null;
            if (!is_array($owner)) {
                throw new RuntimeException('Runtime economy balance has no active account ownership.');
            }

            foreach (self::ASSETS as $assetCode => $field) {
                $amount = (int)($payload[$field] ?? -1);
                if ($amount < 0) {
                    throw new RuntimeException('Runtime economy balance contains an invalid amount.');
                }

                $existing = $this->ledger->getBalance($owner['account_ref'], $assetCode);
                if ($existing !== null) {
                    $this->assertIdentity($existing, $owner, $legacyUserId);
                    $unchanged++;
                    continue;
                }

                if ($amount > 0) {
                    $this->ledger->postAvailableDelta([
                        'operation_key' => 'runtime_opening:v1:' . substr(hash('sha256', implode('|', [
                            $legacyUserId,
                            $assetCode,
                            (string)($row['payload_sha256'] ?? ''),
                        ])), 0, 48),
                        'account_ref' => $owner['account_ref'],
                        'mgw_id' => $owner['mgw_id'],
                        'legacy_user_id' => $legacyUserId,
                        'asset_code' => $assetCode,
                        'available_delta' => $amount,
                        'category' => 'legacy_runtime_opening',
                        'source_type' => 'legacy_json_runtime',
                        'source_ref' => 'economy_shadow:' . substr((string)($row['payload_sha256'] ?? ''), 0, 64),
                        'metadata' => [
                            'reason' => 'new_runtime_account_balance',
                            'source_amount' => $amount,
                        ],
                    ]);
                    $credited += $amount;
                } else {
                    $this->insertZeroBalance($owner, $legacyUserId, $assetCode);
                    $zeroBalances++;
                }

                $stored = $this->ledger->getBalance($owner['account_ref'], $assetCode);
                if ($stored === null) {
                    throw new RuntimeException('Runtime economy balance bootstrap did not create the balance row.');
                }
                $this->assertIdentity($stored, $owner, $legacyUserId);
                $created++;
            }
        }

        return [
            'ok' => true,
            'source_user_count' => count($rows),
            'created_count' => $created,
            'unchanged_count' => $unchanged,
            'zero_balance_count' => $zeroBalances,
            'credited_total' => $credited,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function ownershipMap(): array
    {
        $map = [];
        foreach ($this->database->fetchAll(
            'SELECT account_ref, mgw_id, legacy_user_id, ownership_status FROM mgw_account_ownership'
        ) as $row) {
            if ((string)($row['ownership_status'] ?? '') !== 'active') continue;
            $legacyUserId = trim((string)($row['legacy_user_id'] ?? ''));
            $accountRef = trim((string)($row['account_ref'] ?? ''));
            $mgwId = trim((string)($row['mgw_id'] ?? ''));
            if ($legacyUserId === '' || $accountRef === '' || $mgwId === '') continue;
            if (isset($map[$legacyUserId])) {
                throw new RuntimeException('Runtime economy user has multiple active ownership rows.');
            }
            $map[$legacyUserId] = ['account_ref' => $accountRef, 'mgw_id' => $mgwId];
        }
        return $map;
    }

    private function verifiedPayload(string $legacyUserId, string $payloadJson, string $storedHash): array
    {
        if ($legacyUserId === '') throw new RuntimeException('Runtime economy shadow contains an empty user key.');
        try {
            $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Runtime economy shadow contains invalid JSON.');
        }
        if (!is_array($payload)) throw new RuntimeException('Runtime economy shadow payload is invalid.');
        $canonical = LedgerIntegrity::canonicalJson($payload);
        $storedHash = strtolower(trim($storedHash));
        if (preg_match('/^[a-f0-9]{64}$/', $storedHash) !== 1
            || !hash_equals($storedHash, hash('sha256', $canonical))) {
            throw new RuntimeException('Runtime economy shadow hash verification failed.');
        }
        if (trim((string)($payload['legacy_user_id'] ?? '')) !== $legacyUserId) {
            throw new RuntimeException('Runtime economy shadow identity does not match its key.');
        }
        return $payload;
    }

    private function insertZeroBalance(array $owner, string $legacyUserId, string $assetCode): void
    {
        $timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $parameters = [
            'account_ref' => $owner['account_ref'],
            'mgw_id' => $owner['mgw_id'],
            'legacy_user_id' => $legacyUserId,
            'asset_code' => $assetCode,
            'created_at_utc' => $timestamp,
            'updated_at_utc' => $timestamp,
        ];
        if ($this->database->driver() === 'sqlite') {
            $this->database->execute(
                'INSERT INTO mgw_balances (
                    account_ref, mgw_id, legacy_user_id, asset_code,
                    available_amount, reserved_amount, version, created_at_utc, updated_at_utc
                 ) VALUES (
                    :account_ref, :mgw_id, :legacy_user_id, :asset_code,
                    0, 0, 0, :created_at_utc, :updated_at_utc
                 ) ON CONFLICT(account_ref, asset_code) DO NOTHING',
                $parameters
            );
            return;
        }
        $this->database->execute(
            'INSERT INTO mgw_balances (
                account_ref, mgw_id, legacy_user_id, asset_code,
                available_amount, reserved_amount, version, created_at_utc, updated_at_utc
             ) VALUES (
                :account_ref, :mgw_id, :legacy_user_id, :asset_code,
                0, 0, 0, :created_at_utc, :updated_at_utc
             ) ON DUPLICATE KEY UPDATE account_ref = account_ref',
            $parameters
        );
    }

    private function assertIdentity(array $balance, array $owner, string $legacyUserId): void
    {
        if ((string)($balance['account_ref'] ?? '') !== $owner['account_ref']
            || (string)($balance['mgw_id'] ?? '') !== $owner['mgw_id']
            || (string)($balance['legacy_user_id'] ?? '') !== $legacyUserId) {
            throw new RuntimeException('Runtime economy balance ownership is inconsistent.');
        }
    }
}
