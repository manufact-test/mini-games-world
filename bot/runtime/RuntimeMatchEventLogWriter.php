<?php
declare(strict_types=1);

require_once __DIR__ . '/RuntimeMatchEventContext.php';
require_once __DIR__ . '/RuntimeMatchVersionResolver.php';

final class RuntimeMatchEventLogWriter
{
    public const TABLE = 'mgw_match_events';

    private RuntimeMatchVersionResolver $versions;

    public function __construct(string $projectRoot)
    {
        $this->versions = new RuntimeMatchVersionResolver($projectRoot);
    }

    public function appendTransition(
        DatabaseConnectionInterface $database,
        int $primaryRevision,
        array $beforeState,
        array $afterState,
        ?array $requestContext = null
    ): array {
        if ($primaryRevision < 1) {
            throw new InvalidArgumentException('Match event primary revision must be positive.');
        }

        $beforeGames = is_array($beforeState['games'] ?? null) ? $beforeState['games'] : [];
        $afterGames = is_array($afterState['games'] ?? null) ? $afterState['games'] : [];
        $request = $requestContext ?? RuntimeMatchEventContext::current();
        $created = 0;
        $matches = [];

        foreach ($afterGames as $matchId => $afterGame) {
            if (!is_array($afterGame)) continue;
            $matchId = trim((string)($afterGame['id'] ?? $matchId));
            if ($matchId === '') continue;

            $beforeGame = is_array($beforeGames[$matchId] ?? null) ? $beforeGames[$matchId] : null;
            if ($beforeGame !== null
                && hash_equals($this->canonicalHash($beforeGame), $this->canonicalHash($afterGame))) {
                continue;
            }

            $events = $this->eventsForTransition($matchId, $beforeGame, $afterGame, $request);
            if ($events === []) continue;

            $snapshotVersion = $this->expectedSnapshotVersion($database, $matchId);
            $gameType = trim((string)($afterGame['game_type'] ?? $afterGame['type'] ?? 'tictactoe'));
            $versions = $this->versions->resolve($gameType);
            $beforeHash = $beforeGame === null ? null : $this->canonicalHash($beforeGame);
            $afterHash = $this->canonicalHash($afterGame);

            foreach (array_values($events) as $ordinal => $event) {
                $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
                $occurredAt = $this->validTimestamp(
                    (string)($event['occurred_at_utc'] ?? ''),
                    (string)($request['occurred_at_utc'] ?? '')
                );
                $actor = trim((string)($event['actor_user_id'] ?? ''));
                $eventType = trim((string)($event['event_type'] ?? 'lifecycle'));
                $payloadJson = $this->canonicalJson($payload);
                $eventId = hash('sha256', implode('|', [
                    $matchId,
                    (string)$primaryRevision,
                    (string)$ordinal,
                    $eventType,
                    $afterHash,
                    $payloadJson,
                ]));

                $inserted = $database->execute(
                    'INSERT INTO ' . self::TABLE . '
                        (event_id, match_id, primary_revision, event_ordinal, snapshot_state_version,
                         event_type, occurred_at_utc, actor_user_id, game_type,
                         rules_version, engine_version, payload_json,
                         before_state_sha256, after_state_sha256,
                         retention_class, retain_until_utc, created_at_utc)
                     VALUES
                        (:event_id, :match_id, :primary_revision, :event_ordinal, :snapshot_state_version,
                         :event_type, :occurred_at_utc, :actor_user_id, :game_type,
                         :rules_version, :engine_version, :payload_json,
                         :before_state_sha256, :after_state_sha256,
                         :retention_class, :retain_until_utc, :created_at_utc)',
                    [
                        'event_id' => $eventId,
                        'match_id' => $matchId,
                        'primary_revision' => $primaryRevision,
                        'event_ordinal' => $ordinal,
                        'snapshot_state_version' => $snapshotVersion,
                        'event_type' => $eventType,
                        'occurred_at_utc' => $occurredAt,
                        'actor_user_id' => $actor !== '' ? $actor : null,
                        'game_type' => $gameType,
                        'rules_version' => $versions['rules_version'],
                        'engine_version' => $versions['engine_version'],
                        'payload_json' => $payloadJson,
                        'before_state_sha256' => $beforeHash,
                        'after_state_sha256' => $afterHash,
                        'retention_class' => 'default',
                        'retain_until_utc' => null,
                        'created_at_utc' => gmdate(DATE_ATOM),
                    ]
                );
                if ($inserted !== 1) {
                    throw new RuntimeException('Match event log insert did not affect exactly one row.');
                }
                $created++;
            }
            $matches[] = $matchId;
        }

        return [
            'created_count' => $created,
            'match_ids' => array_values(array_unique($matches)),
        ];
    }

