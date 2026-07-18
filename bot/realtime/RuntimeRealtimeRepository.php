<?php
declare(strict_types=1);

require_once __DIR__ . '/RuntimeRealtimeIdentityTrait.php';
require_once __DIR__ . '/RuntimeRealtimeValueTrait.php';
require_once __DIR__ . '/RuntimeRealtimeSourceTrait.php';
require_once __DIR__ . '/RuntimeRealtimeDatabaseTrait.php';

final class RuntimeRealtimeRepository
{
    use RuntimeRealtimeIdentityTrait;
    use RuntimeRealtimeValueTrait;
    use RuntimeRealtimeSourceTrait;
    use RuntimeRealtimeDatabaseTrait;

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
        $retainedTerminalGames = 0;
        $createdQueue = 0;
        $updatedQueue = 0;
        $unchangedQueue = 0;
        $deletedQueue = 0;

        $database->transaction(function (DatabaseConnectionInterface $db) use (
            $source,
            &$createdGames,
            &$updatedGames,
            &$unchangedGames,
            &$retainedTerminalGames,
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
                if (!$this->isTerminalStatus((string)($existing['projection']['status'] ?? ''))) {
                    throw new RuntimeException('Realtime DB contains a non-terminal match missing from JSON.');
                }
                $retainedTerminalGames++;
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
                'database_total_count' => $comparison['database_total_game_count'],
                'created_count' => $createdGames,
                'updated_count' => $updatedGames,
                'unchanged_count' => $unchangedGames,
                'retained_terminal_count' => $retainedTerminalGames,
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
            'database_total_game_count' => $comparison['database_total_game_count'],
            'retained_terminal_game_count' => $comparison['retained_terminal_game_count'],
            'source_queue_count' => $comparison['source_queue_count'],
            'database_queue_count' => $comparison['database_queue_count'],
            'source_fingerprint' => $comparison['source_fingerprint'],
            'database_fingerprint' => $comparison['database_fingerprint'],
            'blockers' => $comparison['blockers'],
        ];
    }
}
