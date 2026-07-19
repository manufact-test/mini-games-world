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

    public function synchronizeCurrentJson(): array
    {
        $this->assertDatabaseRoute();
        $snapshot = $this->snapshot();

        $economy = (new RuntimeEconomyRepository(
            $this->config,
            $this->router,
            $this->database
        ))->synchronize($snapshot);

        $archive = $this->archiveDelta()->run();
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
            'financial_archive' => [
                'ok' => !empty($archive['ok']),
                'metadata_advanced' => !empty($archive['metadata_advanced']),
                'created_counts' => $archive['created_counts'] ?? [],
                'unchanged_counts' => $archive['unchanged_counts'] ?? [],
                'source_fingerprint' => (string)($archive['source_fingerprint'] ?? ''),
            ],
            'audit' => $audit,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    public function auditParity(?array $jsonSnapshot = null): array
    {
        $this->assertDatabaseRoute();
        $snapshot = $jsonSnapshot ?? $this->snapshot();
        $orders = array_values(array_filter(
            is_array($snapshot['shop_orders'] ?? null) ? $snapshot['shop_orders'] : [],
            'is_array'
        ));

        $rows = $this->database->fetchAll(
            "SELECT source_index, snapshot_json, snapshot_sha256
             FROM mgw_legacy_shop_orders
             WHERE source_file = 'shop_orders.json'
             ORDER BY source_index"
        );

        $byIndex = [];
        $duplicateIndexCount = 0;
        foreach ($rows as $row) {
            $index = (int)($row['source_index'] ?? -1);
            if ($index < 0 || isset($byIndex[$index])) {
                $duplicateIndexCount++;
                continue;
            }
            $byIndex[$index] = $row;
        }

        $mismatchCount = 0;
        $missingCount = 0;
        $corruptedCount = 0;
        $sourceParts = [];
        $databaseParts = [];

        foreach ($orders as $index => $order) {
            $sourceJson = LedgerIntegrity::canonicalJson($order);
            $sourceHash = hash('sha256', $sourceJson);
            $sourceParts[] = $index . ':' . $sourceHash;

            $row = $byIndex[$index] ?? null;
            if (!is_array($row)) {
                $missingCount++;
                $mismatchCount++;
                continue;
            }

            $storedJson = (string)($row['snapshot_json'] ?? '');
            $storedHash = strtolower(trim((string)($row['snapshot_sha256'] ?? '')));
            $verifiedHash = preg_match('/^[a-f0-9]{64}$/', $storedHash) === 1
                && hash_equals($storedHash, hash('sha256', $storedJson));
            if (!$verifiedHash) {
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

            $databaseJson = LedgerIntegrity::canonicalJson($decoded);
            $databaseHash = hash('sha256', $databaseJson);
            $databaseParts[] = $index . ':' . $databaseHash;
            if (!hash_equals($sourceHash, $databaseHash)) $mismatchCount++;
        }

        $extraCount = max(0, count($byIndex) - count($orders));
        if ($extraCount > 0) $mismatchCount += $extraCount;
        sort($sourceParts, SORT_STRING);
        sort($databaseParts, SORT_STRING);
        $sourceFingerprint = hash('sha256', implode("\n", $sourceParts));
        $databaseFingerprint = hash('sha256', implode("\n", $databaseParts));

        $archive = $this->archiveDelta()->preview();
        $economy = (new RuntimeEconomyRepository(
            $this->config,
            $this->router,
            $this->database
        ))->auditParity($snapshot);

        $blockers = [];
        if ($mismatchCount > 0 || !hash_equals($sourceFingerprint, $databaseFingerprint)) {
            $blockers[] = 'Database shop orders differ from the current JSON shop orders.';
        }
        if ((int)($archive['planned_create_total'] ?? 0) > 0
            || (int)($archive['conflict_total'] ?? 0) > 0
            || (int)($archive['unmanaged_total'] ?? 0) > 0
            || !empty($archive['requires_metadata_advance'])) {
            $blockers[] = 'Current JSON financial archive still has unapplied shop changes.';
        }
        foreach ((array)($archive['blocking_reasons'] ?? []) as $reason) {
            $blockers[] = (string)$reason;
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
            'source_order_count' => count($orders),
            'database_order_count' => count($byIndex),
            'mismatch_count' => $mismatchCount,
            'missing_count' => $missingCount,
            'extra_count' => $extraCount,
            'duplicate_index_count' => $duplicateIndexCount,
            'corrupted_count' => $corruptedCount,
            'json_shop_fingerprint' => $sourceFingerprint,
            'database_shop_fingerprint' => $databaseFingerprint,
            'archive' => [
                'ready' => !empty($archive['ready']),
                'planned_create_total' => (int)($archive['planned_create_total'] ?? 0),
                'conflict_total' => (int)($archive['conflict_total'] ?? 0),
                'unmanaged_total' => (int)($archive['unmanaged_total'] ?? 0),
                'requires_metadata_advance' => !empty($archive['requires_metadata_advance']),
                'source_fingerprint' => (string)($archive['source_fingerprint'] ?? ''),
            ],
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
}
