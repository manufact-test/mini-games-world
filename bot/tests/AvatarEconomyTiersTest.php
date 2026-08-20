<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$inventory = file_get_contents($root . '/bot/catalog/ProductInventoryService.php');
$migration = file_get_contents($root . '/bot/database/migrations/20260820_0014_expand_avatar_economy_tiers.php');
$identity = file_get_contents($root . '/bot/accounts/MgwIdentityPolicy.php');

if ($inventory === false || $migration === false || $identity === false) {
    throw new RuntimeException('Avatar economy source files are unavailable.');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

preg_match('/STARTER_AVATAR_ITEM_IDS\s*=\s*\[(.*?)\];/s', $identity, $starterBlock);
$assert(isset($starterBlock[1]), 'Starter avatar constant is missing.');
preg_match_all("/'starter-default-(\\d{2})'/", $starterBlock[1], $starterMatches);
$assert(($starterMatches[1] ?? []) === ['01', '02', '03'], 'Exactly three free starter avatars must remain canonical.');

preg_match('/STORE_AVATAR_ITEM_IDS\s*=\s*\[(.*?)\];/s', $inventory, $paidBlock);
$assert(isset($paidBlock[1]), 'Paid avatar inventory constant is missing.');
preg_match_all("/'store-avatar-(\\d{2})'/", $paidBlock[1], $paidMatches);
$expectedPaidIds = array_map(static fn(int $number): string => sprintf('%02d', $number), range(1, 9));
$assert(($paidMatches[1] ?? []) === $expectedPaidIds, 'Paid avatar inventory must contain exactly store-avatar-01 through store-avatar-09.');

preg_match_all("/'store-avatar-(\\d{2})'\s*=>\s*\['rarity'\s*=>\s*'(rare|elite|legendary)',\s*'price'\s*=>\s*(250|300|400)\]/", $migration, $tierRows, PREG_SET_ORDER);
$assert(count($tierRows) === 9, 'Migration must define exactly nine paid avatar economy rows.');

$expected = [
    '01' => ['rare', '250'], '02' => ['rare', '250'], '03' => ['rare', '250'],
    '04' => ['elite', '300'], '05' => ['elite', '300'], '06' => ['elite', '300'],
    '07' => ['legendary', '400'], '08' => ['legendary', '400'], '09' => ['legendary', '400'],
];
foreach ($tierRows as $row) {
    $id = $row[1];
    $assert(isset($expected[$id]), 'Unexpected paid avatar id in economy migration: ' . $id);
    $assert([$row[2], $row[3]] === $expected[$id], 'Incorrect rarity/price for store-avatar-' . $id . '.');
    unset($expected[$id]);
}
$assert($expected === [], 'Some paid avatar rarity/price rows are missing.');

$assert(str_contains($migration, "json_encode(['rarity' => \$rarity]"), 'Avatar rarity must be persisted as catalog metadata.');
$assert(str_contains($migration, "WHERE offer_id = 'avatar-bundle-5'"), 'Superseded five-avatar bundle must be explicitly retired.');
$assert(str_contains($migration, "SET offer_status = 'retired'"), 'Superseded bundle retirement must preserve history instead of deleting it.');
$assert(!preg_match("/'avatar-bundle-(?!5)[^']*'/", $migration), 'No replacement avatar bundle may be invented without an approved price.');
$assert(!str_contains($migration, "'offer_type' => 'bundle'"), 'Avatar tier migration must not create a new bundle offer.');

foreach (['frame', 'background', 'effect'] as $foreignCosmetic) {
    $assert(!preg_match("/'item_family'\s*=>\s*'" . preg_quote($foreignCosmetic, '/') . "'/", $migration), 'Avatar migration must not take ownership of ' . $foreignCosmetic . ' cosmetics.');
}

echo "MVP-19.3 avatar economy tiers: PASS\n";
