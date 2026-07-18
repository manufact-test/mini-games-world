<?php
declare(strict_types=1);

trait RuntimeRealtimeSourceTrait
{
    private function sourceState(array $jsonData, DatabaseConnectionInterface $database): array
    {
        $games = [];
        foreach (($jsonData['games'] ?? []) as $sourceKey => $payload) {
            if (!is_array($payload)) throw new RuntimeException('Realtime game JSON row is not an object.');
            $matchId = $this->requiredText(
                $payload['id'] ?? (is_string($sourceKey) ? $sourceKey : ''),
                96,
                'game id'
            );
            if (isset($games[$matchId])) throw new RuntimeException('Realtime game JSON contains duplicate IDs.');
            $games[$matchId] = $this->sourceGame($matchId, $payload, $database);
        }
        ksort($games, SORT_STRING);

        $queue = [];
        foreach (($jsonData['queue'] ?? []) as $payload) {
            if (!is_array($payload)) throw new RuntimeException('Realtime queue JSON row is not an object.');
            $legacyUserId = $this->requiredText($payload['user_id'] ?? '', 191, 'queue user id');
            $queueId = $this->requiredText($payload['id'] ?? ('user:' . $legacyUserId), 96, 'queue id');
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
}
