<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/database/DatabaseConnectionInterface.php';
require_once $root . '/bot/database/DatabaseExceptionClassifier.php';
require_once $root . '/bot/database/PdoDatabaseConnection.php';
require_once $root . '/bot/database/DatabaseMigrationInterface.php';

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('C2.1 migration test requires pdo_sqlite.');

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$db = new PdoDatabaseConnection($pdo);

$db->execute('CREATE TABLE mgw_users (mgw_id TEXT PRIMARY KEY)');
$db->execute('CREATE TABLE mgw_product_catalog (
    item_id TEXT PRIMARY KEY,
    item_type TEXT NOT NULL,
    item_family TEXT NOT NULL,
    equip_slot TEXT NULL,
    metadata_json TEXT NULL,
    updated_at_utc TEXT NOT NULL
)');
$db->execute('CREATE TABLE mgw_inventory_items (
    mgw_id TEXT NOT NULL,
    item_id TEXT NOT NULL,
    PRIMARY KEY (mgw_id, item_id),
    FOREIGN KEY (mgw_id) REFERENCES mgw_users(mgw_id),
    FOREIGN KEY (item_id) REFERENCES mgw_product_catalog(item_id)
)');
$db->execute('CREATE TABLE mgw_equipped_items (
    mgw_id TEXT NOT NULL,
    equip_slot TEXT NOT NULL,
    item_id TEXT NOT NULL,
    equipped_at_utc TEXT NOT NULL,
    PRIMARY KEY (mgw_id, equip_slot),
    FOREIGN KEY (mgw_id, item_id) REFERENCES mgw_inventory_items(mgw_id, item_id)
)');

$db->execute("INSERT INTO mgw_users (mgw_id) VALUES ('MGW-TEST-C2-1')");
$effects = [
    ['game-ttt-effect-sign','game_tictactoe_effect_sign','Импульс знака','sign','sign'],
    ['game-ttt-effect-winning-line','game_tictactoe_effect_winning_line','Победный импульс','winning-line','winning_line'],
    ['game-ttt-effect-strike','game_tictactoe_effect_strike_through','Импульс хода','move-pulse','move_pulse'],
];
foreach ($effects as [$itemId,$slot,$name,$variant,$event]) {
    $metadata = json_encode([
        'display_name'=>$name,
        'layer'=>'effect',
        'variant'=>$variant,
        'event'=>$event,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $db->execute(
        'INSERT INTO mgw_product_catalog (item_id,item_type,item_family,equip_slot,metadata_json,updated_at_utc)
         VALUES (:item_id,\'game\',\'game_tictactoe\',:slot,:metadata,\'2026-08-31 10:00:00.000000\')',
        ['item_id'=>$itemId,'slot'=>$slot,'metadata'=>$metadata]
    );
    $db->execute(
        'INSERT INTO mgw_inventory_items (mgw_id,item_id) VALUES (\'MGW-TEST-C2-1\',:item_id)',
        ['item_id'=>$itemId]
    );
}

$db->execute("INSERT INTO mgw_equipped_items VALUES ('MGW-TEST-C2-1','game_tictactoe_effect_sign','game-ttt-effect-sign','2026-08-31 10:00:00.000000')");
$db->execute("INSERT INTO mgw_equipped_items VALUES ('MGW-TEST-C2-1','game_tictactoe_effect_winning_line','game-ttt-effect-winning-line','2026-08-31 11:00:00.000000')");
$db->execute("INSERT INTO mgw_equipped_items VALUES ('MGW-TEST-C2-1','game_tictactoe_effect_strike_through','game-ttt-effect-strike','2026-08-31 12:00:00.000000')");

$migration = require $root . '/bot/database/migrations/20260901_0018_tictactoe_single_effect_slot.php';
$migration->up($db);

$rows = $db->fetchAll("SELECT item_id,equip_slot,metadata_json FROM mgw_product_catalog ORDER BY item_id");
if (count($rows) !== 3) throw new RuntimeException('All three effect catalogue identities must remain.');
$metadataById = [];
foreach ($rows as $row) {
    if ((string)$row['equip_slot'] !== 'game_tictactoe_effect') {
        throw new RuntimeException('Every effect must migrate to game_tictactoe_effect.');
    }
    $metadataById[(string)$row['item_id']] = json_decode((string)$row['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
}
if (($metadataById['game-ttt-effect-sign']['variant'] ?? '') !== 'impact') throw new RuntimeException('Sign must migrate to impact.');
if (($metadataById['game-ttt-effect-winning-line']['display_name'] ?? '') !== 'Искры хода') throw new RuntimeException('Winning-line purchase must migrate to Искры хода.');
if (($metadataById['game-ttt-effect-winning-line']['variant'] ?? '') !== 'sparks') throw new RuntimeException('Winning-line purchase must migrate to sparks.');
if (($metadataById['game-ttt-effect-strike']['variant'] ?? '') !== 'wave') throw new RuntimeException('Strike purchase must migrate to wave.');
foreach ($metadataById as $metadata) {
    if (($metadata['event'] ?? '') !== 'move') throw new RuntimeException('Every migrated effect must be a move-time event.');
}

$equipped = $db->fetchAll("SELECT equip_slot,item_id,equipped_at_utc FROM mgw_equipped_items WHERE mgw_id = 'MGW-TEST-C2-1'");
if (count($equipped) !== 1) throw new RuntimeException('Legacy multi-selection must collapse to exactly one active effect.');
if ((string)$equipped[0]['equip_slot'] !== 'game_tictactoe_effect') throw new RuntimeException('Collapsed effect must use the common slot.');
if ((string)$equipped[0]['item_id'] !== 'game-ttt-effect-strike') throw new RuntimeException('Most recently equipped legacy effect must win migration.');
if ((string)$equipped[0]['equipped_at_utc'] !== '2026-08-31 12:00:00.000000') throw new RuntimeException('Migration must preserve the winning equipment timestamp.');

fwrite(STDOUT, "PASS: C2.1 legacy multi-effect migration collapses to the newest effect\n");
