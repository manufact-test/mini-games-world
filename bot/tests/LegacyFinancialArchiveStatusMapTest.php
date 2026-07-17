<?php
declare(strict_types=1);

$map = static function (string $entity, string $raw): string {
    $status = strtolower(trim($raw));
    if ($entity === 'payment') {
        return match ($status) {
            'paid', 'applied', 'completed', 'success', 'succeeded' => 'completed',
            'rejected', 'declined', 'failed' => 'rejected',
            'cancelled', 'canceled' => 'cancelled',
            'draft', 'pending', 'waiting', 'created' => 'pending',
            default => 'unknown',
        };
    }

    return match ($status) {
        'fulfilled', 'completed', 'delivered', 'issued' => 'completed',
        'rejected', 'declined', 'failed' => 'rejected',
        'cancelled', 'canceled', 'refunded' => 'cancelled',
        'draft', 'pending', 'processing', 'created' => 'pending',
        default => 'unknown',
    };
};

$cases = [
    ['payment', 'paid', 'completed'],
    ['payment', 'draft', 'pending'],
    ['payment', 'rejected', 'rejected'],
    ['payment', 'cancelled', 'cancelled'],
    ['payment', 'future-status', 'unknown'],
    ['order', 'fulfilled', 'completed'],
    ['order', 'processing', 'pending'],
    ['order', 'failed', 'rejected'],
    ['order', 'refunded', 'cancelled'],
    ['order', 'future-status', 'unknown'],
];

$assertions = 0;
foreach ($cases as [$entity, $raw, $expected]) {
    $assertions++;
    $actual = $map($entity, $raw);
    if ($actual !== $expected) {
        throw new RuntimeException("Status map failed for {$entity}/{$raw}: expected {$expected}, got {$actual}");
    }
}

fwrite(STDOUT, "LegacyFinancialArchiveStatusMapTest passed: {$assertions} assertions.\n");
