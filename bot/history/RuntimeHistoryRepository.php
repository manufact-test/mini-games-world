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
        return $this->formatter->formatHistory($this->databaseSnapshot(), $legacyUserId, $limit);
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
        if (!in_array($environment, ['staging', 'local'], true)) {
            throw new RuntimeException('History DB runtime is forbidden outside staging/local.');
        }
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
