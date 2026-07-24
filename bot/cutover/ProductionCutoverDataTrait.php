<?php
declare(strict_types=1);

trait ProductionCutoverDataTrait
{
    private function importAll(array $snapshot): array
    {
        $realtimeShadow = (new LegacyRealtimeShadowSyncService($this->storage, $this->database))->run();
        $economyShadow = (new LegacyEconomyShadowSyncService($this->storage, $this->database))->run();
        if (empty($realtimeShadow['ok']) || empty($economyShadow['ok'])) {
            throw new RuntimeException('Legacy shadow synchronization failed.');
        }

        $ledgerVerifier = new LedgerIntegrityVerifier($this->database);
        $openingBalances = (new LegacyOpeningBalanceImportService(
            $this->database,
            new LedgerWriteService($this->database),
            $ledgerVerifier
        ))->run();
        $accountImport = (new LegacyAccountImportService($this->storage, $this->database))->run();
        $ownership = (new LegacyAccountOwnershipLinkService(
            $this->storage,
            $this->database,
            $ledgerVerifier
        ))->run();
        $realtimeImport = (new LegacyRealtimeNormalizedImportService(
            $this->storage,
            $this->database,
            new LegacyRealtimeShadowSyncService($this->storage, $this->database)
        ))->run();
        $financialArchive = (new LegacyFinancialArchiveImportService(
            $this->storage,
            $this->database,
            new LegacyFinancialStatusNormalizer()
        ))->run();

        $schemas = [
            'shop' => (new RuntimeShopSchemaInstaller($this->database))->install(),
            'payments' => (new RuntimePaymentSchemaInstaller($this->database))->install(),
            'weekly_bonus' => (new RuntimeWeeklyBonusSchemaInstaller($this->database))->install(),
        ];
        foreach ($schemas as $module => $schema) {
            if (empty($schema['ok'])) throw new RuntimeException($module . ' runtime schema installation failed.');
        }

        return [
            'ok' => true,
            'source_user_count' => count(is_array($snapshot['users'] ?? null) ? $snapshot['users'] : []),
            'source_game_count' => count(is_array($snapshot['games'] ?? null) ? $snapshot['games'] : []),
            'source_transaction_count' => count(is_array($snapshot['transactions'] ?? null) ? $snapshot['transactions'] : []),
            'realtime_shadow' => $this->compactShadow($realtimeShadow),
            'economy_shadow' => $this->compactShadow($economyShadow),
            'accounts' => $this->compactImport($accountImport, [
                'source_user_count', 'created_user_count', 'created_legacy_link_count',
                'updated_user_count', 'unchanged_user_count',
            ]),
            'opening_balances' => $this->compactImport($openingBalances, [
                'source_user_count', 'source_asset_count', 'created_balance_count',
                'created_ledger_count', 'replayed_ledger_count',
            ]),
            'ownership' => $this->compactImport($ownership, [
                'source_user_count', 'created_ownership_count',
                'created_provider_identity_count', 'reused_provider_identity_count',
                'unchanged_user_count',
            ]),
            'realtime_normalized' => [
                'ok' => !empty($realtimeImport['ok']),
                'status' => (string)($realtimeImport['status'] ?? ''),
                'source_counts' => $realtimeImport['source_counts'] ?? [],
                'created_counts' => $realtimeImport['created_counts'] ?? [],
                'unchanged_counts' => $realtimeImport['unchanged_counts'] ?? [],
            ],
            'financial_archive' => [
                'ok' => !empty($financialArchive['ok']),
                'status' => (string)($financialArchive['status'] ?? ''),
                'source_counts' => $financialArchive['source_counts'] ?? [],
                'archive_counts' => $financialArchive['archive_counts'] ?? [],
                'created_counts' => $financialArchive['created_counts'] ?? [],
                'unchanged_counts' => $financialArchive['unchanged_counts'] ?? [],
                'unknown_status_count' => (int)($financialArchive['unknown_status_count'] ?? 0),
            ],
            'runtime_schemas' => [
                'shop_ok' => !empty($schemas['shop']['ok']),
                'payments_ok' => !empty($schemas['payments']['ok']),
                'weekly_bonus_ok' => !empty($schemas['weekly_bonus']['ok']),
            ],
        ];
    }

