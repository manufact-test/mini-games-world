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
    throw new RuntimeException('Mvp15ProviderNeutralIdentityCoreTest requires pdo_sqlite.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
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
$runner->migrate(false);
$accounts = new AccountIdentityService($database, 3600);

$google = $accounts->resolveProviderIdentity(
    'google',
    'google-subject-123',
    'android',
    [
        'display_name' => 'Google Player',
        'username' => 'gplayer',
        'avatar_ref' => 'https://example.test/google-avatar.jpg',
    ],
    'google-session-a'
);
$assert(MgwIdGenerator::isValid($google['mgw_id']), 'Generic provider must resolve to a valid MGW id.');
$assertSame('google', $google['provider'], 'Generic resolver must preserve the verified provider id.');
$assertSame(true, $google['created'], 'First generic provider identity must create one MGW account.');
$assertSame(true, $google['session_registered'], 'Generic provider session must use the shared session owner.');

$googleAgain = $accounts->resolveProviderIdentity(
    'GOOGLE',
    'google-subject-123',
    'android',
    [
        'display_name' => 'Renamed Google Player',
        'username' => 'renamed_gplayer',
        'avatar_ref' => null,
    ],
    'google-session-b'
);
$assertSame($google['mgw_id'], $googleAgain['mgw_id'], 'Repeated generic identity must resolve the same MGW account.');
$assertSame(false, $googleAgain['created'], 'Repeated generic identity must not create a duplicate account.');
$assertSame('renamed_gplayer', $accounts->findByIdentity('google', 'google-subject-123')['provider_username'] ?? null, 'Generic provider metadata must refresh in the shared identity table.');

$apple = $accounts->resolveProviderIdentity(
    'apple',
    'google-subject-123',
    'ios',
    ['display_name' => 'Apple Player'],
    'apple-session-a'
);
$assert($apple['mgw_id'] !== $google['mgw_id'], 'Matching subject text across different providers must not merge accounts implicitly.');

$telegram = $accounts->resolveTelegramUser([
    'id' => 'telegram-123',
    'first_name' => 'Telegram Player',
    'username' => 'tgplayer',
], 'telegram-session-a');
$assertSame('telegram', $telegram['provider'], 'Existing Telegram adapter must preserve current provider semantics.');
$assert(MgwIdGenerator::isValid($telegram['mgw_id']), 'Existing Telegram adapter must still resolve a valid MGW id.');

$platforms = array_column($database->fetchAll('SELECT platform FROM mgw_devices ORDER BY device_id ASC'), 'platform');
$assert(in_array('android', $platforms, true), 'Generic Android platform must flow through the shared device/session owner.');
$assert(in_array('ios', $platforms, true), 'Generic iOS platform must flow through the shared device/session owner.');
$assert(in_array('telegram_web', $platforms, true), 'Telegram adapter must retain telegram_web platform semantics.');

$assertThrows(
    static fn() => $accounts->resolveProviderIdentity('bad provider', 'subject', 'web', ['display_name' => 'X'], ''),
    'provider',
    'Provider ids must be bounded safe identifiers.'
);
$assertThrows(
    static fn() => $accounts->resolveProviderIdentity('google', '', 'android', ['display_name' => 'X'], ''),
    'subject',
    'Provider subjects must not be empty.'
);
$assertThrows(
    static fn() => $accounts->resolveProviderIdentity('google', 'subject', 'bad platform!', ['display_name' => 'X'], ''),
    'platform',
    'Platform ids must be bounded safe identifiers.'
);

$source = file_get_contents(dirname(__DIR__) . '/accounts/AccountIdentityService.php');
$assert(is_string($source) && str_contains($source, 'public function resolveProviderIdentity('), 'Provider-neutral core method must remain explicit.');
$assert(str_contains($source, '$this->resolveProviderIdentity('), 'Telegram must be an adapter over the provider-neutral core rather than a parallel account resolver.');

fwrite(STDOUT, "Mvp15ProviderNeutralIdentityCoreTest: {$assertions} assertions passed\n");
