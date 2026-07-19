<?php
declare(strict_types=1);

final class RuntimeNotificationRepository
{
    private RuntimeStorageRouter $router;

    public function __construct(
        private array $config,
        ?RuntimeStorageRouter $router = null,
        private ?DatabaseConnectionInterface $database = null
    ) {
        $this->router = $router ?? new RuntimeStorageRouter($this->config);
    }

    public function synchronizeAndList(
        array $jsonData,
        string|int $legacyUserId,
        ?string $authenticatedMgwId = null
    ): array {
        $this->assertDatabaseRoute();
        $legacyUserId = trim((string)$legacyUserId);
        if ($legacyUserId === '') {
            throw new InvalidArgumentException('Notification runtime requires a legacy user ID.');
        }

        $database = $this->database();
        $ownership = $this->ownership($database, $legacyUserId, $authenticatedMgwId);
        $store = new RealtimeDatabaseStore($database);
        $source = $this->sourceNotifications($jsonData, $legacyUserId);
        $created = 0;
        $unchanged = 0;

        foreach ($source as $notification) {
            $expected = $this->databaseNotification($notification, $ownership);
            $existing = $database->fetchAll(
                'SELECT notification_id FROM mgw_notifications
                 WHERE recipient_ref = :recipient_ref AND event_key = :event_key',
                [
                    'recipient_ref' => $ownership['account_ref'],
                    'event_key' => $expected['event_key'],
                ]
            );
            $row = $store->addNotification($expected);
            $this->assertImmutableRow($row, $expected);
            $this->synchronizeMutableState($database, $row, $expected);
            $existing === [] ? $created++ : $unchanged++;
        }

        $items = $this->databaseItems($database, $ownership['account_ref']);
        $sourceFingerprint = $this->fingerprint($source);
        $databaseFingerprint = $this->fingerprint($items);
        if (count($source) !== count($items) || !hash_equals($sourceFingerprint, $databaseFingerprint)) {
            throw new RuntimeException('Notification JSON and DB runtime parity check failed.');
        }

        return [
            'items' => $items,
            'summary' => [
                'source_count' => count($source),
                'database_count' => count($items),
                'created_count' => $created,
                'unchanged_count' => $unchanged,
                'source_fingerprint' => $sourceFingerprint,
                'database_fingerprint' => $databaseFingerprint,
                'parity' => true,
            ],
        ];
    }

    public function auditParity(
        array $jsonData,
        string|int $legacyUserId,
        ?string $authenticatedMgwId = null
    ): array {
        $this->assertDatabaseRoute();
        $legacyUserId = trim((string)$legacyUserId);
        if ($legacyUserId === '') {
            throw new InvalidArgumentException('Notification audit requires a legacy user ID.');
        }

        $database = $this->database();
        $ownership = $this->ownership($database, $legacyUserId, $authenticatedMgwId);
        $source = $this->sourceNotifications($jsonData, $legacyUserId);
        $items = $this->databaseItems($database, $ownership['account_ref']);
        $sourceFingerprint = $this->fingerprint($source);
        $databaseFingerprint = $this->fingerprint($items);
        $blockers = [];
        if (count($source) !== count($items)) {
            $blockers[] = 'Notification JSON and DB counts differ.';
        }
        if (!hash_equals($sourceFingerprint, $databaseFingerprint)) {
            $blockers[] = 'Notification JSON and DB fingerprints differ.';
        }

        return [
            'ok' => $blockers === [],
            'read_only' => true,
            'source_count' => count($source),
            'database_count' => count($items),
            'source_fingerprint' => $sourceFingerprint,
            'database_fingerprint' => $databaseFingerprint,
            'blockers' => $blockers,
        ];
    }

    private function assertDatabaseRoute(): void
    {
        if (!$this->router->enabled()
            || $this->router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE
            || $this->router->routeFor('notifications') !== RuntimeStorageRouter::DRIVER_DATABASE) {
            throw new RuntimeException('Notification DB runtime requires accounts and notifications routing.');
        }
    }

