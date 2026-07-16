<?php
declare(strict_types=1);

$databaseDir = dirname(__DIR__) . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require $databaseDir . '/MigrationRepository.php';
require $databaseDir . '/MigrationRunner.php';
require dirname(__DIR__) . '/accounts/MgwIdGenerator.php';
require dirname(__DIR__) . '/accounts/AccountIdentityService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('AccountIdentityServiceTest requires pdo_sqlite.');
}

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
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($contains))) return;
        throw new RuntimeException($message . ': unexpected error ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no error was thrown');
};

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$migration = $runner->migrate(false);
$assertSame(2, $migration['executed_count'], 'Account test schema must include both migrations');

$accounts = new AccountIdentityService($database, 3600);
$telegram = [
    'id' => '123456789',
    'first_name' => 'Илья',
    'username' => 'first_name',
    'photo_url' => 'https://example.test/avatar-1.jpg',
];

$first = $accounts->resolveTelegramUser($telegram, 'session-alpha');
$assertTrue(MgwIdGenerator::isValid($first['mgw_id']), 'Telegram login must receive a valid internal MGW ID');
$assertSame(true, $first['created'], 'First provider login must create the MGW account');
$assertSame('telegram', $first['provider'], 'Real Telegram user must use the Telegram provider');
$assertSame(true, $first['session_registered'], 'Non-empty client session must be registered');

$telegram['username'] = 'renamed_user';
$second = $accounts->resolveTelegramUser($telegram, 'session-beta');
$assertSame($first['mgw_id'], $second['mgw_id'], 'Repeated Telegram login must resolve the same MGW ID');
$assertSame(false, $second['created'], 'Repeated provider login must not create a second account');

$identity = $accounts->findByIdentity('telegram', '123456789');
$assertSame($first['mgw_id'], $identity['mgw_id'] ?? null, 'Identity lookup must preserve the legacy Telegram mapping');
$assertSame('renamed_user', $identity['provider_username'] ?? null, 'Provider metadata must refresh without remapping the account');
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_users'), 'Repeated login must keep one user row');
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_identities'), 'Repeated login must keep one identity row');
$assertSame(2, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_devices'), 'Different client sessions must register separate device records');
$assertSame(2, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_sessions'), 'Different client sessions must belong to the same account');
$assertSame('telegram', (string)$database->fetchValue('SELECT avatar_provider FROM mgw_users WHERE mgw_id = :mgw_id', ['mgw_id' => $first['mgw_id']]), 'Signed Telegram avatar metadata must retain its provider');

$storedSessionHashes = array_column($database->fetchAll('SELECT session_key_hash FROM mgw_sessions'), 'session_key_hash');
$assertTrue(!in_array('session-alpha', $storedSessionHashes, true), 'Raw session IDs must never be stored');
$assertTrue(in_array(hash('sha256', 'session|session-alpha'), $storedSessionHashes, true), 'Session ownership must use a one-way hash');

$assertThrows(
    static fn() => $accounts->resolveTelegramUser([
        'id' => '987654321',
        'first_name' => 'Другой игрок',
        'username' => 'other_user',
    ], 'session-alpha'),
    'session ownership',
    'One client session must not be transferable to another MGW account'
);
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_users'), 'Rejected session takeover must roll back the new account');
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_identities'), 'Rejected session takeover must roll back the new identity');

$other = $accounts->resolveTelegramUser([
    'id' => '987654321',
    'first_name' => 'Другой игрок',
    'username' => 'other_user',
], 'session-gamma');
$assertTrue($other['mgw_id'] !== $first['mgw_id'], 'Different Telegram subjects must receive different MGW IDs');
$assertSame(2, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_users'), 'Second Telegram subject must create exactly one additional account');

$development = $accounts->resolveTelegramUser([
    'id' => '123456789',
    'first_name' => 'Локальный тест',
    'username' => 'dev_user',
    'is_dev_user' => true,
], 'session-dev');
$assertTrue($development['mgw_id'] !== $first['mgw_id'], 'Different identity providers must never merge by matching subject text');
$assertSame('development', $development['provider'], 'Browser development users must remain isolated from Telegram identities');

fwrite(STDOUT, "AccountIdentityServiceTest: {$assertions} assertions passed\n");
