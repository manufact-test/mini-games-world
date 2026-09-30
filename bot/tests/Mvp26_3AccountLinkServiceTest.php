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
require $root . '/services/PresenceService.php';
require $root . '/accounts/AccountLinkService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Mvp26_3AccountLinkServiceTest requires pdo_sqlite.');
}

final class Mvp26AccountLinkMemoryStorage implements StorageAdapterInterface
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

    public function replace(array $data): void
    {
        $this->data = $data;
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
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertThrowsReason = static function (
    callable $callback,
    string $reason,
    string $message
) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (AccountLinkException $error) {
        if ($error->reason === $reason) return;
        throw new RuntimeException($message . ': unexpected reason ' . $error->reason);
    }
    throw new RuntimeException($message . ': no AccountLinkException was thrown');
};

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$migration = $runner->migrate(false);
$assert($migration['executed_count'] > 0, 'Account-link test must apply the full current schema.');
$assertSame(
    'mgw_account_link_challenges',
    (string)$database->fetchValue(
        "SELECT name FROM sqlite_master WHERE type='table' AND name='mgw_account_link_challenges'"
    ),
    'Account-link challenge table must exist.'
);

$config = [
    'environment'=>'staging',
    'storage_driver'=>'json',
    'data_dir'=>'/tmp/mgw-account-link-test-unused',
    'bot_token'=>'mvp26-link-test-secret-not-live',
    'staging_bot_username'=>'MiniGamesWorld_test_bot',
    'mgw_account_session_ttl_sec'=>3600,
    'database'=>[
        'enabled'=>true,
        'driver'=>'mysql',
        'host'=>'localhost',
        'port'=>3306,
        'name'=>'mgw_test',
        'user'=>'mgw_test',
        'password'=>'test-only',
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

$telegramSubject = '700100200';
$telegram = $accounts->resolveTelegramUser([
    'id'=>$telegramSubject,
    'first_name'=>'Царь Дворца',
    'username'=>'palace_king',
], 'telegram-link-test-session');
$telegramMgw = (string)$telegram['mgw_id'];
$telegramOwnership = $ownerships->ensure('telegram', $telegramSubject, $telegramMgw);
$ledger->postAvailableDelta([
    'operation_key'=>'mvp26-link-target-seed',
    'account_ref'=>$telegramOwnership['account_ref'],
    'mgw_id'=>$telegramMgw,
    'legacy_user_id'=>$telegramSubject,
    'asset_code'=>'mgw_coin',
    'available_delta'=>5000,
    'category'=>'test_seed',
    'source_type'=>'test',
    'source_ref'=>'mvp26-link-target',
]);

$credentialRaw = str_repeat("\x45", 32);
$credential = rtrim(strtr(base64_encode($credentialRaw), '+/', '-_'), '=');
$androidAuth = new AndroidDeviceAuthService($config, $database);
$androidBootstrap = $androidAuth->bootstrapCredential($credential, '203.0.113.60');
$androidSession = (string)$androidBootstrap['session_token'];
$androidUser = $androidBootstrap['user'];
$sourceMgw = (string)$androidUser['mgw_id'];
$sourceLegacy = (string)$androidUser['id'];
$sourceOwnership = $ownerships->findByMgwId($sourceMgw);
$assert(is_array($sourceOwnership), 'Fresh Android account must own one runtime account row.');

$ledger->postAvailableDelta([
    'operation_key'=>'mvp26-link-source-starter-seed',
    'account_ref'=>$sourceOwnership['account_ref'],
    'mgw_id'=>$sourceMgw,
    'legacy_user_id'=>$sourceLegacy,
    'asset_code'=>'mgw_coin',
    'available_delta'=>1000,
    'category'=>'welcome_bonus',
    'source_type'=>'weekly_match',
    'source_ref'=>'mvp26-link-source',
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

$storage = new Mvp26AccountLinkMemoryStorage([
    'users'=>[
        $telegramSubject=>[
            'id'=>$telegramSubject,
            'telegram_id'=>$telegramSubject,
            'mgw_id'=>$telegramMgw,
            'mgw_account_ref'=>$telegramOwnership['account_ref'],
            'mgw_identity_provider'=>'telegram',
            'first_name'=>'Царь Дворца',
            'username'=>'',
            'balance'=>5000,
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
            'gold_deposited_total'=>0,
            'gold_wagered_total'=>0,
            'gold_shop_spent_total'=>0,
            'stats'=>$zeroStats,
        ],
    ],
    'transactions'=>[
        [
            'id'=>'tx_android_welcome',
            'category'=>'welcome_bonus',
            'user_id'=>$sourceLegacy,
            'mgw_id'=>$sourceMgw,
            'account_ref'=>$sourceOwnership['account_ref'],
            'amount'=>1000,
        ],
        [
            'id'=>'tx_target_existing',
            'category'=>'test_target_history',
            'user_id'=>$telegramSubject,
            'mgw_id'=>$telegramMgw,
            'account_ref'=>$telegramOwnership['account_ref'],
            'amount'=>5000,
        ],
    ],
    'notifications'=>[
        [
            'id'=>'note_android_welcome',
            'user_id'=>$sourceLegacy,
            'mgw_id'=>$sourceMgw,
            'type'=>'welcome_bonus',
        ],
    ],
]);

$presence = new PresenceService(
    sys_get_temp_dir() . '/mgw_mvp26_link_presence_' . bin2hex(random_bytes(6))
);
$presence->touch($telegramSubject, 'telegram-link-presence-session', 'telegram-page');
$presence->touch($sourceLegacy, 'android-link-presence-session', 'android-page');
$assertSame(
    2,
    count($presence->onlineAccountIds()),
    'Before linking, separate Telegram and temporary Android owners are two online accounts.'
);

$link = new AccountLinkService($config, $database, $storage, $presence);
$challenge = $link->createChallenge($androidUser);
$assertSame('pending', $challenge['status'], 'Android must create one pending link challenge.');
$assert(
    preg_match('/\?start=link_([A-Za-z0-9_-]{32})$/', (string)$challenge['telegram_url'], $matches) === 1,
    'Link challenge must produce one bounded Telegram deep link.'
);
$token = (string)$matches[1];
$challengeId = (string)$challenge['challenge_id'];
$assertSame(
    0,
    (int)$database->fetchValue(
        'SELECT COUNT(*) FROM mgw_account_link_challenges WHERE token_sha256=:raw',
        ['raw'=>$token]
    ),
    'Raw link token must never be stored.'
);
$assertSame(
    1,
    (int)$database->fetchValue(
        'SELECT COUNT(*) FROM mgw_account_link_challenges WHERE token_sha256=:hash',
        ['hash'=>hash('sha256', $token)]
    ),
    'Only the one-way link-token hash must be stored.'
);

$assertThrowsReason(
    static fn() => $link->claimTelegramToken($token, '999999999'),
    'telegram_account_missing',
    'Unknown Telegram identity must not be able to claim a link.'
);

$claimed = $link->claimTelegramToken($token, $telegramSubject);
$assertSame('claimed', $claimed['status'], 'Known Telegram identity must claim the link.');
$assertSame($telegramMgw, $claimed['target_mgw_id'], 'Claim must target the existing Telegram MGW account.');

$assertThrowsReason(
    static fn() => $link->confirmTelegramChallenge($challengeId, '888888888'),
    'confirmation_owner_mismatch',
    'A different Telegram identity must not confirm the claimed link.'
);
$confirmed = $link->confirmTelegramChallenge($challengeId, $telegramSubject);
$assertSame('confirmed', $confirmed['status'], 'Owning Telegram identity must explicitly confirm the link.');

$preTargetBalance = $ledger->getBalance($telegramOwnership['account_ref'], 'mgw_coin');
$assertSame(5000, (int)$preTargetBalance['available_amount'], 'Target balance must be 5000 before linking.');

$linked = $link->finalizeForAndroid($challengeId, $androidUser);
$assertSame('linked', $linked['status'], 'Android must finalize only after Telegram confirmation.');
$assertSame($telegramMgw, $linked['target']['mgw_id'], 'Link must keep the existing Telegram MGW account as canonical owner.');
$assertSame($telegramSubject, $linked['runtime_user_id'], 'Android runtime must rotate onto the existing Telegram legacy owner.');

$androidAfter = $androidAuth->authenticateCookie($androidSession);
$assert(is_array($androidAfter), 'The existing Android HttpOnly session must survive the account link.');
$assertSame($telegramMgw, $androidAfter['mgw_id'], 'Existing Android session must now resolve the Telegram MGW account.');
$assertSame($telegramSubject, $androidAfter['id'], 'Existing Android session must now use the target runtime user id.');
$assertSame('android_device', $androidAfter['mgw_identity_provider'], 'Provider metadata must remain Android after linking.');

$onlineAfterLink = $presence->onlineAccountIds();
sort($onlineAfterLink, SORT_STRING);
$assertSame(
    [$telegramSubject],
    $onlineAfterLink,
    'After linking, the retired temporary Android presence owner must disappear immediately.'
);

// A heartbeat authenticated immediately before the ownership switch may arrive
// after finalization. The retirement tombstone must reject that stale source
// lease instead of resurrecting a second online account for up to 75 seconds.
$presence->touch($sourceLegacy, 'android-link-presence-session', 'late-old-page');
$onlineAfterLateSourcePing = $presence->onlineAccountIds();
sort($onlineAfterLateSourcePing, SORT_STRING);
$assertSame(
    [$telegramSubject],
    $onlineAfterLateSourcePing,
    'A late heartbeat from the retired source owner must not recreate duplicate online presence.'
);

$androidIdentity = $accounts->findByIdentity('android_device', hash('sha256', $credentialRaw));
$assertSame($telegramMgw, $androidIdentity['mgw_id'] ?? null, 'Android identity row must move to the existing Telegram MGW account.');
$assertSame(
    2,
    (int)$database->fetchValue(
        'SELECT COUNT(*) FROM mgw_identities WHERE mgw_id=:mgw_id',
        ['mgw_id'=>$telegramMgw]
    ),
    'Linked MGW account must own exactly Telegram + Android provider identities.'
);
$assertSame(
    'linked_retired',
    (string)$database->fetchValue(
        'SELECT status FROM mgw_users WHERE mgw_id=:mgw_id',
        ['mgw_id'=>$sourceMgw]
    ),
    'Temporary Android MGW shell must become an inactive audit record.'
);
$assertSame(
    0,
    (int)$database->fetchValue(
        'SELECT COUNT(*) FROM mgw_account_ownership WHERE mgw_id=:mgw_id',
        ['mgw_id'=>$sourceMgw]
    ),
    'Temporary Android ownership row must be retired after link completion.'
);
$assertSame(
    $telegramSubject,
    (string)$database->fetchValue(
        'SELECT legacy_user_id FROM mgw_account_ownership WHERE mgw_id=:mgw_id',
        ['mgw_id'=>$telegramMgw]
    ),
    'Target runtime ownership must remain unchanged.'
);

$postTargetBalance = $ledger->getBalance($telegramOwnership['account_ref'], 'mgw_coin');
$postSourceBalance = $ledger->getBalance($sourceOwnership['account_ref'], 'mgw_coin');
$assertSame(5000, (int)$postTargetBalance['available_amount'], 'Target Telegram balance must not receive temporary Android starter coins.');
$assertSame(0, (int)$postSourceBalance['available_amount'], 'Temporary Android starter balance must be retired to zero.');
$assertSame(
    1,
    (int)$database->fetchValue(
        "SELECT COUNT(*) FROM mgw_ledger_entries
         WHERE mgw_id=:mgw_id AND category='account_link_retirement'",
        ['mgw_id'=>$sourceMgw]
    ),
    'Starter retirement must be one append-only ledger entry.'
);

$jsonAfter = $storage->snapshot();
$assert(!isset($jsonAfter['users'][$sourceLegacy]), 'Temporary Android runtime user must be removed from JSON after linking.');
$assertSame(5000, (int)$jsonAfter['users'][$telegramSubject]['balance'], 'Existing Telegram JSON balance must remain unchanged.');
foreach ($jsonAfter['transactions'] as $transaction) {
    $assert(
        (string)($transaction['user_id'] ?? '') !== $sourceLegacy,
        'Temporary Android welcome transaction must be removed from active JSON runtime.'
    );
}
foreach ($jsonAfter['notifications'] as $notification) {
    $assert(
        (string)($notification['user_id'] ?? '') !== $sourceLegacy,
        'Temporary Android welcome notification must be removed from active JSON runtime.'
    );
}

$linkedAgain = $link->finalizeForAndroid($challengeId, $androidAfter);
$assertSame('linked', $linkedAgain['status'], 'Repeated Android finalize must be idempotent.');
$assertSame(
    1,
    (int)$database->fetchValue(
        "SELECT COUNT(*) FROM mgw_ledger_entries
         WHERE mgw_id=:mgw_id AND category='account_link_retirement'",
        ['mgw_id'=>$sourceMgw]
    ),
    'Idempotent finalize must not burn starter coins twice.'
);
$alreadyLinked = $link->createChallenge($androidAfter);
$assertSame('linked', $alreadyLinked['status'], 'Already-linked Android identity must not create another challenge.');
$assertSame(true, $alreadyLinked['already_linked'], 'Already-linked response must be explicit.');

// Fail-closed case: a used temporary Android profile cannot be silently merged.
$telegram2Subject = '700100201';
$telegram2 = $accounts->resolveTelegramUser([
    'id'=>$telegram2Subject,
    'first_name'=>'Target Two',
], 'telegram-link-test-session-2');
$telegram2Mgw = (string)$telegram2['mgw_id'];
$ownerships->ensure('telegram', $telegram2Subject, $telegram2Mgw);

$credential2Raw = str_repeat("\x46", 32);
$credential2 = rtrim(strtr(base64_encode($credential2Raw), '+/', '-_'), '=');
$android2 = $androidAuth->bootstrapCredential($credential2, '203.0.113.61');
$android2User = $android2['user'];
$source2Mgw = (string)$android2User['mgw_id'];
$source2Legacy = (string)$android2User['id'];
$source2Ownership = $ownerships->findByMgwId($source2Mgw);

$ledger->postAvailableDelta([
    'operation_key'=>'mvp26-link-source2-starter-seed',
    'account_ref'=>$source2Ownership['account_ref'],
    'mgw_id'=>$source2Mgw,
    'legacy_user_id'=>$source2Legacy,
    'asset_code'=>'mgw_coin',
    'available_delta'=>1000,
    'category'=>'welcome_bonus',
    'source_type'=>'weekly_match',
    'source_ref'=>'mvp26-link-source2',
]);

$storageData = $storage->snapshot();
$storageData['users'][$telegram2Subject] = [
    'id'=>$telegram2Subject,
    'mgw_id'=>$telegram2Mgw,
    'balance'=>0,
    'status'=>'idle',
    'current_game_id'=>null,
    'stats'=>$zeroStats,
];
$storageData['users'][$source2Legacy] = [
    'id'=>$source2Legacy,
    'mgw_id'=>$source2Mgw,
    'mgw_account_ref'=>$source2Ownership['account_ref'],
    'mgw_identity_provider'=>'android_device',
    'balance'=>900,
    'status'=>'idle',
    'current_game_id'=>null,
    'weekly_match_welcome_grant_done'=>true,
    'gold_deposited_total'=>0,
    'gold_wagered_total'=>0,
    'gold_shop_spent_total'=>0,
    'stats'=>$zeroStats,
];
$storageData['transactions'][] = [
    'id'=>'tx_android2_welcome',
    'category'=>'welcome_bonus',
    'user_id'=>$source2Legacy,
    'mgw_id'=>$source2Mgw,
    'account_ref'=>$source2Ownership['account_ref'],
    'amount'=>1000,
];
$storage->replace($storageData);

$challenge2 = $link->createChallenge($android2User);
preg_match('/\?start=link_([A-Za-z0-9_-]{32})$/', (string)$challenge2['telegram_url'], $matches2);
$token2 = (string)($matches2[1] ?? '');
$link->claimTelegramToken($token2, $telegram2Subject);
$link->confirmTelegramChallenge((string)$challenge2['challenge_id'], $telegram2Subject);

$assertThrowsReason(
    static fn() => $link->finalizeForAndroid((string)$challenge2['challenge_id'], $android2User),
    'source_not_pristine',
    'Changed temporary Android balance must stop automatic linking.'
);
$android2Identity = $accounts->findByIdentity('android_device', hash('sha256', $credential2Raw));
$assertSame($source2Mgw, $android2Identity['mgw_id'] ?? null, 'Rejected link must not move the Android identity.');
$assertSame(
    'confirmed',
    (string)$database->fetchValue(
        'SELECT link_status FROM mgw_account_link_challenges WHERE challenge_id=:challenge_id',
        ['challenge_id'=>$challenge2['challenge_id']]
    ),
    'Rejected pristine check must leave the challenge confirmed and retry-safe after explicit cleanup.'
);

fwrite(STDOUT, "Mvp26_3AccountLinkServiceTest: {$assertions} assertions passed\n");
