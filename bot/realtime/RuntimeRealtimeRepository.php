<?php
declare(strict_types=1);

final class RuntimeRealtimeRepository
{
    private RuntimeStorageRouter $router;
    private ?DatabaseConnectionInterface $connection;
    private array $ownershipCache = [];

    public function __construct(
        private array $config,
        ?RuntimeStorageRouter $router = null,
        ?DatabaseConnectionInterface $database = null
    ) {
        $this->router = $router ?? new RuntimeStorageRouter($config);
        $this->connection = $database;
    }

    public function synchronize(array $jsonData): array
    {
        $this->assertDatabaseRoute();
        $database = $this->database();
        $source = $this->sourceState($jsonData, $database);
        $createdGames = 0;
        $updatedGames = 0;
        $unchangedGames = 0;
        $deletedGames = 0;
        $createdQueue = 0;
        $updatedQueue = 0;
        $unchangedQueue = 0;
        $deletedQueue = 0;

        $database->transaction(function (DatabaseConnectionInterface $db) use (
            $source,
            &$createdGames,
            &$updatedGames,
            &$unchangedGames,
            &$deletedGames,
            &$createdQueue,
            &$updatedQueue,
            &$unchangedQueue,
            &$deletedQueue
        ): void {
            $store = new RealtimeDatabaseStore($db);
            $current = $this->databaseState($db);

            foreach ($source['games'] as $matchId => $expected) {
                $existing = $current['games'][$matchId] ?? null;
                if ($existing === null) {
                    $match = $expected['match'];
                    $match['state_version'] = 1;
                    $match['server_state'] = $expected['payload'];
                    $store->saveMatchSnapshot($match, $expected['players']);
                    $createdGames++;
                    continue;
                }

                $this->assertImmutableGameIdentity($expected['projection'], $existing['projection']);
                if ($expected['fingerprint'] === $existing['fingerprint'] && $existing['snapshot_ok']) {
                    $unchangedGames++;
                    continue;
                }
                if ($this->timestampSortValue($existing['projection']['updated_at_utc'])
                    > $this->timestampSortValue($expected['projection']['updated_at_utc'])) {
                    throw new RuntimeException('Realtime match DB state is ahead of the JSON rollback source.');
                }

                $match = $expected['match'];
                $match['state_version'] = max(1, (int)$existing['state_version'] + 1);
                $match['server_state'] = $expected['payload'];
                $store->saveMatchSnapshot($match, $expected['players']);
                $updatedGames++;
            }

            foreach ($current['games'] as $matchId => $existing) {
                if (isset($source['games'][$matchId])) continue;
                $status = (string)($existing['projection']['status'] ?? '');
                if (!in_array($status, ['finished', 'cancelled', 'expired', 'abandoned', 'failed'], true)) {
                    throw new RuntimeException('Realtime DB contains a non-terminal match missing from JSON.');
                }
                $db->execute('DELETE FROM mgw_matches WHERE match_id = :match_id', ['match_id' => $matchId]);
                $deletedGames++;
            }

            foreach ($source['queue'] as $queueId => $expected) {
                $existing = $current['queue'][$queueId] ?? null;
                if ($existing === null) {
                    $store->upsertQueueEntry($expected['row']);
                    $createdQueue++;
                    continue;
                }
                $this->assertImmutableQueueIdentity($expected['projection'], $existing['projection']);
                if ($expected['fingerprint'] === $existing['fingerprint']) {
                    $unchangedQueue++;
                    continue;
                }
                if ($this->timestampSortValue($existing['projection']['updated_at_utc'])
                    > $this->timestampSortValue($expected['projection']['updated_at_utc'])) {
                    throw new RuntimeException('Realtime queue DB state is ahead of the JSON rollback source.');
                }
                $store->upsertQueueEntry($expected['row']);
                $updatedQueue++;
            }

            foreach ($current['queue'] as $queueId => $existing) {
                if (isset($source['queue'][$queueId])) continue;
                $db->execute('DELETE FROM mgw_match_queue WHERE queue_id = :queue_id', ['queue_id' => $queueId]);
                $deletedQueue++;
            }
        });

        $comparison = $this->compare($source, $this->databaseState($database));
        if (!$comparison['ok']) {
            throw new RuntimeException(implode(' ', $comparison['blockers']));
        }

        return [
            'games' => [
                'source_count' => $comparison['source_game_count'],
                'database_count' => $comparison['database_game_count'],
                'created_count' => $createdGames,
                'updated_count' => $updatedGames,
                'unchanged_count' => $unchangedGames,
                'deleted_count' => $deletedGames,
            ],
            'queue' => [
                'source_count' => $comparison['source_queue_count'],
                'database_count' => $comparison['database_queue_count'],
                'created_count' => $createdQueue,
                'updated_count' => $updatedQueue,
                'unchanged_count' => $unchangedQueue,
                'deleted_count' => $deletedQueue,
            ],
            'source_fingerprint' => $comparison['source_fingerprint'],
            'database_fingerprint' => $comparison['database_fingerprint'],
            'parity' => true,
        ];
    }

