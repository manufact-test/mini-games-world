<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$databaseDir = $root . '/database';

require $root . '/storage/contracts/StorageTransactionInterface.php';
require $root . '/storage/contracts/StorageAdapterInterface.php';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/DatabaseConfig.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/PdoConnectionFactory.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require $databaseDir . '/MigrationRepository.php';
require $databaseDir . '/MigrationRunner.php';
require $root . '/storage/RuntimeStorageRouter.php';
require $root . '/accounts/MgwIdGenerator.php';
require $root . '/accounts/MgwIdentityPolicy.php';
require $root . '/catalog/ProductInventoryService.php';
require $root . '/accounts/AccountIdentityService.php';
require $root . '/accounts/RuntimeAccountOwnershipService.php';
require $root . '/accounts/AndroidAuthAttemptLimiter.php';
require $root . '/accounts/AndroidDeviceAuthService.php';
require $root . '/economy/EconomyConfigSimulator.php';
require $root . '/economy/EconomyConfigDefinition.php';
require $root . '/economy/EconomyConfigService.php';
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/ledger/LedgerWriteService.php';
require $root . '/accounts/AccountLinkService.php';

if (!extension_loaded('pdo_mysql')) {
    throw new RuntimeException('Mvp26_3_3AccountLinkMysqlFullFlowTest requires pdo_mysql.');
}

final class Mvp26_3_3MemoryStorage implements StorageAdapterInterface
{
    public function __construct(private array $data) {}

    public function driver(): string
    {
        return RuntimeStorageRouter::DRIVER_JSON;
    }

    public function transaction(callable $callback): mixed
    {
        $working = $this->data;
        $result = $callback($working);
        $this->data = $working;
        return $result;
    }

    public function readOnly(callable $callback): mixed
    {
        $snapshot = $this->data;
        return $callback($snapshot);
    }

