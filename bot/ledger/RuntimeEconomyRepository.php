<?php
declare(strict_types=1);

final class RuntimeEconomyRepository
{
    private RuntimeStorageRouter $router;
    private ?DatabaseConnectionInterface $connection;
    /** @var array<string,array<string,mixed>> */
    private static array $requestSynchronizeCache = [];
    /** @var array<string,array<string,mixed>> */
    private static array $requestAuditCache = [];

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
        $cacheKey = $this->requestCacheKey($database, $jsonSnapshot);
        if (array_key_exists($cacheKey, self::$requestSynchronizeCache)) {
            return self::$requestSynchronizeCache[$cacheKey];
        }

        // Phase A remains the immutable legacy audit owner. Match/Gold are first
        // reconciled exactly as they existed before MVP-15.3 and are never
        // rewritten from the new unified runtime field afterwards.
        $storage = new RuntimeEconomySnapshotStorage($jsonSnapshot);
        $ledger = new LedgerWriteService($database);
        $integrity = new LedgerIntegrityVerifier($database);
        $shadow = new LegacyEconomyShadowSyncService($storage, $database);
        $shadowReport = $shadow->run();
        $bootstrapReport = (new RuntimeEconomyBalanceBootstrapService($database, $ledger))->ensureFromShadow();
        $delta = new LegacyEconomyDeltaImportService($database, $ledger, $integrity);
        $deltaReport = $delta->run();
        $legacyReconciliation = (new LegacyEconomyRuntimeReconciliationService(
            $database,
            $delta,
            $integrity
        ))->preview();

        if (empty($legacyReconciliation['ready'])) {
            throw new RuntimeException(
                'Legacy economy reconciliation failed before unified cutover: '
                . implode('; ', array_map('strval', (array)($legacyReconciliation['blocking_reasons'] ?? [])))
            );
        }

        // Phase B owns the one-time conversion. The durable marker makes this a
        // true cutover: future users/balance changes are not reinterpreted as
        // legacy Match/Gold migration input.
        $rule = $this->unifiedRule();
        $coordinator = new UnifiedBalanceMigrationCoordinator(
            $database,
            $rule,
            $ledger,
            $integrity
        );
        $migrationReport = $coordinator->ensureMigrated();

        // Phase C is the only live balance owner after cutover. Runtime `balance`
        // converges to DB asset `mgw_coin`; legacy source rows remain history.
        $unified = new UnifiedEconomyRuntimeSyncService($database, $ledger, $integrity);
        $unifiedReport = $unified->run($jsonSnapshot);

        $finalReconciliation = (new LegacyEconomyRuntimeReconciliationService(
            $database,
            $delta,
            $integrity
        ))->preview();
        if (empty($finalReconciliation['ready'])) {
            throw new RuntimeException(
                'Economy ledger integrity failed after unified runtime sync: '
                . implode('; ', array_map('strval', (array)($finalReconciliation['blocking_reasons'] ?? [])))
            );
        }

        $result = [
            'ok' => true,
            'action' => 'synchronize',
            'storage_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'shadow' => $this->compactShadow($shadowReport),
            'balance_bootstrap' => $this->compactBootstrap($bootstrapReport),
            'legacy_delta' => $this->compactDelta($deltaReport),
            'migration' => $this->compactMigration($migrationReport),
            'unified' => $this->compactUnified($unifiedReport),
            'reconciliation' => $this->compactReconciliation($finalReconciliation),
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
        unset(self::$requestAuditCache[$cacheKey]);
        self::$requestSynchronizeCache[$cacheKey] = $result;
        return $result;
    }

