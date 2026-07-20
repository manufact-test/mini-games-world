<?php
declare(strict_types=1);

final class RuntimePrimaryRepositoryProjectorFactory
{
    private const MODULES = [
        'accounts', 'realtime', 'economy', 'notifications', 'invites',
        'history', 'shop', 'payments', 'weekly_bonus',
    ];

    private array $projectionConfig;
    private RuntimeStorageRouter $router;

    public function __construct(
        private array $config,
        private DatabaseConnectionInterface $database
    ) {
        $environment = strtolower(trim((string)($config['environment'] ?? '')));
        if (!in_array($environment, ['local', 'staging'], true)) {
            throw new RuntimeException(
                'Repository projector remains staging/local-only until the protected production activation contract is merged.'
            );
        }
        $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Repository projector requires an enabled database configuration.');
        }

        $this->projectionConfig = $config;
        $this->projectionConfig['storage_driver'] = RuntimeStorageRouter::DRIVER_JSON;
        if (!isset($this->projectionConfig['feature_flags'])
            || !is_array($this->projectionConfig['feature_flags'])) {
            $this->projectionConfig['feature_flags'] = [];
        }
        $moduleFlags = array_fill_keys(self::MODULES, true);
        $this->projectionConfig['feature_flags']['database_runtime'] = [
            'enabled' => true,
            'modules' => $moduleFlags,
        ];
        $this->router = new RuntimeStorageRouter($this->projectionConfig);
        if (!$this->router->enabled()
            || array_values(array_diff(self::MODULES, $this->router->enabledModules())) !== []) {
            throw new RuntimeException('Repository projector could not activate all module routes.');
        }
    }

    public function create(): RuntimePrimaryAllModuleProjector
    {
        return new RuntimePrimaryAllModuleProjector([
            new RuntimePrimaryAccountsModuleProjector($this->database),
            $this->realtimeProjector(),
            $this->economyProjector(),
            $this->notificationsProjector(),
            $this->invitesProjector(),
            $this->historyProjector(),
            $this->shopProjector(),
            $this->paymentsProjector(),
            $this->weeklyBonusProjector(),
        ]);
    }

    private function realtimeProjector(): RuntimePrimaryModuleProjectorInterface
    {
        return new RuntimePrimaryCallbackModuleProjector(
            'realtime',
            function (array $snapshot, int $revision, string $stateSha): array {
                $repository = new RuntimeRealtimeRepository(
                    $this->projectionConfig,
                    $this->router,
                    $this->database
                );
                $project = $repository->synchronize($snapshot);
                $audit = $repository->auditParity($snapshot);
                return $this->repositoryReport(
                    'realtime',
                    $revision,
                    $stateSha,
                    false,
                    $audit,
                    (string)($audit['source_fingerprint'] ?? ''),
                    (string)($audit['database_fingerprint'] ?? ''),
                    ['project' => $project]
                );
            },
            function (array $snapshot, int $revision, string $stateSha): array {
                $audit = (new RuntimeRealtimeRepository(
                    $this->projectionConfig,
                    $this->router,
                    $this->database
                ))->auditParity($snapshot);
                return $this->repositoryReport(
                    'realtime',
                    $revision,
                    $stateSha,
                    true,
                    $audit,
                    (string)($audit['source_fingerprint'] ?? ''),
                    (string)($audit['database_fingerprint'] ?? '')
                );
            }
        );
    }

    private function economyProjector(): RuntimePrimaryModuleProjectorInterface
    {
        return new RuntimePrimaryCallbackModuleProjector(
            'economy',
            function (array $snapshot, int $revision, string $stateSha): array {
                $repository = new RuntimeEconomyRepository(
                    $this->projectionConfig,
                    $this->router,
                    $this->database
                );
                $project = $repository->synchronize($snapshot);
                $audit = $repository->auditParity($snapshot);
                $fingerprint = $this->economyFingerprint($audit);
                return $this->repositoryReport(
                    'economy',
                    $revision,
                    $stateSha,
                    false,
                    $audit,
                    $fingerprint,
                    $fingerprint,
                    ['project' => $project]
                );
            },
            function (array $snapshot, int $revision, string $stateSha): array {
                $audit = (new RuntimeEconomyRepository(
                    $this->projectionConfig,
                    $this->router,
                    $this->database
                ))->auditParity($snapshot);
                $fingerprint = $this->economyFingerprint($audit);
                return $this->repositoryReport(
                    'economy',
                    $revision,
                    $stateSha,
                    true,
                    $audit,
                    $fingerprint,
                    $fingerprint
                );
            }
        );
    }

    private function notificationsProjector(): RuntimePrimaryModuleProjectorInterface
    {
        return new RuntimePrimaryCallbackModuleProjector(
            'notifications',
            function (array $snapshot, int $revision, string $stateSha): array {
                $repository = new RuntimeNotificationRepository(
                    $this->projectionConfig,
                    $this->router,
                    $this->database
                );
                $projectSummaries = [];
                foreach ($this->sourceUsers($snapshot) as $legacyUserId) {
                    $projectSummaries[$legacyUserId] = $repository->synchronizeAndList(
                        $snapshot,
                        $legacyUserId
                    )['summary'] ?? [];
                }
                $audit = $this->notificationAudit($repository, $snapshot);
                return $this->repositoryReport(
                    'notifications',
                    $revision,
                    $stateSha,
                    false,
                    $audit,
                    (string)$audit['source_fingerprint'],
                    (string)$audit['database_fingerprint'],
                    ['users' => $projectSummaries]
                );
            },
            function (array $snapshot, int $revision, string $stateSha): array {
                $repository = new RuntimeNotificationRepository(
                    $this->projectionConfig,
                    $this->router,
                    $this->database
                );
                $audit = $this->notificationAudit($repository, $snapshot);
                return $this->repositoryReport(
                    'notifications',
                    $revision,
                    $stateSha,
                    true,
                    $audit,
                    (string)$audit['source_fingerprint'],
                    (string)$audit['database_fingerprint']
                );
            }
        );
    }

    private function invitesProjector(): RuntimePrimaryModuleProjectorInterface
    {
        return new RuntimePrimaryCallbackModuleProjector(
            'invites',
            function (array $snapshot, int $revision, string $stateSha): array {
                $repository = new RuntimeInviteRepository(
                    $this->projectionConfig,
                    $this->router,
                    $this->database
                );
                $project = $repository->synchronize($snapshot);
                $audit = $repository->auditParity($snapshot);
                return $this->repositoryReport(
                    'invites',
                    $revision,
                    $stateSha,
                    false,
                    $audit,
                    (string)($audit['source_fingerprint'] ?? ''),
                    (string)($audit['database_fingerprint'] ?? ''),
                    ['project' => $project]
                );
            },
            function (array $snapshot, int $revision, string $stateSha): array {
                $audit = (new RuntimeInviteRepository(
                    $this->projectionConfig,
                    $this->router,
                    $this->database
                ))->auditParity($snapshot);
                return $this->repositoryReport(
                    'invites',
                    $revision,
                    $stateSha,
                    true,
                    $audit,
                    (string)($audit['source_fingerprint'] ?? ''),
                    (string)($audit['database_fingerprint'] ?? '')
                );
            }
        );
    }

    private function historyProjector(): RuntimePrimaryModuleProjectorInterface
    {
        return new RuntimePrimaryCallbackModuleProjector(
            'history',
            function (array $snapshot, int $revision, string $stateSha): array {
                $storage = new RuntimeEconomySnapshotStorage($snapshot);
                $shadowRealtime = (new LegacyRealtimeShadowSyncService($storage, $this->database))->run();
                $shadowEconomy = (new LegacyEconomyShadowSyncService($storage, $this->database))->run();
                if (empty($shadowRealtime['ok']) || empty($shadowEconomy['ok'])) {
                    throw new RuntimeException('History dependency shadow synchronization failed.');
                }
                $audit = $this->historyRepository()->auditParity($snapshot);
                return $this->repositoryReport(
                    'history',
                    $revision,
                    $stateSha,
                    false,
                    $audit,
                    (string)($audit['json_history_fingerprint'] ?? ''),
                    (string)($audit['database_history_fingerprint'] ?? ''),
                    [
                        'realtime_shadow_fingerprint' => (string)($shadowRealtime['source_fingerprint'] ?? ''),
                        'economy_shadow_fingerprint' => (string)($shadowEconomy['source_fingerprint'] ?? ''),
                    ]
                );
            },
            function (array $snapshot, int $revision, string $stateSha): array {
                $audit = $this->historyRepository()->auditParity($snapshot);
                return $this->repositoryReport(
                    'history',
                    $revision,
                    $stateSha,
                    true,
                    $audit,
                    (string)($audit['json_history_fingerprint'] ?? ''),
                    (string)($audit['database_history_fingerprint'] ?? '')
                );
            }
        );
    }

    private function shopProjector(): RuntimePrimaryModuleProjectorInterface
    {
        return new RuntimePrimaryCallbackModuleProjector(
            'shop',
            function (array $snapshot, int $revision, string $stateSha): array {
                $repository = $this->shopRepository($snapshot);
                $project = $repository->synchronizeCurrentJson();
                $audit = $repository->auditParity($snapshot);
                return $this->repositoryReport(
                    'shop',
                    $revision,
                    $stateSha,
                    false,
                    $audit,
                    (string)($audit['json_shop_fingerprint'] ?? ''),
                    (string)($audit['database_shop_fingerprint'] ?? ''),
                    ['project' => $project]
                );
            },
            function (array $snapshot, int $revision, string $stateSha): array {
                $audit = $this->shopRepository($snapshot)->auditParity($snapshot);
                return $this->repositoryReport(
                    'shop',
                    $revision,
                    $stateSha,
                    true,
                    $audit,
                    (string)($audit['json_shop_fingerprint'] ?? ''),
                    (string)($audit['database_shop_fingerprint'] ?? '')
                );
            }
        );
    }

    private function paymentsProjector(): RuntimePrimaryModuleProjectorInterface
    {
        return new RuntimePrimaryCallbackModuleProjector(
            'payments',
            function (array $snapshot, int $revision, string $stateSha): array {
                $repository = $this->paymentRepository($snapshot);
                $project = $repository->synchronizeCurrentJson();
                $audit = $repository->auditParity($snapshot);
                return $this->repositoryReport(
                    'payments',
                    $revision,
                    $stateSha,
                    false,
                    $audit,
                    (string)($audit['json_payment_fingerprint'] ?? ''),
                    (string)($audit['database_payment_fingerprint'] ?? ''),
                    ['project' => $project]
                );
            },
            function (array $snapshot, int $revision, string $stateSha): array {
                $audit = $this->paymentRepository($snapshot)->auditParity($snapshot);
                return $this->repositoryReport(
                    'payments',
                    $revision,
                    $stateSha,
                    true,
                    $audit,
                    (string)($audit['json_payment_fingerprint'] ?? ''),
                    (string)($audit['database_payment_fingerprint'] ?? '')
                );
            }
        );
    }

    private function weeklyBonusProjector(): RuntimePrimaryModuleProjectorInterface
    {
        return new RuntimePrimaryCallbackModuleProjector(
            'weekly_bonus',
            function (array $snapshot, int $revision, string $stateSha): array {
                $repository = $this->weeklyRepository($snapshot);
                $project = $repository->synchronizeCurrentJson();
                $audit = $repository->auditParity($snapshot);
                return $this->repositoryReport(
                    'weekly_bonus',
                    $revision,
                    $stateSha,
                    false,
                    $audit,
                    (string)($audit['json_weekly_fingerprint'] ?? ''),
                    (string)($audit['database_weekly_fingerprint'] ?? ''),
                    ['project' => $project]
                );
            },
            function (array $snapshot, int $revision, string $stateSha): array {
                $audit = $this->weeklyRepository($snapshot)->auditParity($snapshot);
                return $this->repositoryReport(
                    'weekly_bonus',
                    $revision,
                    $stateSha,
                    true,
                    $audit,
                    (string)($audit['json_weekly_fingerprint'] ?? ''),
                    (string)($audit['database_weekly_fingerprint'] ?? '')
                );
            }
        );
    }

    private function historyRepository(): RuntimeHistoryRepository
    {
        return new RuntimeHistoryRepository(
            $this->projectionConfig,
            $this->router,
            $this->database,
            new HistoryService(
                $this->projectionConfig,
                new UserService($this->projectionConfig)
            )
        );
    }

    private function shopRepository(array $snapshot): RuntimeShopRepository
    {
        return new RuntimeShopRepository(
            $this->projectionConfig,
            $this->router,
            new RuntimeEconomySnapshotStorage($snapshot),
            $this->database
        );
    }

    private function paymentRepository(array $snapshot): RuntimePaymentRepository
    {
        return new RuntimePaymentRepository(
            $this->projectionConfig,
            $this->router,
            new RuntimeEconomySnapshotStorage($snapshot),
            $this->database
        );
    }

    private function weeklyRepository(array $snapshot): RuntimeWeeklyBonusRepository
    {
        return new RuntimeWeeklyBonusRepository(
            $this->projectionConfig,
            $this->router,
            new RuntimeEconomySnapshotStorage($snapshot),
            $this->database
        );
    }

    private function notificationAudit(
        RuntimeNotificationRepository $repository,
        array $snapshot
    ): array {
        $sourceParts = [];
        $databaseParts = [];
        $blockers = [];
        $sourceCount = 0;
        $databaseCount = 0;
        $users = $this->sourceUsers($snapshot);
        $knownUsers = array_fill_keys($users, true);

        foreach ($users as $legacyUserId) {
            $report = $repository->auditParity($snapshot, $legacyUserId);
            $sourceFingerprint = strtolower(trim((string)($report['source_fingerprint'] ?? '')));
            $databaseFingerprint = strtolower(trim((string)($report['database_fingerprint'] ?? '')));
            $sourceParts[] = $legacyUserId . ':' . $sourceFingerprint;
            $databaseParts[] = $legacyUserId . ':' . $databaseFingerprint;
            $sourceCount += (int)($report['source_count'] ?? 0);
            $databaseCount += (int)($report['database_count'] ?? 0);
            foreach ((array)($report['blockers'] ?? []) as $blocker) {
                $blockers[] = $legacyUserId . ': ' . (string)$blocker;
            }
            if (empty($report['ok'])) {
                $blockers[] = $legacyUserId . ': notification parity failed.';
            }
        }

        foreach (is_array($snapshot['notifications'] ?? null) ? $snapshot['notifications'] : [] as $notification) {
            if (!is_array($notification)) {
                $blockers[] = 'Notification snapshot contains a non-object row.';
                continue;
            }
            $legacyUserId = trim((string)($notification['user_id'] ?? ''));
            if ($legacyUserId === '' || !isset($knownUsers[$legacyUserId])) {
                $blockers[] = 'Notification snapshot references an unknown legacy user.';
            }
        }

        sort($sourceParts, SORT_STRING);
        sort($databaseParts, SORT_STRING);
        $sourceFingerprint = hash('sha256', implode("\n", $sourceParts));
        $databaseFingerprint = hash('sha256', implode("\n", $databaseParts));
        if ($sourceCount !== $databaseCount || !hash_equals($sourceFingerprint, $databaseFingerprint)) {
            $blockers[] = 'Notification aggregate fingerprint differs from the snapshot.';
        }
        $blockers = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $blockers
        ), static fn(string $value): bool => $value !== '')));

        return [
            'ok' => $blockers === [],
            'read_only' => true,
            'source_count' => $sourceCount,
            'database_count' => $databaseCount,
            'source_fingerprint' => $sourceFingerprint,
            'database_fingerprint' => $databaseFingerprint,
            'blockers' => $blockers,
        ];
    }

    private function repositoryReport(
        string $module,
        int $revision,
        string $stateSha,
        bool $readOnly,
        array $audit,
        string $sourceFingerprint,
        string $databaseFingerprint,
        array $projectSummary = []
    ): array {
        $blockers = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            (array)($audit['blockers'] ?? [])
        ), static fn(string $value): bool => $value !== '')));
        $ok = !empty($audit['ok'])
            && $blockers === []
            && preg_match('/^[a-f0-9]{64}$/', strtolower(trim($sourceFingerprint))) === 1
            && preg_match('/^[a-f0-9]{64}$/', strtolower(trim($databaseFingerprint))) === 1
            && hash_equals(strtolower(trim($sourceFingerprint)), strtolower(trim($databaseFingerprint)));

        return [
            'ok' => $ok,
            'parity' => $ok,
            'read_only' => $readOnly,
            'module' => $module,
            'state_revision' => $revision,
            'state_sha256' => strtolower(trim($stateSha)),
            'source_fingerprint' => strtolower(trim($sourceFingerprint)),
            'database_fingerprint' => strtolower(trim($databaseFingerprint)),
            'summary' => $projectSummary + [
                'audit' => $this->compactAudit($audit),
            ],
            'blockers' => $blockers,
        ];
    }

    private function economyFingerprint(array $audit): string
    {
        if (empty($audit['ok']) || !empty($audit['blockers'])) {
            return str_repeat('0', 64);
        }
        return hash('sha256', $this->canonicalJson([
            'source_fingerprint' => (string)($audit['source_fingerprint'] ?? ''),
            'source_user_count' => (int)($audit['source_user_count'] ?? 0),
            'source_asset_count' => (int)($audit['source_asset_count'] ?? 0),
            'source_totals' => $audit['source_totals'] ?? [],
            'database_totals' => $audit['database_totals'] ?? [],
            'planned_delta_count' => (int)($audit['planned_delta_count'] ?? 0),
            'integrity_failure_count' => (int)($audit['integrity_failure_count'] ?? 0),
            'active_reservation_count' => (int)($audit['active_reservation_count'] ?? 0),
        ]));
    }

    private function sourceUsers(array $snapshot): array
    {
        $ids = [];
        foreach (is_array($snapshot['users'] ?? null) ? $snapshot['users'] : [] as $key => $user) {
            if (!is_array($user)) {
                throw new RuntimeException('Repository projector user snapshot contains a non-object row.');
            }
            $legacyId = trim((string)($user['id'] ?? (is_string($key) || is_int($key) ? $key : '')));
            if ($legacyId === '' || isset($ids[$legacyId])) {
                throw new RuntimeException('Repository projector user ID is missing or duplicated.');
            }
            $ids[$legacyId] = true;
        }
        $ids = array_keys($ids);
        sort($ids, SORT_STRING);
        return $ids;
    }

    private function compactAudit(array $audit): array
    {
        $compact = [];
        foreach ($audit as $key => $value) {
            if (in_array((string)$key, ['samples', 'items', 'records', 'errors'], true)) continue;
            if (is_scalar($value) || $value === null || is_array($value)) {
                $compact[(string)$key] = $value;
            }
        }
        return $compact;
    }

    private function canonicalJson(array $value): string
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
}
