<?php
declare(strict_types=1);

require dirname(__DIR__) . '/accounts/MgwIdGenerator.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$ids = [];
for ($index = 0; $index < 500; $index++) {
    $id = MgwIdGenerator::generate();
    $assertTrue(MgwIdGenerator::isValid($id), 'Generated MGW ID must use the public opaque format');
    $assertTrue(!isset($ids[$id]), 'Generated MGW IDs must be unique in the test sample');
    $ids[$id] = true;
}

$assertTrue(!MgwIdGenerator::isValid('123456789'), 'Telegram IDs must never be accepted as MGW IDs');
$assertTrue(!MgwIdGenerator::isValid('MGW-INVALID-O000000'), 'Ambiguous or malformed MGW IDs must be rejected');

fwrite(STDOUT, "MgwIdGeneratorTest: {$assertions} assertions passed\n");
