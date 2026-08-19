<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/database/DatabaseConnectionInterface.php';
require $projectRoot . '/bot/database/PdoDatabaseConnection.php';
require $projectRoot . '/bot/database/DatabaseMigrationInterface.php';
require $projectRoot . '/bot/database/MigrationRepository.php';
require $projectRoot . '/bot/database/MigrationRunner.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('RuntimeMatchEventMigrationContractTest requires PDO SQLite.');
}

$database = new PdoDatabaseConnection(new PDO('sqlite::memory:'));
$runner = new MigrationRunner($database, $projectRoot . '/bot/database/migrations');
$status = $runner->status();

if (($status['ok'] ?? false) !== true) {
    throw new RuntimeException('Migration runner status must succeed.');
}

$expected = '20260819_0010_create_match_event_log';
$versions = [];
foreach ((array)($status['pending'] ?? []) as $migration) {
    if (!is_array($migration)) continue;
    $versions[] = (string)($migration['version'] ?? '');
}

if (!in_array($expected, $versions, true)) {
    throw new RuntimeException('Event-log migration must be discoverable by its exact filename version.');
}

fwrite(STDOUT, "RuntimeMatchEventMigrationContractTest passed.\n");
