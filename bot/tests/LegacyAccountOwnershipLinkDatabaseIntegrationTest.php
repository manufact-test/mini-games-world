<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/storage/contracts/StorageTransactionInterface.php';
require $root . '/storage/contracts/StorageAdapterInterface.php';
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/DatabaseConfig.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/database/PdoConnectionFactory.php';
require $root . '/database/DatabaseMigrationInterface.php';
require $root . '/database/MigrationRepository.php';
require $root . '/database/MigrationRunner.php';
require $root . '/accounts/MgwIdGenerator.php';
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/ledger/LedgerWriteService.php';
require $root . '/ledger/LedgerIntegrityVerifier.php';
require $root . '/ledger/LegacyEconomyShadowSyncService.php';
require $root . '/ledger/LegacyOpeningBalanceImportService.php';
require $root . '/accounts/LegacyAccountImportService.php';
require $root . '/accounts/LegacyAccountOwnershipLinkService.php';

final class LegacyOwnershipDatabaseStorage implements StorageAdapterInterface
{
    public function __construct(private array $data) {}
    public function transaction(callable $callback): mixed { return $callback($this->data); }
    public function readOnly(callable $callback): mixed { $snapshot = $this->data; return $callback($snapshot); }
    public function driver(): string { return 'json'; }
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$canonicalRows = static function (array $rows): string {
    return LedgerIntegrity::canonicalJson($rows);
};

$targets = [
    'MySQL' => [
        'dsn' => trim((string)(getenv('MGW_TEST_MYSQL_DSN') ?: '')),
        'user' => (string)(getenv('MGW_TEST_MYSQL_USER') ?: ''),
        'password' => (string)(getenv('MGW_TEST_MYSQL_PASSWORD') ?: ''),
        'driver' => 'mysql',
        'legacy_id' => '73001',
    ],
    'MariaDB' => [
        'dsn' => trim((string)(getenv('MGW_TEST_MARIADB_DSN') ?: '')),
        'user' => (string)(getenv('MGW_TEST_MARIADB_USER') ?: ''),
        'password' => (string)(getenv('MGW_TEST_MARIADB_PASSWORD') ?: ''),
        'driver' => 'mariadb',
        'legacy_id' => '74001',
    ],
];

foreach ($targets as $label => $target) {
    if ($target['dsn'] === '') {
        fwrite(STDOUT, "LegacyAccountOwnershipLinkDatabaseIntegrationTest: {$label} skipped.\n");
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
            'mgw_account_ownership',
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
        $assertSame(7, $runner->migrate(false)['executed_count'], "{$label} must build all schemas");

        $legacyId = $target['legacy_id'];
        $preexistingMgwId = MgwIdGenerator::generate();
        $now = '2026-07-18 07:59:00.000000';
        $database->execute(
            'INSERT INTO mgw_users (
                mgw_id, status, display_name, username,
                created_at_utc, updated_at_utc, last_seen_at_utc
             ) VALUES (
                :mgw_id, :status, :display_name, :username,
                :created_at_utc, :updated_at_utc, :last_seen_at_utc
             )',
            [
                'mgw_id' => $preexistingMgwId,
                'status' => 'active',
                'display_name' => $label . ' Existing Owner',
                'username' => strtolower($label) . '_existing',
                'created_at_utc' => $now,
                'updated_at_utc' => $now,
                'last_seen_at_utc' => $now,
            ]
        );
        $database->execute(
            'INSERT INTO mgw_identities (
                mgw_id, provider, provider_subject, provider_username,
                linked_at_utc, last_authenticated_at_utc
             ) VALUES (
                :mgw_id, :provider, :provider_subject, :provider_username,
                :linked_at_utc, :last_authenticated_at_utc
             )',
            [
                'mgw_id' => $preexistingMgwId,
                'provider' => 'telegram',
                'provider_subject' => $legacyId,
                'provider_username' => strtolower($label) . '_existing',
                'linked_at_utc' => $now,
                'last_authenticated_at_utc' => $now,
            ]
        );

        $data = [
            'users' => [
                $legacyId => [
                    'id' => $legacyId,
                    'telegram_id' => $legacyId,
                    'first_name' => $label . ' Owner',
                    'username' => strtolower($label) . '_owner',
                    'balance_match' => 40,
                    'balance_gold' => 5,
                    'registered_at' => '2026-07-18T08:00:00+00:00',
                    'last_seen_at' => '2026-07-18T09:00:00+00:00',
                ],
            ],
            'transactions' => [],
            'games' => [],
            'queue' => [],
            'invites' => [],
            'notifications' => [],
            'payments' => [],
            'shop_orders' => [],
        ];
        $storage = new LegacyOwnershipDatabaseStorage($data);
        $assertSame(true, (new LegacyEconomyShadowSyncService($storage, $database))->run()['ok'], "{$label} economy shadow must sync");
        $verifier = new LedgerIntegrityVerifier($database);
        $opening = new LegacyOpeningBalanceImportService(
            $database,
            new LedgerWriteService($database),
            $verifier
        );
        $assertSame('completed', $opening->run()['status'], "{$label} opening import must complete");
        $assertSame(2, (int)$database->fetchValue(
            'SELECT COUNT(*) FROM mgw_balances WHERE account_ref = :account_ref',
            ['account_ref' => 'legacy:' . $legacyId]
        ), "{$label} preexisting identity balances must remain on the legacy account");
        $assertSame($preexistingMgwId, (string)$database->fetchValue(
            'SELECT mgw_id FROM mgw_balances
             WHERE account_ref = :account_ref AND asset_code = :asset_code',
            ['account_ref' => 'legacy:' . $legacyId, 'asset_code' => 'match_coin']
        ), "{$label} opening balance must retain the existing MGW-ID as metadata");

