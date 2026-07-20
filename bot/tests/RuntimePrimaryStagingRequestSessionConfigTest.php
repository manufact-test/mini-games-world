<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingRequestSessionConfig.php';

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

$now = 1_800_000_000;
$disabled = RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig([]);
$assertTrue($disabled->enabled() === false, 'Request session must default to disabled');
$assertTrue(($disabled->safeSummary()['webhook_allowed'] ?? true) === false, 'Disabled request session must still forbid webhook');
$assertThrows(
    static fn() => $disabled->assertEnabledForApi(1, 1, $now),
    'session is disabled'
);

$settings = [
    'enabled' => true,
    'contract_version' => RuntimePrimaryStagingRequestSessionConfig::CONTRACT_VERSION,
    'allowed_entrypoints' => ['api'],
    'baseline_revision' => 7,
    'max_revision_delta' => 3,
    'max_worker_ticks' => 4,
    'lease_seconds' => 60,
    'expires_at_utc' => gmdate(DATE_ATOM, $now + 900),
];
$session = RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig([
    'staging_db_primary_request_session' => $settings,
]);
$assertTrue($session->enabled() === true, 'Exact request session contract must enable');
$assertTrue($session->baselineRevision() === 7, 'Request session must preserve baseline revision');
$assertTrue($session->maximumRevision() === 10, 'Request session must calculate maximum revision');
$assertTrue($session->remainingRevisions(8) === 2, 'Request session must calculate remaining revisions');
$assertTrue($session->maxWorkerTicks() === 4, 'Request session must preserve worker tick bound');
$assertTrue($session->leaseSeconds() === 60, 'Request session must preserve worker lease');
$session->assertEnabledForApi(7, 7, $now);
$assertTrue(true, 'Baseline revision must be accepted');
$session->assertEnabledForApi(7, 10, $now);
$assertTrue(true, 'Maximum bounded revision must be accepted');

$assertThrows(static fn() => $session->assertEnabledForApi(6, 7, $now), 'baseline does not match evidence');
$assertThrows(static fn() => $session->assertEnabledForApi(7, 6, $now), 'behind the request session baseline');
$assertThrows(static fn() => $session->assertEnabledForApi(7, 11, $now), 'exceeds the bounded request session');
$assertThrows(static fn() => $session->assertEnabledForApi(7, 7, $now + 901), 'session has expired');
$assertThrows(static fn() => $session->assertEnabledForApi(7, 7, $now - 1000), 'more than 30 minutes away');

$invalidCases = [
    [
        ['staging_db_primary_request_session' => 'enabled'],
        'configuration array',
    ],
    [
        ['staging_db_primary_request_session' => ['enabled' => 'tru']],
        'strict boolean',
    ],
    [
        ['staging_db_primary_request_session' => array_replace($settings, ['contract_version' => 'wrong'])],
        'contract version is invalid',
    ],
    [
        ['staging_db_primary_request_session' => array_replace($settings, ['allowed_entrypoints' => ['webhook']])],
        'supports only api',
    ],
    [
        ['staging_db_primary_request_session' => array_replace($settings, ['baseline_revision' => 0])],
        'baseline revision must be positive',
    ],
    [
        ['staging_db_primary_request_session' => array_replace($settings, [
            'max_revision_delta' => 4,
            'max_worker_ticks' => 3,
        ])],
        'must cover the revision delta',
    ],
    [
        ['staging_db_primary_request_session' => array_replace($settings, ['lease_seconds' => 10])],
        'lease must be between 30 and 300',
    ],
    [
        ['staging_db_primary_request_session' => array_replace($settings, [
            'allowed_entrypoints' => ['api', 'api'],
        ])],
        'contains duplicates',
    ],
    [
        ['staging_db_primary_request_session' => array_replace($settings, [
            'expires_at_utc' => '2030-01-01T00:00:00',
        ])],
        'explicit utc offset',
    ],
];
foreach ($invalidCases as [$application, $message]) {
    $assertThrows(
        static fn() => RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($application),
        $message
    );
}

fwrite(STDOUT, "RuntimePrimaryStagingRequestSessionConfigTest passed: {$assertions} assertions.\n");
