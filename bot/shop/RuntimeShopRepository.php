<?php
declare(strict_types=1);

final class RuntimeShopRepository
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
                'Shop archive baseline is not ready: '
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
        $mirror = $this->synchronizeOrders($snapshot);
        $audit = $this->auditParity($snapshot);
        if (empty($audit['ok'])) {
            throw new RuntimeException(
                'Shop DB runtime reconciliation failed: '
                . implode('; ', array_map('strval', (array)($audit['blockers'] ?? [])))
            );
        }

        return [
            'ok' => true,
            'action' => 'synchronize',
            'storage_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'economy' => $this->compactEconomy($economy),
            'orders' => $mirror,
            'audit' => $audit,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    public function auditParity(?array $jsonSnapshot = null): array
    {
        $this->assertDatabaseRoute();
        $snapshot = $jsonSnapshot ?? $this->snapshot();
        $source = $this->sourceOrders($snapshot);
        $rows = $this->database->fetchAll(
            'SELECT order_ref, payload_json, payload_sha256 FROM mgw_runtime_shop_orders ORDER BY order_ref'
        );

        $databaseOrders = [];
        $duplicateDatabaseCount = 0;
        foreach ($rows as $row) {
            $orderRef = trim((string)($row['order_ref'] ?? ''));
            if ($orderRef === '' || isset($databaseOrders[$orderRef])) {
                $duplicateDatabaseCount++;
                continue;
            }
            $databaseOrders[$orderRef] = $row;
        }

        $mismatchCount = 0;
        $missingCount = 0;
        $corruptedCount = 0;
        $sourceParts = [];
        $databaseParts = [];

        foreach ($source['orders'] as $orderRef => $order) {
            $sourceJson = LedgerIntegrity::canonicalJson($order);
            $sourceHash = hash('sha256', $sourceJson);
            $sourceParts[] = $orderRef . ':' . $sourceHash;

            $row = $databaseOrders[$orderRef] ?? null;
            if (!is_array($row)) {
                $missingCount++;
                $mismatchCount++;
                continue;
            }

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
            $databaseParts[] = $orderRef . ':' . $databaseHash;
            if (!hash_equals($sourceHash, $databaseHash)) $mismatchCount++;
        }

        $extraCount = 0;
        foreach (array_keys($databaseOrders) as $orderRef) {
            if (!isset($source['orders'][$orderRef])) $extraCount++;
        }
        if ($extraCount > 0) $mismatchCount += $extraCount;

        sort($sourceParts, SORT_STRING);
        sort($databaseParts, SORT_STRING);
        $sourceFingerprint = hash('sha256', implode("\n", $sourceParts));
        $databaseFingerprint = hash('sha256', implode("\n", $databaseParts));
        $economy = (new RuntimeEconomyRepository(
            $this->config,
            $this->router,
            $this->database
        ))->auditParity($snapshot);

        $blockers = [];
        if ($source['invalid_count'] > 0) {
            $blockers[] = 'Current JSON shop orders contain missing or duplicate stable IDs.';
        }
        if ($duplicateDatabaseCount > 0) {
            $blockers[] = 'Database shop mirror contains duplicate or invalid order references.';
        }
        if ($mismatchCount > 0 || !hash_equals($sourceFingerprint, $databaseFingerprint)) {
            $blockers[] = 'Database shop orders differ from the current JSON shop orders.';
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
            'source_order_count' => count($source['orders']),
            'database_order_count' => count($databaseOrders),
            'invalid_source_count' => $source['invalid_count'],
            'mismatch_count' => $mismatchCount,
            'missing_count' => $missingCount,
            'extra_count' => $extraCount,
            'duplicate_database_count' => $duplicateDatabaseCount,
            'corrupted_count' => $corruptedCount,
            'json_shop_fingerprint' => $sourceFingerprint,
            'database_shop_fingerprint' => $databaseFingerprint,
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

    private function synchronizeOrders(array $snapshot): array
    {
        $source = $this->sourceOrders($snapshot);
        if ($source['invalid_count'] > 0) {
            throw new RuntimeException('Shop JSON contains orders without unique stable IDs.');
        }

        $existingRows = $this->database->fetchAll(
            'SELECT order_ref, payload_sha256 FROM mgw_runtime_shop_orders'
        );
        $existing = [];
        foreach ($existingRows as $row) {
            $orderRef = trim((string)($row['order_ref'] ?? ''));
            if ($orderRef !== '') $existing[$orderRef] = strtolower(trim((string)($row['payload_sha256'] ?? '')));
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
            foreach ($source['orders'] as $orderRef => $order) {
                $payloadJson = LedgerIntegrity::canonicalJson($order);
                $payloadHash = hash('sha256', $payloadJson);
                if (isset($existing[$orderRef]) && hash_equals($existing[$orderRef], $payloadHash)) {
                    $unchanged++;
                    continue;
                }

                $row = $this->runtimeRow($orderRef, $order, $payloadJson, $payloadHash);
                if (!isset($existing[$orderRef])) {
                    $database->execute(
                        'INSERT INTO mgw_runtime_shop_orders (
                            order_ref, account_ref, legacy_user_id, client_request_id, status_raw,
                            refund_done, gold_amount, item_id, denomination_id, payload_json,
                            payload_sha256, source_created_at_utc, source_updated_at_utc, synced_at_utc
                         ) VALUES (
                            :order_ref, :account_ref, :legacy_user_id, :client_request_id, :status_raw,
                            :refund_done, :gold_amount, :item_id, :denomination_id, :payload_json,
                            :payload_sha256, :source_created_at_utc, :source_updated_at_utc, :synced_at_utc
                         )',
                        $row
                    );
                    $inserted++;
                    continue;
                }

                $database->execute(
                    'UPDATE mgw_runtime_shop_orders SET
                        account_ref = :account_ref,
                        legacy_user_id = :legacy_user_id,
                        client_request_id = :client_request_id,
                        status_raw = :status_raw,
                        refund_done = :refund_done,
                        gold_amount = :gold_amount,
                        item_id = :item_id,
                        denomination_id = :denomination_id,
                        payload_json = :payload_json,
                        payload_sha256 = :payload_sha256,
                        source_created_at_utc = :source_created_at_utc,
                        source_updated_at_utc = :source_updated_at_utc,
                        synced_at_utc = :synced_at_utc
                     WHERE order_ref = :order_ref',
                    $row
                );
                $updated++;
            }
        });

        $extraCount = 0;
        foreach (array_keys($existing) as $orderRef) {
            if (!isset($source['orders'][$orderRef])) $extraCount++;
        }
        if ($extraCount > 0) {
            throw new RuntimeException('Shop DB mirror contains orders missing from JSON rollback storage.');
        }

        return [
            'source_order_count' => count($source['orders']),
            'inserted_count' => $inserted,
            'updated_count' => $updated,
            'unchanged_count' => $unchanged,
            'deleted_count' => 0,
        ];
    }

    private function runtimeRow(string $orderRef, array $order, string $payloadJson, string $payloadHash): array
    {
        $legacyUserId = trim((string)($order['user_id'] ?? ''));
        if ($legacyUserId === '') throw new RuntimeException('Shop order has no legacy user identity.');
        $ownership = $this->database->fetchAll(
            "SELECT account_ref FROM mgw_account_ownership
             WHERE legacy_user_id = :legacy_user_id AND ownership_status = 'active'",
            ['legacy_user_id' => $legacyUserId]
        );
        if (count($ownership) !== 1) {
            throw new RuntimeException('Shop order account ownership is missing or ambiguous.');
        }

        $requestId = trim((string)($order['client_request_id'] ?? ''));
        $updatedAt = $order['updated_at']
            ?? $order['completed_at']
            ?? $order['rejected_at']
            ?? $order['refunded_at']
            ?? $order['created_at']
            ?? null;

        return [
            'order_ref' => $orderRef,
            'account_ref' => (string)$ownership[0]['account_ref'],
            'legacy_user_id' => $legacyUserId,
            'client_request_id' => $requestId !== '' ? $requestId : null,
            'status_raw' => mb_substr(trim((string)($order['status'] ?? 'pending')), 0, 64),
            'refund_done' => !empty($order['refund_done']) ? 1 : 0,
            'gold_amount' => (int)($order['gold_cost'] ?? $order['amount'] ?? 0),
            'item_id' => $this->nullableText($order['item_id'] ?? ($order['prize_snapshot']['item_id'] ?? null), 191),
            'denomination_id' => $this->nullableText(
                $order['denomination_id'] ?? ($order['prize_snapshot']['denomination_id'] ?? null),
                191
            ),
            'payload_json' => $payloadJson,
            'payload_sha256' => $payloadHash,
            'source_created_at_utc' => $this->timestamp($order['created_at'] ?? null),
            'source_updated_at_utc' => $this->timestamp($updatedAt),
            'synced_at_utc' => $this->now(),
        ];
    }

    private function sourceOrders(array $snapshot): array
    {
        $orders = [];
        $invalid = 0;
        foreach (is_array($snapshot['shop_orders'] ?? null) ? $snapshot['shop_orders'] : [] as $order) {
            if (!is_array($order)) {
                $invalid++;
                continue;
            }
            $orderRef = trim((string)($order['id'] ?? ''));
            if ($orderRef === '' || isset($orders[$orderRef])) {
                $invalid++;
                continue;
            }
            $orders[$orderRef] = $order;
        }
        ksort($orders, SORT_STRING);
        return ['orders' => $orders, 'invalid_count' => $invalid];
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
            throw new RuntimeException('Shop DB runtime requires JSON rollback storage.');
        }
        $snapshot = $this->storage->readOnly(static fn(array $data): array => $data);
        if (!is_array($snapshot)) throw new RuntimeException('Shop JSON snapshot is invalid.');
        return $snapshot;
    }

    private function assertDatabaseRoute(): void
    {
        foreach (['accounts', 'economy', 'history', 'shop'] as $module) {
            if ($this->router->routeFor($module) !== RuntimeStorageRouter::DRIVER_DATABASE) {
                throw new RuntimeException('Shop DB runtime requires accounts, economy, history and shop routing.');
            }
        }
        $environment = strtolower(trim((string)($this->config['environment'] ?? 'production')));
        if (!in_array($environment, ['staging', 'local'], true)) {
            throw new RuntimeException('Shop DB runtime is forbidden outside staging/local.');
        }
    }

    private function connect(): DatabaseConnectionInterface
    {
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) throw new RuntimeException('Shop DB runtime requires an enabled database.');
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
