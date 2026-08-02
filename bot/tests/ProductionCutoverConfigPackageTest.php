<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/cutover/ProductionCutoverConfig.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $needle) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($needle))) return;
        throw new RuntimeException('Unexpected exception: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected exception was not thrown.');
};

$now = 1_800_000_000;
$plan = str_repeat('a', 64);
$source = str_repeat('b', 64);
$package = str_repeat('c', 64);
$smoke = str_repeat('d', 64);
$commit = str_repeat('e', 40);
$config = [
    'production_cutover' => [
        'enabled' => true,
        'expected_build' => 'v103-mvp14-production-cutover',
        'expected_release_commit' => $commit,
        'expected_package_fingerprint' => $package,
        'approval_request_id' => str_repeat('1', 32),
        'approval_confirmation' => ProductionCutoverConfig::RUN_CONFIRMATION,
        'approval_plan_fingerprint' => $plan,
        'approval_expires_at_utc' => gmdate(DATE_ATOM, $now + 900),
        'require_primary_backup' => true,
        'require_external_copy' => true,
        'release' => [
            'enabled' => true,
            'request_id' => str_repeat('2', 32),
            'confirmation' => ProductionCutoverConfig::RELEASE_CONFIRMATION,
            'plan_fingerprint' => $plan,
            'source_fingerprint' => $source,
            'smoke_receipt_fingerprint' => $smoke,
            'expires_at_utc' => gmdate(DATE_ATOM, $now + 600),
        ],
    ],
];

$policy = ProductionCutoverConfig::fromApplicationConfig($config);
$policy->assertPackage([
    'ready' => true,
    'build' => 'v103-mvp14-production-cutover',
    'release_commit' => $commit,
    'package_fingerprint' => $package,
]);
$policy->assertApproved('v103-mvp14-production-cutover', $plan, $now);
$policy->assertReleaseApproved($plan, $source, $smoke, $now);
$summary = $policy->safeSummary();
$assertTrue(($summary['enabled'] ?? false) === true, 'Run approval must be enabled');
$assertTrue(($summary['release_enabled'] ?? false) === true, 'Release approval must be separate and enabled');
$assertTrue(($summary['approval_confirmation_exact'] ?? false) === true, 'Run confirmation must be exact');
$assertTrue(($summary['release_confirmation_exact'] ?? false) === true, 'Release confirmation must be exact');

$badRelease = $config;
$badRelease['production_cutover']['release']['confirmation'] = ProductionCutoverConfig::RUN_CONFIRMATION;
$badReleasePolicy = ProductionCutoverConfig::fromApplicationConfig($badRelease);
$assertThrows(
    static fn() => $badReleasePolicy->assertReleaseApproved($plan, $source, $smoke, $now),
    'release confirmation phrase'
);

$badRun = $config;
$badRun['production_cutover']['approval_confirmation'] = ProductionCutoverConfig::RELEASE_CONFIRMATION;
$badRunPolicy = ProductionCutoverConfig::fromApplicationConfig($badRun);
$assertThrows(
    static fn() => $badRunPolicy->assertApproved('v103-mvp14-production-cutover', $plan, $now),
    'cutover confirmation phrase'
);

$expired = $config;
$expired['production_cutover']['release']['expires_at_utc'] = gmdate(DATE_ATOM, $now - 1);
$expiredPolicy = ProductionCutoverConfig::fromApplicationConfig($expired);
$assertThrows(
    static fn() => $expiredPolicy->assertReleaseApproved($plan, $source, $smoke, $now),
    'missing or expired'
);

$tooLong = $config;
$tooLong['production_cutover']['approval_expires_at_utc'] = gmdate(DATE_ATOM, $now + 1801);
$tooLongPolicy = ProductionCutoverConfig::fromApplicationConfig($tooLong);
$assertThrows(
    static fn() => $tooLongPolicy->assertApproved('v103-mvp14-production-cutover', $plan, $now),
    'too far in the future'
);

$uppercaseRequest = $config;
$uppercaseRequest['production_cutover']['approval_request_id'] = str_repeat('A', 32);
$uppercasePolicy = ProductionCutoverConfig::fromApplicationConfig($uppercaseRequest);
$assertThrows(
    static fn() => $uppercasePolicy->assertApproved('v103-mvp14-production-cutover', $plan, $now),
    'request ID is invalid'
);

$wrongPackage = ProductionCutoverConfig::fromApplicationConfig($config);
$assertThrows(
    static fn() => $wrongPackage->assertPackage([
        'ready' => true,
        'build' => 'v103-mvp14-production-cutover',
        'release_commit' => $commit,
        'package_fingerprint' => str_repeat('f', 64),
    ]),
    'exact package'
);

$releaseDisabled = $config;
$releaseDisabled['production_cutover']['release']['enabled'] = false;
$releaseDisabledPolicy = ProductionCutoverConfig::fromApplicationConfig($releaseDisabled);
$assertThrows(
    static fn() => $releaseDisabledPolicy->assertReleaseApproved($plan, $source, $smoke, $now),
    'release is not separately enabled'
);

fwrite(STDOUT, "ProductionCutoverConfigPackageTest passed: {$assertions} assertions.\n");
