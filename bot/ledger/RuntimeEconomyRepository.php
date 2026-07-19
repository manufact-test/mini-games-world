<?php
declare(strict_types=1);

final class RuntimeEconomyRepository
{
    private RuntimeStorageRouter $router;
    private ?DatabaseConnectionInterface $connection;

    public function __construct(
        private array $config,
        ?RuntimeStorageRouter $router = null,
        ?DatabaseConnectionInterface $database = null
    ) {
        $this->router = $router ?? new RuntimeStorageRouter($config);
        $this->connection = $database;
    }

    public function synchronize(array $jsonSnapshot): array
    {
        $this->assertDatabaseRoute();
        $database = $this->database();
        $storage = new RuntimeEconomySnapshotStorage($jsonSnapshot);
        $ledger = new LedgerWriteService($database);
        $integrity = new LedgerIntegrityVerifier($database);
        $shadow = new LegacyEconomyShadowSyncService($storage, $database);
        $shadowReport = $shadow->run();
        $bootstrapReport = (new RuntimeEconomyBalanceBootstrapService($database, $ledger))->ensureFromShadow();
        $delta = new LegacyEconomyDeltaImportService($database, $ledger, $integrity);
        $deltaReport = $delta->run();
        $reconciliation = (new LegacyEconomyRuntimeReconciliationService(
            $database,
            $delta,
            $integrity
        ))->preview();

        if (empty($reconciliation['ready'])) {
            throw new RuntimeException(
                'Economy DB runtime reconciliation failed: '
                . implode('; ', array_map('strval', (array)($reconciliation['blocking_reasons'] ?? [])))
            );
        }

        return [
            'ok' => true,
            'action' => 'synchronize',
            'storage_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'shadow' => $this->compactShadow($shadowReport),
            'balance_bootstrap' => $this->compactBootstrap($bootstrapReport),
            'delta' => $this->compactDelta($deltaReport),
            'reconciliation' => $this->compactReconciliation($reconciliation),
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    public function auditParity(array $jsonSnapshot): array
    {
        $this->assertDatabaseRoute();
        $database = $this->database();
        $storage = new RuntimeEconomySnapshotStorage($jsonSnapshot);
        $shadowReport = (new LegacyEconomyShadowSyncService($storage, $database))->preview();
        $ledger = new LedgerWriteService($database);
        $integrity = new LedgerIntegrityVerifier($database);
        $delta = new LegacyEconomyDeltaImportService($database, $ledger, $integrity);
        $reconciliation = (new LegacyEconomyRuntimeReconciliationService(
            $database,
            $delta,
            $integrity
        ))->preview();

        $shadowDeltaCount = $this->shadowDeltaCount($shadowReport);
        $blockers = [];
        if ($shadowDeltaCount > 0) {
            $blockers[] = 'Current JSON economy shadow differs from the database shadow.';
        }
        foreach ((array)($reconciliation['blocking_reasons'] ?? []) as $reason) {
            $blockers[] = (string)$reason;
        }
        $blockers = array_values(array_unique(array_filter($blockers, static fn(string $value): bool => $value !== '')));

        return [
            'ok' => $blockers === [],
            'read_only' => true,
            'storage_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'source_fingerprint' => (string)($shadowReport['source_fingerprint'] ?? ''),
            'shadow_delta_count' => $shadowDeltaCount,
            'shadow' => $this->compactShadow($shadowReport),
            'reconciliation' => $this->compactReconciliation($reconciliation),
            'blockers' => $blockers,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function assertDatabaseRoute(): void
    {
        if (!$this->router->enabled()
            || $this->router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE
            || $this->router->routeFor('economy') !== RuntimeStorageRouter::DRIVER_DATABASE) {
            throw new RuntimeException('Economy DB runtime requires accounts and economy routing.');
        }
    }

    private function database(): DatabaseConnectionInterface
    {
        if ($this->connection !== null) return $this->connection;
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Economy DB runtime requires an enabled database.');
        }
        return $this->connection = PdoConnectionFactory::create($databaseConfig);
    }

    private function shadowDeltaCount(array $report): int
    {
        $count = 0;
        foreach ((array)($report['sections'] ?? []) as $section) {
            if (!is_array($section)) continue;
            foreach (['inserted_count', 'updated_count', 'repair_count', 'deleted_count'] as $field) {
                $count += max(0, (int)($section[$field] ?? 0));
            }
        }
        return $count;
    }

    private function compactShadow(array $report): array
    {
        $integrity = is_array($report['shadow_integrity'] ?? null)
            ? $report['shadow_integrity']
            : [];
        return [
            'source_fingerprint' => (string)($report['source_fingerprint'] ?? ''),
            'sections' => $report['sections'] ?? [],
            'integrity_ok' => (int)($integrity['corrupted_count'] ?? 0) === 0,
            'checked_count' => (int)($integrity['checked_count'] ?? 0),
            'corrupted_count' => (int)($integrity['corrupted_count'] ?? 0),
        ];
    }

    private function compactBootstrap(array $report): array
    {
        return [
            'source_user_count' => (int)($report['source_user_count'] ?? 0),
            'created_count' => (int)($report['created_count'] ?? 0),
            'unchanged_count' => (int)($report['unchanged_count'] ?? 0),
            'zero_balance_count' => (int)($report['zero_balance_count'] ?? 0),
            'credited_total' => (int)($report['credited_total'] ?? 0),
        ];
    }

    private function compactDelta(array $report): array
    {
        return [
            'source_user_count' => (int)($report['source_user_count'] ?? 0),
            'source_asset_count' => (int)($report['source_asset_count'] ?? 0),
            'planned_delta_count' => (int)($report['planned_delta_count'] ?? 0),
            'applied_delta_count' => (int)($report['applied_delta_count'] ?? 0),
            'replayed_delta_count' => (int)($report['replayed_delta_count'] ?? 0),
            'credited_total' => (int)($report['credited_total'] ?? 0),
            'debited_total' => (int)($report['debited_total'] ?? 0),
            'source_totals' => $report['source_totals'] ?? [],
            'database_totals' => $report['database_totals'] ?? [],
            'reconciled' => !empty($report['reconciled']),
        ];
    }

    private function compactReconciliation(array $report): array
    {
        return [
            'ready' => !empty($report['ready']),
            'source_user_count' => (int)($report['source_user_count'] ?? 0),
            'source_asset_count' => (int)($report['source_asset_count'] ?? 0),
            'source_totals' => $report['source_totals'] ?? [],
            'database_totals' => $report['database_totals'] ?? [],
            'planned_delta_count' => (int)($report['planned_delta_count'] ?? 0),
            'integrity_failure_count' => (int)($report['integrity_failure_count'] ?? 0),
            'ledger_entry_count' => (int)($report['ledger_entry_count'] ?? 0),
            'active_reservation_count' => (int)($report['active_reservation_count'] ?? 0),
            'blocking_reasons' => array_values((array)($report['blocking_reasons'] ?? [])),
        ];
    }
}