    public function auditParity(array $jsonSnapshot): array
    {
        $this->assertDatabaseRoute();
        $database = $this->database();
        $cacheKey = $this->requestCacheKey($database, $jsonSnapshot);
        if (array_key_exists($cacheKey, self::$requestAuditCache)) {
            return self::$requestAuditCache[$cacheKey];
        }

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

        $rule = $this->unifiedRule();
        $migration = (new UnifiedBalanceMigrationCoordinator(
            $database,
            $rule,
            $ledger,
            $integrity
        ))->preview();
        $unified = (new UnifiedEconomyRuntimeSyncService($database, $ledger, $integrity))->preview($jsonSnapshot);

        $shadowDeltaCount = $this->shadowDeltaCount($shadowReport);
        $blockers = [];
        if ($shadowDeltaCount > 0) {
            $blockers[] = 'Current JSON economy shadow differs from the database shadow.';
        }
        foreach ((array)($reconciliation['blocking_reasons'] ?? []) as $reason) {
            $blockers[] = (string)$reason;
        }
        foreach ((array)($migration['blockers'] ?? []) as $reason) {
            $blockers[] = (string)$reason;
        }
        foreach ((array)($unified['blocking_reasons'] ?? []) as $reason) {
            $blockers[] = (string)$reason;
        }
        if ((int)($unified['planned_delta_count'] ?? 0) > 0) {
            $blockers[] = 'Canonical runtime balance differs from mgw_coin and requires synchronization.';
        }
        $blockers = array_values(array_unique(array_filter($blockers, static fn(string $value): bool => $value !== '')));
        $compactReconciliation = $this->compactReconciliation($reconciliation);

        $result = [
            'ok' => $blockers === [],
            'read_only' => true,
            'storage_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'source_fingerprint' => (string)($shadowReport['source_fingerprint'] ?? ''),
            'source_user_count' => (int)($compactReconciliation['source_user_count'] ?? 0),
            'source_asset_count' => (int)($compactReconciliation['source_asset_count'] ?? 0),
            'source_totals' => $compactReconciliation['source_totals'] ?? [],
            'database_totals' => $compactReconciliation['database_totals'] ?? [],
            'planned_delta_count' => (int)($compactReconciliation['planned_delta_count'] ?? 0),
            'integrity_failure_count' => (int)($compactReconciliation['integrity_failure_count'] ?? 0),
            'ledger_entry_count' => (int)($compactReconciliation['ledger_entry_count'] ?? 0),
            'active_reservation_count' => (int)($compactReconciliation['active_reservation_count'] ?? 0),
            'shadow_delta_count' => $shadowDeltaCount,
            'shadow' => $this->compactShadow($shadowReport),
            'migration' => [
                'completed' => !empty($migration['completed']),
                'migration_version' => (string)($migration['migration_version'] ?? $rule->version()),
                'verified_migration_account_count' => (int)($migration['verified_migration_account_count'] ?? 0),
            ],
            'unified' => $this->compactUnified($unified),
            'reconciliation' => $compactReconciliation,
            'blockers' => $blockers,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
        self::$requestAuditCache[$cacheKey] = $result;
        return $result;
    }

    private function unifiedRule(): UnifiedBalanceMigrationRule
    {
        $file = dirname(__DIR__) . '/economy/unified_balance_mapping.php';
        if (!is_file($file)) {
            throw new RuntimeException('Approved unified balance mapping file is missing.');
        }
        $config = require $file;
        if (!is_array($config)) {
            throw new RuntimeException('Approved unified balance mapping must return an array.');
        }
        return UnifiedBalanceMigrationRule::fromApprovedConfig($config);
    }

    private function requestCacheKey(DatabaseConnectionInterface $database, array $jsonSnapshot): string
    {
        // PdoConnectionFactory intentionally reuses one connection object inside
        // a web request. The object id therefore scopes this memo to one real DB
        // session, while CLI/forked workers keep their existing isolated sockets.
        $source = [
            'users' => is_array($jsonSnapshot['users'] ?? null) ? $jsonSnapshot['users'] : [],
            'transactions' => is_array($jsonSnapshot['transactions'] ?? null) ? $jsonSnapshot['transactions'] : [],
        ];
        return spl_object_id($database) . ':' . hash('sha256', LedgerIntegrity::canonicalJson($source));
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

    private function compactMigration(array $report): array
    {
        return [
            'migration_version' => (string)($report['migration_version'] ?? ''),
            'rule_fingerprint' => (string)($report['rule_fingerprint'] ?? ''),
            'source_account_count' => (int)($report['source_account_count'] ?? 0),
            'source_totals' => $report['source_totals'] ?? [],
            'target_total' => (int)($report['target_total'] ?? 0),
            'applied_ledger_entry_count' => (int)($report['applied_ledger_entry_count'] ?? 0),
            'replayed' => !empty($report['replayed']),
            'source_balances_preserved' => !empty($report['source_balances_preserved']),
        ];
    }

    private function compactUnified(array $report): array
    {
        return [
            'ready' => ($report['ready'] ?? $report['ok'] ?? false) === true,
            'read_only' => !empty($report['read_only']),
            'source_fingerprint' => (string)($report['source_fingerprint'] ?? ''),
            'source_user_count' => (int)($report['source_user_count'] ?? 0),
            'source_total' => (int)($report['source_total'] ?? 0),
            'database_total' => (int)($report['database_total'] ?? 0),
            'planned_delta_count' => (int)($report['planned_delta_count'] ?? 0),
            'applied_delta_count' => (int)($report['applied_delta_count'] ?? 0),
            'replayed_delta_count' => (int)($report['replayed_delta_count'] ?? 0),
            'credited_total' => (int)($report['credited_total'] ?? 0),
            'debited_total' => (int)($report['debited_total'] ?? 0),
            'reconciled' => !empty($report['reconciled']),
            'blocking_reasons' => array_values((array)($report['blocking_reasons'] ?? [])),
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
