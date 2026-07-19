<?php
declare(strict_types=1);

final class StagingDatabaseRuntimeRegressionOperation
{
    private const BUILD = 'v100-mvp14-db-weekly-bonus-routing';
    private const OPERATION_ID = 'mvp-14.8.4j-db-runtime-regression-v1';

    public function __construct(
        private array $config,
        private StorageAdapterInterface $storage,
        private DatabaseConnectionInterface $database
    ) {}

    public function definition(): StagingOperationDefinition
    {
        return new StagingOperationDefinition(
            self::OPERATION_ID,
            self::BUILD,
            function (): array {
                $router = new RuntimeStorageRouter($this->config);
                $requiredModules = [
                    'accounts',
                    'realtime',
                    'invites',
                    'notifications',
                    'economy',
                    'history',
                    'shop',
                    'payments',
                    'weekly_bonus',
                ];
                $enabledModules = $router->enabledModules();
                $missingModules = array_values(array_diff($requiredModules, $enabledModules));
                $blockers = [];
                if ($missingModules !== []) $blockers[] = 'Required staged DB runtime modules are not enabled.';
                if ($this->storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
                    $blockers[] = 'Global JSON rollback storage is not active.';
                }

                $snapshot = $this->storage->readOnly(static fn(array $data): array => $data);
                if (!is_array($snapshot)) {
                    throw new RuntimeException('DB runtime regression could not read the JSON rollback snapshot.');
                }

                $accounts = $this->auditAccounts($snapshot);
                foreach ($accounts['blockers'] as $reason) $blockers[] = $reason;

                $realtime = (new RuntimeRealtimeRepository(
                    $this->config,
                    $router,
                    $this->database
                ))->auditParity($snapshot);
                foreach ((array)($realtime['blockers'] ?? []) as $reason) $blockers[] = (string)$reason;

                $invites = (new RuntimeInviteRepository(
                    $this->config,
                    $router,
                    $this->database
                ))->auditParity($snapshot);
                foreach ((array)($invites['blockers'] ?? []) as $reason) $blockers[] = (string)$reason;

                $notifications = $this->auditNotifications($snapshot, $router);
                foreach ($notifications['blockers'] as $reason) $blockers[] = $reason;

                $history = (new RuntimeHistoryRepository(
                    $this->config,
                    $router,
                    $this->database,
                    new HistoryService($this->config, new UserService($this->config))
                ))->auditParity($snapshot);
                foreach ((array)($history['blockers'] ?? []) as $reason) $blockers[] = (string)$reason;

                $economy = (new RuntimeEconomyRepository(
                    $this->config,
                    $router,
                    $this->database
                ))->auditParity($snapshot);
                foreach ((array)($economy['blockers'] ?? []) as $reason) $blockers[] = (string)$reason;

                $shop = (new RuntimeShopRepository(
                    $this->config,
                    $router,
                    $this->storage,
                    $this->database
                ))->auditParity($snapshot);
                foreach ((array)($shop['blockers'] ?? []) as $reason) $blockers[] = (string)$reason;

                $payments = (new RuntimePaymentRepository(
                    $this->config,
                    $router,
                    $this->storage,
                    $this->database
                ))->auditParity($snapshot);
                foreach ((array)($payments['blockers'] ?? []) as $reason) $blockers[] = (string)$reason;

                $weekly = (new RuntimeWeeklyBonusRepository(
                    $this->config,
                    $router,
                    $this->storage,
                    $this->database
                ))->auditParity($snapshot);
                foreach ((array)($weekly['blockers'] ?? []) as $reason) $blockers[] = (string)$reason;

                $schemas = [
                    'shop' => (new RuntimeShopSchemaInstaller($this->database))->verify(),
                    'payments' => (new RuntimePaymentSchemaInstaller($this->database))->verify(),
                    'weekly_bonus' => (new RuntimeWeeklyBonusSchemaInstaller($this->database))->verify(),
                ];
                foreach ($schemas as $module => $schema) {
                    if (empty($schema['ok'])) $blockers[] = $module . ' runtime schema verification failed.';
                }

                $blockers = $this->uniqueStrings($blockers);
                return [
                    'ok' => $blockers === [],
                    'operation_type' => 'db_runtime_full_regression',
                    'read_only' => true,
                    'storage_driver' => $this->storage->driver(),
                    'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
                    'enabled_modules' => $enabledModules,
                    'missing_required_modules' => $missingModules,
                    'accounts' => $accounts,
                    'realtime' => $this->compactRealtime($realtime),
                    'invites' => $this->compactSimple($invites),
                    'notifications' => $notifications,
                    'history' => [
                        'ok' => !empty($history['ok']),
                        'source_user_count' => (int)($history['source_user_count'] ?? 0),
                        'transaction_count' => (int)($history['transaction_count'] ?? 0),
                        'game_count' => (int)($history['game_count'] ?? 0),
                        'mismatch_count' => (int)($history['mismatch_count'] ?? 0),
                        'json_fingerprint' => (string)($history['json_history_fingerprint'] ?? ''),
                        'database_fingerprint' => (string)($history['database_history_fingerprint'] ?? ''),
                    ],
                    'economy' => [
                        'ok' => !empty($economy['ok']),
                        'source_user_count' => (int)($economy['source_user_count'] ?? 0),
                        'source_asset_count' => (int)($economy['source_asset_count'] ?? 0),
                        'shadow_delta_count' => (int)($economy['shadow_delta_count'] ?? 0),
                        'planned_delta_count' => (int)($economy['reconciliation']['planned_delta_count'] ?? 0),
                        'integrity_failure_count' => (int)($economy['reconciliation']['integrity_failure_count'] ?? 0),
                        'active_reservation_count' => (int)($economy['reconciliation']['active_reservation_count'] ?? 0),
                    ],
                    'shop' => $this->compactFinancial($shop, 'source_order_count', 'database_order_count'),
                    'payments' => $this->compactFinancial($payments, 'source_payment_count', 'database_payment_count'),
                    'weekly_bonus' => $this->compactFinancial($weekly, 'source_user_count', 'database_user_count'),
                    'schemas' => $schemas,
                    'blockers' => $blockers,
                    'production_changed' => false,
                    'sensitive_identifiers_exposed' => false,
                ];
            }
        );
    }

