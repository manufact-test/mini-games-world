<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/DatabaseConfig.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/database/PdoConnectionFactory.php';
require $root . '/database/DatabaseMigrationInterface.php';
require $root . '/database/MigrationRepository.php';
require $root . '/database/MigrationRunner.php';
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/ledger/LedgerWriteService.php';
require $root . '/ledger/LedgerIntegrityVerifier.php';
require $root . '/ledger/LegacyOpeningBalanceImportService.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$targets = [
    'MySQL' => [
        'dsn' => trim((string)(getenv('MGW_TEST_MYSQL_DSN') ?: '')),
        'user' => (string)(getenv('MGW_TEST_MYSQL_USER') ?: ''),
        'password' => (string)(getenv('MGW_TEST_MYSQL_PASSWORD') ?: ''),
        'driver' => 'mysql',
        'mgw_id' => 'MGW-0123456789ABCDEF',
    ],
    'MariaDB' => [
        'dsn' => trim((string)(getenv('MGW_TEST_MARIADB_DSN') ?: '')),
        'user' => (string)(getenv('MGW_TEST_MARIADB_USER') ?: ''),
        'password' => (string)(getenv('MGW_TEST_MARIADB_PASSWORD') ?: ''),
        'driver' => 'mariadb',
        'mgw_id' => 'MGW-FEDCBA9876543210',
    ],
];

