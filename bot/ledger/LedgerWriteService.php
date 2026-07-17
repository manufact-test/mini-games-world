<?php
declare(strict_types=1);

final class LedgerWriteService
{
    private $clock;

    public function __construct(
        private DatabaseConnectionInterface $database,
        ?callable $clock = null
    ) {
        $this->clock = $clock;
    }

    public function postAvailableDelta(array $request): array
    {
        $operationKey = $this->requiredText($request, 'operation_key', 191);
        $identity = $this->identity($request);
        $assetCode = $this->assetCode($request['asset_code'] ?? null);
        $delta = $this->integer($request['available_delta'] ?? null, 'available_delta');
        if ($delta === 0) throw new InvalidArgumentException('available_delta must not be zero.');

        $category = $this->token($request['category'] ?? null, 'category', 64);
        $sourceType = $this->token($request['source_type'] ?? null, 'source_type', 64);
        $sourceRef = $this->nullableText($request['source_ref'] ?? null, 191);
        $metadata = $request['metadata'] ?? null;
        $metadataJson = $this->encodeJson($metadata);
        [$createdAt, $requestTimestamp] = $this->operationTimestamp($request['occurred_at_utc'] ?? null);

        $requestHash = LedgerIntegrity::requestHash([
            'action' => 'post_available_delta',
            'operation_key' => $operationKey,
            'account_ref' => $identity['account_ref'],
            'mgw_id' => $identity['mgw_id'],
            'legacy_user_id' => $identity['legacy_user_id'],
            'asset_code' => $assetCode,
            'available_delta' => $delta,
            'category' => $category,
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
            'metadata' => $metadata,
            'occurred_at_utc' => $requestTimestamp,
        ]);

        return $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $operationKey,
            $requestHash,
            $identity,
            $assetCode,
            $delta,
            $category,
            $sourceType,
            $sourceRef,
            $metadataJson,
            $createdAt
        ): array {
            $replay = $this->claimOperation(
                $db,
                $operationKey,
                'available_delta',
                $identity['account_ref'],
                $requestHash,
                $createdAt
            );
            if ($replay !== null) return $replay;

            $balance = $this->lockBalance($db, $identity, $assetCode, $createdAt, true);
            $availableBefore = (int)$balance['available_amount'];
            $reservedBefore = (int)$balance['reserved_amount'];
            $availableAfter = $availableBefore + $delta;
            if ($availableAfter < 0) throw new RuntimeException('Insufficient available balance.');

            $entry = $this->buildEntry(
                $db,
                $operationKey,
                $identity,
                $assetCode,
                $delta,
                0,
                $availableBefore,
                $availableAfter,
                $reservedBefore,
                $reservedBefore,
                $category,
                $sourceType,
                $sourceRef,
                null,
                $metadataJson,
                $createdAt
            );
            $this->insertEntry($db, $entry);
            $updatedBalance = $this->updateBalance($db, $balance, $availableAfter, $reservedBefore, $createdAt);

            $result = [
                'operation_key' => $operationKey,
                'entry_id' => $entry['entry_id'],
                'reservation_id' => null,
                'balance' => $updatedBalance,
                'replayed' => false,
            ];
            $this->completeOperation($db, $operationKey, $requestHash, $result, $createdAt);
            return $result;
        });
    }

    public function createReservation(array $request): array
    {
        $operationKey = $this->requiredText($request, 'operation_key', 191);
        $identity = $this->identity($request);
        $assetCode = $this->assetCode($request['asset_code'] ?? null);
        $amount = $this->positiveInteger($request['amount'] ?? null, 'amount');
        $sourceType = $this->token($request['source_type'] ?? null, 'source_type', 64);
        $sourceRef = $this->nullableText($request['source_ref'] ?? null, 191);
        $metadata = $request['metadata'] ?? null;
        $metadataJson = $this->encodeJson($metadata);
        [$createdAt, $requestTimestamp] = $this->operationTimestamp($request['occurred_at_utc'] ?? null);
        $expiresAt = $this->nullableTimestamp($request['expires_at_utc'] ?? null);

        $requestHash = LedgerIntegrity::requestHash([
            'action' => 'create_reservation',
            'operation_key' => $operationKey,
            'account_ref' => $identity['account_ref'],
            'mgw_id' => $identity['mgw_id'],
            'legacy_user_id' => $identity['legacy_user_id'],
            'asset_code' => $assetCode,
            'amount' => $amount,
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
            'metadata' => $metadata,
            'expires_at_utc' => $expiresAt,
            'occurred_at_utc' => $requestTimestamp,
        ]);

        return $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $operationKey,
            $requestHash,
            $identity,
            $assetCode,
            $amount,
            $sourceType,
            $sourceRef,
            $metadataJson,
            $createdAt,
            $expiresAt
        ): array {
            $replay = $this->claimOperation(
                $db,
                $operationKey,
                'reservation_create',
                $identity['account_ref'],
                $requestHash,
                $createdAt
            );
            if ($replay !== null) return $replay;

            $balance = $this->lockBalance($db, $identity, $assetCode, $createdAt, true);
            $availableBefore = (int)$balance['available_amount'];
            $reservedBefore = (int)$balance['reserved_amount'];
            if ($availableBefore < $amount) throw new RuntimeException('Insufficient available balance.');

            $availableAfter = $availableBefore - $amount;
            $reservedAfter = $reservedBefore + $amount;
            $reservationId = $this->stableId('res', $operationKey);

            $db->execute(
                'INSERT INTO mgw_reservations (
                    reservation_id, idempotency_key, account_ref, mgw_id, legacy_user_id,
                    asset_code, amount, status, source_type, source_ref, metadata_json,
                    created_at_utc, updated_at_utc, expires_at_utc, consumed_at_utc, released_at_utc
                 ) VALUES (
                    :reservation_id, :idempotency_key, :account_ref, :mgw_id, :legacy_user_id,
                    :asset_code, :amount, :status, :source_type, :source_ref, :metadata_json,
                    :created_at_utc, :updated_at_utc, :expires_at_utc, NULL, NULL
                 )',
                [
                    'reservation_id' => $reservationId,
                    'idempotency_key' => $operationKey,
                    'account_ref' => $identity['account_ref'],
                    'mgw_id' => $identity['mgw_id'],
                    'legacy_user_id' => $identity['legacy_user_id'],
                    'asset_code' => $assetCode,
                    'amount' => $amount,
                    'status' => 'active',
                    'source_type' => $sourceType,
                    'source_ref' => $sourceRef,
                    'metadata_json' => $metadataJson,
                    'created_at_utc' => $createdAt,
                    'updated_at_utc' => $createdAt,
                    'expires_at_utc' => $expiresAt,
                ]
            );

            $this->insertReservationEvent(
                $db,
                $reservationId,
                'created',
                'created',
                -$amount,
                $amount,
                $metadataJson,
                $createdAt
            );

            $entry = $this->buildEntry(
                $db,
                $operationKey,
                $identity,
                $assetCode,
                -$amount,
                $amount,
                $availableBefore,
                $availableAfter,
                $reservedBefore,
                $reservedAfter,
                'reservation_created',
                $sourceType,
                $sourceRef,
                $reservationId,
                $metadataJson,
                $createdAt
            );
            $this->insertEntry($db, $entry);
            $updatedBalance = $this->updateBalance($db, $balance, $availableAfter, $reservedAfter, $createdAt);

            $result = [
                'operation_key' => $operationKey,
                'entry_id' => $entry['entry_id'],
                'reservation_id' => $reservationId,
                'reservation_status' => 'active',
                'balance' => $updatedBalance,
                'replayed' => false,
            ];
            $this->completeOperation($db, $operationKey, $requestHash, $result, $createdAt);
            return $result;
        });
    }

    public function releaseReservation(array $request): array
    {
        return $this->finishReservation($request, 'released');
    }

    public function consumeReservation(array $request): array
    {
        return $this->finishReservation($request, 'consumed');
    }

    public function getBalance(string $accountRef, string $assetCode): ?array
    {
        $accountRef = $this->accountRef($accountRef);
        $assetCode = $this->assetCode($assetCode);
        $rows = $this->database->fetchAll(
            'SELECT account_ref, mgw_id, legacy_user_id, asset_code,
                    available_amount, reserved_amount, version, created_at_utc, updated_at_utc
             FROM mgw_balances WHERE account_ref = :account_ref AND asset_code = :asset_code',
            ['account_ref' => $accountRef, 'asset_code' => $assetCode]
        );
        return $rows === [] ? null : $this->balanceResult($rows[0]);
    }

    private function finishReservation(array $request, string $status): array
    {
        $operationKey = $this->requiredText($request, 'operation_key', 191);
        $reservationId = $this->requiredText($request, 'reservation_id', 96);
        $metadata = $request['metadata'] ?? null;
        $metadataJson = $this->encodeJson($metadata);
        [$createdAt, $requestTimestamp] = $this->operationTimestamp($request['occurred_at_utc'] ?? null);

        $requestHash = LedgerIntegrity::requestHash([
            'action' => $status === 'released' ? 'release_reservation' : 'consume_reservation',
            'operation_key' => $operationKey,
            'reservation_id' => $reservationId,
            'metadata' => $metadata,
            'occurred_at_utc' => $requestTimestamp,
        ]);

        return $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $operationKey,
            $reservationId,
            $metadataJson,
            $createdAt,
            $requestHash,
            $status
        ): array {
            $reservationRows = $db->fetchAll(
                'SELECT * FROM mgw_reservations WHERE reservation_id = :reservation_id' . $this->forUpdate($db),
                ['reservation_id' => $reservationId]
            );
            if ($reservationRows === []) throw new RuntimeException('Reservation was not found.');
            $reservation = $reservationRows[0];

            $replay = $this->claimOperation(
                $db,
                $operationKey,
                $status === 'released' ? 'reservation_release' : 'reservation_consume',
                (string)$reservation['account_ref'],
                $requestHash,
                $createdAt
            );
            if ($replay !== null) return $replay;

            if ((string)$reservation['status'] !== 'active') {
                throw new RuntimeException('Reservation is not active.');
            }

            $identity = [
                'account_ref' => (string)$reservation['account_ref'],
                'mgw_id' => $this->nullableText($reservation['mgw_id'] ?? null, 24),
                'legacy_user_id' => $this->nullableText($reservation['legacy_user_id'] ?? null, 191),
            ];
            $assetCode = $this->assetCode($reservation['asset_code'] ?? null);
            $amount = $this->positiveInteger($reservation['amount'] ?? null, 'reservation amount');
            $balance = $this->lockBalance($db, $identity, $assetCode, $createdAt, false);
            $availableBefore = (int)$balance['available_amount'];
            $reservedBefore = (int)$balance['reserved_amount'];
            if ($reservedBefore < $amount) throw new RuntimeException('Reserved balance is inconsistent.');

            $isRelease = $status === 'released';
            $availableDelta = $isRelease ? $amount : 0;
            $reservedDelta = -$amount;
            $availableAfter = $availableBefore + $availableDelta;
            $reservedAfter = $reservedBefore - $amount;
            $eventType = $isRelease ? 'released' : 'consumed';

            $this->insertReservationEvent(
                $db,
                $reservationId,
                $operationKey,
                $eventType,
                $availableDelta,
                $reservedDelta,
                $metadataJson,
                $createdAt
            );

            $entry = $this->buildEntry(
                $db,
                $operationKey,
                $identity,
                $assetCode,
                $availableDelta,
                $reservedDelta,
                $availableBefore,
                $availableAfter,
                $reservedBefore,
                $reservedAfter,
                $isRelease ? 'reservation_released' : 'reservation_consumed',
                (string)$reservation['source_type'],
                $this->nullableText($reservation['source_ref'] ?? null, 191),
                $reservationId,
                $metadataJson,
                $createdAt
            );
            $this->insertEntry($db, $entry);
            $updatedBalance = $this->updateBalance($db, $balance, $availableAfter, $reservedAfter, $createdAt);

            $db->execute(
                'UPDATE mgw_reservations SET
                    status = :status,
                    updated_at_utc = :updated_at_utc,
                    consumed_at_utc = :consumed_at_utc,
                    released_at_utc = :released_at_utc
                 WHERE reservation_id = :reservation_id AND status = :expected_status',
                [
                    'status' => $status,
                    'updated_at_utc' => $createdAt,
                    'consumed_at_utc' => $isRelease ? null : $createdAt,
                    'released_at_utc' => $isRelease ? $createdAt : null,
                    'reservation_id' => $reservationId,
                    'expected_status' => 'active',
                ]
            );

            $result = [
                'operation_key' => $operationKey,
                'entry_id' => $entry['entry_id'],
                'reservation_id' => $reservationId,
                'reservation_status' => $status,
                'balance' => $updatedBalance,
                'replayed' => false,
            ];
            $this->completeOperation($db, $operationKey, $requestHash, $result, $createdAt);
            return $result;
        });
    }

    private function claimOperation(
        DatabaseConnectionInterface $db,
        string $operationKey,
        string $operationType,
        ?string $ownerRef,
        string $requestHash,
        string $createdAt
    ): ?array {
        $parameters = [
            'operation_key' => $operationKey,
            'operation_type' => $operationType,
            'owner_ref' => $ownerRef,
            'request_sha256' => $requestHash,
            'status' => 'started',
            'created_at_utc' => $createdAt,
            'updated_at_utc' => $createdAt,
        ];

        if ($db->driver() === 'sqlite') {
            $inserted = $db->execute(
                'INSERT INTO mgw_idempotency_keys (
                    operation_key, operation_type, owner_ref, request_sha256,
                    status, result_json, created_at_utc, updated_at_utc, expires_at_utc
                 ) VALUES (
                    :operation_key, :operation_type, :owner_ref, :request_sha256,
                    :status, NULL, :created_at_utc, :updated_at_utc, NULL
                 ) ON CONFLICT(operation_key) DO NOTHING',
                $parameters
            ) === 1;
        } else {
            $inserted = $db->execute(
                'INSERT INTO mgw_idempotency_keys (
                    operation_key, operation_type, owner_ref, request_sha256,
                    status, result_json, created_at_utc, updated_at_utc, expires_at_utc
                 ) VALUES (
                    :operation_key, :operation_type, :owner_ref, :request_sha256,
                    :status, NULL, :created_at_utc, :updated_at_utc, NULL
                 ) ON DUPLICATE KEY UPDATE operation_key = operation_key',
                $parameters
            ) === 1;
        }

        $rows = $db->fetchAll(
            'SELECT operation_type, owner_ref, request_sha256, status, result_json
             FROM mgw_idempotency_keys WHERE operation_key = :operation_key' . $this->forUpdate($db),
            ['operation_key' => $operationKey]
        );
        if ($rows === []) throw new RuntimeException('Idempotency operation could not be claimed.');
        $row = $rows[0];

        if (!hash_equals((string)$row['request_sha256'], $requestHash)
            || (string)$row['operation_type'] !== $operationType
            || $this->nullableText($row['owner_ref'] ?? null, 255) !== $ownerRef) {
            throw new RuntimeException('Idempotency key was reused with different input.');
        }

        if ((string)$row['status'] === 'completed') {
            $result = LedgerIntegrity::decodeJson($row['result_json'] ?? null);
            if (!is_array($result)) throw new RuntimeException('Completed idempotency result is invalid.');
            $result['replayed'] = true;
            return $result;
        }

        if (!$inserted || (string)$row['status'] !== 'started') {
            throw new RuntimeException('Idempotency operation is already in progress or failed.');
        }

        return null;
    }

    private function completeOperation(
        DatabaseConnectionInterface $db,
        string $operationKey,
        string $requestHash,
        array $result,
        string $updatedAt
    ): void {
        $updated = $db->execute(
            'UPDATE mgw_idempotency_keys SET
                status = :completed_status,
                result_json = :result_json,
                updated_at_utc = :updated_at_utc
             WHERE operation_key = :operation_key
               AND request_sha256 = :request_sha256
               AND status = :started_status',
            [
                'completed_status' => 'completed',
                'result_json' => LedgerIntegrity::canonicalJson($result),
                'updated_at_utc' => $updatedAt,
                'operation_key' => $operationKey,
                'request_sha256' => $requestHash,
                'started_status' => 'started',
            ]
        );
        if ($updated !== 1) throw new RuntimeException('Idempotency operation could not be completed.');
    }

    private function lockBalance(
        DatabaseConnectionInterface $db,
        array $identity,
        string $assetCode,
        string $createdAt,
        bool $createIfMissing
    ): array {
        if ($createIfMissing) {
            $parameters = [
                'account_ref' => $identity['account_ref'],
                'mgw_id' => $identity['mgw_id'],
                'legacy_user_id' => $identity['legacy_user_id'],
                'asset_code' => $assetCode,
                'created_at_utc' => $createdAt,
                'updated_at_utc' => $createdAt,
            ];
            if ($db->driver() === 'sqlite') {
                $db->execute(
                    'INSERT INTO mgw_balances (
                        account_ref, mgw_id, legacy_user_id, asset_code,
                        available_amount, reserved_amount, version, created_at_utc, updated_at_utc
                     ) VALUES (
                        :account_ref, :mgw_id, :legacy_user_id, :asset_code,
                        0, 0, 0, :created_at_utc, :updated_at_utc
                     ) ON CONFLICT(account_ref, asset_code) DO NOTHING',
                    $parameters
                );
            } else {
                $db->execute(
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
        }

        $rows = $db->fetchAll(
            'SELECT balance_id, account_ref, mgw_id, legacy_user_id, asset_code,
                    available_amount, reserved_amount, version, created_at_utc, updated_at_utc
             FROM mgw_balances
             WHERE account_ref = :account_ref AND asset_code = :asset_code' . $this->forUpdate($db),
            ['account_ref' => $identity['account_ref'], 'asset_code' => $assetCode]
        );
        if ($rows === []) throw new RuntimeException('Balance was not found.');
        $balance = $rows[0];

        foreach (['mgw_id', 'legacy_user_id'] as $column) {
            $stored = $this->nullableText($balance[$column] ?? null, $column === 'mgw_id' ? 24 : 191);
            $incoming = $identity[$column];
            if ($stored !== null && $incoming !== null && $stored !== $incoming) {
                throw new RuntimeException('Balance identity does not match the account reference.');
            }
        }

        $mgwId = $this->nullableText($balance['mgw_id'] ?? null, 24) ?? $identity['mgw_id'];
        $legacyUserId = $this->nullableText($balance['legacy_user_id'] ?? null, 191) ?? $identity['legacy_user_id'];
        if ($mgwId !== $this->nullableText($balance['mgw_id'] ?? null, 24)
            || $legacyUserId !== $this->nullableText($balance['legacy_user_id'] ?? null, 191)) {
            $db->execute(
                'UPDATE mgw_balances SET mgw_id = :mgw_id, legacy_user_id = :legacy_user_id
                 WHERE balance_id = :balance_id',
                ['mgw_id' => $mgwId, 'legacy_user_id' => $legacyUserId, 'balance_id' => $balance['balance_id']]
            );
            $balance['mgw_id'] = $mgwId;
            $balance['legacy_user_id'] = $legacyUserId;
        }

        return $balance;
    }

    private function updateBalance(
        DatabaseConnectionInterface $db,
        array $balance,
        int $availableAfter,
        int $reservedAfter,
        string $updatedAt
    ): array {
        if ($availableAfter < 0 || $reservedAfter < 0) throw new RuntimeException('Balance cannot become negative.');
        $updated = $db->execute(
            'UPDATE mgw_balances SET
                available_amount = :available_amount,
                reserved_amount = :reserved_amount,
                version = version + 1,
                updated_at_utc = :updated_at_utc
             WHERE balance_id = :balance_id AND version = :expected_version',
            [
                'available_amount' => $availableAfter,
                'reserved_amount' => $reservedAfter,
                'updated_at_utc' => $updatedAt,
                'balance_id' => $balance['balance_id'],
                'expected_version' => (int)$balance['version'],
            ]
        );
        if ($updated !== 1) throw new RuntimeException('Concurrent balance update was detected.');

        $balance['available_amount'] = $availableAfter;
        $balance['reserved_amount'] = $reservedAfter;
        $balance['version'] = (int)$balance['version'] + 1;
        $balance['updated_at_utc'] = $updatedAt;
        return $this->balanceResult($balance);
    }

    private function buildEntry(
        DatabaseConnectionInterface $db,
        string $operationKey,
        array $identity,
        string $assetCode,
        int $availableDelta,
        int $reservedDelta,
        int $availableBefore,
        int $availableAfter,
        int $reservedBefore,
        int $reservedAfter,
        string $category,
        string $sourceType,
        ?string $sourceRef,
        ?string $reservationId,
        ?string $metadataJson,
        string $createdAt
    ): array {
        $previousRows = $db->fetchAll(
            'SELECT entry_sha256 FROM mgw_ledger_entries
             WHERE account_ref = :account_ref AND asset_code = :asset_code
             ORDER BY ledger_sequence DESC LIMIT 1' . $this->forUpdate($db),
            ['account_ref' => $identity['account_ref'], 'asset_code' => $assetCode]
        );
        $previousHash = $previousRows === [] ? null : (string)$previousRows[0]['entry_sha256'];

        $entry = [
            'entry_id' => $this->stableId('led', $operationKey),
            'idempotency_key' => $operationKey,
            'account_ref' => $identity['account_ref'],
            'mgw_id' => $identity['mgw_id'],
            'legacy_user_id' => $identity['legacy_user_id'],
            'asset_code' => $assetCode,
            'available_delta' => $availableDelta,
            'reserved_delta' => $reservedDelta,
            'available_before' => $availableBefore,
            'available_after' => $availableAfter,
            'reserved_before' => $reservedBefore,
            'reserved_after' => $reservedAfter,
            'category' => $category,
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
            'reservation_id' => $reservationId,
            'metadata_json' => $metadataJson,
            'previous_entry_sha256' => $previousHash,
            'created_at_utc' => $createdAt,
        ];
        $entry['entry_sha256'] = LedgerIntegrity::entryHash($entry);
        return $entry;
    }

    private function insertEntry(DatabaseConnectionInterface $db, array $entry): void
    {
        $db->execute(
            'INSERT INTO mgw_ledger_entries (
                entry_id, idempotency_key, account_ref, mgw_id, legacy_user_id, asset_code,
                available_delta, reserved_delta, available_before, available_after,
                reserved_before, reserved_after, category, source_type, source_ref,
                reservation_id, metadata_json, previous_entry_sha256, entry_sha256, created_at_utc
             ) VALUES (
                :entry_id, :idempotency_key, :account_ref, :mgw_id, :legacy_user_id, :asset_code,
                :available_delta, :reserved_delta, :available_before, :available_after,
                :reserved_before, :reserved_after, :category, :source_type, :source_ref,
                :reservation_id, :metadata_json, :previous_entry_sha256, :entry_sha256, :created_at_utc
             )',
            $entry
        );
    }

    private function insertReservationEvent(
        DatabaseConnectionInterface $db,
        string $reservationId,
        string $eventKey,
        string $eventType,
        int $availableDelta,
        int $reservedDelta,
        ?string $metadataJson,
        string $createdAt
    ): void {
        $event = [
            'event_id' => $this->stableId('rev', $reservationId . '|' . $eventKey),
            'reservation_id' => $reservationId,
            'event_key' => $eventKey,
            'event_type' => $eventType,
            'available_delta' => $availableDelta,
            'reserved_delta' => $reservedDelta,
            'metadata_json' => $metadataJson,
            'created_at_utc' => $createdAt,
        ];
        $event['event_sha256'] = LedgerIntegrity::reservationEventHash($event);

        $db->execute(
            'INSERT INTO mgw_reservation_events (
                event_id, reservation_id, event_key, event_type,
                available_delta, reserved_delta, metadata_json, event_sha256, created_at_utc
             ) VALUES (
                :event_id, :reservation_id, :event_key, :event_type,
                :available_delta, :reserved_delta, :metadata_json, :event_sha256, :created_at_utc
             )',
            $event
        );
    }

    private function balanceResult(array $balance): array
    {
        return [
            'account_ref' => (string)$balance['account_ref'],
            'mgw_id' => $this->nullableText($balance['mgw_id'] ?? null, 24),
            'legacy_user_id' => $this->nullableText($balance['legacy_user_id'] ?? null, 191),
            'asset_code' => (string)$balance['asset_code'],
            'available_amount' => (int)$balance['available_amount'],
            'reserved_amount' => (int)$balance['reserved_amount'],
            'version' => (int)$balance['version'],
            'created_at_utc' => (string)$balance['created_at_utc'],
            'updated_at_utc' => (string)$balance['updated_at_utc'],
        ];
    }

    private function identity(array $request): array
    {
        $accountRef = $this->accountRef($request['account_ref'] ?? null);
        $mgwId = $this->nullableText($request['mgw_id'] ?? null, 24);
        $legacyUserId = $this->nullableText($request['legacy_user_id'] ?? null, 191);

        [$prefix, $subject] = explode(':', $accountRef, 2);
        if ($prefix === 'mgw') {
            if ($mgwId !== null && $mgwId !== $subject) throw new InvalidArgumentException('mgw_id does not match account_ref.');
            $mgwId = $subject;
        } else {
            if ($legacyUserId !== null && $legacyUserId !== $subject) throw new InvalidArgumentException('legacy_user_id does not match account_ref.');
            $legacyUserId = $subject;
        }

        return ['account_ref' => $accountRef, 'mgw_id' => $mgwId, 'legacy_user_id' => $legacyUserId];
    }

    private function accountRef(mixed $value): string
    {
        $value = $this->text((string)($value ?? ''), 255);
        if (preg_match('/^(mgw|legacy):[^\s:][^\s]{0,239}$/u', $value) !== 1) {
            throw new InvalidArgumentException('A valid mgw: or legacy: account_ref is required.');
        }
        return $value;
    }

    private function assetCode(mixed $value): string
    {
        $value = strtolower($this->text((string)($value ?? ''), 32));
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $value) !== 1) {
            throw new InvalidArgumentException('A valid asset_code is required.');
        }
        return $value;
    }

    private function token(mixed $value, string $name, int $maxLength): string
    {
        $value = strtolower($this->text((string)($value ?? ''), $maxLength));
        if (preg_match('/^[a-z][a-z0-9_]{0,' . ($maxLength - 1) . '}$/', $value) !== 1) {
            throw new InvalidArgumentException('A valid ' . $name . ' is required.');
        }
        return $value;
    }

    private function requiredText(array $source, string $key, int $maxLength): string
    {
        $value = $this->text((string)($source[$key] ?? ''), $maxLength);
        if ($value === '') throw new InvalidArgumentException($key . ' is required.');
        return $value;
    }

    private function nullableText(mixed $value, int $maxLength): ?string
    {
        $value = $this->text((string)($value ?? ''), $maxLength);
        return $value === '' ? null : $value;
    }

    private function text(string $value, int $maxLength): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');
        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    private function integer(mixed $value, string $name): int
    {
        if (is_int($value)) return $value;
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) return (int)$value;
        throw new InvalidArgumentException($name . ' must be an integer.');
    }

    private function positiveInteger(mixed $value, string $name): int
    {
        $value = $this->integer($value, $name);
        if ($value < 1) throw new InvalidArgumentException($name . ' must be positive.');
        return $value;
    }

    private function operationTimestamp(mixed $value): array
    {
        $provided = trim((string)($value ?? ''));
        if ($provided === '') return [$this->now(), null];
        $timestamp = $this->timestamp($provided);
        return [$timestamp, $timestamp];
    }

    private function nullableTimestamp(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $this->timestamp($value);
    }

    private function timestamp(mixed $value): string
    {
        try {
            return (new DateTimeImmutable((string)$value))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s.u');
        } catch (Throwable) {
            throw new InvalidArgumentException('Invalid UTC timestamp.');
        }
    }

    private function now(): string
    {
        if ($this->clock !== null) return $this->timestamp(($this->clock)());
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null) return null;
        $json = LedgerIntegrity::canonicalJson($value);
        if (strlen($json) > 65535) throw new InvalidArgumentException('metadata is too large.');
        return $json;
    }

    private function stableId(string $prefix, string $value): string
    {
        return $prefix . '_' . substr(hash('sha256', $prefix . '|' . $value), 0, 48);
    }

    private function forUpdate(DatabaseConnectionInterface $db): string
    {
        return $db->driver() === 'sqlite' ? '' : ' FOR UPDATE';
    }
}