    public function auditParity(array $jsonData): array
    {
        $this->assertDatabaseRoute();
        $database = $this->database();
        $comparison = $this->compare(
            $this->sourceState($jsonData, $database),
            $this->databaseState($database)
        );

        return [
            'ok' => $comparison['ok'],
            'read_only' => true,
            'source_game_count' => $comparison['source_game_count'],
            'database_game_count' => $comparison['database_game_count'],
            'source_queue_count' => $comparison['source_queue_count'],
            'database_queue_count' => $comparison['database_queue_count'],
            'source_fingerprint' => $comparison['source_fingerprint'],
            'database_fingerprint' => $comparison['database_fingerprint'],
            'blockers' => $comparison['blockers'],
        ];
    }

    private function assertDatabaseRoute(): void
    {
        if (!$this->router->enabled()
            || $this->router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE
            || $this->router->routeFor('realtime') !== RuntimeStorageRouter::DRIVER_DATABASE) {
            throw new RuntimeException('Realtime DB runtime requires accounts and realtime routing.');
        }
    }

    private function database(): DatabaseConnectionInterface
    {
        if ($this->connection !== null) return $this->connection;
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Realtime DB runtime requires an enabled database.');
        }
        return $this->connection = PdoConnectionFactory::create($databaseConfig);
    }

    private function sourceState(array $jsonData, DatabaseConnectionInterface $database): array
    {
        $games = [];
        foreach (($jsonData['games'] ?? []) as $sourceKey => $payload) {
            if (!is_array($payload)) throw new RuntimeException('Realtime game JSON row is not an object.');
            $matchId = $this->requiredText($payload['id'] ?? (is_string($sourceKey) ? $sourceKey : ''), 96, 'game id');
            if (isset($games[$matchId])) throw new RuntimeException('Realtime game JSON contains duplicate IDs.');
            $games[$matchId] = $this->sourceGame($matchId, $payload, $database);
        }
        ksort($games, SORT_STRING);

        $queue = [];
        foreach (($jsonData['queue'] ?? []) as $sourceKey => $payload) {
            if (!is_array($payload)) throw new RuntimeException('Realtime queue JSON row is not an object.');
            $legacyUserId = $this->requiredText($payload['user_id'] ?? '', 191, 'queue user id');
            $fallbackId = 'user:' . $legacyUserId;
            $queueId = $this->requiredText(
                $payload['id'] ?? (is_string($sourceKey) ? $sourceKey : $fallbackId),
                96,
                'queue id'
            );
            if (isset($queue[$queueId])) throw new RuntimeException('Realtime queue JSON contains duplicate IDs.');
            $queue[$queueId] = $this->sourceQueue($queueId, $legacyUserId, $payload, $database);
        }
        ksort($queue, SORT_STRING);

        return ['games' => $games, 'queue' => $queue];
    }

    private function sourceGame(
        string $matchId,
        array $payload,
        DatabaseConnectionInterface $database
    ): array {
        $createdAt = $this->timestamp($payload['created_at'] ?? null, $payload['started_at'] ?? null);
        $updatedAt = $this->timestamp(
            $payload['updated_at'] ?? null,
            $payload['last_move_at'] ?? null,
            $payload['finished_at'] ?? null,
            $createdAt
        );
        $status = $this->requiredText($payload['status'] ?? 'active', 32, 'game status');
        $playerIds = is_array($payload['player_ids'] ?? null) ? array_values($payload['player_ids']) : [];
        if ($playerIds === []) throw new RuntimeException('Realtime game has no player IDs.');

        $players = [];
        foreach ($playerIds as $seat => $rawId) {
            $legacyId = $this->requiredText($rawId, 191, 'game player id');
            $identity = $this->playerIdentity($database, $legacyId);
            $players[] = [
                'seat' => $seat,
                'player_ref' => $identity['player_ref'],
                'mgw_id' => $identity['mgw_id'],
                'legacy_user_id' => $identity['legacy_user_id'],
                'player_type' => $identity['player_type'],
                'symbol' => $this->nullableText(
                    is_array($payload['symbols'] ?? null) ? ($payload['symbols'][$legacyId] ?? null) : null,
                    32
                ),
                'display_name' => $this->nullableText(
                    is_array($payload['player_names'] ?? null) ? ($payload['player_names'][$legacyId] ?? null) : null,
                    80
                ),
                'result' => $this->playerResult($legacyId, $payload, $status),
                'joined_at_utc' => $createdAt,
                'updated_at_utc' => $updatedAt,
            ];
        }

        $match = [
            'match_id' => $matchId,
            'game_type' => $this->requiredText($payload['game_type'] ?? 'tictactoe', 32, 'game type'),
            'room' => $this->room($payload['room'] ?? 'match'),
            'status' => $status,
            'board_size' => $this->positiveInt(
                $payload['board_size'] ?? $payload['game_variant_size'] ?? $payload['board_columns'] ?? 3,
                3
            ),
            'bet' => $this->nonNegativeInt($payload['bet'] ?? 0, 'game bet'),
            'match_source' => $this->nullableText($payload['match_source'] ?? 'legacy_json', 32),
            'invite_id' => $this->nullableText($payload['invite_id'] ?? null, 96),
            'source_match_id' => $this->nullableText(
                $payload['source_game_id'] ?? $payload['source_match_id'] ?? $matchId,
                96
            ),
            'turn_player_ref' => $this->nullablePlayerReference($database, $payload['turn'] ?? null),
            'winner_player_ref' => $this->nullablePlayerReference($database, $payload['winner_id'] ?? null),
            'finish_reason' => $this->nullableText($payload['finish_reason'] ?? null, 64),
            'public_state' => null,
            'created_at_utc' => $createdAt,
            'started_at_utc' => $this->nullableTimestamp($payload['started_at'] ?? $payload['created_at'] ?? null),
            'updated_at_utc' => $updatedAt,
            'finished_at_utc' => $this->nullableTimestamp($payload['finished_at'] ?? null),
        ];

        $projection = $match;
        unset($projection['public_state']);
        $projection['server_state_sha256'] = hash('sha256', $this->canonicalJson($payload));
        $projection['players'] = $this->playerProjections($players);

        return [
            'payload' => $payload,
            'match' => $match,
            'players' => $players,
            'projection' => $projection,
            'fingerprint' => hash('sha256', $this->canonicalJson($projection)),
        ];
    }

    private function sourceQueue(
        string $queueId,
        string $legacyUserId,
        array $payload,
        DatabaseConnectionInterface $database
    ): array {
        $identity = $this->ownership($database, $legacyUserId);
        $createdAt = $this->timestamp($payload['created_at'] ?? null);
        $row = [
            'queue_id' => $queueId,
            'player_ref' => $identity['account_ref'],
            'mgw_id' => $identity['mgw_id'],
            'legacy_user_id' => $legacyUserId,
            'game_type' => $this->requiredText($payload['game_type'] ?? 'tictactoe', 32, 'queue game type'),
            'room' => $this->room($payload['room'] ?? 'match'),
            'bet' => $this->nonNegativeInt($payload['bet'] ?? 0, 'queue bet'),
            'board_size' => $this->positiveInt($payload['board_size'] ?? 3, 3),
            'status' => $this->requiredText($payload['status'] ?? 'waiting', 32, 'queue status'),
            'reserved_match_id' => $this->nullableText($payload['reserved_match_id'] ?? null, 96),
            'created_at_utc' => $createdAt,
            'updated_at_utc' => $this->timestamp($payload['updated_at'] ?? null, $createdAt),
            'expires_at_utc' => $this->nullableTimestamp($payload['expires_at'] ?? null),
        ];
        return [
            'row' => $row,
            'projection' => $row,
            'fingerprint' => hash('sha256', $this->canonicalJson($row)),
        ];
    }

    private function databaseState(DatabaseConnectionInterface $database): array
    {
        $games = [];
        foreach ($database->fetchAll('SELECT * FROM mgw_matches ORDER BY match_id') as $row) {
            $matchId = trim((string)($row['match_id'] ?? ''));
            if ($matchId === '' || isset($games[$matchId])) {
                throw new RuntimeException('Realtime DB contains invalid or duplicate match IDs.');
            }
            $players = [];
            foreach ($database->fetchAll(
                'SELECT seat, player_ref, mgw_id, legacy_user_id, player_type, symbol, display_name, result,
                        joined_at_utc, updated_at_utc
                 FROM mgw_match_players WHERE match_id = :match_id ORDER BY seat',
                ['match_id' => $matchId]
            ) as $player) {
                $players[] = [
                    'seat' => (int)($player['seat'] ?? 0),
                    'player_ref' => trim((string)($player['player_ref'] ?? '')),
                    'mgw_id' => $this->nullableText($player['mgw_id'] ?? null, 24),
                    'legacy_user_id' => $this->nullableText($player['legacy_user_id'] ?? null, 191),
                    'player_type' => trim((string)($player['player_type'] ?? 'human')),
                    'symbol' => $this->nullableText($player['symbol'] ?? null, 32),
                    'display_name' => $this->nullableText($player['display_name'] ?? null, 80),
                    'result' => $this->nullableText($player['result'] ?? null, 32),
                    'joined_at_utc' => $this->timestamp($player['joined_at_utc'] ?? null),
                    'updated_at_utc' => $this->timestamp($player['updated_at_utc'] ?? null),
                ];
            }

            $serverState = $this->decodeJson($row['server_state_json'] ?? null, 'match server state');
            $projection = [
                'match_id' => $matchId,
                'game_type' => trim((string)($row['game_type'] ?? '')),
                'room' => trim((string)($row['room'] ?? '')),
                'status' => trim((string)($row['status'] ?? '')),
                'board_size' => (int)($row['board_size'] ?? 0),
                'bet' => (int)($row['bet'] ?? 0),
                'match_source' => $this->nullableText($row['match_source'] ?? null, 32),
                'invite_id' => $this->nullableText($row['invite_id'] ?? null, 96),
                'source_match_id' => $this->nullableText($row['source_match_id'] ?? null, 96),
                'turn_player_ref' => $this->nullableText($row['turn_player_ref'] ?? null, 255),
                'winner_player_ref' => $this->nullableText($row['winner_player_ref'] ?? null, 255),
                'finish_reason' => $this->nullableText($row['finish_reason'] ?? null, 64),
                'created_at_utc' => $this->timestamp($row['created_at_utc'] ?? null),
                'started_at_utc' => $this->nullableTimestamp($row['started_at_utc'] ?? null),
                'updated_at_utc' => $this->timestamp($row['updated_at_utc'] ?? null),
                'finished_at_utc' => $this->nullableTimestamp($row['finished_at_utc'] ?? null),
                'server_state_sha256' => hash('sha256', $this->canonicalJson($serverState)),
                'players' => $players,
            ];
            $stateVersion = max(0, (int)($row['state_version'] ?? 0));
            $snapshotRows = $database->fetchAll(
                'SELECT server_state_json FROM mgw_match_snapshots
                 WHERE match_id = :match_id AND state_version = :state_version',
                ['match_id' => $matchId, 'state_version' => $stateVersion]
            );
            $snapshotOk = false;
            if ($stateVersion > 0 && count($snapshotRows) === 1) {
                $snapshotState = $this->decodeJson($snapshotRows[0]['server_state_json'] ?? null, 'match snapshot state');
                $snapshotOk = hash_equals(
                    $projection['server_state_sha256'],
                    hash('sha256', $this->canonicalJson($snapshotState))
                );
            }
            $games[$matchId] = [
                'projection' => $projection,
                'fingerprint' => hash('sha256', $this->canonicalJson($projection)),
                'state_version' => $stateVersion,
                'snapshot_ok' => $snapshotOk,
            ];
        }
        ksort($games, SORT_STRING);

        $queue = [];
        foreach ($database->fetchAll('SELECT * FROM mgw_match_queue ORDER BY queue_id') as $row) {
            $queueId = trim((string)($row['queue_id'] ?? ''));
            if ($queueId === '' || isset($queue[$queueId])) {
                throw new RuntimeException('Realtime DB contains invalid or duplicate queue IDs.');
            }
            $projection = [
                'queue_id' => $queueId,
                'player_ref' => trim((string)($row['player_ref'] ?? '')),
                'mgw_id' => $this->nullableText($row['mgw_id'] ?? null, 24),
                'legacy_user_id' => $this->nullableText($row['legacy_user_id'] ?? null, 191),
                'game_type' => trim((string)($row['game_type'] ?? '')),
                'room' => trim((string)($row['room'] ?? '')),
                'bet' => (int)($row['bet'] ?? 0),
                'board_size' => (int)($row['board_size'] ?? 0),
                'status' => trim((string)($row['status'] ?? '')),
                'reserved_match_id' => $this->nullableText($row['reserved_match_id'] ?? null, 96),
                'created_at_utc' => $this->timestamp($row['created_at_utc'] ?? null),
                'updated_at_utc' => $this->timestamp($row['updated_at_utc'] ?? null),
                'expires_at_utc' => $this->nullableTimestamp($row['expires_at_utc'] ?? null),
            ];
            $queue[$queueId] = [
                'projection' => $projection,
                'fingerprint' => hash('sha256', $this->canonicalJson($projection)),
            ];
        }
        ksort($queue, SORT_STRING);

        return ['games' => $games, 'queue' => $queue];
    }

    private function compare(array $source, array $database): array
    {
        $blockers = [];
        foreach ($database['games'] as $matchId => $row) {
            if (!$row['snapshot_ok']) $blockers[] = 'Realtime match snapshot parity failed.';
        }
        if (array_keys($source['games']) !== array_keys($database['games'])) {
            $blockers[] = 'Realtime match IDs differ between JSON and DB.';
        }
        if (array_keys($source['queue']) !== array_keys($database['queue'])) {
            $blockers[] = 'Realtime queue IDs differ between JSON and DB.';
        }
        foreach ($source['games'] as $matchId => $expected) {
            $actual = $database['games'][$matchId] ?? null;
            if ($actual === null || $expected['fingerprint'] !== $actual['fingerprint']) {
                $blockers[] = 'Realtime match content differs between JSON and DB.';
            }
        }
        foreach ($source['queue'] as $queueId => $expected) {
            $actual = $database['queue'][$queueId] ?? null;
            if ($actual === null || $expected['fingerprint'] !== $actual['fingerprint']) {
                $blockers[] = 'Realtime queue content differs between JSON and DB.';
            }
        }
        $blockers = array_values(array_unique($blockers));
        $sourceFingerprint = $this->stateFingerprint($source);
        $databaseFingerprint = $this->stateFingerprint($database);
        if (!hash_equals($sourceFingerprint, $databaseFingerprint)) {
            $blockers[] = 'Realtime aggregate fingerprint differs between JSON and DB.';
        }
        $blockers = array_values(array_unique($blockers));

        return [
            'ok' => $blockers === [],
            'source_game_count' => count($source['games']),
            'database_game_count' => count($database['games']),
            'source_queue_count' => count($source['queue']),
            'database_queue_count' => count($database['queue']),
            'source_fingerprint' => $sourceFingerprint,
            'database_fingerprint' => $databaseFingerprint,
            'blockers' => $blockers,
        ];
    }

    private function stateFingerprint(array $state): string
    {
        $parts = [];
        foreach (['games', 'queue'] as $type) {
            foreach ($state[$type] as $key => $row) {
                $parts[] = $type . "\0" . $key . "\0" . (string)$row['fingerprint'];
            }
        }
        sort($parts, SORT_STRING);
        return hash('sha256', implode("\n", $parts));
    }

    private function assertImmutableGameIdentity(array $expected, array $actual): void
    {
        foreach (['match_id', 'game_type', 'room', 'created_at_utc', 'source_match_id'] as $key) {
            if (($expected[$key] ?? null) !== ($actual[$key] ?? null)) {
                throw new RuntimeException('Realtime match immutable identity differs from JSON.');
            }
        }
        $expectedPlayers = array_map(
            static fn(array $row): array => [$row['seat'], $row['player_ref']],
            $expected['players'] ?? []
        );
        $actualPlayers = array_map(
            static fn(array $row): array => [$row['seat'], $row['player_ref']],
            $actual['players'] ?? []
        );
        if ($expectedPlayers !== $actualPlayers) {
            throw new RuntimeException('Realtime match immutable player identity differs from JSON.');
        }
    }

    private function assertImmutableQueueIdentity(array $expected, array $actual): void
    {
        foreach (['queue_id', 'player_ref', 'mgw_id', 'legacy_user_id', 'created_at_utc'] as $key) {
            if (($expected[$key] ?? null) !== ($actual[$key] ?? null)) {
                throw new RuntimeException('Realtime queue immutable identity differs from JSON.');
            }
        }
    }

    private function playerIdentity(DatabaseConnectionInterface $database, string $legacyUserId): array
    {
        if (str_starts_with($legacyUserId, 'bot_')) {
            return [
                'player_ref' => RealtimeDatabaseStore::playerReference(null, null, $legacyUserId),
                'mgw_id' => null,
                'legacy_user_id' => null,
                'player_type' => 'bot',
            ];
        }
        $owned = $this->ownership($database, $legacyUserId);
        return [
            'player_ref' => $owned['account_ref'],
            'mgw_id' => $owned['mgw_id'],
            'legacy_user_id' => $legacyUserId,
            'player_type' => 'human',
        ];
    }

    private function ownership(DatabaseConnectionInterface $database, string $legacyUserId): array
    {
        if (isset($this->ownershipCache[$legacyUserId])) return $this->ownershipCache[$legacyUserId];
        $rows = $database->fetchAll(
            'SELECT account_ref, mgw_id, ownership_status FROM mgw_account_ownership
             WHERE legacy_user_id = :legacy_user_id',
            ['legacy_user_id' => $legacyUserId]
        );
        if (count($rows) !== 1) throw new RuntimeException('Realtime runtime requires exactly one account ownership row.');
        $row = $rows[0];
        $accountRef = trim((string)($row['account_ref'] ?? ''));
        $mgwId = trim((string)($row['mgw_id'] ?? ''));
        if ($accountRef === '' || $mgwId === '' || (string)($row['ownership_status'] ?? '') !== 'active') {
            throw new RuntimeException('Realtime account ownership is incomplete or inactive.');
        }
        return $this->ownershipCache[$legacyUserId] = ['account_ref' => $accountRef, 'mgw_id' => $mgwId];
    }

    private function nullablePlayerReference(DatabaseConnectionInterface $database, mixed $value): ?string
    {
        $legacyUserId = trim((string)$value);
        return $legacyUserId === '' ? null : $this->playerIdentity($database, $legacyUserId)['player_ref'];
    }

    private function playerResult(string $legacyUserId, array $payload, string $status): ?string
    {
        if ($status !== 'finished') return null;
        $winner = trim((string)($payload['winner_id'] ?? ''));
        $loser = trim((string)($payload['loser_id'] ?? ''));
        if ($winner === '') return 'draw';
        if ($legacyUserId === $winner) return 'win';
        if ($legacyUserId === $loser) return 'loss';
        return null;
    }

    private function playerProjections(array $players): array
    {
        return array_map(static fn(array $row): array => [
            'seat' => (int)$row['seat'],
            'player_ref' => $row['player_ref'],
            'mgw_id' => $row['mgw_id'],
            'legacy_user_id' => $row['legacy_user_id'],
            'player_type' => $row['player_type'],
            'symbol' => $row['symbol'],
            'display_name' => $row['display_name'],
            'result' => $row['result'],
            'joined_at_utc' => $row['joined_at_utc'],
            'updated_at_utc' => $row['updated_at_utc'],
        ], $players);
    }

    private function room(mixed $value): string
    {
        return trim((string)$value) === 'gold' ? 'gold' : 'match';
    }

    private function requiredText(mixed $value, int $maxLength, string $label): string
    {
        $text = trim((string)$value);
        if ($text === '') throw new RuntimeException('Missing ' . $label . '.');
        if (strlen($text) > $maxLength) throw new RuntimeException(ucfirst($label) . ' is too long.');
        return $text;
    }

    private function nullableText(mixed $value, int $maxLength): ?string
    {
        $text = trim((string)($value ?? ''));
        if ($text === '') return null;
        if (strlen($text) > $maxLength) throw new RuntimeException('Realtime text value is too long.');
        return $text;
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        $number = is_numeric($value) ? (int)$value : $fallback;
        return $number > 0 ? $number : $fallback;
    }

    private function nonNegativeInt(mixed $value, string $label): int
    {
        if (is_int($value)) $number = $value;
        elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) $number = (int)$value;
        elseif (is_float($value) && floor($value) === $value) $number = (int)$value;
        else throw new RuntimeException('Invalid ' . $label . '.');
        if ($number < 0) throw new RuntimeException('Negative ' . $label . ' is not allowed.');
        return $number;
    }

    private function nullableTimestamp(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));
        return $text === '' ? null : $this->timestamp($text);
    }

    private function timestamp(mixed ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $text = trim((string)($candidate ?? ''));
            if ($text === '') continue;
            try {
                return (new DateTimeImmutable($text))
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s.u');
            } catch (Throwable) {
                continue;
            }
        }
        return '1970-01-01 00:00:00.000000';
    }

    private function timestampSortValue(mixed $value): float
    {
        $text = trim((string)($value ?? ''));
        if ($text === '') return 0.0;
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $text, new DateTimeZone('UTC'));
        if (!$parsed) {
            try {
                $parsed = new DateTimeImmutable($text, new DateTimeZone('UTC'));
            } catch (Throwable) {
                return 0.0;
            }
        }
        return (float)$parsed->format('U.u');
    }

    private function decodeJson(mixed $value, string $label): mixed
    {
        if ($value === null || trim((string)$value) === '') return null;
        try {
            return json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Invalid ' . $label . '.');
        }
    }

    private function canonicalJson(mixed $value): string
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
