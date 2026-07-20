<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/cutover/ProductionCutoverConfig.php';

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

$build = 'v103-mvp14-production-cutover';
$fingerprint = str_repeat('a', 64);
$now = strtotime('2026-07-20T08:00:00+00:00');

$disabled = ProductionCutoverConfig::fromApplicationConfig([]);
$assertTrue($disabled->enabled() === false, 'Cutover approval must default to disabled');
$assertThrows(
    static fn() => $disabled->assertApproved($build, $fingerprint, $now),
    'not enabled'
);

$approved = ProductionCutoverConfig::fromApplicationConfig([
    'production_cutover' => [
        'enabled' => true,
        'expected_build' => $build,
        'approval_plan_fingerprint' => $fingerprint,
        'approval_expires_at_utc' => '2026-07-20T09:00:00+00:00',
        'require_primary_backup' => true,
        'require_external_copy' => true,
    ],
]);
$approved->assertApproved($build, $fingerprint, $now);
$summary = $approved->safeSummary();
$assertTrue($approved->enabled() === true, 'Approval enabled state must be available for safe rearm checks');
$assertTrue($summary['enabled'] === true, 'Approval summary must report enabled');
$assertTrue($summary['approval_plan_fingerprint_configured'] === true, 'Approval fingerprint must remain redacted');
$assertTrue($approved->requirePrimaryBackup(), 'Primary backup must be required');
$assertTrue($approved->requireExternalCopy(), 'External backup must be required');

$assertThrows(
    static fn() => $approved->assertApproved('wrong-build', $fingerprint, $now),
    'deployed build'
);
$assertThrows(
    static fn() => $approved->assertApproved($build, str_repeat('b', 64), $now),
    'not explicitly approved'
);
$assertThrows(
    static fn() => $approved->assertApproved($build, $fingerprint, strtotime('2026-07-20T09:00:00+00:00')),
    'expired'
);
$assertThrows(
    static fn() => $approved->assertApproved($build, $fingerprint, strtotime('2026-07-20T10:00:00+00:00')),
    'expired'
);
$assertThrows(
    static fn() => ProductionCutoverConfig::fromApplicationConfig([
        'production_cutover' => ['approval_plan_fingerprint' => 'bad'],
    ]),
    'sha-256'
);
$assertThrows(
    static fn() => ProductionCutoverConfig::fromApplicationConfig([
        'production_cutover' => ['approval_expires_at_utc' => '2026-07-20 09:00:00'],
    ]),
    'explicit utc offset'
);
$assertThrows(
    static fn() => ProductionCutoverConfig::fromApplicationConfig([
        'production_cutover' => ['approval_expires_at_utc' => 1784538000],
    ]),
    'iso-8601 string'
);

fwrite(STDOUT, "ProductionCutoverConfigTest passed: {$assertions} assertions.\n");
