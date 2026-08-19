<?php
declare(strict_types=1);

final class MatchReplayReader
{
    public function __construct(private DatabaseConnectionInterface $database) {}

    public function load(string $matchId): ?array
    {
        $matchId = trim($matchId);
        if ($matchId === '' || strlen($matchId) > 191) {
            throw new InvalidArgumentException('Match ID is invalid.');
        }

        $matchRows = $this->database->fetchAll(
            'SELECT * FROM mgw_matches WHERE match_id = :match_id LIMIT 1',
            ['match_id' => $matchId]
        );
        $match = $matchRows[0] ?? null;
        if (!is_array($match)) return null;

        $players = $this->database->fetchAll(
            'SELECT * FROM mgw_match_players WHERE match_id = :match_id ORDER BY seat_index, player_ref',
            ['match_id' => $matchId]
        );
        $events = $this->database->fetchAll(
            'SELECT * FROM mgw_match_events WHERE match_id = :match_id ORDER BY primary_revision, event_ordinal, event_id',
            ['match_id' => $matchId]
        );
        $snapshots = $this->database->fetchAll(
            'SELECT * FROM mgw_match_snapshots WHERE match_id = :match_id ORDER BY state_version, snapshot_id',
            ['match_id' => $matchId]
        );
        $privateRows = $this->database->fetchAll(
            'SELECT * FROM mgw_match_player_snapshots WHERE match_id = :match_id ORDER BY state_version, player_ref',
            ['match_id' => $matchId]
        );

        $privateByVersion = [];
        foreach ($privateRows as $row) {
            $version = (int)($row['state_version'] ?? 0);
            $playerRef = (string)($row['player_ref'] ?? '');
            if ($version < 1 || $playerRef === '') continue;
            $privateByVersion[$version][$playerRef] = $this->decodeJson((string)($row['private_state_json'] ?? '{}'));
        }

        $eventsByVersion = [];
        $timeline = [];
        foreach ($events as $row) {
            $event = [
                'event_id' => (string)($row['event_id'] ?? ''),
                'primary_revision' => (int)($row['primary_revision'] ?? 0),
                'event_ordinal' => (int)($row['event_ordinal'] ?? 0),
                'snapshot_state_version' => (int)($row['snapshot_state_version'] ?? 0),
                'event_type' => (string)($row['event_type'] ?? ''),
                'occurred_at_utc' => (string)($row['occurred_at_utc'] ?? ''),
                'actor_user_id' => $row['actor_user_id'] ?? null,
                'game_type' => (string)($row['game_type'] ?? ''),
                'rules_version' => (string)($row['rules_version'] ?? ''),
                'engine_version' => (string)($row['engine_version'] ?? ''),
                'payload' => $this->decodeJson((string)($row['payload_json'] ?? '{}')),
                'before_state_sha256' => $row['before_state_sha256'] ?? null,
                'after_state_sha256' => (string)($row['after_state_sha256'] ?? ''),
                'retention_class' => (string)($row['retention_class'] ?? ''),
                'retain_until_utc' => $row['retain_until_utc'] ?? null,
            ];
            $timeline[] = $event;
            $version = $event['snapshot_state_version'];
            if ($version > 0) $eventsByVersion[$version][] = $event;
        }

        $frames = [];
        $availableVersions = [];
        foreach ($snapshots as $row) {
            $version = (int)($row['state_version'] ?? 0);
            if ($version < 1) continue;
            $availableVersions[$version] = true;
            $frames[] = [
                'state_version' => $version,
                'created_at_utc' => (string)($row['created_at_utc'] ?? ''),
                'public_state' => $this->decodeJson((string)($row['public_state_json'] ?? '{}')),
                'server_state' => $this->decodeJson((string)($row['server_state_json'] ?? '{}')),
                'private_states' => $privateByVersion[$version] ?? [],
                'events' => $eventsByVersion[$version] ?? [],
            ];
        }

        $missing = [];
        foreach (array_keys($eventsByVersion) as $version) {
            if (!isset($availableVersions[$version])) $missing[] = (int)$version;
        }
        sort($missing, SORT_NUMERIC);

        return [
            'match' => $this->normalizeMatch($match),
            'players' => array_map(fn(array $player): array => $this->normalizePlayer($player), $players),
            'timeline' => $timeline,
            'frames' => $frames,
            'diagnostics' => [
                'replayable' => $frames !== [] && $missing === [],
                'event_count' => count($timeline),
                'snapshot_count' => count($frames),
                'missing_snapshot_versions' => $missing,
            ],
        ];
    }

    private function normalizeMatch(array $row): array
    {
        return [
            'match_id' => (string)($row['match_id'] ?? ''),
            'game_type' => (string)($row['game_type'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'board_size' => $row['board_size'] ?? null,
            'bet' => $row['bet'] ?? null,
            'match_source' => $row['match_source'] ?? null,
            'winner_player_ref' => $row['winner_player_ref'] ?? null,
            'finish_reason' => $row['finish_reason'] ?? null,
            'state_version' => (int)($row['state_version'] ?? 0),
            'created_at_utc' => $row['created_at_utc'] ?? null,
            'started_at_utc' => $row['started_at_utc'] ?? null,
            'updated_at_utc' => $row['updated_at_utc'] ?? null,
            'finished_at_utc' => $row['finished_at_utc'] ?? null,
        ];
    }

    private function normalizePlayer(array $row): array
    {
        return [
            'player_ref' => (string)($row['player_ref'] ?? ''),
            'seat_index' => (int)($row['seat_index'] ?? 0),
            'role' => $row['role'] ?? null,
            'is_bot' => (bool)($row['is_bot'] ?? false),
            'display_name' => $row['display_name'] ?? null,
        ];
    }

    private function decodeJson(string $json): mixed
    {
        if ($json === '') return null;
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            return ['_invalid_json' => true, '_sha256' => hash('sha256', $json)];
        }
    }
}