    public function snapshot(): array
    {
        return $this->data;
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
};

$dsn = trim((string)getenv('MGW_ACCOUNT_LINK_MYSQL_DSN'));
$user = (string)(getenv('MGW_ACCOUNT_LINK_MYSQL_USER') ?: 'root');
$password = (string)(getenv('MGW_ACCOUNT_LINK_MYSQL_PASSWORD') ?: 'root');
if ($dsn === '') {
    throw new RuntimeException('MGW_ACCOUNT_LINK_MYSQL_DSN is required.');
}

$pdo = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$migration = $runner->migrate(false);
$assert($migration['executed_count'] > 0, 'MySQL proof must apply the current migration set.');
$assertSame(
    'mysql',
    $database->driver(),
    'Full-flow proof must execute on MySQL.'
);

$config = [
    'environment'=>'staging',
    'storage_driver'=>'json',
    'data_dir'=>'/tmp/mgw-account-link-mysql-proof-unused',
    'bot_token'=>'mvp26-link-mysql-proof-not-live',
    'staging_bot_username'=>'MiniGamesWorld_test_bot',
    'mgw_account_session_ttl_sec'=>3600,
    'database'=>[
        'enabled'=>true,
        'driver'=>'mysql',
        'host'=>'127.0.0.1',
        'port'=>3306,
        'name'=>'mgw_link_full',
        'user'=>$user,
        'password'=>$password,
        'charset'=>'utf8mb4',
    ],
    'feature_flags'=>[
        'database_runtime'=>[
            'enabled'=>true,
            'modules'=>[
                'accounts'=>true,
                'economy'=>true,
            ],
        ],
    ],
    'android_auth_rate_limits'=>[
        'window_seconds'=>900,
        'network_max_requests'=>100,
        'subject_max_requests'=>100,
    ],
];

$accounts = new AccountIdentityService($database, 3600, (string)$config['bot_token']);
$ownerships = new RuntimeAccountOwnershipService($database);
$ledger = new LedgerWriteService($database);

$telegramSubject = '799900011';
$telegram = $accounts->resolveTelegramUser([
    'id'=>$telegramSubject,
    'first_name'=>'MySQL Target',
    'username'=>'mysql_target',
], 'mvp26-mysql-target-session');
$targetMgw = (string)$telegram['mgw_id'];
$targetOwnership = $ownerships->ensure('telegram', $telegramSubject, $targetMgw);

$ledger->postAvailableDelta([
    'operation_key'=>'mvp26-3-3-target-seed',
    'account_ref'=>$targetOwnership['account_ref'],
    'mgw_id'=>$targetMgw,
    'legacy_user_id'=>$telegramSubject,
    'asset_code'=>'mgw_coin',
    'available_delta'=>5000,
    'category'=>'test_seed',
    'source_type'=>'test',
    'source_ref'=>'mvp26-3-3-target',
]);

$credentialRaw = str_repeat("\x71", 32);
$credential = rtrim(strtr(base64_encode($credentialRaw), '+/', '-_'), '=');
$androidAuth = new AndroidDeviceAuthService($config, $database);
$androidBootstrap = $androidAuth->bootstrapCredential($credential, '203.0.113.77');
$androidSessionToken = (string)$androidBootstrap['session_token'];
$androidUser = $androidBootstrap['user'];

$sourceMgw = (string)$androidUser['mgw_id'];
$sourceLegacy = (string)$androidUser['id'];
$sourceOwnership = $ownerships->findByMgwId($sourceMgw);
$assert(is_array($sourceOwnership), 'Fresh Android account must have one runtime ownership row.');
$assert($sourceMgw !== $targetMgw, 'Fresh Android and Telegram accounts must start distinct.');

$ledger->postAvailableDelta([
    'operation_key'=>'mvp26-3-3-source-starter',
    'account_ref'=>$sourceOwnership['account_ref'],
    'mgw_id'=>$sourceMgw,
    'legacy_user_id'=>$sourceLegacy,
    'asset_code'=>'mgw_coin',
    'available_delta'=>1000,
    'category'=>'welcome_bonus',
    'source_type'=>'weekly_match',
    'source_ref'=>'mvp26-3-3-source',
]);

$zeroStats = [
    'games_played'=>0,
    'wins'=>0,
    'losses'=>0,
    'draws'=>0,
    'match_games_this_week'=>0,
    'match_games_prev_week'=>0,
    'bot_games_played'=>0,
    'bot_wins'=>0,
    'bot_losses'=>0,
    'bot_draws'=>0,
    'bot_win_streak'=>0,
];

$storage = new Mvp26_3_3MemoryStorage([
    'users'=>[
        $telegramSubject=>[
            'id'=>$telegramSubject,
            'telegram_id'=>$telegramSubject,
            'mgw_id'=>$targetMgw,
            'mgw_account_ref'=>$targetOwnership['account_ref'],
            'mgw_identity_provider'=>'telegram',
            'first_name'=>'MySQL Target',
            'username'=>'mysql_target',
            // Simulate the real-device defect: the existing Telegram runtime
            // snapshot is stale/zero even though canonical DB balance is 5000.
            'balance'=>0,
            'status'=>'idle',
            'current_game_id'=>null,
            'stats'=>$zeroStats,
        ],
        $sourceLegacy=>[
            'id'=>$sourceLegacy,
            'telegram_id'=>$sourceLegacy,
            'mgw_id'=>$sourceMgw,
            'mgw_account_ref'=>$sourceOwnership['account_ref'],
            'mgw_identity_provider'=>'android_device',
            'first_name'=>'Player Android',
            'username'=>'',
            'balance'=>1000,
            'status'=>'idle',
            'current_game_id'=>null,
            'weekly_match_welcome_grant_done'=>true,
            'weekly_match_welcome_grant_amount'=>1000,
            'weekly_match_first_grant_done'=>true,
            'weekly_match_first_game_grants'=>[],
            'gold_deposited_total'=>0,
            'gold_wagered_total'=>0,
            'gold_shop_spent_total'=>0,
            'stats'=>$zeroStats,
        ],
    ],
    'transactions'=>[
        [
            'id'=>'tx_mysql_android_welcome',
            'category'=>'welcome_bonus',
            'user_id'=>$sourceLegacy,
            'mgw_id'=>$sourceMgw,
            'account_ref'=>$sourceOwnership['account_ref'],
            'amount'=>1000,
        ],
        [
            'id'=>'tx_mysql_target_history',
            'category'=>'test_target_history',
            'user_id'=>$telegramSubject,
            'mgw_id'=>$targetMgw,
            'account_ref'=>$targetOwnership['account_ref'],
            'amount'=>5000,
        ],
    ],
    'notifications'=>[],
]);

$link = new AccountLinkService($config, $database, $storage);
$challenge = $link->createChallenge($androidUser);
$assertSame('pending', $challenge['status'], 'MySQL challenge must start pending.');
$assert(
    preg_match('/\?start=link_([A-Za-z0-9_-]{32})$/', (string)$challenge['telegram_url'], $matches) === 1,
    'MySQL challenge must produce one Telegram deep link.'
);
$token = (string)$matches[1];
$challengeId = (string)$challenge['challenge_id'];

$claimed = $link->claimTelegramToken($token, $telegramSubject);
$assertSame('claimed', $claimed['status'], 'Telegram must claim MySQL challenge.');
$assertSame($targetMgw, $claimed['target_mgw_id'], 'Claim must resolve the existing Telegram MGW account.');

$confirmed = $link->confirmTelegramChallenge($challengeId, $telegramSubject);
$assertSame('confirmed', $confirmed['status'], 'Telegram must explicitly confirm MySQL challenge.');

$targetBefore = $ledger->getBalance($targetOwnership['account_ref'], 'mgw_coin');
$sourceBefore = $ledger->getBalance($sourceOwnership['account_ref'], 'mgw_coin');
$assertSame(5000, (int)$targetBefore['available_amount'], 'Target balance must start at 5000.');
$assertSame(1000, (int)$sourceBefore['available_amount'], 'Temporary Android balance must start at 1000.');

$linked = $link->finalizeForAndroid($challengeId, $androidUser);
$assertSame('linked', $linked['status'], 'MySQL finalize must complete.');
$assertSame($targetMgw, $linked['target']['mgw_id'], 'Existing Telegram MGW account must remain canonical.');
$assertSame($telegramSubject, $linked['runtime_user_id'], 'Android runtime owner must rotate to the Telegram legacy owner.');

$androidAfter = $androidAuth->authenticateCookie($androidSessionToken);
$assert(is_array($androidAfter), 'Original Android session token must remain valid after MySQL link.');
$assertSame($targetMgw, $androidAfter['mgw_id'], 'Android session must now resolve the target MGW account.');
$assertSame($telegramSubject, $androidAfter['id'], 'Android session must now resolve the target runtime user id.');
$assertSame('android_device', $androidAfter['mgw_identity_provider'], 'Android session provider metadata must remain android_device.');

$androidIdentity = $accounts->findByIdentity('android_device', hash('sha256', $credentialRaw));
$assertSame($targetMgw, $androidIdentity['mgw_id'] ?? null, 'Android identity must move to target MGW on MySQL.');
$assertSame(
    2,
    (int)$database->fetchValue(
        'SELECT COUNT(*) FROM mgw_identities WHERE mgw_id=:mgw_id',
        ['mgw_id'=>$targetMgw]
    ),
    'Target MGW must own Telegram + Android identities.'
);

$targetAfter = $ledger->getBalance($targetOwnership['account_ref'], 'mgw_coin');
$sourceAfter = $ledger->getBalance($sourceOwnership['account_ref'], 'mgw_coin');
$assertSame(5000, (int)$targetAfter['available_amount'], 'Telegram target balance must remain unchanged.');
$assertSame(0, (int)$sourceAfter['available_amount'], 'Temporary Android starter balance must retire to zero.');

$assertSame(
    'linked_retired',
    (string)$database->fetchValue(
        'SELECT status FROM mgw_users WHERE mgw_id=:mgw_id',
        ['mgw_id'=>$sourceMgw]
    ),
    'Temporary Android MGW shell must be retained only as linked_retired audit history.'
);
$assertSame(
    0,
    (int)$database->fetchValue(
        'SELECT COUNT(*) FROM mgw_account_ownership WHERE mgw_id=:mgw_id',
        ['mgw_id'=>$sourceMgw]
    ),
    'Temporary Android active ownership locator must be removed.'
);
$assertSame(
    1000,
    (int)$database->fetchValue(
        'SELECT retired_balance_amount FROM mgw_account_link_challenges WHERE challenge_id=:challenge_id',
        ['challenge_id'=>$challengeId]
    ),
    'Challenge audit must record the retired starter balance.'
);
$assertSame(
    1,
    (int)$database->fetchValue(
        "SELECT COUNT(*) FROM mgw_ledger_entries
         WHERE mgw_id=:mgw_id AND category='account_link_retirement'",
        ['mgw_id'=>$sourceMgw]
    ),
    'MySQL flow must append exactly one account-link retirement ledger entry.'
);

$jsonAfter = $storage->snapshot();
$assert(!isset($jsonAfter['users'][$sourceLegacy]), 'Temporary Android JSON runtime user must be retired.');
$assertSame(
    5000,
    (int)$jsonAfter['users'][$telegramSubject]['balance'],
    'Link finalization must hydrate a stale target runtime balance from the canonical target ledger before reload.'
);

$linkedAgain = $link->finalizeForAndroid($challengeId, $androidAfter);
$assertSame('linked', $linkedAgain['status'], 'MySQL finalize retry must be idempotent.');
$assertSame(
    1,
    (int)$database->fetchValue(
        "SELECT COUNT(*) FROM mgw_ledger_entries
         WHERE mgw_id=:mgw_id AND category='account_link_retirement'",
        ['mgw_id'=>$sourceMgw]
    ),
    'Idempotent MySQL finalize must not retire coins twice.'
);

fwrite(STDOUT, "Mvp26_3_3AccountLinkMysqlFullFlowTest: {$assertions} assertions passed\n");
