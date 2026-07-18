<?php
declare(strict_types=1);

require dirname(__DIR__) . '/notifications/NotificationRuntimeAuditSource.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains($error->getMessage(), $contains)) return;
        throw new RuntimeException($message . ': unexpected error ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no error was thrown');
};

$source = new NotificationRuntimeAuditSource();

$numericRecordId = $source->userIds([
    'users' => [972585905 => ['id' => 972585905]],
]);
$assertSame(['972585905'], $numericRecordId, 'Numeric legacy ID must remain a string');
$assertSame('string', gettype($numericRecordId[0] ?? null), 'Collected legacy ID type must be string');

$numericSourceKey = $source->userIds([
    'users' => [972585905 => ['display_name' => 'Test']],
]);
$assertSame(['972585905'], $numericSourceKey, 'Numeric source key fallback must remain a string');

$assertThrows(
    static fn() => $source->userIds([
        'users' => [
            ['id' => 972585905],
            ['id' => '972585905'],
        ],
    ]),
    'duplicate stable ID',
    'Equivalent numeric and string IDs must be rejected as duplicates'
);

$assertThrows(
    static fn() => $source->userIds(['users' => ['invalid']]),
    'not an object',
    'Non-object JSON user rows must fail closed'
);

fwrite(STDOUT, "NotificationRuntimeAuditSourceTest: {$assertions} assertions passed\n");