    private function database(): DatabaseConnectionInterface
    {
        if ($this->database !== null) return $this->database;
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Notification DB runtime requires an enabled database.');
        }
        return $this->database = PdoConnectionFactory::create($databaseConfig);
    }

    private function ownership(
        DatabaseConnectionInterface $database,
        string $legacyUserId,
        ?string $authenticatedMgwId
    ): array {
        $rows = $database->fetchAll(
            'SELECT account_ref, mgw_id, legacy_user_id, ownership_status
             FROM mgw_account_ownership WHERE legacy_user_id = :legacy_user_id',
            ['legacy_user_id' => $legacyUserId]
        );
        if (count($rows) !== 1) {
            throw new RuntimeException('Notification runtime requires exactly one account ownership row.');
        }
        $row = $rows[0];
        $accountRef = trim((string)($row['account_ref'] ?? ''));
        $mgwId = trim((string)($row['mgw_id'] ?? ''));
        if ($accountRef === '' || $mgwId === '' || (string)($row['ownership_status'] ?? '') !== 'active') {
            throw new RuntimeException('Notification account ownership is incomplete or inactive.');
        }
        $authenticatedMgwId = trim((string)$authenticatedMgwId);
        if ($authenticatedMgwId !== '' && !hash_equals($mgwId, $authenticatedMgwId)) {
            throw new RuntimeException('Authenticated MGW account does not match notification ownership.');
        }
        return [
            'account_ref' => $accountRef,
            'mgw_id' => $mgwId,
            'legacy_user_id' => $legacyUserId,
        ];
    }

    private function sourceNotifications(array $jsonData, string $legacyUserId): array
    {
        $items = [];
        $eventKeys = [];
        foreach (($jsonData['notifications'] ?? []) as $notification) {
            if (!is_array($notification)
                || (string)($notification['user_id'] ?? '') !== $legacyUserId) {
                continue;
            }
            $id = trim((string)($notification['id'] ?? ''));
            $eventKey = trim((string)($notification['event_key'] ?? ''));
            if ($id === '' || $eventKey === '') {
                throw new RuntimeException('Notification JSON row has no stable ID or event key.');
            }
            if (isset($items[$id]) || isset($eventKeys[$eventKey])) {
                throw new RuntimeException('Notification JSON contains duplicate IDs or event keys.');
            }
            $eventKeys[$eventKey] = true;
            $items[$id] = $this->normalizeLegacyNotification($notification);
        }
        $items = array_values($items);
        usort($items, fn(array $left, array $right): int => $this->compareNotifications($left, $right));
        return $items;
    }

    private function databaseItems(DatabaseConnectionInterface $database, string $accountRef): array
    {
        $rows = $database->fetchAll(
            'SELECT * FROM mgw_notifications
             WHERE recipient_ref = :recipient_ref
             ORDER BY created_at_utc DESC, notification_id DESC',
            ['recipient_ref' => $accountRef]
        );
        return array_map(fn(array $row): array => $this->legacyNotification($row), $rows);
    }

    private function databaseNotification(array $notification, array $ownership): array
    {
        return [
            'notification_id' => $notification['id'],
            'event_key' => $notification['event_key'],
            'recipient_ref' => $ownership['account_ref'],
            'mgw_id' => $ownership['mgw_id'],
            'legacy_user_id' => $ownership['legacy_user_id'],
            'type' => $notification['type'],
            'title' => $notification['title'],
            'message' => $notification['message'],
            'tone' => $notification['tone'],
            'invite_token' => $notification['invite_token'] !== '' ? $notification['invite_token'] : null,
            'payload' => $notification,
            'created_at_utc' => $this->requiredTimestamp($notification['created_at']),
            'read_at_utc' => $this->nullableTimestamp($notification['read_at']),
            'hidden_at_utc' => $this->nullableTimestamp($notification['hidden_at']),
        ];
    }

    private function assertImmutableRow(array $row, array $expected): void
    {
        $actualPayload = $this->normalizeLegacyNotification(
            $this->decodePayload($row['payload_json'] ?? null)
        );
        $expectedPayload = $this->normalizeLegacyNotification($expected['payload']);
        $actualPayload['read_at'] = null;
        $actualPayload['hidden_at'] = null;
        $expectedPayload['read_at'] = null;
        $expectedPayload['hidden_at'] = null;

        $checks = [
            'notification_id' => (string)($row['notification_id'] ?? ''),
            'event_key' => (string)($row['event_key'] ?? ''),
            'recipient_ref' => (string)($row['recipient_ref'] ?? ''),
            'mgw_id' => (string)($row['mgw_id'] ?? ''),
            'legacy_user_id' => (string)($row['legacy_user_id'] ?? ''),
            'type' => (string)($row['type'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'message' => (string)($row['message'] ?? ''),
            'tone' => trim((string)($row['tone'] ?? '')) ?: 'info',
            'invite_token' => (string)($row['invite_token'] ?? ''),
            'created_at_utc' => $this->requiredTimestamp($row['created_at_utc'] ?? null),
            'payload' => $actualPayload,
        ];
        $expectedChecks = [
            'notification_id' => $expected['notification_id'],
            'event_key' => $expected['event_key'],
            'recipient_ref' => $expected['recipient_ref'],
            'mgw_id' => $expected['mgw_id'],
            'legacy_user_id' => $expected['legacy_user_id'],
            'type' => $expected['type'],
            'title' => $expected['title'],
            'message' => $expected['message'],
            'tone' => trim((string)($expected['tone'] ?? '')) ?: 'info',
            'invite_token' => (string)($expected['invite_token'] ?? ''),
            'created_at_utc' => $expected['created_at_utc'],
            'payload' => $expectedPayload,
        ];
        if (!hash_equals($this->canonicalJson($expectedChecks), $this->canonicalJson($checks))) {
            throw new RuntimeException('Existing DB notification conflicts with JSON rollback source.');
        }
    }

    private function synchronizeMutableState(
        DatabaseConnectionInterface $database,
        array $row,
        array $expected
    ): void {
        $actualRead = $this->nullableTimestamp($row['read_at_utc'] ?? null);
        $actualHidden = $this->nullableTimestamp($row['hidden_at_utc'] ?? null);
        $expectedRead = $expected['read_at_utc'];
        $expectedHidden = $expected['hidden_at_utc'];

        if ($actualRead !== null && $expectedRead === null) {
            throw new RuntimeException('DB notification read state is ahead of the JSON rollback source.');
        }
        if ($actualHidden !== null && $expectedHidden === null) {
            throw new RuntimeException('DB notification hidden state is ahead of the JSON rollback source.');
        }
        if ($actualRead !== null && $expectedRead !== null && !hash_equals($actualRead, $expectedRead)) {
            throw new RuntimeException('Notification read timestamps differ between JSON and DB.');
        }
        if ($actualHidden !== null && $expectedHidden !== null && !hash_equals($actualHidden, $expectedHidden)) {
            throw new RuntimeException('Notification hidden timestamps differ between JSON and DB.');
        }

        if ($actualRead === null && $expectedRead !== null) {
            $database->execute(
                'UPDATE mgw_notifications SET read_at_utc = :read_at_utc
                 WHERE notification_id = :notification_id AND recipient_ref = :recipient_ref',
                [
                    'read_at_utc' => $expectedRead,
                    'notification_id' => $expected['notification_id'],
                    'recipient_ref' => $expected['recipient_ref'],
                ]
            );
        }
        if ($actualHidden === null && $expectedHidden !== null) {
            $database->execute(
                'UPDATE mgw_notifications SET hidden_at_utc = :hidden_at_utc
                 WHERE notification_id = :notification_id AND recipient_ref = :recipient_ref',
                [
                    'hidden_at_utc' => $expectedHidden,
                    'notification_id' => $expected['notification_id'],
                    'recipient_ref' => $expected['recipient_ref'],
                ]
            );
        }
    }

    private function legacyNotification(array $row): array
    {
        $legacy = $this->decodePayload($row['payload_json'] ?? null);
        $legacy['id'] = (string)($row['notification_id'] ?? '');
        $legacy['event_key'] = (string)($row['event_key'] ?? '');
        $legacy['user_id'] = (string)($row['legacy_user_id'] ?? '');
        $legacy['type'] = (string)($row['type'] ?? '');
        $legacy['title'] = (string)($row['title'] ?? 'Уведомление');
        $legacy['message'] = (string)($row['message'] ?? '');
        $legacy['tone'] = trim((string)($row['tone'] ?? '')) ?: 'info';
        $legacy['invite_token'] = (string)($row['invite_token'] ?? '');
        $legacy['created_at'] = $this->requiredTimestamp($row['created_at_utc'] ?? null);
        $legacy['read_at'] = $this->nullableTimestamp($row['read_at_utc'] ?? null);
        $legacy['hidden_at'] = $this->nullableTimestamp($row['hidden_at_utc'] ?? null);
        $normalized = $this->normalizeLegacyNotification($legacy);
        $normalized['created_at'] = $this->isoTimestamp($normalized['created_at']);
        $normalized['read_at'] = $normalized['read_at'] === null ? null : $this->isoTimestamp($normalized['read_at']);
        $normalized['hidden_at'] = $normalized['hidden_at'] === null ? null : $this->isoTimestamp($normalized['hidden_at']);
        return $normalized;
    }

    private function normalizeLegacyNotification(array $notification): array
    {
        return [
            'id' => trim((string)($notification['id'] ?? '')),
            'event_key' => trim((string)($notification['event_key'] ?? '')),
            'user_id' => trim((string)($notification['user_id'] ?? '')),
            'type' => trim((string)($notification['type'] ?? '')),
            'title' => (string)($notification['title'] ?? 'Уведомление'),
            'message' => (string)($notification['message'] ?? ''),
            'tone' => trim((string)($notification['tone'] ?? 'info')) ?: 'info',
            'order_id' => trim((string)($notification['order_id'] ?? '')),
            'payment_id' => trim((string)($notification['payment_id'] ?? '')),
            'transaction_id' => trim((string)($notification['transaction_id'] ?? '')),
            'invite_token' => trim((string)($notification['invite_token'] ?? '')),
            'cycle_key' => trim((string)($notification['cycle_key'] ?? '')),
            'created_at' => $this->requiredTimestamp($notification['created_at'] ?? null),
            'read_at' => $this->nullableTimestamp($notification['read_at'] ?? null),
            'hidden_at' => $this->nullableTimestamp($notification['hidden_at'] ?? null),
        ];
    }

    private function fingerprint(array $notifications): string
    {
        $normalized = array_map(
            fn(array $notification): array => $this->normalizeLegacyNotification($notification),
            $notifications
        );
        usort($normalized, fn(array $left, array $right): int => $this->compareNotifications($left, $right));
        return hash('sha256', $this->canonicalJson($normalized));
    }

    private function compareNotifications(array $left, array $right): int
    {
        $time = strcmp((string)$right['created_at'], (string)$left['created_at']);
        return $time !== 0 ? $time : strcmp((string)$right['id'], (string)$left['id']);
    }

    private function decodePayload(mixed $payload): array
    {
        if ($payload === null || trim((string)$payload) === '') return [];
        try {
            $decoded = json_decode((string)$payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('DB notification payload is invalid JSON.');
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('DB notification payload must be an object.');
        }
        return $decoded;
    }

    private function requiredTimestamp(mixed $value): string
    {
        $timestamp = $this->nullableTimestamp($value);
        if ($timestamp === null) throw new RuntimeException('Notification timestamp is missing or invalid.');
        return $timestamp;
    }

    private function nullableTimestamp(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        try {
            return (new DateTimeImmutable($value))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s.u');
        } catch (Throwable) {
            throw new RuntimeException('Notification timestamp is invalid.');
        }
    }

    private function isoTimestamp(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DATE_ATOM);
    }

    private function canonicalJson(mixed $value): string
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