    private function synchronizeRuntime(array $config, array $snapshot): array
    {
        $router = new RuntimeStorageRouter($config);
        $realtime = (new RuntimeRealtimeRepository($config, $router, $this->database))->synchronize($snapshot);
        $invites = (new RuntimeInviteRepository($config, $router, $this->database))->synchronize($snapshot);

        $notificationUsers = 0;
        $notificationSourceCount = 0;
        $notificationDatabaseCount = 0;
        $notifications = new RuntimeNotificationRepository($config, $router, $this->database);
        foreach ($this->sourceUsers($snapshot) as $legacyUserId) {
            $report = $notifications->synchronizeAndList($snapshot, $legacyUserId);
            $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
            $notificationUsers++;
            $notificationSourceCount += (int)($summary['source_count'] ?? 0);
            $notificationDatabaseCount += (int)($summary['database_count'] ?? 0);
        }

        $economy = (new RuntimeEconomyRepository($config, $router, $this->database))->synchronize($snapshot);
        $shop = (new RuntimeShopRepository($config, $router, $this->storage, $this->database))->synchronizeCurrentJson();
        $payments = (new RuntimePaymentRepository($config, $router, $this->storage, $this->database))->synchronizeCurrentJson();
        $weekly = (new RuntimeWeeklyBonusRepository($config, $router, $this->storage, $this->database))->synchronizeCurrentJson();
        $history = (new RuntimeHistoryRepository(
            $config,
            $router,
            $this->database,
            new HistoryService($config, new UserService($config))
        ))->auditParity($snapshot);

        $ok = !empty($realtime['parity'])
            && !empty($invites['parity'])
            && $notificationSourceCount === $notificationDatabaseCount
            && !empty($economy['ok'])
            && !empty($shop['ok'])
            && !empty($payments['ok'])
            && !empty($weekly['ok'])
            && !empty($history['ok']);

        return [
            'ok' => $ok,
            'realtime' => [
                'source_count' => (int)($realtime['games']['source_count'] ?? 0),
                'database_count' => (int)($realtime['games']['database_count'] ?? 0),
                'parity' => !empty($realtime['parity']),
            ],
            'invites' => [
                'source_count' => (int)($invites['source_count'] ?? 0),
                'database_count' => (int)($invites['database_count'] ?? 0),
                'parity' => !empty($invites['parity']),
            ],
            'notifications' => [
                'audited_user_count' => $notificationUsers,
                'source_count' => $notificationSourceCount,
                'database_count' => $notificationDatabaseCount,
                'parity' => $notificationSourceCount === $notificationDatabaseCount,
            ],
            'economy' => [
                'ok' => !empty($economy['ok']),
                'planned_delta_count' => (int)($economy['reconciliation']['planned_delta_count'] ?? 0),
                'integrity_failure_count' => (int)($economy['reconciliation']['integrity_failure_count'] ?? 0),
            ],
            'shop' => $this->compactFinancialAudit($shop, 'source_order_count', 'database_order_count'),
            'payments' => $this->compactFinancialAudit($payments, 'source_payment_count', 'database_payment_count'),
            'weekly_bonus' => $this->compactFinancialAudit($weekly, 'source_user_count', 'database_user_count'),
            'history' => [
                'ok' => !empty($history['ok']),
                'source_user_count' => (int)($history['source_user_count'] ?? 0),
                'mismatch_count' => (int)($history['mismatch_count'] ?? 0),
            ],
        ];
    }

    private function fullRegression(array $config): array
    {
        return (new StagingDatabaseRuntimeRegressionOperation(
            $config,
            $this->storage,
            $this->database
        ))->definition()->execute();
    }

