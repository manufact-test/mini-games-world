<?php
declare(strict_types=1);

final class RuntimeHistoryRepository
{
    public function __construct(
        private array $config,
        private RuntimeStorageRouter $router,
        private DatabaseConnectionInterface $database,
        private HistoryService $formatter
    ) {}

    public function synchronizeAndRead(array $jsonSnapshot, string $legacyUserId, int $limit = 24): array
    {
        $this->assertDatabaseRoute();
        $storage = new RuntimeEconomySnapshotStorage($jsonSnapshot);
        $realtime = (new LegacyRealtimeShadowSyncService($storage, $this->database))->run();
        $economy = (new LegacyEconomyShadowSyncService($storage, $this->database))->run();
        $snapshot = $this->databaseSnapshot($jsonSnapshot);

        return [
            'history' => $this->formatter->formatHistory($snapshot, $legacyUserId, $limit),
            'synchronization' => [
                'realtime' => $this->compactShadow($realtime),
                'economy' => $this->compactEconomyShadow($economy),
            ],
        ];
    }

    public function read(string $legacyUserId, int $limit = 24): array
    {
        $this->assertDatabaseRoute();
        return $this->formatter->formatHistory(
            $this->runtimeReadSnapshot($legacyUserId, $limit),
            $legacyUserId,
            $limit
        );
    }

