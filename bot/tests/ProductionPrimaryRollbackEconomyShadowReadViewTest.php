<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/database/DatabaseConnectionInterface.php';
require_once $root . '/bot/runtime/RuntimePrimaryStateSchemaInstaller.php';
require_once $root . '/bot/runtime/ProductionPrimaryRollbackSnapshotMaterializer.php';
require_once $root . '/bot/runtime/ProductionPrimaryRollbackMaterializedStateConnection.php';

final class ProductionRollbackEconomyShadowReadViewDatabase implements DatabaseConnectionInterface
{
    public array $stateRow = [];
    public array $shadowRows = [];
    public int $executeCalls = 0;

    public function driver(): string
    {
        return 'mysql';
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->executeCalls++;
        return 1;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        if (str_contains($normalized, 'from ' . strtolower(RuntimePrimaryStateSchemaInstaller::TABLE))) {
            return [$this->stateRow];
        }
        if (str_contains($normalized, 'from mgw_legacy_realtime_shadow')) {
            if (str_contains($normalized, "where entity_type = 'economy_user_balance'")) {
                return array_values(array_filter(
                    $this->shadowRows,
                    static fn(array $row): bool => ($row['entity_type'] ?? null) === 'economy_user_balance'
                ));
            }
            return $this->shadowRows;
        }
        throw new RuntimeException('Unexpected economy shadow test query: ' . $normalized);
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $rows = $this->fetchAll($sql, $params);
        return $rows === [] ? null : reset($rows[0]);
    }

    public function transaction(callable $callback): mixed
    {
        return $callback($this);
    }
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $messagePart) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($messagePart))) return;
        throw new RuntimeException('Unexpected exception: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected exception was not thrown.');
};
$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = $canonicalize($item);
    return $value;
};
$canonicalJson = static fn(mixed $value): string => json_encode(
    $canonicalize($value),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);
$shadowRow = static function (string $key, array $user) use ($canonicalJson): array {
    $payload = [
        'legacy_user_id' => $key,
        'telegram_id' => $user['telegram_id'] ?? null,
        'balance_match' => (int)($user['balance_match'] ?? 0),
        'balance_gold' => (int)($user['balance_gold'] ?? 0),
        'registered_at' => $user['registered_at'] ?? null,
        'last_seen_at' => $user['last_seen_at'] ?? null,
        'source_record_sha256' => hash('sha256', $canonicalJson($user)),
    ];
    $payloadJson = $canonicalJson($payload);
    return [
        'entity_type' => 'economy_user_balance',
        'entity_key' => $key,
        'payload_json' => $payloadJson,
        'payload_sha256' => hash('sha256', $payloadJson),
    ];
};

$source = [
    'users' => [
        '100' => [
            'id' => '100', 'telegram_id' => '100', 'first_name' => 'Первый',
            'username' => 'stale', 'balance_match' => 70, 'balance_gold' => 5,
            'registered_at' => '2026-07-01T09:00:00+00:00',
            'last_seen_at' => '2026-07-29T10:00:00+00:00',
        ],
        '200' => [
            'id' => '200', 'telegram_id' => '200', 'first_name' => 'Второй',
            'balance_match' => 20, 'balance_gold' => 0,
            'registered_at' => '2026-07-02T09:00:00+00:00',
            'last_seen_at' => '2026-07-29T11:00:00+00:00',
        ],
    ],
    'games' => [], 'queue' => [], 'transactions' => [], 'support' => [],
    'shop_orders' => [], 'payments' => [], 'notifications' => [], 'invites' => [], 'system' => [],
];
$materialized = $source;
unset($materialized['users']['100']['username']);
$materialized['users']['100']['last_seen_at'] = '2026-07-29T10:00:03+00:00';
$materialized['users']['200']['last_seen_at'] = '2026-07-29T11:00:02+00:00';
$sourceSha = hash('sha256', $canonicalJson($source));
$materializedSha = hash('sha256', $canonicalJson($materialized));
$materialization = [
    'ok' => true,
    'contract_version' => ProductionPrimaryRollbackSnapshotMaterializer::CONTRACT_VERSION,
    'snapshot' => $materialized,
    'source_state_revision' => 2190,
    'source_state_sha256' => $sourceSha,
    'materialized_state_sha256' => $materializedSha,
    'read_only' => true,
    'database_write_executed' => false,
];

