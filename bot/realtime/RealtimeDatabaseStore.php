<?php
declare(strict_types=1);

final class RealtimeDatabaseStore
{
    public function __construct(private DatabaseConnectionInterface $database) {}

    public static function playerReference(?string $mgwId, ?string $legacyUserId, ?string $botId = null): string
    {
        foreach (['mgw' => $mgwId, 'legacy' => $legacyUserId, 'bot' => $botId] as $prefix => $value) {
            $value = trim((string)$value);
            if ($value !== '') return $prefix . ':' . $value;
        }
        throw new InvalidArgumentException('A stable player identity is required.');
    }

    public function saveMatchSnapshot(array $match, array $players, array $privateStates = []): array
    {
        $matchId = $this->required($match, 'match_id', 96);
        $version = (int)($match['state_version'] ?? 0);
        if ($version < 1) throw new InvalidArgumentException('Match state_version must be at least 1.');
        if ($players === []) throw new InvalidArgumentException('At least one match player is required.');

        $createdAt = $this->timestamp($match['created_at_utc'] ?? null);
        $updatedAt = $this->timestamp($match['updated_at_utc'] ?? null);
        $publicJson = $this->encodeJson($match['public_state'] ?? null);
        $serverJson = $this->encodeJson($match['server_state'] ?? null);
        $params = [
            'match_id' => $matchId,
            'game_type' => $this->required($match, 'game_type', 32),
            'room' => $this->required($match, 'room', 16),
            'status' => $this->required($match, 'status', 32),
            'board_size' => max(1, (int)($match['board_size'] ?? 0)),
            'bet' => max(0, (int)($match['bet'] ?? 0)),
            'match_source' => $this->nullable($match['match_source'] ?? null, 32),
            'invite_id' => $this->nullable($match['invite_id'] ?? null, 96),
            'source_match_id' => $this->nullable($match['source_match_id'] ?? null, 96),
            'turn_player_ref' => $this->nullable($match['turn_player_ref'] ?? null, 255),
            'winner_player_ref' => $this->nullable($match['winner_player_ref'] ?? null, 255),
            'finish_reason' => $this->nullable($match['finish_reason'] ?? null, 64),
            'state_version' => $version,
            'public_state_json' => $publicJson,
            'server_state_json' => $serverJson,
            'created_at_utc' => $createdAt,
            'started_at_utc' => $this->nullableTimestamp($match['started_at_utc'] ?? null),
            'updated_at_utc' => $updatedAt,
            'finished_at_utc' => $this->nullableTimestamp($match['finished_at_utc'] ?? null),
        ];

        return $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $matchId,
            $version,
            $players,
            $privateStates,
            $publicJson,
            $serverJson,
            $updatedAt,
            $params
        ): array {
            $exists = $db->fetchAll(
                'SELECT match_id FROM mgw_matches WHERE match_id = :match_id' . $this->forUpdate($db),
                ['match_id' => $matchId]
            ) !== [];

            if (!$exists) {
                $db->execute(
                    'INSERT INTO mgw_matches (
                        match_id, game_type, room, status, board_size, bet, match_source, invite_id,
                        source_match_id, turn_player_ref, winner_player_ref, finish_reason, state_version,
                        public_state_json, server_state_json, created_at_utc, started_at_utc,
                        updated_at_utc, finished_at_utc
                     ) VALUES (
                        :match_id, :game_type, :room, :status, :board_size, :bet, :match_source, :invite_id,
                        :source_match_id, :turn_player_ref, :winner_player_ref, :finish_reason, :state_version,
                        :public_state_json, :server_state_json, :created_at_utc, :started_at_utc,
                        :updated_at_utc, :finished_at_utc
                     )',
                    $params
                );
            } else {
                $update = $params;
                unset($update['created_at_utc']);
                $db->execute(
                    'UPDATE mgw_matches SET
                        game_type = :game_type, room = :room, status = :status, board_size = :board_size,
                        bet = :bet, match_source = :match_source, invite_id = :invite_id,
                        source_match_id = :source_match_id, turn_player_ref = :turn_player_ref,
                        winner_player_ref = :winner_player_ref, finish_reason = :finish_reason,
                        state_version = :state_version, public_state_json = :public_state_json,
                        server_state_json = :server_state_json, started_at_utc = :started_at_utc,
                        updated_at_utc = :updated_at_utc, finished_at_utc = :finished_at_utc
                     WHERE match_id = :match_id',
                    $update
                );
            }