    public function auditParity(array $jsonSnapshot): array
    {
        $this->assertDatabaseRoute();
        $databaseSnapshot = $this->databaseSnapshot($jsonSnapshot);
        $userIds = array_values(array_filter(
            array_map('strval', array_keys(is_array($jsonSnapshot['users'] ?? null) ? $jsonSnapshot['users'] : [])),
            static fn(string $value): bool => $value !== ''
        ));
        sort($userIds, SORT_STRING);

        $mismatchCount = 0;
        $operationMismatchCount = 0;
        $matchMismatchCount = 0;
        $operationCountDelta = 0;
        $matchCountDelta = 0;
        $legacyParts = [];
        $databaseParts = [];

        foreach ($userIds as $userId) {
            $legacy = $this->formatter->formatHistory($jsonSnapshot, $userId, 24);
            $database = $this->formatter->formatHistory($databaseSnapshot, $userId, 24);
            $legacyHash = hash('sha256', LedgerIntegrity::canonicalJson($legacy));
            $databaseHash = hash('sha256', LedgerIntegrity::canonicalJson($database));
            $legacyParts[] = hash('sha256', $userId) . ':' . $legacyHash;
            $databaseParts[] = hash('sha256', $userId) . ':' . $databaseHash;

            $legacyOperations = is_array($legacy['operations'] ?? null) ? $legacy['operations'] : [];
            $databaseOperations = is_array($database['operations'] ?? null) ? $database['operations'] : [];
            $legacyMatches = is_array($legacy['matches'] ?? null) ? $legacy['matches'] : [];
            $databaseMatches = is_array($database['matches'] ?? null) ? $database['matches'] : [];

            if (!hash_equals(
                hash('sha256', LedgerIntegrity::canonicalJson($legacyOperations)),
                hash('sha256', LedgerIntegrity::canonicalJson($databaseOperations))
            )) {
                $operationMismatchCount++;
                $operationCountDelta += abs(count($legacyOperations) - count($databaseOperations));
            }
            if (!hash_equals(
                hash('sha256', LedgerIntegrity::canonicalJson($legacyMatches)),
                hash('sha256', LedgerIntegrity::canonicalJson($databaseMatches))
            )) {
                $matchMismatchCount++;
                $matchCountDelta += abs(count($legacyMatches) - count($databaseMatches));
            }
            if (!hash_equals($legacyHash, $databaseHash)) $mismatchCount++;
        }

        sort($legacyParts, SORT_STRING);
        sort($databaseParts, SORT_STRING);
        $legacyFingerprint = hash('sha256', implode("\n", $legacyParts));
        $databaseFingerprint = hash('sha256', implode("\n", $databaseParts));
        $blockers = [];
        if ($operationMismatchCount > 0) {
            $blockers[] = 'Database operation history differs from the current JSON operation history.';
        }
        if ($matchMismatchCount > 0) {
            $blockers[] = 'Database match history differs from the current JSON match history.';
        }
        if ($mismatchCount > 0 || !hash_equals($legacyFingerprint, $databaseFingerprint)) {
            $blockers[] = 'Database history fingerprint differs from the current JSON history fingerprint.';
        }

        return [
            'ok' => $blockers === [],
            'read_only' => true,
            'source_user_count' => count($userIds),
            'transaction_count' => count($databaseSnapshot['transactions']),
            'game_count' => count($databaseSnapshot['games']),
            'mismatch_count' => $mismatchCount,
            'operation_mismatch_count' => $operationMismatchCount,
            'match_mismatch_count' => $matchMismatchCount,
            'operation_count_delta' => $operationCountDelta,
            'match_count_delta' => $matchCountDelta,
            'json_history_fingerprint' => $legacyFingerprint,
            'database_history_fingerprint' => $databaseFingerprint,
            'blockers' => array_values(array_unique($blockers)),
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    /**
     * Live player history must read the canonical relational match projection.
     * The legacy shadow below remains intentionally available only for migration
     * synchronization/parity audits and for the older economy transaction bridge.
     */
    private function runtimeReadSnapshot(string $legacyUserId, int $limit): array
    {
        $shadow = $this->databaseSnapshot();
        $transactions = is_array($shadow['transactions'] ?? null) ? $shadow['transactions'] : [];
        $games = [];

        $safeLimit = max(12, min(100, $limit * 2));
        $rows = $this->database->fetchAll(
            'SELECT
                m.match_id, m.game_type, m.room, m.status, m.board_size, m.bet,
                m.match_source, m.winner_player_ref, m.finish_reason,
                m.server_state_json, m.created_at_utc, m.started_at_utc,
                m.updated_at_utc, m.finished_at_utc
             FROM mgw_matches m
             INNER JOIN mgw_match_players me ON me.match_id = m.match_id
             WHERE me.legacy_user_id = :legacy_user_id
             ORDER BY
                COALESCE(m.finished_at_utc, m.updated_at_utc, m.created_at_utc) DESC,
                m.match_id DESC
             LIMIT ' . $safeLimit,
            ['legacy_user_id' => $legacyUserId]
        );

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $matchId = trim((string)($row['match_id'] ?? ''));
            if ($matchId === '') continue;

            $game = $this->decodeRuntimeState($row['server_state_json'] ?? null);
            $players = $this->database->fetchAll(
                'SELECT seat, player_ref, legacy_user_id, display_name, result
                 FROM mgw_match_players
                 WHERE match_id = :match_id
                 ORDER BY seat',
                ['match_id' => $matchId]
            );

            $playerIds = [];
            $playerNames = [];
            $winnerId = null;
            $winnerRef = trim((string)($row['winner_player_ref'] ?? ''));

            foreach ($players as $player) {
                if (!is_array($player)) continue;
                $playerRef = trim((string)($player['player_ref'] ?? ''));
                $legacyId = trim((string)($player['legacy_user_id'] ?? ''));
                $identity = $legacyId !== '' ? $legacyId : $playerRef;
                if ($identity === '') continue;

                $playerIds[] = $identity;
                $displayName = trim((string)($player['display_name'] ?? ''));
                if ($displayName !== '') $playerNames[$identity] = $displayName;
                if ($winnerRef !== '' && $playerRef === $winnerRef) $winnerId = $identity;
            }

            if ($playerIds === []) {
                $playerIds = array_values(array_map('strval', is_array($game['player_ids'] ?? null) ? $game['player_ids'] : []));
            }
            if ($playerNames === [] && is_array($game['player_names'] ?? null)) {
                $playerNames = $game['player_names'];
            }
            if ($winnerId === null && isset($game['winner_id'])) {
                $winnerId = trim((string)$game['winner_id']);
                if ($winnerId === '') $winnerId = null;
            }

            $game['id'] = $matchId;
            $game['game_type'] = (string)($row['game_type'] ?? ($game['game_type'] ?? 'tictactoe'));
            $game['room'] = (string)($row['room'] ?? ($game['room'] ?? 'match'));
            $game['status'] = (string)($row['status'] ?? ($game['status'] ?? ''));
            $game['board_size'] = max(1, (int)($row['board_size'] ?? ($game['board_size'] ?? 3)));
            $game['bet'] = max(0, (int)($row['bet'] ?? ($game['bet'] ?? 0)));
            $game['match_source'] = (string)($row['match_source'] ?? ($game['match_source'] ?? ''));
            $game['finish_reason'] = (string)($row['finish_reason'] ?? ($game['finish_reason'] ?? ''));
            $game['player_ids'] = $playerIds;
            $game['player_names'] = $playerNames;
            $game['winner_id'] = $winnerId;
            $game['created_at'] = $this->utcIso($row['created_at_utc'] ?? ($game['created_at'] ?? null));
            $game['started_at'] = $this->utcIso($row['started_at_utc'] ?? ($game['started_at'] ?? null));
            $game['updated_at'] = $this->utcIso($row['updated_at_utc'] ?? ($game['updated_at'] ?? null));
            $game['finished_at'] = $this->utcIso($row['finished_at_utc'] ?? ($game['finished_at'] ?? null));

            $games[$matchId] = $game;
        }

        return ['transactions' => $transactions, 'games' => $games];
    }

    private function decodeRuntimeState(mixed $value): array
    {
        if ($value === null || trim((string)$value) === '') return [];
        try {
            $decoded = json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            return [];
        }
    }

    private function utcIso(mixed $value): string
    {
        $raw = trim((string)($value ?? ''));
        if ($raw === '') return '';
        try {
            return (new DateTimeImmutable($raw, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format(DATE_ATOM);
        } catch (Throwable) {
            return $raw;
        }
    }

    private function databaseSnapshot(?array $sourceSnapshot = null): array
    {
        $transactions = [];
        $games = [];
        $rows = $this->database->fetchAll(
            "SELECT entity_type, entity_key, payload_json, payload_sha256, source_updated_at_utc
             FROM mgw_legacy_realtime_shadow
             WHERE entity_type IN ('economy_transaction', 'games')"
        );

        foreach ($rows as $row) {
            $type = (string)($row['entity_type'] ?? '');
            $payload = $this->verifiedPayload(
                (string)($row['payload_json'] ?? ''),
                (string)($row['payload_sha256'] ?? '')
            );
            if ($type === 'economy_transaction') {
                $transactions[] = $payload;
                continue;
            }
            if ($type === 'games') {
                $id = trim((string)($payload['id'] ?? $row['entity_key'] ?? ''));
                if ($id === '') throw new RuntimeException('History game shadow has no stable ID.');
                if (isset($games[$id])) throw new RuntimeException('History game shadow contains a duplicate ID.');
                $games[$id] = $payload;
            }
        }

        $transactionOrder = $this->sourceOrder($sourceSnapshot['transactions'] ?? null);
        $gameOrder = $this->sourceOrder($sourceSnapshot['games'] ?? null);

        usort($transactions, fn(array $left, array $right): int => $this->compareRecords(
            $left,
            $right,
            $transactionOrder,
            ['created_at']
        ));
        uasort($games, fn(array $left, array $right): int => $this->compareRecords(
            $left,
            $right,
            $gameOrder,
            ['created_at', 'started_at', 'updated_at']
        ));

        return ['transactions' => $transactions, 'games' => $games];
    }

    private function sourceOrder(mixed $records): array
    {
        if (!is_array($records)) return [];
        $order = [];
        $position = 0;
        foreach ($records as $record) {
            if (!is_array($record)) continue;
            $identity = $this->recordIdentity($record);
            if (!array_key_exists($identity, $order)) $order[$identity] = $position;
            $position++;
        }
        return $order;
    }

    private function compareRecords(array $left, array $right, array $sourceOrder, array $timestampFields): int
    {
        $leftIdentity = $this->recordIdentity($left);
        $rightIdentity = $this->recordIdentity($right);
        $leftPosition = $sourceOrder[$leftIdentity] ?? null;
        $rightPosition = $sourceOrder[$rightIdentity] ?? null;

        if (is_int($leftPosition) && is_int($rightPosition) && $leftPosition !== $rightPosition) {
            return $leftPosition <=> $rightPosition;
        }
        if (is_int($leftPosition) !== is_int($rightPosition)) {
            return is_int($leftPosition) ? -1 : 1;
        }

        $leftTimestamp = $this->recordTimestamp($left, $timestampFields);
        $rightTimestamp = $this->recordTimestamp($right, $timestampFields);
        $timestampComparison = strcmp($leftTimestamp, $rightTimestamp);
        if ($timestampComparison !== 0) return $timestampComparison;

        return strcmp($leftIdentity, $rightIdentity);
    }

    private function recordIdentity(array $record): string
    {
        $id = trim((string)($record['id'] ?? ''));
        if ($id !== '') return 'id:' . $id;
        return 'sha256:' . hash('sha256', LedgerIntegrity::canonicalJson($record));
    }

    private function recordTimestamp(array $record, array $fields): string
    {
        foreach ($fields as $field) {
            $value = trim((string)($record[$field] ?? ''));
            if ($value !== '') return $value;
        }
        return '';
    }

    private function verifiedPayload(string $payloadJson, string $storedHash): array
    {
        if ($payloadJson === '') throw new RuntimeException('History shadow payload is empty.');
        try {
            $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('History shadow payload is invalid JSON.', 0, $error);
        }
        if (!is_array($payload)) throw new RuntimeException('History shadow payload must be an object.');
        $canonical = LedgerIntegrity::canonicalJson($payload);
        $storedHash = strtolower(trim($storedHash));
        if (preg_match('/^[a-f0-9]{64}$/', $storedHash) !== 1
            || !hash_equals($storedHash, hash('sha256', $canonical))) {
            throw new RuntimeException('History shadow hash verification failed.');
        }
        return $payload;
    }

    private function assertDatabaseRoute(): void
    {
        foreach (['accounts', 'realtime', 'economy', 'history'] as $module) {
            if ($this->router->routeFor($module) !== RuntimeStorageRouter::DRIVER_DATABASE) {
                throw new RuntimeException('History DB runtime requires accounts, realtime, economy and history routing.');
            }
        }

        $environment = strtolower(trim((string)($this->config['environment'] ?? 'production')));
        if (in_array($environment, ['staging', 'local'], true)) return;

        $routerStatus = $this->router->publicStatus();
        if ($environment === 'production'
            && ($routerStatus['production_allowed'] ?? false) === true) {
            return;
        }

        throw new RuntimeException(
            'History DB runtime requires staging/local or the exact protected production activation contract.'
        );
    }

    private function compactShadow(array $report): array
    {
        return [
            'ok' => !empty($report['ok']),
            'source_fingerprint' => (string)($report['source_fingerprint'] ?? ''),
            'sections' => $report['sections'] ?? [],
        ];
    }

    private function compactEconomyShadow(array $report): array
    {
        $integrity = is_array($report['shadow_integrity'] ?? null) ? $report['shadow_integrity'] : [];
        return [
            'ok' => !empty($report['ok']),
            'source_fingerprint' => (string)($report['source_fingerprint'] ?? ''),
            'sections' => $report['sections'] ?? [],
            'integrity_ok' => (int)($integrity['corrupted_count'] ?? 0) === 0,
            'checked_count' => (int)($integrity['checked_count'] ?? 0),
            'corrupted_count' => (int)($integrity['corrupted_count'] ?? 0),
        ];
    }
}
