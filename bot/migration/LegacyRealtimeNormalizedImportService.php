<?php
declare(strict_types=1);

final class LegacyRealtimeNormalizedImportService
{
    private const META_KEY = 'legacy_realtime_normalized_import_v1';
    private const OWNERSHIP_META_KEY = 'legacy_account_ownership_link_v1';
    private const TYPES = ['games', 'queue', 'invites', 'notifications'];

    public function __construct(
        private StorageAdapterInterface $storage,
        private DatabaseConnectionInterface $database,
        private LegacyRealtimeShadowSyncService $shadowSync
    ) {}

    public function preview(): array
    {
        return $this->inspect(false);
    }

    public function run(): array
    {
        $plan = $this->inspect(true);
        if (!$plan['ready']) {
            throw new RuntimeException(
                'Legacy realtime normalized import is not ready: ' . implode('; ', $plan['blocking_reasons'])
            );
        }

        $sourceFingerprint = (string)$plan['source_fingerprint'];
        $this->writeMeta('started', $sourceFingerprint, [
            'source_counts' => $plan['source_counts'],
            'started_at_utc' => $this->now(),
        ]);

        $created = array_fill_keys(self::TYPES, 0);
        $unchanged = array_fill_keys(self::TYPES, 0);

        $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $plan,
            &$created,
            &$unchanged
        ): void {
            foreach (self::TYPES as $type) {
                foreach ($plan['items'][$type] as $item) {
                    $state = $this->targetState($type, $item, $database, true);
                    if ($state['state'] === 'conflict') {
                        throw new RuntimeException(
                            $type . '/' . $item['entity_key'] . ': ' . implode('; ', $state['reasons'])
                        );
                    }
                    if ($state['state'] === 'unchanged') {
                        $unchanged[$type]++;
                        continue;
                    }
                    $this->insertItem($type, $item, $database);
                    $verified = $this->targetState($type, $item, $database, true);
                    if ($verified['state'] !== 'unchanged') {
                        throw new RuntimeException(
                            $type . '/' . $item['entity_key'] . ': target verification failed after insert.'
                        );
                    }
                    $created[$type]++;
                }
            }
        });

        $verification = $this->inspect(false);
        if (!$verification['ready'] || array_sum($verification['planned_create_counts']) !== 0) {
            throw new RuntimeException('Legacy realtime normalized import failed final verification.');
        }
        if (!hash_equals($sourceFingerprint, (string)$verification['source_fingerprint'])) {
            throw new RuntimeException('Legacy realtime source changed during normalized import.');
        }

        $completedAt = $this->now();
        $details = [
            'source_counts' => $verification['source_counts'],
            'created_counts' => $created,
            'unchanged_counts' => $unchanged,
            'completed_at_utc' => $completedAt,
        ];
        $this->writeMeta('completed', $sourceFingerprint, $details);

        return [
            'ok' => true,
            'dry_run' => false,
            'status' => 'completed',
            'source_fingerprint' => $sourceFingerprint,
            'source_counts' => $verification['source_counts'],
            'created_counts' => $created,
            'unchanged_counts' => $unchanged,
            'verification' => [
                'ok' => true,
                'database_counts' => $verification['database_counts'],
                'conflict_counts' => $verification['conflict_counts'],
                'unmanaged_counts' => $verification['unmanaged_counts'],
            ],
            'completed_at_utc' => $completedAt,
        ];
    }

    private function inspect(bool $includeItems): array
    {
        $blocking = [];
        $shadowPreview = $this->shadowSync->preview();
        foreach ((array)($shadowPreview['sections'] ?? []) as $section => $summary) {
            if (!is_array($summary)) {
                $blocking[] = 'Realtime shadow summary is invalid for ' . (string)$section . '.';
                continue;
            }
            $changes = (int)($summary['inserted_count'] ?? 0)
                + (int)($summary['updated_count'] ?? 0)
                + (int)($summary['repair_count'] ?? 0)
                + (int)($summary['deleted_count'] ?? 0);
            if ($changes > 0) {
                $blocking[] = 'Realtime shadow differs from current JSON for ' . (string)$section . '.';
            }
        }

        $ownershipMeta = $this->loadMeta(self::OWNERSHIP_META_KEY);
        if (($ownershipMeta['status'] ?? '') !== 'completed') {
            $blocking[] = 'Legacy account ownership link is not completed.';
        }

        $source = $this->loadSourcePlan();
        $meta = $this->loadMeta(self::META_KEY);
        if ($meta !== null && !hash_equals((string)$meta['source_fingerprint'], $source['fingerprint'])) {
            $blocking[] = 'Normalized realtime import metadata belongs to a different source fingerprint.';
        }

        $planned = array_fill_keys(self::TYPES, 0);
        $unchanged = array_fill_keys(self::TYPES, 0);
        $conflicts = array_fill_keys(self::TYPES, 0);
        $samples = [];

        foreach (self::TYPES as $type) {
            foreach ($source['items'][$type] as $item) {
                $state = $this->targetState($type, $item, $this->database, false);
                if ($state['state'] === 'create') {
                    $planned[$type]++;
                } elseif ($state['state'] === 'unchanged') {
                    $unchanged[$type]++;
                } else {
                    $conflicts[$type]++;
                    foreach ($state['reasons'] as $reason) {
                        $blocking[] = $type . '/' . $item['entity_key'] . ': ' . $reason;
                    }
                }
                if (count($samples) < 50) {
                    $samples[] = [
                        'type' => $type,
                        'entity_key' => $item['entity_key'],
                        'state' => $state['state'],
                        'reasons' => $state['reasons'],
                    ];
                }
            }
        }

        $unmanaged = $this->unmanagedCounts($source['items']);
        foreach ($unmanaged as $type => $count) {
            if ($count > 0) {
                $blocking[] = 'Normalized ' . $type . ' rows exist outside the current source plan.';
            }
        }

        $status = $meta['status'] ?? 'not_started';
        if ($status === 'completed' && array_sum($planned) > 0) {
            $blocking[] = 'Completed normalized realtime import is missing expected target state.';
        }

        $result = [
            'ok' => $blocking === [],
            'dry_run' => true,
            'ready' => $blocking === [],
            'status' => $status,
            'source_fingerprint' => $source['fingerprint'],
            'source_counts' => $source['counts'],
            'database_counts' => $this->databaseCounts(),
            'planned_create_counts' => $planned,
            'unchanged_counts' => $unchanged,
            'conflict_counts' => $conflicts,
            'unmanaged_counts' => $unmanaged,
            'blocking_reasons' => array_values(array_unique($blocking)),
            'samples' => $samples,
            'meta' => $meta,
        ];
        if ($includeItems) $result['items'] = $source['items'];
        return $result;
    }

    private function loadSourcePlan(): array
    {
        $ownership = $this->ownershipMap();
        $rows = $this->database->fetchAll(
            'SELECT entity_type, entity_key, payload_json, payload_sha256,
                    source_updated_at_utc, synced_at_utc
             FROM mgw_legacy_realtime_shadow
             WHERE entity_type IN (\'games\', \'queue\', \'invites\', \'notifications\')
             ORDER BY entity_type, entity_key'
        );

        $items = array_fill_keys(self::TYPES, []);
        $fingerprintParts = [];
        foreach ($rows as $row) {
            $type = (string)($row['entity_type'] ?? '');
            $entityKey = trim((string)($row['entity_key'] ?? ''));
            if (!in_array($type, self::TYPES, true) || $entityKey === '') {
                throw new RuntimeException('Realtime shadow row has an invalid identity.');
            }
            $payloadJson = (string)($row['payload_json'] ?? '');
            try {
                $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException('Realtime shadow payload is invalid for ' . $type . '/' . $entityKey . '.');
            }
            if (!is_array($payload)) {
                throw new RuntimeException('Realtime shadow payload is invalid for ' . $type . '/' . $entityKey . '.');
            }
            $canonical = LedgerIntegrity::canonicalJson($payload);
            $storedHash = strtolower(trim((string)($row['payload_sha256'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/', $storedHash) !== 1
                || !hash_equals($storedHash, hash('sha256', $canonical))) {
                throw new RuntimeException('Realtime shadow hash mismatch for ' . $type . '/' . $entityKey . '.');
            }

            $fallbackTime = $this->timestamp(
                $row['source_updated_at_utc'] ?? null,
                $row['synced_at_utc'] ?? null
            );
            $item = match ($type) {
                'games' => $this->normalizeGame($entityKey, $payload, $storedHash, $fallbackTime, $ownership),
                'queue' => $this->normalizeQueue($entityKey, $payload, $storedHash, $fallbackTime, $ownership),
                'invites' => $this->normalizeInvite($entityKey, $payload, $storedHash, $fallbackTime, $ownership),
                'notifications' => $this->normalizeNotification($entityKey, $payload, $storedHash, $fallbackTime, $ownership),
            };
            $items[$type][$entityKey] = $item;
            $fingerprintParts[] = $type . "\0" . $entityKey . "\0" . $storedHash;
        }

        foreach (self::TYPES as $type) {
            ksort($items[$type], SORT_STRING);
        }
        sort($fingerprintParts, SORT_STRING);
        return [
            'fingerprint' => hash('sha256', implode("\n", $fingerprintParts)),
            'counts' => array_map('count', $items),
            'items' => $items,
        ];
    }

    private function normalizeGame(
        string $entityKey,
        array $payload,
        string $sourceHash,
        string $fallbackTime,
        array $ownership
    ): array {
        $matchId = $this->requiredId($payload['id'] ?? $entityKey, 96, 'game id');
        $createdAt = $this->timestamp($payload['created_at'] ?? null, $fallbackTime);
        $updatedAt = $this->timestamp(
            $payload['updated_at'] ?? null,
            $payload['last_move_at'] ?? null,
            $payload['finished_at'] ?? null,
            $fallbackTime
        );
        $status = $this->text($payload['status'] ?? 'active', 32, 'game status');
        $playerIds = is_array($payload['player_ids'] ?? null) ? array_values($payload['player_ids']) : [];
        if ($playerIds === []) {
            throw new RuntimeException('Legacy game ' . $matchId . ' has no player_ids.');
        }

        $players = [];
        foreach ($playerIds as $seat => $rawId) {
            $legacyId = $this->requiredId($rawId, 191, 'game player id');
            $identity = $this->playerIdentity($legacyId, $payload, $ownership);
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

        return [
            'entity_key' => $entityKey,
            'source_sha256' => $sourceHash,
            'match' => [
                'match_id' => $matchId,
                'game_type' => $this->text($payload['game_type'] ?? 'tictactoe', 32, 'game type'),
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
                'turn_player_ref' => $this->nullablePlayerReference($payload['turn'] ?? null, $payload, $ownership),
                'winner_player_ref' => $this->nullablePlayerReference($payload['winner_id'] ?? null, $payload, $ownership),
                'finish_reason' => $this->nullableText($payload['finish_reason'] ?? null, 64),
                'state_version' => max(1, (int)($payload['state_version'] ?? $payload['version'] ?? 1)),
                'public_state_json' => null,
                'server_state_json' => LedgerIntegrity::canonicalJson($payload),
                'created_at_utc' => $createdAt,
                'started_at_utc' => $this->nullableTimestamp($payload['started_at'] ?? $payload['created_at'] ?? null),
                'updated_at_utc' => $updatedAt,
                'finished_at_utc' => $this->nullableTimestamp($payload['finished_at'] ?? null),
            ],
            'players' => $players,
        ];
    }

    private function normalizeQueue(
        string $entityKey,
        array $payload,
        string $sourceHash,
        string $fallbackTime,
        array $ownership
    ): array {
        $legacyId = $this->requiredId($payload['user_id'] ?? null, 191, 'queue user id');
        $identity = $this->ownedIdentity($legacyId, $ownership);
        $createdAt = $this->timestamp($payload['created_at'] ?? null, $fallbackTime);
        return [
            'entity_key' => $entityKey,
            'source_sha256' => $sourceHash,
            'queue' => [
                'queue_id' => $this->requiredId($payload['id'] ?? $entityKey, 96, 'queue id'),
                'player_ref' => $identity['account_ref'],
                'mgw_id' => $identity['mgw_id'],
                'legacy_user_id' => $legacyId,
                'game_type' => $this->text($payload['game_type'] ?? 'tictactoe', 32, 'queue game type'),
                'room' => $this->room($payload['room'] ?? 'match'),
                'bet' => $this->nonNegativeInt($payload['bet'] ?? 0, 'queue bet'),
                'board_size' => $this->positiveInt($payload['board_size'] ?? 3, 3),
                'status' => $this->text($payload['status'] ?? 'waiting', 32, 'queue status'),
                'reserved_match_id' => $this->nullableText($payload['reserved_match_id'] ?? null, 96),
                'created_at_utc' => $createdAt,
                'updated_at_utc' => $this->timestamp($payload['updated_at'] ?? null, $createdAt, $fallbackTime),
                'expires_at_utc' => $this->nullableTimestamp($payload['expires_at'] ?? null),
            ],
        ];
    }

    private function normalizeInvite(
        string $entityKey,
        array $payload,
        string $sourceHash,
        string $fallbackTime,
        array $ownership
    ): array {
        $inviterId = $this->requiredId($payload['inviter_id'] ?? null, 191, 'invite inviter id');
        $inviter = $this->ownedIdentity($inviterId, $ownership);
        $inviteeId = trim((string)($payload['invitee_id'] ?? ''));
        $invitee = $inviteeId === '' ? null : $this->ownedIdentity($inviteeId, $ownership);
        $createdAt = $this->timestamp($payload['created_at'] ?? null, $fallbackTime);
        return [
            'entity_key' => $entityKey,
            'source_sha256' => $sourceHash,
            'invite' => [
                'invite_id' => $this->requiredId($payload['id'] ?? $entityKey, 96, 'invite id'),
                'token' => $this->requiredId($payload['token'] ?? null, 96, 'invite token'),
                'status' => $this->text($payload['status'] ?? 'pending', 32, 'invite status'),
                'source' => $this->text($payload['source'] ?? 'legacy_json', 32, 'invite source'),
                'inviter_ref' => $inviter['account_ref'],
                'inviter_mgw_id' => $inviter['mgw_id'],
                'inviter_legacy_user_id' => $inviterId,
                'inviter_name' => $this->text($payload['inviter_name'] ?? 'Игрок', 80, 'inviter name'),
                'invitee_ref' => $invitee['account_ref'] ?? null,
                'invitee_mgw_id' => $invitee['mgw_id'] ?? null,
                'invitee_legacy_user_id' => $inviteeId === '' ? null : $inviteeId,
                'invitee_name' => $this->nullableText($payload['invitee_name'] ?? null, 80),
                'game_type' => $this->text($payload['game_type'] ?? 'tictactoe', 32, 'invite game type'),
                'game_title' => $this->text($payload['game_title'] ?? ($payload['game_type'] ?? 'Игра'), 120, 'invite game title'),
                'room' => $this->room($payload['room'] ?? 'match'),
                'bet' => $this->nonNegativeInt($payload['bet'] ?? 0, 'invite bet'),
                'board_size' => $this->positiveInt($payload['board_size'] ?? 3, 3),
                'board_columns' => $this->nullablePositiveInt($payload['board_columns'] ?? null),
                'board_rows' => $this->nullablePositiveInt($payload['board_rows'] ?? null),
                'source_match_id' => $this->nullableText(
                    $payload['source_game_id'] ?? $payload['source_match_id'] ?? null,
                    96
                ),
                'match_id' => $this->nullableText($payload['game_id'] ?? $payload['match_id'] ?? null, 96),
                'version' => max(1, (int)($payload['version'] ?? 1)),
                'created_at_utc' => $createdAt,
                'updated_at_utc' => $this->timestamp($payload['updated_at'] ?? null, $createdAt, $fallbackTime),
                'expires_at_utc' => $this->nullableTimestamp($payload['expires_at'] ?? null),
                'shared_at_utc' => $this->nullableTimestamp($payload['shared_at'] ?? null),
                'opened_at_utc' => $this->nullableTimestamp($payload['opened_at'] ?? null),
                'accepted_at_utc' => $this->nullableTimestamp($payload['accepted_at'] ?? null),
                'ready_deadline_at_utc' => $this->nullableTimestamp(
                    $payload['ready_deadline_at'] ?? $payload['start_deadline_at'] ?? null
                ),
                'started_at_utc' => $this->nullableTimestamp($payload['started_at'] ?? null),
                'declined_at_utc' => $this->nullableTimestamp($payload['declined_at'] ?? null),
                'cancelled_at_utc' => $this->nullableTimestamp($payload['cancelled_at'] ?? null),
                'cancelled_by_ref' => $this->nullablePlayerReference(
                    $payload['cancelled_by'] ?? $payload['cancelled_by_ref'] ?? null,
                    $payload,
                    $ownership
                ),
            ],
        ];
    }

    private function normalizeNotification(
        string $entityKey,
        array $payload,
        string $sourceHash,
        string $fallbackTime,
        array $ownership
    ): array {
        $legacyId = $this->requiredId($payload['user_id'] ?? null, 191, 'notification user id');
        $identity = $this->ownedIdentity($legacyId, $ownership);
        return [
            'entity_key' => $entityKey,
            'source_sha256' => $sourceHash,
            'notification' => [
                'notification_id' => $this->requiredId($payload['id'] ?? $entityKey, 96, 'notification id'),
                'event_key' => $this->text($payload['event_key'] ?? ('legacy:' . $entityKey), 191, 'notification event key'),
                'recipient_ref' => $identity['account_ref'],
                'mgw_id' => $identity['mgw_id'],
                'legacy_user_id' => $legacyId,
                'type' => $this->text($payload['type'] ?? 'legacy', 64, 'notification type'),
                'title' => $this->text($payload['title'] ?? 'Уведомление', 160, 'notification title'),
                'message' => $this->text($payload['message'] ?? '', 10000, 'notification message', true),
                'tone' => $this->nullableText($payload['tone'] ?? null, 32),
                'invite_token' => $this->nullableText($payload['invite_token'] ?? null, 96),
                'payload_json' => LedgerIntegrity::canonicalJson($payload),
                'created_at_utc' => $this->timestamp($payload['created_at'] ?? null, $fallbackTime),
                'read_at_utc' => $this->nullableTimestamp($payload['read_at'] ?? null),
                'hidden_at_utc' => $this->nullableTimestamp($payload['hidden_at'] ?? null),
            ],
        ];
    }

    private function targetState(
        string $type,
        array $item,
        DatabaseConnectionInterface $database,
        bool $forUpdate
    ): array {
        return match ($type) {
            'games' => $this->matchState($item, $database, $forUpdate),
            'queue' => $this->simpleState(
                'mgw_match_queue',
                'queue_id',
                $item['queue']['queue_id'],
                $item['queue'],
                $database,
                $forUpdate
            ),
            'invites' => $this->simpleState(
                'mgw_invites',
                'invite_id',
                $item['invite']['invite_id'],
                $item['invite'],
                $database,
                $forUpdate
            ),
            'notifications' => $this->notificationState($item, $database, $forUpdate),
            default => ['state' => 'conflict', 'reasons' => ['Unknown realtime entity type.']],
        };
    }

    private function matchState(array $item, DatabaseConnectionInterface $database, bool $forUpdate): array
    {
        $match = $item['match'];
        $rows = $database->fetchAll(
            'SELECT match_id, game_type, room, status, board_size, bet, match_source, invite_id,
                    source_match_id, turn_player_ref, winner_player_ref, finish_reason, state_version,
                    public_state_json, server_state_json, created_at_utc, started_at_utc,
                    updated_at_utc, finished_at_utc
             FROM mgw_matches WHERE match_id = :match_id' . $this->forUpdate($database, $forUpdate),
            ['match_id' => $match['match_id']]
        );
        if ($rows === []) return ['state' => 'create', 'reasons' => []];
        if (count($rows) !== 1) return ['state' => 'conflict', 'reasons' => ['Multiple target match rows exist.']];

        $reasons = $this->rowDifferences($match, $rows[0]);
        $players = $database->fetchAll(
            'SELECT seat, player_ref, mgw_id, legacy_user_id, player_type, symbol,
                    display_name, result, joined_at_utc, updated_at_utc
             FROM mgw_match_players WHERE match_id = :match_id ORDER BY seat' . $this->forUpdate($database, $forUpdate),
            ['match_id' => $match['match_id']]
        );
        $expectedPlayers = $item['players'];
        if (!$this->rowsEqual($expectedPlayers, $players)) {
            $reasons[] = 'Target match players differ from the source plan.';
        }
        $snapshots = $database->fetchAll(
            'SELECT state_version, public_state_json, server_state_json, created_at_utc
             FROM mgw_match_snapshots WHERE match_id = :match_id ORDER BY state_version' . $this->forUpdate($database, $forUpdate),
            ['match_id' => $match['match_id']]
        );
        $expectedSnapshot = [[
            'state_version' => $match['state_version'],
            'public_state_json' => $match['public_state_json'],
            'server_state_json' => $match['server_state_json'],
            'created_at_utc' => $match['updated_at_utc'],
        ]];
        if (!$this->rowsEqual($expectedSnapshot, $snapshots)) {
            $reasons[] = 'Target match snapshot differs from the source plan.';
        }
        $privateCount = (int)$database->fetchValue(
            'SELECT COUNT(*) FROM mgw_match_player_snapshots WHERE match_id = :match_id',
            ['match_id' => $match['match_id']]
        );
        if ($privateCount !== 0) {
            $reasons[] = 'Unexpected private snapshots exist for a legacy normalized match.';
        }
        return [
            'state' => $reasons === [] ? 'unchanged' : 'conflict',
            'reasons' => $reasons,
        ];
    }

    private function notificationState(array $item, DatabaseConnectionInterface $database, bool $forUpdate): array
    {
        $notification = $item['notification'];
        $rows = $database->fetchAll(
            'SELECT notification_id, event_key, recipient_ref, mgw_id, legacy_user_id, type,
                    title, message, tone, invite_token, payload_json, created_at_utc,
                    read_at_utc, hidden_at_utc
             FROM mgw_notifications
             WHERE notification_id = :notification_id
                OR (recipient_ref = :recipient_ref AND event_key = :event_key)'
                . $this->forUpdate($database, $forUpdate),
            [
                'notification_id' => $notification['notification_id'],
                'recipient_ref' => $notification['recipient_ref'],
                'event_key' => $notification['event_key'],
            ]
        );
        if ($rows === []) return ['state' => 'create', 'reasons' => []];
        if (count($rows) !== 1) return ['state' => 'conflict', 'reasons' => ['Multiple target notification rows collide.']];
        $reasons = $this->rowDifferences($notification, $rows[0]);
        return ['state' => $reasons === [] ? 'unchanged' : 'conflict', 'reasons' => $reasons];
    }

    private function simpleState(
        string $table,
        string $idColumn,
        string $id,
        array $expected,
        DatabaseConnectionInterface $database,
        bool $forUpdate
    ): array {
        $rows = $database->fetchAll(
            'SELECT * FROM ' . $table . ' WHERE ' . $idColumn . ' = :' . $idColumn
                . $this->forUpdate($database, $forUpdate),
            [$idColumn => $id]
        );
        if ($rows === []) return ['state' => 'create', 'reasons' => []];
        if (count($rows) !== 1) return ['state' => 'conflict', 'reasons' => ['Multiple target rows exist.']];
        $reasons = $this->rowDifferences($expected, $rows[0]);
        return ['state' => $reasons === [] ? 'unchanged' : 'conflict', 'reasons' => $reasons];
    }

    private function insertItem(string $type, array $item, DatabaseConnectionInterface $database): void
    {
        match ($type) {
            'games' => $this->insertMatch($item, $database),
            'queue' => $this->insertRow('mgw_match_queue', $item['queue'], $database),
            'invites' => $this->insertRow('mgw_invites', $item['invite'], $database),
            'notifications' => $this->insertRow('mgw_notifications', $item['notification'], $database),
            default => throw new RuntimeException('Unknown realtime entity type.'),
        };
    }

    private function insertMatch(array $item, DatabaseConnectionInterface $database): void
    {
        $match = $item['match'];
        $this->insertRow('mgw_matches', $match, $database);
        foreach ($item['players'] as $player) {
            $this->insertRow('mgw_match_players', ['match_id' => $match['match_id']] + $player, $database);
        }
        $this->insertRow('mgw_match_snapshots', [
            'match_id' => $match['match_id'],
            'state_version' => $match['state_version'],
            'public_state_json' => $match['public_state_json'],
            'server_state_json' => $match['server_state_json'],
            'created_at_utc' => $match['updated_at_utc'],
        ], $database);
    }

    private function insertRow(string $table, array $values, DatabaseConnectionInterface $database): void
    {
        $columns = array_keys($values);
        $database->execute(
            'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ')
             VALUES (:' . implode(', :', $columns) . ')',
            $values
        );
    }

    private function unmanagedCounts(array $items): array
    {
        $ids = [
            'games' => array_map(static fn(array $item): string => $item['match']['match_id'], $items['games']),
            'queue' => array_map(static fn(array $item): string => $item['queue']['queue_id'], $items['queue']),
            'invites' => array_map(static fn(array $item): string => $item['invite']['invite_id'], $items['invites']),
            'notifications' => array_map(
                static fn(array $item): string => $item['notification']['notification_id'],
                $items['notifications']
            ),
        ];
        return [
            'games' => $this->countUnmanaged('mgw_matches', 'match_id', $ids['games']),
            'queue' => $this->countUnmanaged('mgw_match_queue', 'queue_id', $ids['queue']),
            'invites' => $this->countUnmanaged('mgw_invites', 'invite_id', $ids['invites']),
            'notifications' => $this->countUnmanaged('mgw_notifications', 'notification_id', $ids['notifications']),
        ];
    }

    private function countUnmanaged(string $table, string $column, array $expectedIds): int
    {
        $rows = $this->database->fetchAll('SELECT ' . $column . ' FROM ' . $table);
        $expected = array_fill_keys($expectedIds, true);
        $count = 0;
        foreach ($rows as $row) {
            $id = (string)($row[$column] ?? '');
            if ($id === '' || !isset($expected[$id])) $count++;
        }
        return $count;
    }

    private function databaseCounts(): array
    {
        return [
            'games' => (int)$this->database->fetchValue('SELECT COUNT(*) FROM mgw_matches'),
            'queue' => (int)$this->database->fetchValue('SELECT COUNT(*) FROM mgw_match_queue'),
            'invites' => (int)$this->database->fetchValue('SELECT COUNT(*) FROM mgw_invites'),
            'notifications' => (int)$this->database->fetchValue('SELECT COUNT(*) FROM mgw_notifications'),
        ];
    }

    private function ownershipMap(): array
    {
        $map = [];
        foreach ($this->database->fetchAll(
            'SELECT account_ref, mgw_id, legacy_user_id, ownership_status
             FROM mgw_account_ownership ORDER BY legacy_user_id'
        ) as $row) {
            $legacyId = trim((string)($row['legacy_user_id'] ?? ''));
            $accountRef = trim((string)($row['account_ref'] ?? ''));
            $mgwId = trim((string)($row['mgw_id'] ?? ''));
            if ($legacyId === '' || $accountRef === '' || $mgwId === ''
                || (string)($row['ownership_status'] ?? '') !== 'active') {
                throw new RuntimeException('Account ownership row is invalid for realtime import.');
            }
            if (isset($map[$legacyId])) {
                throw new RuntimeException('Duplicate account ownership for legacy user ' . $legacyId . '.');
            }
            $map[$legacyId] = ['account_ref' => $accountRef, 'mgw_id' => $mgwId];
        }
        return $map;
    }

    private function ownedIdentity(string $legacyId, array $ownership): array
    {
        if (!isset($ownership[$legacyId])) {
            throw new RuntimeException('Account ownership is missing for legacy user ' . $legacyId . '.');
        }
        return $ownership[$legacyId];
    }

    private function playerIdentity(string $legacyId, array $payload, array $ownership): array
    {
        $botId = trim((string)($payload['bot_id'] ?? ''));
        $isBot = str_starts_with($legacyId, 'bot_') || ($botId !== '' && $legacyId === $botId);
        if ($isBot) {
            return [
                'player_ref' => 'bot:' . $legacyId,
                'mgw_id' => null,
                'legacy_user_id' => null,
                'player_type' => 'bot',
            ];
        }
        $owned = $this->ownedIdentity($legacyId, $ownership);
        return [
            'player_ref' => $owned['account_ref'],
            'mgw_id' => $owned['mgw_id'],
            'legacy_user_id' => $legacyId,
            'player_type' => 'human',
        ];
    }

    private function nullablePlayerReference(mixed $value, array $payload, array $ownership): ?string
    {
        $id = trim((string)$value);
        return $id === '' ? null : $this->playerIdentity($id, $payload, $ownership)['player_ref'];
    }

    private function playerResult(string $legacyId, array $payload, string $status): ?string
    {
        if ($status !== 'finished') return null;
        $winner = trim((string)($payload['winner_id'] ?? ''));
        $loser = trim((string)($payload['loser_id'] ?? ''));
        if ($winner === '') return 'draw';
        if ($legacyId === $winner) return 'win';
        if ($legacyId === $loser) return 'loss';
        return null;
    }

    private function rowDifferences(array $expected, array $actual): array
    {
        $reasons = [];
        foreach ($expected as $column => $value) {
            if (!array_key_exists($column, $actual)) {
                $reasons[] = 'Target row is missing column ' . $column . '.';
                continue;
            }
            if (!$this->sameValue($value, $actual[$column])) {
                $reasons[] = 'Target column ' . $column . ' differs from the source plan.';
            }
        }
        return $reasons;
    }

    private function rowsEqual(array $expected, array $actual): bool
    {
        if (count($expected) !== count($actual)) return false;
        foreach (array_values($expected) as $index => $expectedRow) {
            if (!isset($actual[$index]) || $this->rowDifferences($expectedRow, $actual[$index]) !== []) {
                return false;
            }
        }
        return true;
    }

    private function sameValue(mixed $expected, mixed $actual): bool
    {
        if ($expected === null || $actual === null) return $expected === null && $actual === null;
        if (is_int($expected)) return (int)$actual === $expected;
        return (string)$actual === (string)$expected;
    }

    private function forUpdate(DatabaseConnectionInterface $database, bool $enabled): string
    {
        return $enabled && $database->driver() !== 'sqlite' ? ' FOR UPDATE' : '';
    }

    private function loadMeta(string $key): ?array
    {
        $rows = $this->database->fetchAll(
            'SELECT meta_value, updated_at_utc FROM mgw_meta WHERE meta_key = :meta_key',
            ['meta_key' => $key]
        );
        if ($rows === []) return null;
        try {
            $value = json_decode((string)$rows[0]['meta_value'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Migration metadata is invalid for ' . $key . '.');
        }
        if (!is_array($value)) throw new RuntimeException('Migration metadata is invalid for ' . $key . '.');
        return [
            'status' => (string)($value['status'] ?? ''),
            'source_fingerprint' => (string)($value['source_fingerprint'] ?? ''),
            'details' => is_array($value['details'] ?? null) ? $value['details'] : [],
            'updated_at_utc' => (string)$rows[0]['updated_at_utc'],
        ];
    }

    private function writeMeta(string $status, string $fingerprint, array $details): void
    {
        $updatedAt = $this->now();
        $value = LedgerIntegrity::canonicalJson([
            'status' => $status,
            'source_fingerprint' => $fingerprint,
            'details' => $details,
        ]);
        $updated = $this->database->execute(
            'UPDATE mgw_meta SET meta_value = :meta_value, updated_at_utc = :updated_at_utc
             WHERE meta_key = :meta_key',
            [
                'meta_value' => $value,
                'updated_at_utc' => $updatedAt,
                'meta_key' => self::META_KEY,
            ]
        );
        if ($updated === 0) {
            try {
                $this->database->execute(
                    'INSERT INTO mgw_meta (meta_key, meta_value, updated_at_utc)
                     VALUES (:meta_key, :meta_value, :updated_at_utc)',
                    [
                        'meta_key' => self::META_KEY,
                        'meta_value' => $value,
                        'updated_at_utc' => $updatedAt,
                    ]
                );
            } catch (Throwable) {
                $this->database->execute(
                    'UPDATE mgw_meta SET meta_value = :meta_value, updated_at_utc = :updated_at_utc
                     WHERE meta_key = :meta_key',
                    [
                        'meta_value' => $value,
                        'updated_at_utc' => $updatedAt,
                        'meta_key' => self::META_KEY,
                    ]
                );
            }
        }
    }

    private function room(mixed $value): string
    {
        return trim((string)$value) === 'gold' ? 'gold' : 'match';
    }

    private function requiredId(mixed $value, int $maxLength, string $label): string
    {
        return $this->text($value, $maxLength, $label);
    }

    private function text(
        mixed $value,
        int $maxLength,
        string $label,
        bool $allowEmpty = false
    ): string {
        $text = trim((string)$value);
        if (!$allowEmpty && $text === '') throw new RuntimeException('Missing ' . $label . '.');
        if (strlen($text) > $maxLength) throw new RuntimeException(ucfirst($label) . ' is too long.');
        return $text;
    }

    private function nullableText(mixed $value, int $maxLength): ?string
    {
        $text = trim((string)$value);
        if ($text === '') return null;
        if (strlen($text) > $maxLength) throw new RuntimeException('Legacy text value is too long.');
        return $text;
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        $number = is_numeric($value) ? (int)$value : $fallback;
        return $number > 0 ? $number : $fallback;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || trim((string)$value) === '') return null;
        $number = (int)$value;
        return $number > 0 ? $number : null;
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
        $text = trim((string)$value);
        if ($text === '') return null;
        return $this->timestamp($text);
    }

    private function timestamp(mixed ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $text = trim((string)$candidate);
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

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
