<?php
declare(strict_types=1);

require dirname(__DIR__) . '/services/UserService.php';
require dirname(__DIR__) . '/services/PaymentService.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assertContains = static function (string $needle, string $actual, string $message) use (&$assertions): void {
    $assertions++;
    if (!str_contains($actual, $needle)) {
        throw new RuntimeException($message . ': missing ' . var_export($needle, true));
    }
};
$assertNotContains = static function (string $needle, string $actual, string $message) use (&$assertions): void {
    $assertions++;
    if (str_contains($actual, $needle)) {
        throw new RuntimeException($message . ': unexpectedly found ' . var_export($needle, true));
    }
};

$payments = [
    [
        'id' => 'pay_WAIT0001AAAA',
        'user_id' => 'u1',
        'status' => 'draft',
        'room' => 'match',
        'coins' => 2,
        'price' => 1,
        'currency' => 'RUB',
        'balance_applied' => false,
        'created_at' => '2026-07-01T10:00:00+00:00',
    ],
    [
        'id' => 'pay_WAIT0002BBBB',
        'user_id' => 'u1',
        'status' => 'pending',
        'room' => 'gold',
        'coins' => 5,
        'price' => 5,
        'currency' => 'RUB',
        'balance_applied' => false,
        'created_at' => '2026-07-01T11:00:00+00:00',
    ],
];

for ($i = 1; $i <= 12; $i++) {
    $suffix = str_pad((string)$i, 4, '0', STR_PAD_LEFT);
    $paid = $i % 2 === 0;
    $payments[] = [
        'id' => 'pay_DONE' . $suffix . 'ZZZZ',
        'user_id' => 'u1',
        'status' => $paid ? 'paid' : 'rejected',
        'room' => 'match',
        'coins' => 2,
        'price' => 1,
        'currency' => 'RUB',
        'balance_applied' => $paid,
        'created_at' => sprintf('2026-07-%02dT12:00:00+00:00', $i + 1),
    ];
}

$db = [
    'users' => [
        'u1' => [
            'id' => 'u1',
            'first_name' => 'Тест',
            'username' => 'test_user',
        ],
    ],
    'payments' => $payments,
];

$service = new PaymentService([], new UserService([]));
$output = $service->adminList($db, 12);

$assertContains('⏳ Ожидают решения:', $output, 'Waiting section must be present');
$assertContains('№ WAIT0001 · ожидает решения', $output, 'Old draft must remain visible');
$assertContains('№ WAIT0002 · ожидает решения', $output, 'Old pending request must remain visible');
$assertContains('Последние обработанные:', $output, 'Processed section must remain visible');
$assertContains('№ DONE0012', $output, 'Newest processed payment must be visible');
$assertNotContains('№ DONE0001', $output, 'Old processed payment must yield its slot to waiting requests');
$assertTrue(
    strpos($output, '№ WAIT0001') < strpos($output, 'Последние обработанные:'),
    'Waiting requests must appear before processed requests'
);
$assertTrue(substr_count($output, "
№ ") === 12, 'Visible payment card count must respect the list limit');

fwrite(STDOUT, "PaymentAdminListPriorityTest passed: {$assertions} assertions.
");
