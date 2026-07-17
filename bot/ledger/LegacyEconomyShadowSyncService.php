<?php
declare(strict_types=1);

final class LegacyEconomyShadowSyncService
{
    private const USER_TYPE = 'economy_user_balance';
    private const TRANSACTION_TYPE = 'economy_transaction';
    private const TYPES = [self::USER_TYPE, self::TRANSACTION_TYPE];

    public function __construct(
        private StorageAdapterInterface $storage,
        private DatabaseConnectionInterface $database
    ) {}

    public function preview(): array
    {
        return $this->synchronize(true);
    }

    public function run(): array
    {
        return $this->synchronize(false);
    }

    private function synchronize(bool $dryRun): array
    {
        $source = $this->storage->readOnly(static function (array $data): array {
            return [
                'users' => is_array($data['users'] ?? null) ? $data['users'] : [],
                'transactions' => is_array($data['transactions'] ?? null) ? $data['transactions'] : [],
            ];
        });
        if (!is_array($source)) {
            throw new RuntimeException('Legacy economy snapshot is invalid.');
        }

        $entities = $this->normalizeSnapshot($source);
        $existing = $this->loadExisting();
        $summary = [];
        $fingerprintParts = [];

        foreach (self::TYPES as $type) {
            $summary[$type] = [
                'source_count' => count($entities[$type]),
                'inserted_count' => 0,
                'updated_count' => 0,
                'repair_count' => 0,
                'unchanged_count' => 0,
                'deleted_count' => 0,
            ];

            foreach ($entities[$type] as $key => $entity) {
                $fingerprintParts[] = $type . "\0" . $key . "\0" . $entity['payload_sha256'];
                $stored = $existing[$type][$key] ?? null;
                if ($stored === null) {
                    $summary[$type]['inserted_count']++;
                    continue;
                }
                if ($stored['needs_repair']) {
                    $summary[$type]['repair_count']++;
                }
                if ($stored['needs_repair'] || $stored['actual_sha256'] !== $entity['payload_sha256']) {
                    $summary[$type]['updated_count']++;
                } else {
                    $summary[$type]['unchanged_count']++;
                }
            }

            foreach (array_keys($existing[$type] ?? []) as $key) {
                if (!array_key_exists($key, $entities[$type])) {
                    $summary[$type]['deleted_count']++;
                }
            }
        }

        sort($fingerprintParts, SORT_STRING);
        $sourceFingerprint = hash('sha256', implode("\n", $fingerprintParts));

        if (!$dryRun) {
            $syncedAt = $this->timestamp();
            $this->database->transaction(function (DatabaseConnectionInterface $database) use (
                $entities,
                $existing,
                $syncedAt
            ): void {
                foreach (self::TYPES as $type) {
                    foreach ($entities[$type] as $key => $entity) {
                        $stored = $existing[$type][$key] ?? null;
                        if ($stored === null) {
                            $database->execute(
                                'INSERT INTO mgw_legacy_realtime_shadow (
                                    entity_type, entity_key, payload_json, payload_sha256,
                                    source_updated_at_utc, synced_at_utc
                                 ) VALUES (
                                    :entity_type, :entity_key, :payload_json, :payload_sha256,
                                    :source_updated_at_utc, :synced_at_utc
                                 )',
                                [
                                    'entity_type' => $type,
                                    'entity_key' => $key,
                                    'payload_json' => $entity['payload_json'],
                                    'payload_sha256' => $entity['payload_sha256'],
                                    'source_updated_at_utc' => $entity['source_updated_at_utc'],
                                    'synced_at_utc' => $syncedAt,
                                ]
                            );
                            continue;
                        }

                        if (!$stored['needs_repair'] && $stored['actual_sha256'] === $entity['payload_sha256']) {
                            continue;
                        }

                        $database->execute(
                            'UPDATE mgw_legacy_realtime_shadow SET
                                payload_json = :payload_json,
                                payload_sha256 = :payload_sha256,
                                source_updated_at_utc = :source_updated_at_utc,
                                synced_at_utc = :synced_at_utc
                             WHERE entity_type = :entity_type AND entity_key = :entity_key',
                            [
                                'payload_json' => $entity['payload_json'],
                                'payload_sha256' => $entity['payload_sha256'],
                                'source_updated_at_utc' => $entity['source_updated_at_utc'],
                                'synced_at_utc' => $syncedAt,
                                'entity_type' => $type,
                                'entity_key' => $key,
                            ]
                        );
                    }

                    foreach (array_keys($existing[$type] ?? []) as $key) {
                        if (array_key_exists($key, $entities[$type])) continue;
                        $database->execute(
                            'DELETE FROM mgw_legacy_realtime_shadow
                             WHERE entity_type = :entity_type AND entity_key = :entity_key',
                            ['entity_type' => $type, 'entity_key' => $key]
                        );
                    }
                }
            });
        }

        $shadowIntegrity = $this->shadowIntegrity($dryRun ? $entities : null);
        $reconciliation = $this->reconcile($entities[self::USER_TYPE]);

