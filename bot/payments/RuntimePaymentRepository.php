<?php
declare(strict_types=1);

final class RuntimePaymentRepository
{
    private RuntimeStorageRouter $router;
    private StorageAdapterInterface $storage;
    private DatabaseConnectionInterface $database;

    public function __construct(
        private array $config,
        ?RuntimeStorageRouter $router = null,
        ?StorageAdapterInterface $storage = null,
        ?DatabaseConnectionInterface $database = null
    ) {
        $this->router = $router ?? new RuntimeStorageRouter($config);
        $this->storage = $storage ?? StorageFactory::create($config);
        $this->database = $database ?? $this->connect();
    }

    public function bootstrapCurrentJson(): array
    {
        $this->assertDatabaseRoute();
        $archivePreview = $this->archiveDelta()->preview();
        if ((int)($archivePreview['conflict_total'] ?? 0) > 0
            || (int)($archivePreview['unmanaged_total'] ?? 0) > 0
            || !empty($archivePreview['blocking_reasons'])) {
            throw new RuntimeException(
                'Payment archive baseline is not ready: '
                . implode('; ', array_map('strval', (array)($archivePreview['blocking_reasons'] ?? [])))
            );
        }

        $archive = null;
        if ((int)($archivePreview['planned_create_total'] ?? 0) > 0
            || !empty($archivePreview['requires_metadata_advance'])) {
            $archive = $this->archiveDelta()->run();
        }

        $runtime = $this->synchronizeCurrentJson();
        $runtime['archive_baseline'] = $archive === null
            ? [
                'ok' => true,
                'action' => 'unchanged',
                'source_fingerprint' => (string)($archivePreview['source_fingerprint'] ?? ''),
                'created_counts' => [],
            ]
            : [
                'ok' => !empty($archive['ok']),
                'action' => 'advanced',
                'source_fingerprint' => (string)($archive['source_fingerprint'] ?? ''),
                'created_counts' => $archive['created_counts'] ?? [],
            ];
        return $runtime;
    }

