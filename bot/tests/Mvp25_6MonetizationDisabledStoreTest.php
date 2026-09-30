<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$dbDir = $root . '/bot/database';

require_once $dbDir . '/DatabaseConnectionInterface.php';
require_once $dbDir . '/DatabaseExceptionClassifier.php';
require_once $dbDir . '/PdoDatabaseConnection.php';
require_once $dbDir . '/DatabaseMigrationInterface.php';
require_once $dbDir . '/MigrationRepository.php';
require_once $dbDir . '/MigrationRunner.php';
require_once $root . '/bot/accounts/MgwIdGenerator.php';
require_once $root . '/bot/accounts/MgwIdentityPolicy.php';
require_once $root . '/bot/accounts/AccountIdentityService.php';
require_once $root . '/bot/economy/UnifiedBalanceRuntimeState.php';
require_once $root . '/bot/catalog/ProductInventoryService.php';
require_once $root . '/bot/catalog/CosmeticStoreService.php';

if (!function_exists('now_iso')) {
    function now_iso(): string { return gmdate('c'); }
}
if (!function_exists('make_id')) {
    function make_id(string $prefix = 'id'): string {
        static $sequence = 0;
        $sequence++;
        return $prefix . '_mvp25_6_' . $sequence;
    }
}

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDOUT, "SKIP: pdo_sqlite is unavailable\n");
    exit(0);
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$db = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($db, $dbDir . '/migrations');
$runner->migrate(false);

$account = (new AccountIdentityService($db, 3600))->resolveProviderIdentity(
    'development',
    'mvp25-6-user',
    'browser_dev',
    ['username' => 'mvp25-6-store'],
    'mvp25-6-session'
);
$mgwId = (string)$account['mgw_id'];

$packages = [
    ['id'=>'coins_5000','coins'=>5000,'price_eur_cents'=>499,'enabled'=>true],
    ['id'=>'coins_10500','coins'=>10500,'price_eur_cents'=>999,'enabled'=>true],
];

$snapshot = (new CosmeticStoreService($db))->snapshot($mgwId, 1000, $packages);

$tabs = [];
foreach (($snapshot['tabs'] ?? []) as $tab) {
    if (!is_array($tab)) continue;
    $tabs[(string)($tab['id'] ?? '')] = $tab;
}

$assertSame(false, $tabs['coins']['available'] ?? null, 'Coin top-up tab must be unavailable while external billing is disabled.');
$assertSame(true, $tabs['profile']['available'] ?? null, 'Profile cosmetics tab must remain available.');
$assertSame(true, $tabs['games']['available'] ?? null, 'Game cosmetics tab must remain available.');
$assertSame(true, $tabs['bundles']['available'] ?? null, 'Bundle cosmetics tab must remain available.');
$assertSame(false, $snapshot['coins']['billing_available'] ?? null, 'Store snapshot must explicitly publish disabled billing.');
$assertSame([], $snapshot['coins']['packages'] ?? null, 'Disabled billing must not expose provider coin-package catalogue to the player.');
$assertSame('mgw_coin', $snapshot['currency'] ?? null, 'Internal cosmetic economy currency must remain unchanged.');
$assertSame(1000, $snapshot['balance'] ?? null, 'Player coin balance must remain visible in the completed Store.');
$assertSame(9, count($snapshot['profile']['avatars'] ?? []), 'Paid avatar catalogue must remain available for in-game coin purchases.');
$assertSame(true, $snapshot['games']['available'] ?? false, 'Game cosmetics catalogue must remain available.');

fwrite(STDOUT, "Mvp25_6MonetizationDisabledStoreTest: {$assertions} assertions passed\n");
