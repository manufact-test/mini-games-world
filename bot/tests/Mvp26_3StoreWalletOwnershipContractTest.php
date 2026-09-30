<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$store = file_get_contents($root . '/app/assets/js/screens/store-screen.js');
$manifest = file_get_contents($root . '/app/runtime/client/version-manifest.php');

if (!is_string($store) || !is_string($manifest)) {
    throw new RuntimeException('Store wallet ownership sources unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($store, "applyStoreResponse(result, { preserveBalance:true })"),
    'Read-only Store status must preserve the already-authoritative unified balance.'
);
$assert(
    str_contains($store, "Object.prototype.hasOwnProperty.call(state.user, 'balance')"),
    'Store status must preserve explicit zero by own-property semantics.'
);
$assert(
    str_contains($store, "? { ...incomingStore, balance:state.user.balance }"),
    'Store snapshot must be normalized to the current authoritative balance during status warm.'
);
$assert(
    substr_count($store, 'applyStoreResponse(result);') >= 3,
    'Purchase/equip/unequip responses must retain the existing mutation path.'
);
$assert(
    preg_match('/store-screen\\.js\\?v=\\d+[^\\n]*mvp26_3_13=store-status-wallet-nonowner-v1/', $manifest) === 1,
    'Manifest must publish the new Store wallet-ownership cache identity.'
);

fwrite(STDOUT, "Mvp26_3StoreWalletOwnershipContractTest: {$assertions} assertions passed\n");