        return [
            'ok' => true,
            'dry_run' => $dryRun,
            'storage_driver' => $this->storage->driver(),
            'source_fingerprint' => $sourceFingerprint,
            'sections' => [
                'user_balances' => $summary[self::USER_TYPE],
                'transactions' => $summary[self::TRANSACTION_TYPE],
            ],
            'shadow_integrity' => $shadowIntegrity,
            'reconciliation' => $reconciliation,
        ];
    }

    private function normalizeSnapshot(array $source): array
    {
        $users = [];
        foreach ($source['users'] ?? [] as $sourceKey => $record) {
            if (!is_array($record)) continue;
            $legacyUserId = $this->legacyUserId($sourceKey, $record);
            if (isset($users[$legacyUserId])) {
                throw new RuntimeException('Duplicate legacy economy user: ' . $legacyUserId);
            }

            $match = $this->nonNegativeInteger($record['balance_match'] ?? 0, 'balance_match', $legacyUserId);
            $gold = $this->nonNegativeInteger($record['balance_gold'] ?? 0, 'balance_gold', $legacyUserId);
            $rawCanonical = $this->canonicalJson($record);
            $payload = [
                'legacy_user_id' => $legacyUserId,
                'telegram_id' => $this->nullableText($record['telegram_id'] ?? null, 191),
                'balance_match' => $match,
                'balance_gold' => $gold,
                'registered_at' => $this->nullableText($record['registered_at'] ?? null, 64),
                'last_seen_at' => $this->nullableText($record['last_seen_at'] ?? null, 64),
                'source_record_sha256' => hash('sha256', $rawCanonical),
            ];
            $payloadJson = $this->canonicalJson($payload);
            $users[$legacyUserId] = [
                'payload' => $payload,
                'payload_json' => $payloadJson,
                'payload_sha256' => hash('sha256', $payloadJson),
                'source_updated_at_utc' => $this->recordTimestamp($record),
            ];
        }
        ksort($users, SORT_STRING);

        $transactions = [];
        foreach ($source['transactions'] ?? [] as $sourceKey => $record) {
            if (!is_array($record)) continue;
            $payloadJson = $this->canonicalJson($record);
            $key = $this->transactionKey($sourceKey, $record, $payloadJson);
            if (isset($transactions[$key])) {
                throw new RuntimeException('Duplicate legacy economy transaction: ' . $key);
            }
            $transactions[$key] = [
                'payload' => $record,
                'payload_json' => $payloadJson,
                'payload_sha256' => hash('sha256', $payloadJson),
                'source_updated_at_utc' => $this->recordTimestamp($record),
            ];
        }
        ksort($transactions, SORT_STRING);

        return [self::USER_TYPE => $users, self::TRANSACTION_TYPE => $transactions];
    }

    private function loadExisting(): array
    {
        $rows = $this->database->fetchAll(
            "SELECT entity_type, entity_key, payload_json, payload_sha256
             FROM mgw_legacy_realtime_shadow
             WHERE entity_type IN ('" . self::USER_TYPE . "', '" . self::TRANSACTION_TYPE . "')"
        );
        $existing = [];
        foreach ($rows as $row) {
            $type = (string)($row['entity_type'] ?? '');
            $key = (string)($row['entity_key'] ?? '');
            if (!in_array($type, self::TYPES, true) || $key === '') continue;

            $storedHash = strtolower(trim((string)($row['payload_sha256'] ?? '')));
            $canonical = $this->canonicalStoredJson($row['payload_json'] ?? null);
            $actualHash = $canonical === null ? '' : hash('sha256', $canonical);
            $existing[$type][$key] = [
                'actual_sha256' => $actualHash,
                'stored_sha256' => $storedHash,
                'needs_repair' => $canonical === null
                    || preg_match('/^[a-f0-9]{64}$/', $storedHash) !== 1
                    || !hash_equals($storedHash, $actualHash),
            ];
        }
        return $existing;
    }

    private function shadowIntegrity(?array $previewEntities): array
    {
        if ($previewEntities !== null) {
            return [
                'checked_count' => 0,
                'corrupted_count' => 0,
                'pending_source_count' => count($previewEntities[self::USER_TYPE])
                    + count($previewEntities[self::TRANSACTION_TYPE]),
            ];
        }

        $rows = $this->database->fetchAll(
            "SELECT payload_json, payload_sha256 FROM mgw_legacy_realtime_shadow
             WHERE entity_type IN ('" . self::USER_TYPE . "', '" . self::TRANSACTION_TYPE . "')"
        );
        $corrupted = 0;
        foreach ($rows as $row) {
            $canonical = $this->canonicalStoredJson($row['payload_json'] ?? null);
            $storedHash = strtolower(trim((string)($row['payload_sha256'] ?? '')));
            if ($canonical === null
                || preg_match('/^[a-f0-9]{64}$/', $storedHash) !== 1
                || !hash_equals($storedHash, hash('sha256', $canonical))) {
                $corrupted++;
            }
        }
        return [
            'checked_count' => count($rows),
            'corrupted_count' => $corrupted,
            'pending_source_count' => 0,
        ];
    }

    private function reconcile(array $users): array
    {
        $identityRows = $this->database->fetchAll(
            "SELECT mgw_id, provider_subject FROM mgw_identities
             WHERE provider IN ('telegram', 'development')"
        );
        $identities = [];
        foreach ($identityRows as $row) {
            $subject = trim((string)($row['provider_subject'] ?? ''));
            $mgwId = trim((string)($row['mgw_id'] ?? ''));
            if ($subject !== '' && $mgwId !== '') $identities[$subject] = $mgwId;
        }

        $balanceRows = $this->database->fetchAll(
            'SELECT account_ref, asset_code, available_amount, reserved_amount FROM mgw_balances'
        );
        $balances = [];
        foreach ($balanceRows as $row) {
            $accountRef = (string)($row['account_ref'] ?? '');
            $asset = (string)($row['asset_code'] ?? '');
            if ($accountRef === '' || $asset === '') continue;
            $balances[$accountRef][$asset] = [
                'available' => (int)($row['available_amount'] ?? 0),
                'reserved' => (int)($row['reserved_amount'] ?? 0),
            ];
        }

        $summary = [
            'source_user_count' => count($users),
            'mapped_identity_count' => 0,
            'legacy_only_count' => 0,
            'missing_balance_row_count' => 0,
            'balance_mismatch_count' => 0,
            'reserved_nonzero_count' => 0,
            'source_totals' => ['match_coin' => 0, 'gold_coin' => 0],
            'database_totals' => ['match_coin' => 0, 'gold_coin' => 0],
            'mismatches' => [],
        ];

        foreach ($users as $legacyUserId => $entity) {
            $payload = $entity['payload'];
            $mgwId = $identities[$legacyUserId] ?? null;
            $accountRef = $mgwId === null ? 'legacy:' . $legacyUserId : 'mgw:' . $mgwId;
            if ($mgwId === null) $summary['legacy_only_count']++;
            else $summary['mapped_identity_count']++;

            foreach (['match_coin' => 'balance_match', 'gold_coin' => 'balance_gold'] as $asset => $field) {
                $sourceAmount = (int)$payload[$field];
                $summary['source_totals'][$asset] += $sourceAmount;
                $row = $balances[$accountRef][$asset] ?? null;
                if ($row === null) {
                    $summary['missing_balance_row_count']++;
                    $databaseAmount = 0;
                    $reservedAmount = 0;
                } else {
                    $databaseAmount = (int)$row['available'];
                    $reservedAmount = (int)$row['reserved'];
                }
                $summary['database_totals'][$asset] += $databaseAmount;
                if ($reservedAmount !== 0) $summary['reserved_nonzero_count']++;

                if ($databaseAmount !== $sourceAmount || $reservedAmount !== 0) {
                    $summary['balance_mismatch_count']++;
                    if (count($summary['mismatches']) < 25) {
                        $summary['mismatches'][] = [
                            'legacy_user_id' => $legacyUserId,
                            'account_ref' => $accountRef,
                            'asset_code' => $asset,
                            'source_available' => $sourceAmount,
                            'database_available' => $databaseAmount,
                            'database_reserved' => $reservedAmount,
                            'missing_row' => $row === null,
                        ];
                    }
                }
            }
        }

        return $summary;
    }

    private function legacyUserId(int|string $sourceKey, array $record): string
    {
        foreach ([$record['id'] ?? null, $record['telegram_id'] ?? null, $sourceKey] as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '') continue;
            return $this->safeKey($candidate, 191);
        }
        throw new RuntimeException('Legacy economy user has no stable ID.');
    }

    private function transactionKey(int|string $sourceKey, array $record, string $payloadJson): string
    {
        foreach ([$record['id'] ?? null, is_string($sourceKey) ? $sourceKey : null] as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') return $this->safeKey($candidate, 180);
        }
        return 'sha256:' . hash('sha256', $payloadJson);
    }

    private function safeKey(string $value, int $maxLength): string
    {
        if (strlen($value) <= $maxLength && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1) {
            return $value;
        }
        return 'sha256:' . hash('sha256', $value);
    }

    private function nonNegativeInteger(mixed $value, string $field, string $userId): int
    {
        if (is_int($value)) $number = $value;
        elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) $number = (int)$value;
        elseif (is_float($value) && floor($value) === $value) $number = (int)$value;
        else throw new RuntimeException('Invalid ' . $field . ' for legacy user ' . $userId . '.');

        if ($number < 0) {
            throw new RuntimeException('Negative ' . $field . ' for legacy user ' . $userId . '.');
        }
        return $number;
    }

    private function recordTimestamp(array $record): ?string
    {
        foreach (['updated_at', 'created_at', 'last_seen_at', 'registered_at', 'finished_at'] as $field) {
            $value = trim((string)($record[$field] ?? ''));
            if ($value === '') continue;
            try {
                return (new DateTimeImmutable($value))
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s.u');
            } catch (Throwable) {
                continue;
            }
        }
        return null;
    }

    private function nullableText(mixed $value, int $maxLength): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') return null;
        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    private function canonicalStoredJson(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') return null;
        try {
            return $this->canonicalJson(json_decode($value, true, 512, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return null;
        }
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
