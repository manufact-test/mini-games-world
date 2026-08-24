<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$databaseDir = $root . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require $databaseDir . '/MigrationRepository.php';
require $databaseDir . '/MigrationRunner.php';
require $root . '/economy/EconomyConfigSimulator.php';
require $root . '/economy/EconomyConfigDefinition.php';
require $root . '/economy/EconomyConfigService.php';
require $root . '/helpers/AdminWebAuth.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Mvp15EconomyRemoteConfigTest requires pdo_sqlite.');
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
$assertThrows = static function (callable $callback, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message . ': expected an exception');
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$migrated = $runner->migrate(false);
$expectedMigrations = count(glob($databaseDir . '/migrations/*.php') ?: []);
$assertSame($expectedMigrations, $migrated['executed_count'], 'Focused test must apply every current canonical migration');
$assertSame(0, $runner->migrate(false)['executed_count'], 'Migration rerun must be idempotent');

$service = new EconomyConfigService($database);
$current = $service->current();
$defaults = EconomyConfigDefinition::defaults();
$assertSame(1, $current['version'], 'Seed config must be version 1');
$assertSame('seed', $current['change_type'], 'Version 1 must be the seed event');
$assertSame($defaults, $current['config'], 'Seed config must equal canonical roadmap defaults');
$assertSame(100, $current['config']['match']['entry_cost'], 'Match entry default must be 100');
$assertSame(180, $current['config']['match']['winner_reward'], 'Winner reward default must be 180');
$assertSame(20, $current['config']['match']['system_sink'], 'System sink default must be 20');
$assertSame(100, $current['config']['match']['draw_refund'], 'Draw refund default must be 100');
$assertSame(1000, $current['config']['bonuses']['starter'], 'Starter bonus default must be 1000');
$assertSame(500, $current['config']['bonuses']['weekly'], 'Weekly bonus default must be 500');
$assertSame(3, $current['config']['bonuses']['weekly_match_threshold'], 'Weekly threshold default must be 3');
$assertSame(50, $current['config']['bonuses']['first_game'], 'First-game bonus default must be 50');
$assertSame(25, $current['config']['rewarded_ads']['reward'], 'Rewarded-ad reward default must be 25');
$assertSame(12, $current['config']['rewarded_ads']['daily_limit'], 'Rewarded-ad daily limit must be 12');
$assertSame(60, $current['config']['rewarded_ads']['cooldown_seconds'], 'Rewarded-ad cooldown default must be 60 seconds');
$assertSame(300, $current['config']['rewarded_ads']['daily_coin_cap'], 'Rewarded-ad daily cap default must be 300');
$assertSame([5000,10500,27500,57500,120000], array_column($current['config']['coin_packages'], 'coins'), 'Coin package amounts must match roadmap defaults');
$assertSame([499,999,2499,4999,9999], array_column($current['config']['coin_packages'], 'price_eur_cents'), 'Coin package EUR prices must match roadmap defaults');
$assertSame(true, $current['simulation']['normal_match']['balanced'], 'Normal match source/sink simulation must balance');
$assertSame(true, $current['simulation']['draw']['balanced'], 'Draw source/sink simulation must balance');
$assertSame(300, $current['simulation']['rewarded_ads']['effective_daily_source'], 'Rewarded-ad simulation must cap at 300 coins');
$assertSame(400, $current['simulation']['first_game_bonus']['all_games_source'], 'Eight first-game bonuses must total 400');
$assertSame(64, strlen($current['config_sha256']), 'Current config must expose a SHA-256 fingerprint');