    private function eventsForTransition(
        string $matchId,
        ?array $before,
        array $after,
        ?array $request
    ): array {
        $events = [];
        $requestAction = trim((string)($request['api_action'] ?? ''));
        $requestGameId = trim((string)($request['game_id'] ?? ''));
        $requestTargetsMatch = $requestGameId === '' || hash_equals($matchId, $requestGameId);
        $requestTime = (string)($request['occurred_at_utc'] ?? '');

        if ($before === null) {
            $events[] = [
                'event_type' => 'match_started',
                'occurred_at_utc' => (string)($after['created_at'] ?? $after['started_at'] ?? $requestTime),
                'actor_user_id' => '',
                'payload' => [
                    'status' => (string)($after['status'] ?? ''),
                    'player_ids' => array_values(array_map('strval', (array)($after['player_ids'] ?? []))),
                    'board_size' => isset($after['board_size']) ? (int)$after['board_size'] : null,
                    'is_bot_game' => !empty($after['is_bot_game']),
                ],
            ];
        }

        foreach ($this->disconnectEvents($before, $after, $requestTime) as $event) {
            $events[] = $event;
        }

        if ($before !== null && $requestTargetsMatch
            && in_array($requestAction, ['game_action', 'make_move'], true)) {
            $actor = trim((string)($before['current_turn'] ?? $before['turn_user_id'] ?? ''));
            $events[] = [
                'event_type' => 'move',
                'occurred_at_utc' => $requestTime,
                'actor_user_id' => $actor,
                'payload' => [
                    'api_action' => $requestAction,
                    'game_action' => is_array($request['game_action'] ?? null)
                        ? $request['game_action']
                        : [],
                ],
            ];
        } elseif ($before !== null && $requestTargetsMatch && $requestAction === 'leave_game') {
            $winnerId = trim((string)($after['winner_id'] ?? ''));
            $actor = $this->otherPlayer($after, $winnerId);
            $events[] = [
                'event_type' => 'surrender',
                'occurred_at_utc' => $requestTime,
                'actor_user_id' => $actor,
                'payload' => ['api_action' => 'leave_game'],
            ];
        }

        $beforeStatus = (string)($before['status'] ?? '');
        $afterStatus = (string)($after['status'] ?? '');
        if ($before !== null && $beforeStatus !== 'finished' && $afterStatus === 'finished') {
            $events[] = [
                'event_type' => 'result',
                'occurred_at_utc' => (string)($after['finished_at'] ?? $after['updated_at'] ?? $requestTime),
                'actor_user_id' => '',
                'payload' => array_filter([
                    'status' => 'finished',
                    'winner_id' => (string)($after['winner_id'] ?? ''),
                    'loser_id' => (string)($after['loser_id'] ?? ''),
                    'finish_reason' => (string)($after['finish_reason'] ?? $after['end_reason'] ?? ''),
                    'result' => is_scalar($after['result'] ?? null) ? $after['result'] : null,
                ], static fn(mixed $value): bool => $value !== '' && $value !== null),
            ];
        }

        if ($events === []) {
            $events[] = [
                'event_type' => 'lifecycle',
                'occurred_at_utc' => $requestTime,
                'actor_user_id' => '',
                'payload' => [
                    'api_action' => $requestAction,
                    'before_status' => $beforeStatus,
                    'after_status' => $afterStatus,
                ],
            ];
        }

        return $events;
    }

    private function disconnectEvents(?array $before, array $after, string $fallbackTime): array
    {
        if ($before === null) return [];

        $beforeReconnect = is_array($before['reconnect_v2'] ?? null) ? $before['reconnect_v2'] : [];
        $afterReconnect = is_array($after['reconnect_v2'] ?? null) ? $after['reconnect_v2'] : [];
        $beforePlayers = is_array($beforeReconnect['players'] ?? null) ? $beforeReconnect['players'] : [];
        $afterPlayers = is_array($afterReconnect['players'] ?? null) ? $afterReconnect['players'] : [];
        $events = [];

        foreach ($afterPlayers as $playerId => $state) {
            if (isset($beforePlayers[$playerId]) || !is_array($state)) continue;
            $events[] = [
                'event_type' => 'disconnect',
                'occurred_at_utc' => (string)($state['disconnected_at'] ?? $fallbackTime),
                'actor_user_id' => (string)$playerId,
                'payload' => array_filter([
                    'deadline_at' => (string)($state['deadline_at'] ?? ''),
                    'disconnected_at_ms' => isset($state['disconnected_at_ms'])
                        ? (int)$state['disconnected_at_ms']
                        : null,
                    'deadline_ms' => isset($state['deadline_ms']) ? (int)$state['deadline_ms'] : null,
                ], static fn(mixed $value): bool => $value !== '' && $value !== null),
            ];
        }

        foreach ($beforePlayers as $playerId => $state) {
            if (isset($afterPlayers[$playerId])) continue;
            if (($after['status'] ?? '') === 'finished') continue;
            $events[] = [
                'event_type' => 'reconnect',
                'occurred_at_utc' => $fallbackTime,
                'actor_user_id' => (string)$playerId,
                'payload' => [],
            ];
        }

        return $events;
    }

    private function expectedSnapshotVersion(DatabaseConnectionInterface $database, string $matchId): int
    {
        $value = $database->fetchValue(
            'SELECT state_version FROM mgw_matches WHERE match_id = :match_id',
            ['match_id' => $matchId]
        );
        if ($value === null || $value === false || $value === '') {
            return 1;
        }
        return max(1, (int)$value + 1);
    }

    private function otherPlayer(array $game, string $knownPlayerId): string
    {
        foreach ((array)($game['player_ids'] ?? []) as $playerId) {
            $playerId = trim((string)$playerId);
            if ($playerId !== '' && ($knownPlayerId === '' || $playerId !== $knownPlayerId)) {
                return $playerId;
            }
        }
        return '';
    }

    private function validTimestamp(string $preferred, string $fallback): string
    {
        foreach ([$preferred, $fallback, gmdate(DATE_ATOM)] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && strtotime($candidate) !== false) {
                return gmdate('c', (int)strtotime($candidate));
            }
        }
        return gmdate(DATE_ATOM);
    }

    private function canonicalHash(array $value): string
    {
        return hash('sha256', $this->canonicalJson($value));
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