    public function synchronizeCurrentJson(): array
    {
        $this->assertDatabaseRoute();
        $snapshot = $this->snapshot();

        $economy = (new RuntimeEconomyRepository(
            $this->config,
            $this->router,
            $this->database
        ))->synchronize($snapshot);
        $mirror = $this->synchronizePayments($snapshot);
        $audit = $this->auditParity($snapshot);
        if (empty($audit['ok'])) {
            throw new RuntimeException(
                'Payment DB runtime reconciliation failed: '
                . implode('; ', array_map('strval', (array)($audit['blockers'] ?? [])))
            );
        }

        return [
            'ok' => true,
            'action' => 'synchronize',
            'storage_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'economy' => $this->compactEconomy($economy),
            'payments' => $mirror,
            'audit' => $audit,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    public function paymentRecords(): array
    {
        $this->assertDatabaseRoute();
        $rows = $this->database->fetchAll(
            'SELECT payment_ref, source_position, payload_json, payload_sha256 '
            . 'FROM mgw_runtime_payments ORDER BY source_position, payment_ref'
        );

        $records = [];
        $seenRefs = [];
        $seenPositions = [];
        foreach ($rows as $row) {
            $paymentRef = trim((string)($row['payment_ref'] ?? ''));
            $position = (int)($row['source_position'] ?? -1);
            $payloadJson = (string)($row['payload_json'] ?? '');
            $payloadHash = strtolower(trim((string)($row['payload_sha256'] ?? '')));
            if ($paymentRef === '' || $position < 0 || isset($seenRefs[$paymentRef]) || isset($seenPositions[$position])) {
                throw new RuntimeException('Payment DB runtime contains duplicate or invalid ordering metadata.');
            }
            if (preg_match('/^[a-f0-9]{64}$/', $payloadHash) !== 1
                || !hash_equals($payloadHash, hash('sha256', $payloadJson))) {
                throw new RuntimeException('Payment DB runtime payload integrity verification failed.');
            }
            try {
                $decoded = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new RuntimeException('Payment DB runtime payload is invalid.', 0, $error);
            }
            if (!is_array($decoded) || trim((string)($decoded['id'] ?? '')) !== $paymentRef) {
                throw new RuntimeException('Payment DB runtime payload identity verification failed.');
            }
            $seenRefs[$paymentRef] = true;
            $seenPositions[$position] = true;
            $records[] = $decoded;
        }
        return $records;
    }

    public function auditParity(?array $jsonSnapshot = null): array
    {
        $this->assertDatabaseRoute();
        $snapshot = $jsonSnapshot ?? $this->snapshot();
        $source = $this->sourcePayments($snapshot);
        $rows = $this->database->fetchAll(
            'SELECT payment_ref, source_position, payload_json, payload_sha256 '
            . 'FROM mgw_runtime_payments ORDER BY source_position, payment_ref'
        );

        $databasePayments = [];
        $positionOwners = [];
        $duplicateDatabaseCount = 0;
        $duplicatePositionCount = 0;
        foreach ($rows as $row) {
            $paymentRef = trim((string)($row['payment_ref'] ?? ''));
            $position = (int)($row['source_position'] ?? -1);
            if ($paymentRef === '' || isset($databasePayments[$paymentRef])) {
                $duplicateDatabaseCount++;
                continue;
            }
            if ($position < 0 || isset($positionOwners[$position])) {
                $duplicatePositionCount++;
            } else {
                $positionOwners[$position] = $paymentRef;
            }
            $databasePayments[$paymentRef] = $row;
        }

        $mismatchCount = 0;
        $missingCount = 0;
        $corruptedCount = 0;
        $sourceParts = [];
        $databaseParts = [];

        foreach ($source['payments'] as $paymentRef => $item) {
            $position = (int)$item['position'];
            $payment = $item['payment'];
            $sourceJson = LedgerIntegrity::canonicalJson($payment);
            $sourceHash = hash('sha256', $sourceJson);
            $sourceParts[$position] = $position . ':' . $paymentRef . ':' . $sourceHash;

            $row = $databasePayments[$paymentRef] ?? null;
            if (!is_array($row)) {
                $missingCount++;
                $mismatchCount++;
                continue;
            }

            $storedPosition = (int)($row['source_position'] ?? -1);
            $storedJson = (string)($row['payload_json'] ?? '');
            $storedHash = strtolower(trim((string)($row['payload_sha256'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/', $storedHash) !== 1
                || !hash_equals($storedHash, hash('sha256', $storedJson))) {
                $corruptedCount++;
                $mismatchCount++;
                continue;
            }

            try {
                $decoded = json_decode($storedJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $corruptedCount++;
                $mismatchCount++;
                continue;
            }
            if (!is_array($decoded)) {
                $corruptedCount++;
                $mismatchCount++;
                continue;
            }

            $databaseHash = hash('sha256', LedgerIntegrity::canonicalJson($decoded));
            if ($storedPosition >= 0) {
                $databaseParts[$storedPosition] = $storedPosition . ':' . $paymentRef . ':' . $databaseHash;
            }
            if ($storedPosition !== $position || !hash_equals($sourceHash, $databaseHash)) $mismatchCount++;
        }

        $extraCount = 0;
        foreach (array_keys($databasePayments) as $paymentRef) {
            if (!isset($source['payments'][$paymentRef])) $extraCount++;
        }
        if ($extraCount > 0) $mismatchCount += $extraCount;
        if ($duplicateDatabaseCount > 0 || $duplicatePositionCount > 0) {
            $mismatchCount += $duplicateDatabaseCount + $duplicatePositionCount;
        }

        ksort($sourceParts, SORT_NUMERIC);
        ksort($databaseParts, SORT_NUMERIC);
        $sourceFingerprint = hash('sha256', implode("\n", $sourceParts));
        $databaseFingerprint = hash('sha256', implode("\n", $databaseParts));
        $economy = (new RuntimeEconomyRepository(
            $this->config,
            $this->router,
            $this->database
        ))->auditParity($snapshot);

        $blockers = [];
        if ($source['invalid_count'] > 0) {
            $blockers[] = 'Current JSON payments contain missing or duplicate stable IDs.';
        }
        if ($duplicateDatabaseCount > 0 || $duplicatePositionCount > 0) {
            $blockers[] = 'Database payment mirror contains duplicate or invalid ordering metadata.';
        }
        if ($mismatchCount > 0 || !hash_equals($sourceFingerprint, $databaseFingerprint)) {
            $blockers[] = 'Database payments differ from the current JSON payments.';
        }
        foreach ((array)($economy['blockers'] ?? []) as $reason) {
            $blockers[] = (string)$reason;
        }
        $blockers = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $blockers
        ), static fn(string $value): bool => $value !== '')));

        return [
            'ok' => $blockers === [],
            'read_only' => true,
            'source_payment_count' => count($source['payments']),
            'database_payment_count' => count($databasePayments),
            'invalid_source_count' => $source['invalid_count'],
            'mismatch_count' => $mismatchCount,
            'missing_count' => $missingCount,
            'extra_count' => $extraCount,
            'duplicate_database_count' => $duplicateDatabaseCount,
            'duplicate_position_count' => $duplicatePositionCount,
            'corrupted_count' => $corruptedCount,
            'json_payment_fingerprint' => $sourceFingerprint,
            'database_payment_fingerprint' => $databaseFingerprint,
            'economy' => [
                'ok' => !empty($economy['ok']),
                'shadow_delta_count' => (int)($economy['shadow_delta_count'] ?? 0),
                'planned_delta_count' => (int)($economy['reconciliation']['planned_delta_count'] ?? 0),
                'integrity_failure_count' => (int)($economy['reconciliation']['integrity_failure_count'] ?? 0),
                'active_reservation_count' => (int)($economy['reconciliation']['active_reservation_count'] ?? 0),
            ],
            'blockers' => $blockers,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function synchronizePayments(array $snapshot): array
    {
        $source = $this->sourcePayments($snapshot);
        if ($source['invalid_count'] > 0) {
            throw new RuntimeException('Payment JSON contains records without unique stable IDs.');
        }

        $existingRows = $this->database->fetchAll(
            'SELECT payment_ref, source_position, payload_sha256 FROM mgw_runtime_payments'
        );
        $existing = [];
        foreach ($existingRows as $row) {
            $paymentRef = trim((string)($row['payment_ref'] ?? ''));
            if ($paymentRef !== '') {
                $existing[$paymentRef] = [
                    'source_position' => (int)($row['source_position'] ?? -1),
                    'payload_sha256' => strtolower(trim((string)($row['payload_sha256'] ?? ''))),
                ];
            }
        }

        $inserted = 0;
        $updated = 0;
        $unchanged = 0;
        $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $source,
            $existing,
            &$inserted,
            &$updated,
            &$unchanged
        ): void {
            foreach ($source['payments'] as $paymentRef => $item) {
                $position = (int)$item['position'];
                $payment = $item['payment'];
                $payloadJson = LedgerIntegrity::canonicalJson($payment);
                $payloadHash = hash('sha256', $payloadJson);
                $current = $existing[$paymentRef] ?? null;
                if (is_array($current)
                    && (int)$current['source_position'] === $position
                    && hash_equals((string)$current['payload_sha256'], $payloadHash)) {
                    $unchanged++;
                    continue;
                }

                $row = $this->runtimeRow($paymentRef, $position, $payment, $payloadJson, $payloadHash);
                if (!is_array($current)) {
                    $database->execute(
                        'INSERT INTO mgw_runtime_payments (
                            payment_ref, account_ref, legacy_user_id, source_position, provider,
                            status_raw, room_raw, coin_amount, amount_rub, currency, balance_applied,
                            payload_json, payload_sha256, source_created_at_utc, source_updated_at_utc,
                            source_decided_at_utc, synced_at_utc
                         ) VALUES (
                            :payment_ref, :account_ref, :legacy_user_id, :source_position, :provider,
                            :status_raw, :room_raw, :coin_amount, :amount_rub, :currency, :balance_applied,
                            :payload_json, :payload_sha256, :source_created_at_utc, :source_updated_at_utc,
                            :source_decided_at_utc, :synced_at_utc
                         )',
                        $row
                    );
                    $inserted++;
                    continue;
                }

                $database->execute(
                    'UPDATE mgw_runtime_payments SET
                        account_ref = :account_ref,
                        legacy_user_id = :legacy_user_id,
                        source_position = :source_position,
                        provider = :provider,
                        status_raw = :status_raw,
                        room_raw = :room_raw,
                        coin_amount = :coin_amount,
                        amount_rub = :amount_rub,
                        currency = :currency,
                        balance_applied = :balance_applied,
                        payload_json = :payload_json,
                        payload_sha256 = :payload_sha256,
                        source_created_at_utc = :source_created_at_utc,
                        source_updated_at_utc = :source_updated_at_utc,
                        source_decided_at_utc = :source_decided_at_utc,
                        synced_at_utc = :synced_at_utc
                     WHERE payment_ref = :payment_ref',
                    $row
                );
                $updated++;
            }
        });

        $extraCount = 0;
        foreach (array_keys($existing) as $paymentRef) {
            if (!isset($source['payments'][$paymentRef])) $extraCount++;
        }
        if ($extraCount > 0) {
            throw new RuntimeException('Payment DB mirror contains records missing from JSON rollback storage.');
        }

        return [
            'source_payment_count' => count($source['payments']),
            'inserted_count' => $inserted,
            'updated_count' => $updated,
            'unchanged_count' => $unchanged,
            'deleted_count' => 0,
        ];
    }

    private function runtimeRow(
        string $paymentRef,
        int $position,
        array $payment,
        string $payloadJson,
        string $payloadHash
    ): array {
        $legacyUserId = trim((string)($payment['user_id'] ?? ''));
        if ($legacyUserId === '') throw new RuntimeException('Payment record has no legacy user identity.');
        $ownership = $this->database->fetchAll(
            "SELECT account_ref FROM mgw_account_ownership
             WHERE legacy_user_id = :legacy_user_id AND ownership_status = 'active'",
            ['legacy_user_id' => $legacyUserId]
        );
        if (count($ownership) !== 1) {
            throw new RuntimeException('Payment account ownership is missing or ambiguous.');
        }

        $updatedAt = $payment['updated_at'] ?? $payment['created_at'] ?? null;
        $decidedAt = $payment['applied_at']
            ?? $payment['paid_at']
            ?? $payment['rejected_at']
            ?? $payment['cancelled_at']
            ?? null;
        $room = trim((string)($payment['room'] ?? 'gold'));
        if (!in_array($room, ['match', 'gold'], true)) $room = 'gold';

        return [
            'payment_ref' => $paymentRef,
            'account_ref' => (string)$ownership[0]['account_ref'],
            'legacy_user_id' => $legacyUserId,
            'source_position' => $position,
            'provider' => $this->nullableText($payment['provider'] ?? null, 64),
            'status_raw' => mb_substr(trim((string)($payment['status'] ?? 'draft')), 0, 64),
            'room_raw' => $room,
            'coin_amount' => (int)($payment['coins'] ?? 0),
            'amount_rub' => (int)($payment['amount_rub'] ?? $payment['price'] ?? 0),
            'currency' => mb_substr(strtoupper(trim((string)($payment['currency'] ?? 'RUB'))), 0, 8),
            'balance_applied' => !empty($payment['balance_applied']) ? 1 : 0,
            'payload_json' => $payloadJson,
            'payload_sha256' => $payloadHash,
            'source_created_at_utc' => $this->timestamp($payment['created_at'] ?? null),
            'source_updated_at_utc' => $this->timestamp($updatedAt),
            'source_decided_at_utc' => $this->timestamp($decidedAt),
            'synced_at_utc' => $this->now(),
        ];
    }

    private function sourcePayments(array $snapshot): array
    {
        $payments = [];
        $invalid = 0;
        foreach (is_array($snapshot['payments'] ?? null) ? $snapshot['payments'] : [] as $position => $payment) {
            if (!is_array($payment)) {
                $invalid++;
                continue;
            }
            $paymentRef = trim((string)($payment['id'] ?? ''));
            if ($paymentRef === '' || isset($payments[$paymentRef])) {
                $invalid++;
                continue;
            }
            $payments[$paymentRef] = [
                'position' => (int)$position,
                'payment' => $payment,
            ];
        }
        return ['payments' => $payments, 'invalid_count' => $invalid];
    }

    private function archiveDelta(): LegacyFinancialArchiveDeltaService
    {
        return new LegacyFinancialArchiveDeltaService(
            $this->database,
            new LegacyFinancialArchiveImportService(
                $this->storage,
                $this->database,
                new LegacyFinancialStatusNormalizer()
            )
        );
    }

    private function snapshot(): array
    {
        if ($this->storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
            throw new RuntimeException('Payment DB runtime requires JSON rollback storage.');
        }
        $snapshot = $this->storage->readOnly(static fn(array $data): array => $data);
        if (!is_array($snapshot)) throw new RuntimeException('Payment JSON snapshot is invalid.');
        return $snapshot;
    }

    private function assertDatabaseRoute(): void
    {
        foreach (['accounts', 'economy', 'history', 'payments'] as $module) {
            if ($this->router->routeFor($module) !== RuntimeStorageRouter::DRIVER_DATABASE) {
                throw new RuntimeException('Payment DB runtime requires accounts, economy, history and payments routing.');
            }
        }
        $environment = strtolower(trim((string)($this->config['environment'] ?? 'production')));
        if (!in_array($environment, ['staging', 'local'], true)) {
            throw new RuntimeException('Payment DB runtime is forbidden outside staging/local.');
        }
    }

    private function connect(): DatabaseConnectionInterface
    {
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) throw new RuntimeException('Payment DB runtime requires an enabled database.');
        return PdoConnectionFactory::create($databaseConfig);
    }

    private function compactEconomy(array $report): array
    {
        return [
            'ok' => !empty($report['ok']),
            'planned_delta_count' => (int)($report['delta']['planned_delta_count'] ?? 0),
            'applied_delta_count' => (int)($report['delta']['applied_delta_count'] ?? 0),
            'replayed_delta_count' => (int)($report['delta']['replayed_delta_count'] ?? 0),
            'source_totals' => $report['delta']['source_totals'] ?? [],
            'database_totals' => $report['delta']['database_totals'] ?? [],
            'integrity_failure_count' => (int)($report['reconciliation']['integrity_failure_count'] ?? 0),
            'active_reservation_count' => (int)($report['reconciliation']['active_reservation_count'] ?? 0),
        ];
    }

    private function nullableText(mixed $value, int $limit): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function timestamp(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') return null;
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        } catch (Throwable) {
            return null;
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
