<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$sourcePath = $root . '/cutover/ProductionCutoverDataTrait.php';
$source = file_get_contents($sourcePath);

if (!is_string($source) || $source === '') {
    throw new RuntimeException('Production cutover data trait is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$openingNeedle = 'new LegacyOpeningBalanceImportService(';
$accountNeedle = 'new LegacyAccountImportService(';
$ownershipNeedle = 'new LegacyAccountOwnershipLinkService(';

$openingPosition = strpos($source, $openingNeedle);
$accountPosition = strpos($source, $accountNeedle);
$ownershipPosition = strpos($source, $ownershipNeedle);

$assertTrue($openingPosition !== false, 'Production cutover must run the opening balance import.');
$assertTrue($accountPosition !== false, 'Production cutover must run the account import.');
$assertTrue($ownershipPosition !== false, 'Production cutover must run the ownership link.');
$assertTrue(
    $openingPosition < $accountPosition,
    'Opening balances must retain legacy account references before MGW identities are created.'
);
$assertTrue(
    $accountPosition < $ownershipPosition,
    'Account identities must exist before legacy ownership is linked.'
);
$assertTrue(
    substr_count($source, $openingNeedle) === 1
        && substr_count($source, $accountNeedle) === 1
        && substr_count($source, $ownershipNeedle) === 1,
    'Each guarded legacy import stage must run exactly once in the production cutover pipeline.'
);

fwrite(
    STDOUT,
    "ProductionCutoverLegacyImportOrderTest passed: {$assertions} assertions.\n"
);
