<?php
declare(strict_types=1);

require dirname(__DIR__) . '/ledger/LegacyFinancialStatusNormalizer.php';

$normalizer = new LegacyFinancialStatusNormalizer();
$cases = [
    ['payment', 'paid', 'completed'],
    ['payment', 'draft', 'pending'],
    ['payment', 'rejected', 'rejected'],
    ['payment', 'cancelled', 'cancelled'],
    ['payment', 'future-status', 'unknown'],
    ['order', 'done', 'completed'],
    ['order', 'fulfilled', 'completed'],
    ['order', 'processing', 'pending'],
    ['order', 'failed', 'rejected'],
    ['order', 'refunded', 'cancelled'],
    ['order', 'future-status', 'unknown'],
];

$assertions = 0;
foreach ($cases as [$entity, $raw, $expected]) {
    $assertions++;
    $actual = $entity === 'payment'
        ? $normalizer->payment($raw)
        : $normalizer->order($raw);
    if ($actual !== $expected) {
        throw new RuntimeException("Status map failed for {$entity}/{$raw}: expected {$expected}, got {$actual}");
    }
}

$transactionCases = [
    [['category' => 'payment_draft'], 'pending'],
    [['category' => 'payment_apply'], 'completed'],
    [['category' => 'payment_reject'], 'rejected'],
    [['category' => 'shop_order'], 'completed'],
    [['category' => 'shop_order_done'], 'completed'],
    [['category' => 'shop_refund'], 'cancelled'],
    [['category' => 'shop_order_reject'], 'rejected'],
    [['category' => '', 'type' => 'shop_order_done'], 'completed'],
    [['category' => 'future_category'], 'unknown'],
];
foreach ($transactionCases as [$transaction, $expected]) {
    $assertions++;
    $actual = $normalizer->transaction($transaction);
    if ($actual !== $expected) {
        throw new RuntimeException('Transaction status map failed: expected ' . $expected . ', got ' . $actual);
    }
}

fwrite(STDOUT, "LegacyFinancialArchiveStatusMapTest passed: {$assertions} assertions.\n");
