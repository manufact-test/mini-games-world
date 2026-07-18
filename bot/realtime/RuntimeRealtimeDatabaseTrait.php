<?php
declare(strict_types=1);

trait RuntimeRealtimeDatabaseTrait
{
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
                $snapshotState = $this->decodeJson(
                    $snapshotRows[0]['server_state_json'] ?? null,
                    'match snapshot state'
                );
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
        $managedDatabaseGames = [];
        $retainedTerminalCount = 0;

        foreach ($source['games'] as $matchId => $expected) {
            $actual = $database['games'][$matchId] ?? null;
            if ($actual === null) {
                $blockers[] = 'Realtime match is missing from DB.';
                continue;
            }
            $managedDatabaseGames[$matchId] = $actual;
            if (!$actual['snapshot_ok']) $blockers[] = 'Realtime match snapshot parity failed.';
            if ($expected['fingerprint'] !== $actual['fingerprint']) {
                $blockers[] = 'Realtime match content differs between JSON and DB.';
            }
        }

        foreach ($database['games'] as $matchId => $actual) {
            if (isset($source['games'][$matchId])) continue;
            if (!$this->isTerminalStatus((string)($actual['projection']['status'] ?? ''))) {
                $blockers[] = 'Realtime DB contains a non-terminal match missing from JSON.';
                continue;
            }
            if (!$actual['snapshot_ok']) {
                $blockers[] = 'Retained terminal match snapshot parity failed.';
                continue;
            }
            $retainedTerminalCount++;
        }
        ksort($managedDatabaseGames, SORT_STRING);

        if (array_keys($source['queue']) !== array_keys($database['queue'])) {
            $blockers[] = 'Realtime queue IDs differ between JSON and DB.';
        }
        foreach ($source['queue'] as $queueId => $expected) {
            $actual = $database['queue'][$queueId] ?? null;
            if ($actual === null || $expected['fingerprint'] !== $actual['fingerprint']) {
                $blockers[] = 'Realtime queue content differs between JSON and DB.';
            }
        }

        $sourceFingerprint = $this->stateFingerprint($source);
        $managedDatabase = ['games' => $managedDatabaseGames, 'queue' => $database['queue']];
        $databaseFingerprint = $this->stateFingerprint($managedDatabase);
        if (!hash_equals($sourceFingerprint, $databaseFingerprint)) {
            $blockers[] = 'Realtime aggregate fingerprint differs between JSON and DB.';
        }
        $blockers = array_values(array_unique($blockers));

        return [
            'ok' => $blockers === [],
            'source_game_count' => count($source['games']),
            'database_game_count' => count($managedDatabaseGames),
            'database_total_game_count' => count($database['games']),
            'retained_terminal_game_count' => $retainedTerminalCount,
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
}