foreach ($targets as $label => $target) {
    if ($target['dsn'] === '') {
        fwrite(STDOUT, "LegacyOpeningBalanceImportDatabaseIntegrationTest: {$label} skipped.\n");
        continue;
    }

    $dsnValue = static function (string $key) use ($target): string {
        return preg_match('/(?:^|[:;])' . preg_quote($key, '/') . '=([^;]+)/', $target['dsn'], $matches) === 1
            ? trim((string)$matches[1])
            : '';
    };
    $config = DatabaseConfig::fromApplicationConfig([
        'database' => [
            'enabled' => true,
            'driver' => $target['driver'],
            'host' => $dsnValue('host'),
            'port' => (int)($dsnValue('port') !== '' ? $dsnValue('port') : '3306'),
            'name' => $dsnValue('dbname'),
            'user' => $target['user'],
            'password' => $target['password'],
            'charset' => 'utf8mb4',
        ],
    ]);
    $database = PdoConnectionFactory::create($config);
    $cleanup = static function () use ($database): void {
        foreach ([
            'mgw_legacy_financial_transactions',
            'mgw_legacy_shop_orders',
            'mgw_legacy_payments',
            'mgw_reservation_events',
            'mgw_ledger_entries',
            'mgw_reservations',
            'mgw_idempotency_keys',
            'mgw_balances',
            'mgw_legacy_realtime_shadow',
            'mgw_notifications',
            'mgw_invite_events',
            'mgw_invites',
            'mgw_match_player_snapshots',
            'mgw_match_snapshots',
            'mgw_match_players',
            'mgw_match_queue',
            'mgw_matches',
            'mgw_sessions',
            'mgw_devices',
            'mgw_identities',
            'mgw_users',
            'mgw_meta',
            'mgw_schema_migrations',
        ] as $table) {
            $database->execute('DROP TABLE IF EXISTS `' . $table . '`');
        }
    };

    $cleanup();
    try {
        $runner = new MigrationRunner($database, $root . '/database/migrations');
        $assertSame(6, $runner->migrate(false)['executed_count'], "{$label} must build all schemas");

        $now = '2026-07-17 18:00:00.000000';
        $database->execute(
            'INSERT INTO mgw_users (
                mgw_id, status, display_name, username, created_at_utc, updated_at_utc, last_seen_at_utc
             ) VALUES (
                :mgw_id, :status, :display_name, NULL, :created_at, :updated_at, :last_seen_at
             )',
            [
                'mgw_id' => $target['mgw_id'],
                'status' => 'active',
                'display_name' => $label . ' Opening Import',
                'created_at' => $now,
                'updated_at' => $now,
                'last_seen_at' => $now,
            ]
        );
        $database->execute(
            'INSERT INTO mgw_identities (
                mgw_id, provider, provider_subject, linked_at_utc, last_authenticated_at_utc
             ) VALUES (
                :mgw_id, :provider, :provider_subject, :linked_at, :authenticated_at
             )',
            [
                'mgw_id' => $target['mgw_id'],
                'provider' => 'telegram',
                'provider_subject' => '9001',
                'linked_at' => $now,
                'authenticated_at' => $now,
            ]
        );

        $insertShadow = static function (string $legacyUserId, int $match, int $gold) use ($database): void {
            $payload = [
                'legacy_user_id' => $legacyUserId,
                'telegram_id' => $legacyUserId,
                'balance_match' => $match,
                'balance_gold' => $gold,
                'registered_at' => '2026-07-01T10:00:00+00:00',
                'last_seen_at' => '2026-07-17T17:59:00+00:00',
                'source_record_sha256' => hash('sha256', 'db-source|' . $legacyUserId . '|' . $match . '|' . $gold),
            ];
            $payloadJson = LedgerIntegrity::canonicalJson($payload);
            $database->execute(
                'INSERT INTO mgw_legacy_realtime_shadow (
                    entity_type, entity_key, payload_json, payload_sha256,
                    source_updated_at_utc, synced_at_utc
                 ) VALUES (
                    :entity_type, :entity_key, :payload_json, :payload_sha256,
                    :source_updated_at_utc, :synced_at_utc
                 )',
                [
                    'entity_type' => 'economy_user_balance',
                    'entity_key' => $legacyUserId,
                    'payload_json' => $payloadJson,
                    'payload_sha256' => hash('sha256', $payloadJson),
                    'source_updated_at_utc' => '2026-07-17 17:59:00.000000',
                    'synced_at_utc' => '2026-07-17 18:00:00.000000',
                ]
            );
        };
        $insertShadow('9001', 90, 15);
        $insertShadow('9002', 20, 0);

        $service = new LegacyOpeningBalanceImportService(
            $database,
            new LedgerWriteService($database),
            new LedgerIntegrityVerifier($database)
        );
        $preview = $service->preview();
        $assertSame(true, $preview['ready'], "{$label} opening import preview must be ready");
        $assertSame(4, $preview['planned_balance_create_count'], "{$label} preview must plan four balance rows");
        $assertSame(3, $preview['planned_ledger_create_count'], "{$label} preview must plan three opening entries");
        $assertSame(['match_coin' => 110, 'gold_coin' => 15], $preview['source_totals'], "{$label} preview totals must remain separate");

        $first = $service->run();
        $assertSame(4, $first['created_balance_count'], "{$label} first run must create four balances");
        $assertSame(3, $first['created_ledger_count'], "{$label} first run must create three ledger entries");
        $assertSame(0, $first['replayed_ledger_count'], "{$label} first run must not replay entries");
        $assertSame(true, $first['verification']['ok'], "{$label} first run must verify imported state");
        $assertSame(['match_coin' => 110, 'gold_coin' => 15], $first['verification']['database_totals'], "{$label} database totals must equal source totals");
        $assertSame(4, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_balances'), "{$label} must contain four imported balance rows");
        $assertSame(3, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries'), "{$label} must contain three opening ledger entries");
        $assertSame(90, (int)$database->fetchValue(
            'SELECT available_amount FROM mgw_balances WHERE account_ref = :account_ref AND asset_code = :asset_code',
            ['account_ref' => 'mgw:' . $target['mgw_id'], 'asset_code' => 'match_coin']
        ), "{$label} mapped balance must use MGW-ID account reference");
        $assertSame(0, (int)$database->fetchValue(
            'SELECT available_amount FROM mgw_balances WHERE account_ref = :account_ref AND asset_code = :asset_code',
            ['account_ref' => 'legacy:9002', 'asset_code' => 'gold_coin']
        ), "{$label} zero Gold amount must remain an explicit separate row");

        $repeat = $service->run();
        $assertSame(0, $repeat['created_balance_count'], "{$label} repeat must create no balances");
        $assertSame(0, $repeat['created_ledger_count'], "{$label} repeat must create no entries");
        $assertSame(3, $repeat['replayed_ledger_count'], "{$label} repeat must replay three opening operations");
        $assertSame(3, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries'), "{$label} repeat must not duplicate entries");
        $assertSame('completed', (string)json_decode((string)$database->fetchValue(
            'SELECT meta_value FROM mgw_meta WHERE meta_key = :meta_key',
            ['meta_key' => 'legacy_economy_opening_import_v1']
        ), true, 512, JSON_THROW_ON_ERROR)['status'], "{$label} metadata must record completion");

        foreach ($database->fetchAll('SELECT account_ref, asset_code FROM mgw_balances') as $row) {
            $verified = (new LedgerIntegrityVerifier($database))->verifyAccountAsset(
                (string)$row['account_ref'],
                (string)$row['asset_code']
            );
            $assertTrue($verified['ok'], "{$label} every imported balance chain must verify");
        }
    } finally {
        $cleanup();
    }
}

fwrite(STDOUT, "LegacyOpeningBalanceImportDatabaseIntegrationTest: {$assertions} assertions passed\n");
