<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/cutover/ProductionCutoverPerformTrait.php';

final class ProductionCutoverImportReportHarness
{
    use ProductionCutoverPerformTrait;

    private const BUILD = 'v103-mvp14-production-cutover';
    private const MODULES = [];

    public function validate(array $report): void
    {
        $this->assertImportReportComplete($report);
    }
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $messagePart) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($messagePart))) return;
        throw new RuntimeException('Unexpected exception: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected exception was not thrown.');
};

$complete = [
    'ok' => true,
    'realtime_shadow' => ['ok' => true],
    'economy_shadow' => ['ok' => true],
    'accounts' => ['ok' => true],
    'opening_balances' => ['ok' => true],
    'ownership' => ['ok' => true],
    'realtime_normalized' => ['ok' => true],
    'financial_archive' => ['ok' => true, 'unknown_status_count' => 0],
    'runtime_schemas' => [
        'shop_ok' => true,
        'payments_ok' => true,
        'weekly_bonus_ok' => true,
    ],
];

$harness = new ProductionCutoverImportReportHarness();
$harness->validate($complete);
$assertTrue(true, 'Complete nested import report must pass');

$failedSection = $complete;
$failedSection['ownership']['ok'] = false;
$assertThrows(
    static fn() => $harness->validate($failedSection),
    'ownership'
);

$unknownFinancialStatus = $complete;
$unknownFinancialStatus['financial_archive']['unknown_status_count'] = 1;
$assertThrows(
    static fn() => $harness->validate($unknownFinancialStatus),
    'unknown statuses'
);

$failedSchema = $complete;
$failedSchema['runtime_schemas']['payments_ok'] = false;
$assertThrows(
    static fn() => $harness->validate($failedSchema),
    'payments_ok'
);

$missingTopLevelSuccess = $complete;
unset($missingTopLevelSuccess['ok']);
$assertThrows(
    static fn() => $harness->validate($missingTopLevelSuccess),
    'did not complete cleanly'
);

fwrite(STDOUT, "ProductionCutoverImportReportTest passed: {$assertions} assertions.\n");