            $this->replacePlayers($db, $matchId, $players, $updatedAt);
            $this->persistSnapshot($db, $matchId, $version, $publicJson, $serverJson, $privateStates, $updatedAt);

            return [
                'match_id' => $matchId,
                'state_version' => $version,
                'player_count' => count($players),
                'private_state_count' => count($privateStates),
            ];
        });
    }

    public function loadMatchForPlayer(string $matchId, string $playerRef): ?array
    {
        $matchId = $this->text($matchId, 96);
        $playerRef = $this->text($playerRef, 255);
        if ($matchId === '' || $playerRef === '') return null;

        $rows = $this->database->fetchAll(
            'SELECT match_id, game_type, room, status, board_size, bet, match_source, invite_id,
                    source_match_id, turn_player_ref, winner_player_ref, finish_reason, state_version,
                    public_state_json, created_at_utc, started_at_utc, updated_at_utc, finished_at_utc
             FROM mgw_matches WHERE match_id = :match_id',
            ['match_id' => $matchId]
        );
        if ($rows === []) return null;

        $match = $rows[0];
        $match['board_size'] = (int)$match['board_size'];
        $match['bet'] = (int)$match['bet'];
        $match['state_version'] = (int)$match['state_version'];
        $match['public_state'] = $this->decodeJson($match['public_state_json'] ?? null);
        unset($match['public_state_json']);

        $private = $this->database->fetchAll(
            'SELECT private_state_json FROM mgw_match_player_snapshots
             WHERE match_id = :match_id AND state_version = :state_version AND player_ref = :player_ref',
            ['match_id' => $matchId, 'state_version' => $match['state_version'], 'player_ref' => $playerRef]
        );

        return [
            'match' => $match,
            'players' => $this->database->fetchAll(
                'SELECT seat, player_ref, mgw_id, legacy_user_id, player_type, symbol, display_name, result,
                        joined_at_utc, updated_at_utc
                 FROM mgw_match_players WHERE match_id = :match_id ORDER BY seat',
                ['match_id' => $matchId]
            ),
            'private_state' => $private === [] ? null : $this->decodeJson($private[0]['private_state_json'] ?? null),
        ];
    }

    public function loadServerMatch(string $matchId): ?array
    {
        $matchId = $this->text($matchId, 96);
        if ($matchId === '') return null;
        $rows = $this->database->fetchAll('SELECT * FROM mgw_matches WHERE match_id = :match_id', ['match_id' => $matchId]);
        if ($rows === []) return null;

        $match = $rows[0];
        $match['board_size'] = (int)$match['board_size'];
        $match['bet'] = (int)$match['bet'];
        $match['state_version'] = (int)$match['state_version'];
        $match['public_state'] = $this->decodeJson($match['public_state_json'] ?? null);
        $match['server_state'] = $this->decodeJson($match['server_state_json'] ?? null);
        unset($match['public_state_json'], $match['server_state_json']);
        $match['players'] = $this->database->fetchAll(
            'SELECT * FROM mgw_match_players WHERE match_id = :match_id ORDER BY seat',
            ['match_id' => $matchId]
        );
        $match['private_states'] = [];
        foreach ($this->database->fetchAll(
            'SELECT player_ref, private_state_json FROM mgw_match_player_snapshots
             WHERE match_id = :match_id AND state_version = :state_version',
            ['match_id' => $matchId, 'state_version' => $match['state_version']]
        ) as $row) {
            $match['private_states'][(string)$row['player_ref']] = $this->decodeJson($row['private_state_json'] ?? null);
        }
        return $match;
    }

    public function upsertQueueEntry(array $entry): array
    {
        $queueId = $this->required($entry, 'queue_id', 96);
        $playerRef = $this->required($entry, 'player_ref', 255);
        $createdAt = $this->timestamp($entry['created_at_utc'] ?? null);
        $updatedAt = $this->timestamp($entry['updated_at_utc'] ?? null);

        return $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $entry,
            $queueId,
            $playerRef,
            $createdAt,
            $updatedAt
        ): array {
            $existing = $db->fetchAll(
                'SELECT queue_id, created_at_utc FROM mgw_match_queue WHERE player_ref = :player_ref' . $this->forUpdate($db),
                ['player_ref' => $playerRef]
            );
            $params = [
                'queue_id' => (string)($existing[0]['queue_id'] ?? $queueId),
                'player_ref' => $playerRef,
                'mgw_id' => $this->nullable($entry['mgw_id'] ?? null, 24),
                'legacy_user_id' => $this->nullable($entry['legacy_user_id'] ?? null, 191),
                'game_type' => $this->required($entry, 'game_type', 32),
                'room' => $this->required($entry, 'room', 16),
                'bet' => max(0, (int)($entry['bet'] ?? 0)),
                'board_size' => max(1, (int)($entry['board_size'] ?? 0)),
                'status' => $this->nullable($entry['status'] ?? 'waiting', 32) ?? 'waiting',
                'reserved_match_id' => $this->nullable($entry['reserved_match_id'] ?? null, 96),
                'created_at_utc' => (string)($existing[0]['created_at_utc'] ?? $createdAt),
                'updated_at_utc' => $updatedAt,
                'expires_at_utc' => $this->nullableTimestamp($entry['expires_at_utc'] ?? null),
            ];

            if ($existing === []) {
                $db->execute(
                    'INSERT INTO mgw_match_queue (
                        queue_id, player_ref, mgw_id, legacy_user_id, game_type, room, bet, board_size,
                        status, reserved_match_id, created_at_utc, updated_at_utc, expires_at_utc
                     ) VALUES (
                        :queue_id, :player_ref, :mgw_id, :legacy_user_id, :game_type, :room, :bet, :board_size,
                        :status, :reserved_match_id, :created_at_utc, :updated_at_utc, :expires_at_utc
                     )',
                    $params
                );
            } else {
                $update = $params;
                unset($update['queue_id'], $update['created_at_utc']);
                $db->execute(
                    'UPDATE mgw_match_queue SET
                        mgw_id = :mgw_id, legacy_user_id = :legacy_user_id, game_type = :game_type,
                        room = :room, bet = :bet, board_size = :board_size, status = :status,
                        reserved_match_id = :reserved_match_id, updated_at_utc = :updated_at_utc,
                        expires_at_utc = :expires_at_utc
                     WHERE player_ref = :player_ref',
                    $update
                );
            }
            return $this->findQueueEntry($playerRef) ?? [];
        });
    }

    public function findQueueEntry(string $playerRef): ?array
    {
        $playerRef = $this->text($playerRef, 255);
        if ($playerRef === '') return null;
        $rows = $this->database->fetchAll('SELECT * FROM mgw_match_queue WHERE player_ref = :player_ref', ['player_ref' => $playerRef]);
        if ($rows === []) return null;
        $rows[0]['bet'] = (int)$rows[0]['bet'];
        $rows[0]['board_size'] = (int)$rows[0]['board_size'];
        return $rows[0];
    }

    public function removeQueueEntry(string $playerRef): int
    {
        $playerRef = $this->text($playerRef, 255);
        return $playerRef === '' ? 0 : $this->database->execute(
            'DELETE FROM mgw_match_queue WHERE player_ref = :player_ref',
            ['player_ref' => $playerRef]
        );
    }

    public function upsertInvite(array $invite): array
    {
        $inviteId = $this->required($invite, 'invite_id', 96);
        $token = $this->required($invite, 'token', 96);
        $createdAt = $this->timestamp($invite['created_at_utc'] ?? null);
        $updatedAt = $this->timestamp($invite['updated_at_utc'] ?? null);

        return $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $invite,
            $inviteId,
            $token,
            $createdAt,
            $updatedAt
        ): array {
            $existing = $db->fetchAll(
                'SELECT invite_id, version, created_at_utc FROM mgw_invites WHERE invite_id = :invite_id' . $this->forUpdate($db),
                ['invite_id' => $inviteId]
            );
            $params = [
                'invite_id' => $inviteId,
                'token' => $token,
                'status' => $this->required($invite, 'status', 32),
                'source' => $this->required($invite, 'source', 32),
                'inviter_ref' => $this->required($invite, 'inviter_ref', 255),
                'inviter_mgw_id' => $this->nullable($invite['inviter_mgw_id'] ?? null, 24),
                'inviter_legacy_user_id' => $this->nullable($invite['inviter_legacy_user_id'] ?? null, 191),
                'inviter_name' => $this->required($invite, 'inviter_name', 80),
                'invitee_ref' => $this->nullable($invite['invitee_ref'] ?? null, 255),
                'invitee_mgw_id' => $this->nullable($invite['invitee_mgw_id'] ?? null, 24),
                'invitee_legacy_user_id' => $this->nullable($invite['invitee_legacy_user_id'] ?? null, 191),
                'invitee_name' => $this->nullable($invite['invitee_name'] ?? null, 80),
                'game_type' => $this->required($invite, 'game_type', 32),
                'game_title' => $this->required($invite, 'game_title', 120),
                'room' => $this->required($invite, 'room', 16),
                'bet' => max(0, (int)($invite['bet'] ?? 0)),
                'board_size' => max(1, (int)($invite['board_size'] ?? 0)),
                'board_columns' => isset($invite['board_columns']) ? max(1, (int)$invite['board_columns']) : null,
                'board_rows' => isset($invite['board_rows']) ? max(1, (int)$invite['board_rows']) : null,
                'source_match_id' => $this->nullable($invite['source_match_id'] ?? null, 96),
                'match_id' => $this->nullable($invite['match_id'] ?? null, 96),
                'version' => $existing === [] ? max(1, (int)($invite['version'] ?? 1)) : ((int)$existing[0]['version'] + 1),
                'created_at_utc' => (string)($existing[0]['created_at_utc'] ?? $createdAt),
                'updated_at_utc' => $updatedAt,
                'expires_at_utc' => $this->nullableTimestamp($invite['expires_at_utc'] ?? null),
                'shared_at_utc' => $this->nullableTimestamp($invite['shared_at_utc'] ?? null),
                'opened_at_utc' => $this->nullableTimestamp($invite['opened_at_utc'] ?? null),
                'accepted_at_utc' => $this->nullableTimestamp($invite['accepted_at_utc'] ?? null),
                'ready_deadline_at_utc' => $this->nullableTimestamp($invite['ready_deadline_at_utc'] ?? null),
                'started_at_utc' => $this->nullableTimestamp($invite['started_at_utc'] ?? null),
                'declined_at_utc' => $this->nullableTimestamp($invite['declined_at_utc'] ?? null),
                'cancelled_at_utc' => $this->nullableTimestamp($invite['cancelled_at_utc'] ?? null),
                'cancelled_by_ref' => $this->nullable($invite['cancelled_by_ref'] ?? null, 255),
            ];

            if ($existing === []) {
                $db->execute(
                    'INSERT INTO mgw_invites (' . implode(', ', array_keys($params)) . ')
                     VALUES (:' . implode(', :', array_keys($params)) . ')',
                    $params
                );
            } else {
                $update = $params;
                unset($update['created_at_utc']);
                $assignments = [];
                foreach (array_keys($update) as $column) {
                    if ($column !== 'invite_id') $assignments[] = $column . ' = :' . $column;
                }
                $db->execute('UPDATE mgw_invites SET ' . implode(', ', $assignments) . ' WHERE invite_id = :invite_id', $update);
            }

            return $db->fetchAll('SELECT * FROM mgw_invites WHERE invite_id = :invite_id', ['invite_id' => $inviteId])[0] ?? [];
        });
    }

    public function appendInviteEvent(
        string $inviteId,
        string $eventKey,
        string $eventType,
        ?string $actorRef = null,
        mixed $payload = null,
        ?string $createdAt = null
    ): bool {
        $inviteId = $this->text($inviteId, 96);
        $eventKey = $this->text($eventKey, 191);
        $eventType = $this->text($eventType, 64);
        if ($inviteId === '' || $eventKey === '' || $eventType === '') {
            throw new InvalidArgumentException('Invite event identifiers are required.');
        }
        $actorRef = $this->nullable($actorRef, 255);
        $payloadJson = $this->encodeJson($payload);

        return $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $inviteId,
            $eventKey,
            $eventType,
            $actorRef,
            $payloadJson,
            $createdAt
        ): bool {
            $existing = $db->fetchAll(
                'SELECT event_type, actor_ref, payload_json FROM mgw_invite_events
                 WHERE invite_id = :invite_id AND event_key = :event_key' . $this->forUpdate($db),
                ['invite_id' => $inviteId, 'event_key' => $eventKey]
            );
            if ($existing !== []) {
                $same = (string)$existing[0]['event_type'] === $eventType
                    && (string)($existing[0]['actor_ref'] ?? '') === (string)$actorRef
                    && (string)($existing[0]['payload_json'] ?? '') === (string)$payloadJson;
                if (!$same) throw new RuntimeException('Invite event key was reused with different content.');
                return false;
            }
            $db->execute(
                'INSERT INTO mgw_invite_events (
                    invite_id, event_key, event_type, actor_ref, payload_json, created_at_utc
                 ) VALUES (
                    :invite_id, :event_key, :event_type, :actor_ref, :payload_json, :created_at_utc
                 )',
                [
                    'invite_id' => $inviteId,
                    'event_key' => $eventKey,
                    'event_type' => $eventType,
                    'actor_ref' => $actorRef,
                    'payload_json' => $payloadJson,
                    'created_at_utc' => $this->timestamp($createdAt),
                ]
            );
            return true;
        });
    }

    public function addNotification(array $notification): array
    {
        $notificationId = $this->required($notification, 'notification_id', 96);
        $eventKey = $this->required($notification, 'event_key', 191);
        $recipientRef = $this->required($notification, 'recipient_ref', 255);

        return $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $notification,
            $notificationId,
            $eventKey,
            $recipientRef
        ): array {
            $existing = $db->fetchAll(
                'SELECT * FROM mgw_notifications
                 WHERE recipient_ref = :recipient_ref AND event_key = :event_key' . $this->forUpdate($db),
                ['recipient_ref' => $recipientRef, 'event_key' => $eventKey]
            );
            if ($existing !== []) return $existing[0];

            $db->execute(
                'INSERT INTO mgw_notifications (
                    notification_id, event_key, recipient_ref, mgw_id, legacy_user_id, type, title,
                    message, tone, invite_token, payload_json, created_at_utc, read_at_utc, hidden_at_utc
                 ) VALUES (
                    :notification_id, :event_key, :recipient_ref, :mgw_id, :legacy_user_id, :type, :title,
                    :message, :tone, :invite_token, :payload_json, :created_at_utc, :read_at_utc, :hidden_at_utc
                 )',
                [
                    'notification_id' => $notificationId,
                    'event_key' => $eventKey,
                    'recipient_ref' => $recipientRef,
                    'mgw_id' => $this->nullable($notification['mgw_id'] ?? null, 24),
                    'legacy_user_id' => $this->nullable($notification['legacy_user_id'] ?? null, 191),
                    'type' => $this->required($notification, 'type', 64),
                    'title' => $this->required($notification, 'title', 160),
                    'message' => $this->required($notification, 'message', 10000),
                    'tone' => $this->nullable($notification['tone'] ?? null, 32),
                    'invite_token' => $this->nullable($notification['invite_token'] ?? null, 96),
                    'payload_json' => $this->encodeJson($notification['payload'] ?? null),
                    'created_at_utc' => $this->timestamp($notification['created_at_utc'] ?? null),
                    'read_at_utc' => $this->nullableTimestamp($notification['read_at_utc'] ?? null),
                    'hidden_at_utc' => $this->nullableTimestamp($notification['hidden_at_utc'] ?? null),
                ]
            );
            return $db->fetchAll(
                'SELECT * FROM mgw_notifications WHERE notification_id = :notification_id',
                ['notification_id' => $notificationId]
            )[0] ?? [];
        });
    }

    public function markNotificationRead(string $notificationId, string $recipientRef, ?string $readAt = null): bool
    {
        $notificationId = $this->text($notificationId, 96);
        $recipientRef = $this->text($recipientRef, 255);
        if ($notificationId === '' || $recipientRef === '') return false;
        return $this->database->execute(
            'UPDATE mgw_notifications SET read_at_utc = :read_at_utc
             WHERE notification_id = :notification_id AND recipient_ref = :recipient_ref AND read_at_utc IS NULL',
            [
                'read_at_utc' => $this->timestamp($readAt),
                'notification_id' => $notificationId,
                'recipient_ref' => $recipientRef,
            ]
        ) > 0;
    }

    public function listNotifications(string $recipientRef, int $limit = 50): array
    {
        $recipientRef = $this->text($recipientRef, 255);
        if ($recipientRef === '') return [];
        $limit = max(1, min(100, $limit));
        $rows = $this->database->fetchAll(
            'SELECT * FROM mgw_notifications
             WHERE recipient_ref = :recipient_ref AND hidden_at_utc IS NULL
             ORDER BY created_at_utc DESC LIMIT ' . $limit,
            ['recipient_ref' => $recipientRef]
        );
        foreach ($rows as &$row) {
            $row['payload'] = $this->decodeJson($row['payload_json'] ?? null);
            unset($row['payload_json']);
        }
        unset($row);
        return $rows;
    }

    private function replacePlayers(DatabaseConnectionInterface $db, string $matchId, array $players, string $updatedAt): void
    {
        $db->execute('DELETE FROM mgw_match_players WHERE match_id = :match_id', ['match_id' => $matchId]);
        $seenRefs = [];
        $seenSeats = [];
        foreach (array_values($players) as $index => $player) {
            if (!is_array($player)) throw new InvalidArgumentException('Match players must be arrays.');
            $seat = isset($player['seat']) ? (int)$player['seat'] : $index;
            $playerRef = $this->required($player, 'player_ref', 255);
            if (isset($seenRefs[$playerRef]) || isset($seenSeats[$seat])) {
                throw new InvalidArgumentException('Match player seats and references must be unique.');
            }
            $seenRefs[$playerRef] = true;
            $seenSeats[$seat] = true;
            $db->execute(
                'INSERT INTO mgw_match_players (
                    match_id, seat, player_ref, mgw_id, legacy_user_id, player_type, symbol,
                    display_name, result, joined_at_utc, updated_at_utc
                 ) VALUES (
                    :match_id, :seat, :player_ref, :mgw_id, :legacy_user_id, :player_type, :symbol,
                    :display_name, :result, :joined_at_utc, :updated_at_utc
                 )',
                [
                    'match_id' => $matchId,
                    'seat' => $seat,
                    'player_ref' => $playerRef,
                    'mgw_id' => $this->nullable($player['mgw_id'] ?? null, 24),
                    'legacy_user_id' => $this->nullable($player['legacy_user_id'] ?? null, 191),
                    'player_type' => $this->nullable($player['player_type'] ?? 'human', 16) ?? 'human',
                    'symbol' => $this->nullable($player['symbol'] ?? null, 32),
                    'display_name' => $this->nullable($player['display_name'] ?? null, 80),
                    'result' => $this->nullable($player['result'] ?? null, 32),
                    'joined_at_utc' => $this->timestamp($player['joined_at_utc'] ?? $updatedAt),
                    'updated_at_utc' => $updatedAt,
                ]
            );
        }
    }

    private function persistSnapshot(
        DatabaseConnectionInterface $db,
        string $matchId,
        int $version,
        ?string $publicJson,
        ?string $serverJson,
        array $privateStates,
        string $createdAt
    ): void {
        $existing = $db->fetchAll(
            'SELECT public_state_json, server_state_json FROM mgw_match_snapshots
             WHERE match_id = :match_id AND state_version = :state_version' . $this->forUpdate($db),
            ['match_id' => $matchId, 'state_version' => $version]
        );
        if ($existing !== []) {
            if ((string)($existing[0]['public_state_json'] ?? '') !== (string)$publicJson
                || (string)($existing[0]['server_state_json'] ?? '') !== (string)$serverJson) {
                throw new RuntimeException('Match snapshot version was reused with different state.');
            }
        } else {
            $db->execute(
                'INSERT INTO mgw_match_snapshots (
                    match_id, state_version, public_state_json, server_state_json, created_at_utc
                 ) VALUES (
                    :match_id, :state_version, :public_state_json, :server_state_json, :created_at_utc
                 )',
                [
                    'match_id' => $matchId,
                    'state_version' => $version,
                    'public_state_json' => $publicJson,
                    'server_state_json' => $serverJson,
                    'created_at_utc' => $createdAt,
                ]
            );
        }

        foreach ($privateStates as $playerRef => $state) {
            $playerRef = $this->text((string)$playerRef, 255);
            if ($playerRef === '') throw new InvalidArgumentException('Private match state requires a player reference.');
            $json = $this->encodeJson($state);
            $privateExisting = $db->fetchAll(
                'SELECT private_state_json FROM mgw_match_player_snapshots
                 WHERE match_id = :match_id AND state_version = :state_version AND player_ref = :player_ref' . $this->forUpdate($db),
                ['match_id' => $matchId, 'state_version' => $version, 'player_ref' => $playerRef]
            );
            if ($privateExisting !== []) {
                if ((string)($privateExisting[0]['private_state_json'] ?? '') !== (string)$json) {
                    throw new RuntimeException('Private match snapshot version was reused with different state.');
                }
                continue;
            }
            $db->execute(
                'INSERT INTO mgw_match_player_snapshots (
                    match_id, state_version, player_ref, private_state_json, created_at_utc
                 ) VALUES (
                    :match_id, :state_version, :player_ref, :private_state_json, :created_at_utc
                 )',
                [
                    'match_id' => $matchId,
                    'state_version' => $version,
                    'player_ref' => $playerRef,
                    'private_state_json' => $json,
                    'created_at_utc' => $createdAt,
                ]
            );
        }
    }

    private function forUpdate(DatabaseConnectionInterface $db): string
    {
        return $db->driver() === 'sqlite' ? '' : ' FOR UPDATE';
    }

    private function required(array $source, string $key, int $maxLength): string
    {
        $value = $this->text((string)($source[$key] ?? ''), $maxLength);
        if ($value === '') throw new InvalidArgumentException($key . ' is required.');
        return $value;
    }

    private function nullable(mixed $value, int $maxLength): ?string
    {
        $text = $this->text((string)($value ?? ''), $maxLength);
        return $text === '' ? null : $text;
    }

    private function text(string $value, int $maxLength): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');
        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    private function timestamp(mixed $value): string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') return $this->now();
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        } catch (Throwable) {
            throw new InvalidArgumentException('Invalid UTC timestamp.');
        }
    }

    private function nullableTimestamp(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $this->timestamp($value);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null) return null;
        return json_encode($this->canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function decodeJson(mixed $value): mixed
    {
        if ($value === null || trim((string)$value) === '') return null;
        return json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }
}
