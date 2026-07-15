<?php
declare(strict_types=1);

function now_iso(): string
{
    return '2026-07-15T20:00:00+00:00';
}

function clean_string(mixed $value, int $max): string
{
    return mb_substr(trim((string)$value), 0, $max);
}

function make_id(string $prefix): string
{
    static $counter = 0;
    $counter++;
    return $prefix . '_' . $counter;
}

require dirname(__DIR__) . '/services/UserService.php';
require dirname(__DIR__) . '/services/WeeklyMatchEconomyService.php';

$config = [
    'initial_match_coins' => 50,
    'initial_gold_coins' => 0,
    'weekly_match_bonus_amount' => 50,
    'weekly_match_min_completed' => 3,
    'weekly_match_timezone' => 'Europe/Moscow',
    'weekly_match_start_at' => '2026-07-13 12:00:00',
    'shop_min_order' => 1000,
    'admin_ids' => [],
];

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
};

$db = [
    'users' => [],
    'transactions' => [],
];

$users = new UserService($config);
$weekly = new WeeklyMatchEconomyService($config, null);

$real = $users->ensureUser($db, [
    'id' => 1001,
    'first_name' => 'Real',
    'username' => 'real_user',
]);
$assertSame(0, $real['balance_match'], 'Real user must not receive an implicit initial Match balance');

$db['users']['1001'] = $real;
$real =& $db['users']['1001'];
$welcome = $weekly->ensureWelcomeGrant($db, $real);
$assertSame(true, $welcome['awarded'], 'Real user welcome grant must be awarded');
$assertSame(50, $real['balance_match'], 'Real user final welcome balance must be exactly 50');
$assertSame(1, count($db['transactions']), 'Welcome grant must create one transaction');
$assertSame('welcome_bonus', $db['transactions'][0]['category'], 'Welcome transaction category must be explicit');

$secondWelcome = $weekly->ensureWelcomeGrant($db, $real);
$assertSame(false, $secondWelcome['awarded'], 'Welcome grant must not be awarded twice');
$assertSame(50, $real['balance_match'], 'Second check must not change the balance');
unset($real);

$dev = $users->ensureUser($db, [
    'id' => 2001,
    'first_name' => 'Dev',
    'username' => 'dev_user',
    'is_dev_user' => true,
]);
$assertSame(50, $dev['balance_match'], 'Browser dev user keeps configured test balance');

$db['users']['2001'] = $dev;
$dev =& $db['users']['2001'];
$devWelcome = $weekly->ensureWelcomeGrant($db, $dev);
$assertSame(false, $devWelcome['awarded'], 'Development user must not receive the real welcome grant');
$assertSame(50, $dev['balance_match'], 'Development user balance must remain unchanged');

fwrite(STDOUT, "WelcomeBonusInitializationTest: {$assertions} assertions passed\n");