$database = new ProductionRollbackEconomyShadowReadViewDatabase();
$database->stateRow = [
    'singleton_id' => 1,
    'revision' => 2190,
    'state_json' => $canonicalJson($source),
    'state_sha256' => $sourceSha,
];
$transactionPayload = $canonicalJson(['id' => 'tx-1', 'amount' => 10]);
$database->shadowRows = [
    $shadowRow('100', $source['users']['100']),
    $shadowRow('200', $source['users']['200']),
    [
        'entity_type' => 'economy_transaction',
        'entity_key' => 'tx-1',
        'payload_json' => $transactionPayload,
        'payload_sha256' => hash('sha256', $transactionPayload),
    ],
];

$connection = new ProductionPrimaryRollbackMaterializedStateConnection($database, $materialization);
$combined = $connection->fetchAll(
    "SELECT entity_type, entity_key, payload_json, payload_sha256
     FROM mgw_legacy_realtime_shadow
     WHERE entity_type IN ('economy_user_balance', 'economy_transaction')"
);
$balances = $connection->fetchAll(
    "SELECT entity_key, payload_json, payload_sha256
     FROM mgw_legacy_realtime_shadow
     WHERE entity_type = 'economy_user_balance'
     ORDER BY entity_key"
);

$assertSame(3, count($combined), 'Combined shadow view must preserve all rows');
$assertSame(2, count($balances), 'Balance shadow view must preserve user count');
$decoded = [];
foreach ($balances as $row) {
    $payload = json_decode((string)$row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $decoded[(string)$row['entity_key']] = $payload;
    $assertSame(
        (string)$row['payload_sha256'],
        hash('sha256', (string)$row['payload_json']),
        'Virtual shadow payload hash must verify'
    );
}
$assertSame('2026-07-29T10:00:03+00:00', $decoded['100']['last_seen_at'], 'First activity must be materialized');
$assertSame('2026-07-29T11:00:02+00:00', $decoded['200']['last_seen_at'], 'Second activity must be materialized');
$assertSame(70, $decoded['100']['balance_match'], 'Match balance must remain unchanged');
$assertSame(5, $decoded['100']['balance_gold'], 'Gold balance must remain unchanged');
$assertTrue($connection->economyShadowReadVerified(), 'Economy shadow read view must be verified');
$assertSame(2, $connection->economyShadowReadCount(), 'Both supported shadow reads must be counted');
$assertSame(2, $connection->economyShadowMaterializedUserCount(), 'Materialized shadow user count must be exact');
$assertSame(0, $database->executeCalls, 'Read view must not execute SQL writes');

$tampered = $database->shadowRows;
$payload = json_decode((string)$tampered[0]['payload_json'], true, 512, JSON_THROW_ON_ERROR);
$payload['balance_match'] = 999;
$tampered[0]['payload_json'] = $canonicalJson($payload);
$tampered[0]['payload_sha256'] = hash('sha256', $tampered[0]['payload_json']);
$database->shadowRows = $tampered;
$blocked = new ProductionPrimaryRollbackMaterializedStateConnection($database, $materialization);
$assertThrows(
    static fn() => $blocked->fetchAll(
        "SELECT entity_key, payload_json, payload_sha256
         FROM mgw_legacy_realtime_shadow
         WHERE entity_type = 'economy_user_balance'
         ORDER BY entity_key"
    ),
    'economic identity changed'
);
$assertSame(0, $database->executeCalls, 'Blocked read view must remain SQL-read-only');

fwrite(STDOUT, "ProductionPrimaryRollbackEconomyShadowReadViewTest passed: {$assertions} assertions.\n");