$beforeVersionRows = (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_economy_config_versions');
$updatedConfig = $defaults;
$updatedConfig['bonuses']['weekly'] = 600;
$updated = $service->update($updatedConfig, 'telegram:972585905', 'Focused test weekly bonus change');
$assertSame(2, $updated['version'], 'Update must append version 2');
$assertSame(600, $updated['config']['bonuses']['weekly'], 'Version 2 must contain updated value');
$assertSame($beforeVersionRows + 1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_economy_config_versions'), 'Update must append exactly one audit version');

$history = $service->history(20);
$assertSame(2, count($history), 'History must contain seed and update');
$assertSame(2, $history[0]['version'], 'Newest history item must be version 2');
$assertSame('update', $history[0]['change_type'], 'Version 2 must be an update audit event');
$assertSame('telegram:972585905', $history[0]['actor_ref'], 'Audit must retain author');
$assertSame('Focused test weekly bonus change', $history[0]['reason'], 'Audit must retain reason');
$assertSame(500, $history[0]['before']['bonuses']['weekly'], 'Audit must expose before config');
$assertSame(600, $history[0]['after']['bonuses']['weekly'], 'Audit must expose after config');

$rolledBack = $service->rollback(1, 'telegram:972585905', 'Focused test rollback to roadmap defaults');
$assertSame(3, $rolledBack['version'], 'Rollback must create a new version 3');
$assertSame('rollback', $rolledBack['change_type'], 'Rollback must be an explicit audit event');
$assertSame(1, $rolledBack['source_version'], 'Rollback must record its source version');
$assertSame(500, $rolledBack['config']['bonuses']['weekly'], 'Rollback version must restore target config');
$assertSame(3, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_economy_config_versions'), 'Rollback must preserve prior versions and append one new row');
$assertSame(600, $service->history(20)[1]['config']['bonuses']['weekly'], 'Historical version 2 must remain unchanged after rollback');

$invalidUnknown = $defaults;
$invalidUnknown['hidden_client_owner'] = 1;
$assertThrows(static fn() => EconomyConfigDefinition::normalize($invalidUnknown), 'Unknown economy config fields must fail closed');
$invalidSettlement = $defaults;
$invalidSettlement['match']['entry_cost'] = 101;
$assertThrows(static fn() => EconomyConfigDefinition::normalize($invalidSettlement), 'Unbalanced match source/sink values must fail deterministic simulation');
$assertThrows(static fn() => $service->update($service->current()['config'], 'telegram:972585905', 'No-op config'), 'Identical config must not create a fake audit version');
$assertThrows(static fn() => $service->rollback(3, 'telegram:972585905', 'No-op rollback'), 'Rollback to current version must fail closed');

$now = 1786796000;
$fresh = 'query_id=x&auth_date=' . ($now - AdminWebAuth::MAX_AGE_SECONDS) . '&hash=x';
$stale = 'query_id=x&auth_date=' . ($now - AdminWebAuth::MAX_AGE_SECONDS - 1) . '&hash=x';
$assertSame(true, AdminWebAuth::initDataIsFresh($fresh, $now), 'Accepted 15-minute admin freshness window must be preserved');
$assertSame(false, AdminWebAuth::initDataIsFresh($stale, $now), 'Admin initData older than 15 minutes must fail');

$serviceSource = file_get_contents($root . '/economy/EconomyConfigService.php') ?: '';
$assertSame(false, str_contains($serviceSource, 'mgw_users'), 'Economy config service must not write user records');
$assertSame(false, str_contains($serviceSource, 'ledger'), 'Economy config service must not become a ledger writer');
$endpointSource = file_get_contents($root . '/admin-economy.php') ?: '';
$assertTrue(str_contains($endpointSource, "['snapshot', 'update', 'rollback']"), 'Economy admin endpoint must expose only scoped config actions');
$assertTrue(str_contains($endpointSource, 'AdminWebAuth::authorize'), 'Economy admin endpoint must reuse canonical Web Admin auth');
$entrypointsSource = file_get_contents($root . '/runtime/ProductionPrimaryApplicationEntrypoints.php') ?: '';
$assertTrue(str_contains($entrypointsSource, 'bot/admin-economy.php'), 'Economy admin endpoint must map to accepted API DB-primary context');

fwrite(STDOUT, "Mvp15EconomyRemoteConfigTest: {$assertions} assertions passed\n");
