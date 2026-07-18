<?php
declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/handlers/WebhookHandler.php');
if (!is_string($source)) {
    throw new RuntimeException('Could not read WebhookHandler source.');
}

$methods = [
    'setPendingPaymentReject',
    'cancelPendingPaymentReject',
    'processPaymentDecision',
];

$assertions = 0;
foreach ($methods as $method) {
    $assertions++;
    $pattern = '/private function ' . preg_quote($method, '/')
        . '\\(StorageTransactionInterface \\$db,/';
    if (preg_match($pattern, $source) !== 1) {
        throw new RuntimeException($method . ' must accept StorageTransactionInterface.');
    }
}

$assertions++;
if (str_contains($source, 'JsonDatabase $db')) {
    throw new RuntimeException('WebhookHandler must not require the legacy JsonDatabase concrete type.');
}

fwrite(STDOUT, "WebhookHandlerStorageContractTest: {$assertions} assertions passed\n");