    private function cleanupQueue(): array
    {
        return $this->storage->transaction(function (array &$data): array {
            $queue = is_array($data['queue'] ?? null) ? $data['queue'] : [];
            $removed = count($queue);
            $reset = 0;
            if (!isset($data['users']) || !is_array($data['users'])) $data['users'] = [];
            foreach ($data['users'] as &$user) {
                if (!is_array($user) || (string)($user['status'] ?? '') !== 'searching') continue;
                $user['status'] = 'idle';
                $user['current_game_id'] = null;
                $reset++;
            }
            unset($user);
            $data['queue'] = [];
            return [
                'removed_queue_entries' => $removed,
                'reset_searching_users' => $reset,
                'active_games_untouched' => true,
            ];
        });
    }

    private function inspectDrain(): array
    {
        $snapshot = $this->snapshot();
        $inventory = (new ProductionPreflightService())->inspectSnapshot($snapshot);
        return [
            'active_games' => (int)($inventory['active_games'] ?? 0),
            'queue_entries' => (int)($inventory['queue_entries'] ?? 0),
            'open_invites' => (int)($inventory['open_invites'] ?? 0),
            'searching_users' => (int)($inventory['searching_users'] ?? 0),
            'playing_users' => (int)($inventory['playing_users'] ?? 0),
            'pending_payments' => (int)($inventory['pending_payments'] ?? 0),
            'unknown_payment_statuses' => (int)($inventory['unknown_payment_statuses'] ?? 0),
            'pending_shop_orders' => (int)($inventory['pending_shop_orders'] ?? 0),
            'unknown_shop_order_statuses' => (int)($inventory['unknown_shop_order_statuses'] ?? 0),
        ];
    }

    private function assertDrainReady(array $drain): void
    {
        foreach ($drain as $value) {
            if ((int)$value !== 0) {
                throw new RuntimeException('Production traffic or financial work did not drain to zero.');
            }
        }
    }

    private function assertBackup(array $backup): void
    {
        if (($backup['ok'] ?? false) !== true) {
            throw new RuntimeException('Production cutover backup failed.');
        }
        if ($this->policy->requirePrimaryBackup()
            && trim((string)($backup['backup_id'] ?? '')) === '') {
            throw new RuntimeException('Production cutover primary backup ID is missing.');
        }
        if ($this->policy->requireExternalCopy()
            && ($backup['external_copy']['copied'] ?? false) !== true) {
            throw new RuntimeException('Production cutover external backup copy failed.');
        }
        $primaryHash = strtolower(trim((string)($backup['snapshot_sha256'] ?? '')));
        $externalHash = strtolower(trim((string)($backup['external_copy']['snapshot_sha256'] ?? '')));
        if ($this->policy->requireExternalCopy()
            && ($primaryHash === '' || $externalHash === '' || !hash_equals($primaryHash, $externalHash))) {
            throw new RuntimeException('Production cutover backup copies do not match.');
        }
    }

    private function snapshot(): array
    {
        $snapshot = $this->storage->readOnly(static fn(array $data): array => $data);
        if (!is_array($snapshot)) {
            throw new RuntimeException('Production JSON snapshot is unavailable.');
        }
        return $snapshot;
    }

    private function snapshotFingerprint(array $snapshot): string
    {
        return hash('sha256', LedgerIntegrity::canonicalJson($snapshot));
    }

    private function sourceUsers(array $snapshot): array
    {
        $ids = [];
        foreach (is_array($snapshot['users'] ?? null) ? $snapshot['users'] : [] as $key => $user) {
            if (!is_array($user) || !empty($user['is_dev_user'])) continue;
            $legacyId = trim((string)($user['id'] ?? $key));
            if ($legacyId !== '') $ids[$legacyId] = true;
        }
        $ids = array_keys($ids);
        sort($ids, SORT_STRING);
        return $ids;
    }
}
