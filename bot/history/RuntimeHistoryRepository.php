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
        $snapshot = $this->databaseSnapshot();

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
        $databaseSnapshot = $this->databaseSnapshot();
        $userIds = array_values(array_filter(
            array_map('strval', array_keys(is_array($jsonSnapshot['users'] ?? null) ? $jsonSnapshot['users'] : [])),
            static fn(string $value): bool => $value !== ''
        ));
        sort($userIds, SORT_STRING);

        $mismatchCount = 0;
        $legacyParts = [];
        $databaseParts = [];
        foreach ($userIds as $userId) {
            $legacy = $this->formatter->formatHistory($jsonSnapshot, $userId, 24);
            $database = $this->formatter->formatHistory($databaseSnapshot, $userId, 24);
            $legacyHash = hash('sha256', LedgerIntegrity::canonicalJson($legacy));
            $databaseHash = hash('sha256', LedgerIntegrity::canonicalJson($database));
            $legacyParts[] = hash('sha256', $userId) . ':' . $legacyHash;
            $databaseParts[] = hash('sha256', $userId) . ':' . $databaseHash;
            if (!hash_equals($legacyHash, $databaseHash)) $mismatchCount++;
        }

        sort($legacyParts, SORT_STRING);
        sort($databaseParts, SORT_STRING);
        $legacyFingerprint = hash('sha256', implode("\n", $legacyParts));
        $databaseFingerprint = hash('sha256', implode("\n", $databaseParts));
        $blockers = [];
        if ($mismatchCount > 0 || !hash_equals($legacyFingerprint, $databaseFingerprint)) {
            $blockers[] = 'Database history differs from the current JSON history.';
        }

        return [
            'ok' => $blockers === [],
            'read_only' => true,
            'source_user_count' => count($userIds),
            'transaction_count' => count($databaseSnapshot['transactions']),
            'game_count' => count($databaseSnapshot['games']),
            'mismatch_count' => $mismatchCount,
            'json_history_fingerprint' => $legacyFingerprint,
            'database_history_fingerprint' => $databaseFingerprint,
            'blockers' => $blockers,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function databaseSnapshot(): array
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

        usort($transactions, static function (array $left, array $right): int {
            $date = strcmp((string)($left['created_at'] ?? ''), (string)($right['created_at'] ?? ''));
            if ($date !== 0) return $date;
            return strcmp((string)($left['id'] ?? ''), (string)($right['id'] ?? ''));
        });
        uasort($games, static function (array $left, array $right): int {
            $date = strcmp((string)($left['created_at'] ?? ''), (string)($right['created_at'] ?? ''));
            if ($date !== 0) return $date;
            return strcmp((string)($left['id'] ?? ''), (string)($right['id'] ?? ''));
        });

        return ['transactions' => $transactions, 'games' => $games];
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
