<?php
declare(strict_types=1);

final class RuntimeWeeklyBonusRepository
{
    private const STATE_FIELDS = [
        'weekly_match_welcome_grant_done',
        'weekly_match_welcome_grant_at',
        'weekly_match_welcome_grant_amount',
        'weekly_match_welcome_grant_migrated_at',
        'weekly_match_first_grant_done',
        'weekly_match_bonus_checked_key',
        'weekly_match_bonus_checked_at',
        'weekly_match_bonus_checked_games',
        'weekly_match_bonus_last_key',
        'weekly_match_bonus_last_at',
        'weekly_match_bonus_last_amount',
        'weekly_match_bonus_last_qualification',
        'weekly_bonus_last',
    ];

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
        return $this->synchronizeCurrentJson();
    }

    public function synchronizeCurrentJson(): array
    {
        $this->assertDatabaseRoute();
        $snapshot = $this->snapshot();

        $realtime = (new LegacyRealtimeShadowSyncService($this->storage, $this->database))->run();
        if (empty($realtime['ok'])) {
            throw new RuntimeException('Weekly bonus realtime shadow synchronization failed.');
        }

        $economy = (new RuntimeEconomyRepository(
            $this->config,
            $this->router,
            $this->database
        ))->synchronize($snapshot);
        $states = $this->synchronizeStates($snapshot);
        $audit = $this->auditParity($snapshot);
        if (empty($audit['ok'])) {
            throw new RuntimeException(
                'Weekly bonus DB runtime reconciliation failed: '
                . implode('; ', array_map('strval', (array)($audit['blockers'] ?? [])))
            );
        }

        return [
            'ok' => true,
            'action' => 'synchronize',
            'storage_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'realtime' => [
                'ok' => true,
                'source_fingerprint' => (string)($realtime['source_fingerprint'] ?? ''),
                'sections' => $realtime['sections'] ?? [],
            ],
            'economy' => $this->compactEconomy($economy),
            'weekly_states' => $states,
            'audit' => $audit,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    public function auditParity(?array $jsonSnapshot = null): array
    {
        $this->assertDatabaseRoute();
        $snapshot = $jsonSnapshot ?? $this->snapshot();
        $source = $this->sourceStates($snapshot);
        $rows = $this->database->fetchAll(
            'SELECT account_ref, legacy_user_id, state_json, state_sha256, status_json, status_sha256 '
            . 'FROM mgw_runtime_weekly_bonus_state ORDER BY account_ref'
        );

        $databaseStates = [];
        $duplicateDatabaseCount = 0;
        $corruptedCount = 0;
        foreach ($rows as $row) {
            $accountRef = trim((string)($row['account_ref'] ?? ''));
            if ($accountRef === '' || isset($databaseStates[$accountRef])) {
                $duplicateDatabaseCount++;
                continue;
            }
            $stateJson = (string)($row['state_json'] ?? '');
            $stateHash = strtolower(trim((string)($row['state_sha256'] ?? '')));
            $statusJson = (string)($row['status_json'] ?? '');
            $statusHash = strtolower(trim((string)($row['status_sha256'] ?? '')));
            if (!$this->validPayload($stateJson, $stateHash) || !$this->validPayload($statusJson, $statusHash)) {
                $corruptedCount++;
            }
            $databaseStates[$accountRef] = $row;
        }

        $mismatchCount = 0;
        $missingCount = 0;
        $sourceParts = [];
        $databaseParts = [];
        foreach ($source['states'] as $accountRef => $expected) {
            $sourceHash = (string)$expected['state_sha256'];
            $sourceParts[] = $accountRef . ':' . $sourceHash;
            $row = $databaseStates[$accountRef] ?? null;
            if (!is_array($row)) {
                $missingCount++;
                $mismatchCount++;
                continue;
            }
            $storedHash = strtolower(trim((string)($row['state_sha256'] ?? '')));
            $storedJson = (string)($row['state_json'] ?? '');
            if (!$this->validPayload($storedJson, $storedHash)) {
                $mismatchCount++;
                continue;
            }
            $databaseParts[] = $accountRef . ':' . $storedHash;
            if (!hash_equals($sourceHash, $storedHash)
                || (string)($row['legacy_user_id'] ?? '') !== (string)$expected['legacy_user_id']) {
                $mismatchCount++;
            }
        }

        $extraCount = 0;
        foreach (array_keys($databaseStates) as $accountRef) {
            if (!isset($source['states'][$accountRef])) $extraCount++;
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
        $realtime = (new RuntimeRealtimeRepository(
            $this->config,
            $this->router,
            $this->database
        ))->auditParity($snapshot);

        $blockers = [];
        if ($source['invalid_count'] > 0) {
            $blockers[] = 'Current JSON weekly bonus state contains invalid users or ambiguous ownership.';
        }
        if ($duplicateDatabaseCount > 0) {
            $blockers[] = 'Database weekly bonus state contains duplicate or invalid account references.';
        }
        if ($corruptedCount > 0) {
            $blockers[] = 'Database weekly bonus state contains corrupted payloads.';
        }
        if ($mismatchCount > 0 || !hash_equals($sourceFingerprint, $databaseFingerprint)) {
            $blockers[] = 'Database weekly bonus state differs from the current JSON rollback state.';
        }
        foreach ((array)($economy['blockers'] ?? []) as $reason) $blockers[] = (string)$reason;
        foreach ((array)($realtime['blockers'] ?? []) as $reason) $blockers[] = (string)$reason;
        $blockers = $this->uniqueStrings($blockers);

        return [
            'ok' => $blockers === [],
            'read_only' => true,
            'source_user_count' => count($source['states']),
            'database_user_count' => count($databaseStates),
            'invalid_source_count' => $source['invalid_count'],
            'mismatch_count' => $mismatchCount,
            'missing_count' => $missingCount,
            'extra_count' => $extraCount,
            'duplicate_database_count' => $duplicateDatabaseCount,
            'corrupted_count' => $corruptedCount,
            'json_weekly_fingerprint' => $sourceFingerprint,
            'database_weekly_fingerprint' => $databaseFingerprint,
            'economy' => [
                'ok' => !empty($economy['ok']),
                'shadow_delta_count' => (int)($economy['shadow_delta_count'] ?? 0),
                'planned_delta_count' => (int)($economy['reconciliation']['planned_delta_count'] ?? 0),
                'integrity_failure_count' => (int)($economy['reconciliation']['integrity_failure_count'] ?? 0),
                'active_reservation_count' => (int)($economy['reconciliation']['active_reservation_count'] ?? 0),
            ],
            'realtime' => [
                'ok' => !empty($realtime['ok']),
                'source_game_count' => (int)($realtime['source_game_count'] ?? 0),
                'database_game_count' => (int)($realtime['database_game_count'] ?? 0),
                'source_queue_count' => (int)($realtime['source_queue_count'] ?? 0),
                'database_queue_count' => (int)($realtime['database_queue_count'] ?? 0),
            ],
            'blockers' => $blockers,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    public function statusForLegacyUser(string $legacyUserId): array
    {
        $this->assertDatabaseRoute();
        $legacyUserId = trim($legacyUserId);
        if ($legacyUserId === '') throw new RuntimeException('Weekly bonus DB read requires a user identity.');

        $rows = $this->database->fetchAll(
            'SELECT status_json, status_sha256 FROM mgw_runtime_weekly_bonus_state WHERE legacy_user_id = :legacy_user_id',
            ['legacy_user_id' => $legacyUserId]
        );
        if (count($rows) !== 1) throw new RuntimeException('Weekly bonus DB state is missing or ambiguous.');

        $json = (string)($rows[0]['status_json'] ?? '');
        $hash = strtolower(trim((string)($rows[0]['status_sha256'] ?? '')));
        if (!$this->validPayload($json, $hash)) throw new RuntimeException('Weekly bonus DB status payload is corrupted.');

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Weekly bonus DB status payload is invalid.', 0, $error);
        }
        if (!is_array($decoded)) throw new RuntimeException('Weekly bonus DB status payload root is invalid.');
        return $decoded;
    }

    private function synchronizeStates(array $snapshot): array
    {
        $source = $this->sourceStates($snapshot);
        if ($source['invalid_count'] > 0) {
            throw new RuntimeException('Weekly bonus JSON contains invalid users or ambiguous ownership.');
        }

        $rows = $this->database->fetchAll(
            'SELECT account_ref, state_sha256, status_sha256 FROM mgw_runtime_weekly_bonus_state'
        );
        $existing = [];
        foreach ($rows as $row) {
            $accountRef = trim((string)($row['account_ref'] ?? ''));
            if ($accountRef !== '') $existing[$accountRef] = $row;
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
            foreach ($source['states'] as $accountRef => $expected) {
                $current = $existing[$accountRef] ?? null;
                if (is_array($current)
                    && hash_equals((string)($current['state_sha256'] ?? ''), (string)$expected['state_sha256'])
                    && hash_equals((string)($current['status_sha256'] ?? ''), (string)$expected['status_sha256'])) {
                    $unchanged++;
                    continue;
                }

                if (!is_array($current)) {
                    $database->execute(
                        'INSERT INTO mgw_runtime_weekly_bonus_state (
                            account_ref, legacy_user_id, state_json, state_sha256, status_json, status_sha256,
                            checked_key, last_key, welcome_grant_done, checked_games, source_updated_at_utc, synced_at_utc
                         ) VALUES (
                            :account_ref, :legacy_user_id, :state_json, :state_sha256, :status_json, :status_sha256,
                            :checked_key, :last_key, :welcome_grant_done, :checked_games, :source_updated_at_utc, :synced_at_utc
                         )',
                        $expected
                    );
                    $inserted++;
                    continue;
                }

                $database->execute(
                    'UPDATE mgw_runtime_weekly_bonus_state SET
                        legacy_user_id = :legacy_user_id,
                        state_json = :state_json,
                        state_sha256 = :state_sha256,
                        status_json = :status_json,
                        status_sha256 = :status_sha256,
                        checked_key = :checked_key,
                        last_key = :last_key,
                        welcome_grant_done = :welcome_grant_done,
                        checked_games = :checked_games,
                        source_updated_at_utc = :source_updated_at_utc,
                        synced_at_utc = :synced_at_utc
                     WHERE account_ref = :account_ref',
                    $expected
                );
                $updated++;
            }
        });

        $extraCount = 0;
        foreach (array_keys($existing) as $accountRef) {
            if (!isset($source['states'][$accountRef])) $extraCount++;
        }
        if ($extraCount > 0) {
            throw new RuntimeException('Weekly bonus DB state contains users missing from JSON rollback storage.');
        }

        return [
            'source_user_count' => count($source['states']),
            'inserted_count' => $inserted,
            'updated_count' => $updated,
            'unchanged_count' => $unchanged,
            'deleted_count' => 0,
        ];
    }

    private function sourceStates(array $snapshot): array
    {
        $states = [];
        $invalid = 0;
        $service = new WeeklyMatchEconomyService($this->config);
        foreach (is_array($snapshot['users'] ?? null) ? $snapshot['users'] : [] as $key => $user) {
            if (!is_array($user) || !empty($user['is_dev_user'])) continue;
            $legacyUserId = trim((string)($user['id'] ?? $key));
            if ($legacyUserId === '') {
                $invalid++;
                continue;
            }
            $ownership = $this->ownership($legacyUserId);
            if ($ownership === null || isset($states[$ownership['account_ref']])) {
                $invalid++;
                continue;
            }

            $state = $this->weeklyState($user);
            $status = $service->status($snapshot, $user);
            $stateJson = LedgerIntegrity::canonicalJson($state);
            $statusJson = LedgerIntegrity::canonicalJson($status);
            $states[$ownership['account_ref']] = [
                'account_ref' => $ownership['account_ref'],
                'legacy_user_id' => $legacyUserId,
                'state_json' => $stateJson,
                'state_sha256' => hash('sha256', $stateJson),
                'status_json' => $statusJson,
                'status_sha256' => hash('sha256', $statusJson),
                'checked_key' => $this->nullableText($user['weekly_match_bonus_checked_key'] ?? null, 64),
                'last_key' => $this->nullableText($user['weekly_match_bonus_last_key'] ?? null, 64),
                'welcome_grant_done' => !empty($user['weekly_match_welcome_grant_done']) ? 1 : 0,
                'checked_games' => max(0, (int)($user['weekly_match_bonus_checked_games'] ?? 0)),
                'source_updated_at_utc' => $this->sourceUpdatedAt($user),
                'synced_at_utc' => $this->now(),
            ];
        }
        ksort($states, SORT_STRING);
        return ['states' => $states, 'invalid_count' => $invalid];
    }

    private function weeklyState(array $user): array
    {
        $state = [];
        foreach (self::STATE_FIELDS as $field) {
            if (array_key_exists($field, $user)) $state[$field] = $user[$field];
        }
        ksort($state, SORT_STRING);
        return $state;
    }

    private function ownership(string $legacyUserId): ?array
    {
        $rows = $this->database->fetchAll(
            "SELECT account_ref FROM mgw_account_ownership
             WHERE legacy_user_id = :legacy_user_id AND ownership_status = 'active'",
            ['legacy_user_id' => $legacyUserId]
        );
        if (count($rows) !== 1) return null;
        $accountRef = trim((string)($rows[0]['account_ref'] ?? ''));
        return $accountRef === '' ? null : ['account_ref' => $accountRef];
    }

    private function sourceUpdatedAt(array $user): ?string
    {
        foreach ([
            'weekly_match_bonus_last_at',
            'weekly_match_bonus_checked_at',
            'weekly_match_welcome_grant_at',
            'updated_at',
            'last_seen_at',
            'registered_at',
        ] as $field) {
            $timestamp = $this->timestamp($user[$field] ?? null);
            if ($timestamp !== null) return $timestamp;
        }
        return null;
    }

    private function snapshot(): array
    {
        if ($this->storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
            throw new RuntimeException('Weekly bonus DB runtime requires JSON rollback storage.');
        }
        $snapshot = $this->storage->readOnly(static fn(array $data): array => $data);
        if (!is_array($snapshot)) throw new RuntimeException('Weekly bonus JSON snapshot is invalid.');
        return $snapshot;
    }

    private function assertDatabaseRoute(): void
    {
        foreach (['accounts', 'realtime', 'economy', 'history', 'weekly_bonus'] as $module) {
            if ($this->router->routeFor($module) !== RuntimeStorageRouter::DRIVER_DATABASE) {
                throw new RuntimeException(
                    'Weekly bonus DB runtime requires accounts, realtime, economy, history and weekly_bonus routing.'
                );
            }
        }
        $environment = strtolower(trim((string)($this->config['environment'] ?? 'production')));
        if (!in_array($environment, ['staging', 'local'], true)) {
            throw new RuntimeException('Weekly bonus DB runtime is forbidden outside staging/local.');
        }
    }

    private function connect(): DatabaseConnectionInterface
    {
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) throw new RuntimeException('Weekly bonus DB runtime requires an enabled database.');
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

    private function validPayload(string $json, string $hash): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1 || !hash_equals($hash, hash('sha256', $json))) return false;
        try {
            return is_array(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return false;
        }
    }

    private function uniqueStrings(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $values
        ), static fn(string $value): bool => $value !== '')));
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