    private function auditAccounts(array $snapshot): array
    {
        $sourceUsers = [];
        foreach (is_array($snapshot['users'] ?? null) ? $snapshot['users'] : [] as $key => $user) {
            if (!is_array($user) || !empty($user['is_dev_user'])) continue;
            $legacyUserId = trim((string)($user['id'] ?? $key));
            if ($legacyUserId !== '') $sourceUsers[$legacyUserId] = true;
        }

        $missing = 0;
        $ambiguous = 0;
        foreach (array_keys($sourceUsers) as $legacyUserId) {
            $rows = $this->database->fetchAll(
                "SELECT account_ref FROM mgw_account_ownership
                 WHERE legacy_user_id = :legacy_user_id AND ownership_status = 'active'",
                ['legacy_user_id' => $legacyUserId]
            );
            if ($rows === []) $missing++;
            elseif (count($rows) !== 1) $ambiguous++;
        }
        $activeCount = (int)$this->database->fetchValue(
            "SELECT COUNT(*) FROM mgw_account_ownership WHERE ownership_status = 'active'"
        );
        $blockers = [];
        if ($missing > 0) $blockers[] = 'Some JSON users have no active account ownership row.';
        if ($ambiguous > 0) $blockers[] = 'Some JSON users have ambiguous active account ownership.';

        return [
            'ok' => $blockers === [],
            'source_user_count' => count($sourceUsers),
            'active_ownership_count' => $activeCount,
            'missing_count' => $missing,
            'ambiguous_count' => $ambiguous,
            'blockers' => $blockers,
        ];
    }

    private function auditNotifications(array $snapshot, RuntimeStorageRouter $router): array
    {
        $audited = 0;
        $sourceCount = 0;
        $databaseCount = 0;
        $blockers = [];
        $repository = new RuntimeNotificationRepository($this->config, $router, $this->database);
        foreach (is_array($snapshot['users'] ?? null) ? $snapshot['users'] : [] as $key => $user) {
            if (!is_array($user) || !empty($user['is_dev_user'])) continue;
            $legacyUserId = trim((string)($user['id'] ?? $key));
            if ($legacyUserId === '') continue;
            try {
                $audit = $repository->auditParity($snapshot, $legacyUserId);
                $audited++;
                $sourceCount += (int)($audit['source_count'] ?? 0);
                $databaseCount += (int)($audit['database_count'] ?? 0);
                foreach ((array)($audit['blockers'] ?? []) as $reason) $blockers[] = (string)$reason;
            } catch (Throwable) {
                $blockers[] = 'Notification parity audit failed for at least one user.';
            }
        }
        $blockers = $this->uniqueStrings($blockers);
        return [
            'ok' => $blockers === [],
            'audited_user_count' => $audited,
            'source_count' => $sourceCount,
            'database_count' => $databaseCount,
            'blockers' => $blockers,
        ];
    }

    private function compactRealtime(array $report): array
    {
        return [
            'ok' => !empty($report['ok']),
            'source_game_count' => (int)($report['source_game_count'] ?? 0),
            'database_game_count' => (int)($report['database_game_count'] ?? 0),
            'source_queue_count' => (int)($report['source_queue_count'] ?? 0),
            'database_queue_count' => (int)($report['database_queue_count'] ?? 0),
            'source_fingerprint' => (string)($report['source_fingerprint'] ?? ''),
            'database_fingerprint' => (string)($report['database_fingerprint'] ?? ''),
        ];
    }

    private function compactSimple(array $report): array
    {
        return [
            'ok' => !empty($report['ok']),
            'source_count' => (int)($report['source_count'] ?? 0),
            'database_count' => (int)($report['database_count'] ?? 0),
            'source_fingerprint' => (string)($report['source_fingerprint'] ?? ''),
            'database_fingerprint' => (string)($report['database_fingerprint'] ?? ''),
        ];
    }

    private function compactFinancial(array $report, string $sourceKey, string $databaseKey): array
    {
        return [
            'ok' => !empty($report['ok']),
            'source_count' => (int)($report[$sourceKey] ?? 0),
            'database_count' => (int)($report[$databaseKey] ?? 0),
            'mismatch_count' => (int)($report['mismatch_count'] ?? 0),
            'missing_count' => (int)($report['missing_count'] ?? 0),
            'extra_count' => (int)($report['extra_count'] ?? 0),
            'corrupted_count' => (int)($report['corrupted_count'] ?? 0),
        ];
    }

    private function uniqueStrings(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $values
        ), static fn(string $value): bool => $value !== '')));
    }
}