        $assertSame('completed', (new LegacyAccountImportService($storage, $database))->run()['status'], "{$label} account import must complete");

        $mgwId = (string)$database->fetchValue(
            'SELECT mgw_id FROM mgw_identities WHERE provider = :provider AND provider_subject = :subject',
            ['provider' => 'legacy_import', 'subject' => $legacyId]
        );
        $assertTrue(MgwIdGenerator::isValid($mgwId), "{$label} staged MGW ID must be valid");
        $assertSame($preexistingMgwId, $mgwId, "{$label} account import must reuse the preexisting provider MGW-ID");
        $balanceSnapshot = $canonicalRows($database->fetchAll(
            'SELECT account_ref, mgw_id, legacy_user_id, asset_code, available_amount,
                    reserved_amount, version, created_at_utc, updated_at_utc
             FROM mgw_balances ORDER BY account_ref, asset_code'
        ));
        $ledgerSnapshot = $canonicalRows($database->fetchAll(
            'SELECT ledger_sequence, entry_id, idempotency_key, account_ref, mgw_id, legacy_user_id,
                    asset_code, available_delta, reserved_delta, available_before, available_after,
                    reserved_before, reserved_after, category, source_type, source_ref, reservation_id,
                    metadata_json, previous_entry_sha256, entry_sha256, created_at_utc
             FROM mgw_ledger_entries ORDER BY ledger_sequence'
        ));

        $service = new LegacyAccountOwnershipLinkService($storage, $database, $verifier);
        $preview = $service->preview();
        $assertSame(true, $preview['ready'], "{$label} ownership preview must be ready");
        $assertSame(1, $preview['planned_ownership_create_count'], "{$label} must plan one ownership row");
        $assertSame(0, $preview['planned_provider_identity_create_count'], "{$label} must reuse the existing provider identity");
        $assertSame(1, $preview['reused_provider_identity_count'], "{$label} preview must report one reused provider identity");

        $first = $service->run();
        $assertSame(1, $first['created_ownership_count'], "{$label} must create one ownership row");
        $assertSame(0, $first['created_provider_identity_count'], "{$label} must not duplicate the provider identity");
        $assertSame(1, $first['reused_provider_identity_count'], "{$label} must reuse one provider identity");
        $assertSame(true, $first['verification']['ok'], "{$label} ownership verification must pass");
        $assertSame($mgwId, (string)$database->fetchValue(
            'SELECT mgw_id FROM mgw_account_ownership WHERE account_ref = :account_ref',
            ['account_ref' => 'legacy:' . $legacyId]
        ), "{$label} ownership must resolve to staged MGW ID");
        $assertSame($mgwId, (string)$database->fetchValue(
            'SELECT mgw_id FROM mgw_identities WHERE provider = :provider AND provider_subject = :subject',
            ['provider' => 'telegram', 'subject' => $legacyId]
        ), "{$label} Telegram identity must resolve to the same MGW ID");
        $assertSame(0, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_devices'), "{$label} must not create devices");
        $assertSame(0, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_sessions'), "{$label} must not create sessions");
        $assertSame($balanceSnapshot, $canonicalRows($database->fetchAll(
            'SELECT account_ref, mgw_id, legacy_user_id, asset_code, available_amount,
                    reserved_amount, version, created_at_utc, updated_at_utc
             FROM mgw_balances ORDER BY account_ref, asset_code'
        )), "{$label} ownership linking must not mutate balances");
        $assertSame($ledgerSnapshot, $canonicalRows($database->fetchAll(
            'SELECT ledger_sequence, entry_id, idempotency_key, account_ref, mgw_id, legacy_user_id,
                    asset_code, available_delta, reserved_delta, available_before, available_after,
                    reserved_before, reserved_after, category, source_type, source_ref, reservation_id,
                    metadata_json, previous_entry_sha256, entry_sha256, created_at_utc
             FROM mgw_ledger_entries ORDER BY ledger_sequence'
        )), "{$label} ownership linking must not rewrite ledger rows");

        $repeat = $service->run();
        $assertSame(0, $repeat['created_ownership_count'], "{$label} repeat must not create ownership rows");
        $assertSame(0, $repeat['created_provider_identity_count'], "{$label} repeat must not create identities");
        $assertSame(1, $repeat['reused_provider_identity_count'], "{$label} repeat must reuse provider identity");
        $assertSame(1, $repeat['unchanged_user_count'], "{$label} repeat must verify one unchanged user");
        $assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_account_ownership'), "{$label} repeat must preserve one ownership row");
        $assertSame(2, (int)$database->fetchValue(
            'SELECT COUNT(*) FROM mgw_identities WHERE mgw_id = :mgw_id',
            ['mgw_id' => $mgwId]
        ), "{$label} repeat must preserve exactly two identities");
        $assertSame(true, $verifier->verifyAccountAsset('legacy:' . $legacyId, 'match_coin')['ok'], "{$label} Match ledger must remain valid");
        $assertSame(true, $verifier->verifyAccountAsset('legacy:' . $legacyId, 'gold_coin')['ok'], "{$label} Gold ledger must remain valid");
    } finally {
        $cleanup();
    }
}

fwrite(STDOUT, "LegacyAccountOwnershipLinkDatabaseIntegrationTest passed: {$assertions} assertions.\n");
