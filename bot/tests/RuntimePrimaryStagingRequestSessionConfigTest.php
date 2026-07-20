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

$config = [
    'staging_db_primary_request_session' => [
        'enabled' => true,
        'contract_version' => RuntimePrimaryStagingRequestSessionConfig::CONTRACT_VERSION,
        'allowed_entrypoints' => ['api'],
        'baseline_revision' => 7,
        'max_revision_delta' => 3,
        'max_worker_ticks' => 4,
        'lease_seconds' => 60,
        'expires_at_utc' => gmdate(DATE_ATOM, $now + 900),
    ],
];
$session = RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($config);
$assertTrue($session->enabled() === true, 'Exact request session contract must enable');
$assertTrue($session->baselineRevision() === 7, 'Request session must preserve baseline revision');
$assertTrue($session->maximumRevision() === 10, 'Request session must calculate maximum revision');
$assertTrue($session->remainingRevisions(8) === 2, 'Request session must calculate remaining revisions');
$assertTrue($session->maxWorkerTicks() === 4, 'Request session must preserve worker tick bound');
$assertTrue($session->leaseSeconds() === 60, 'Request session must preserve worker lease');
$session->assertEnabledForApi(7, 7, $now);
$session->assertEnabledForApi(7, 10, $now);
$assertions += 2;

$assertThrows(
    static fn() => $session->assertEnabledForApi(6, 7, $now),
    'baseline does not match evidence'
);
$assertThrows(
    static fn() => $session->assertEnabledForApi(7, 6, $now),
    'behind the request session baseline'
);
$assertThrows(
    static fn() => $session->assertEnabledForApi(7, 11, $now),
    'exceeds the bounded request session'
);
$assertThrows(
    static fn() => $session->assertEnabledForApi(7, 7, $now + 901),
    'session has expired'
);
$assertThrows(
    static fn() => $session->assertEnabledForApi(7, 7, $now - 1000),
    'more than 30 minutes away'
);

$invalidCases = [
    ['staging_db_primary_request_session' => 'enabled', 'configuration array'],
    [['enabled' => 'tru'], 'strict boolean'],
    [[
        'enabled' => true,
        'contract_version' => 'wrong',
        'allowed_entrypoints' => ['api'],
        'baseline_revision' => 7,
        'max_revision_delta' => 1,
        'max_worker_ticks' => 1,
        'lease_seconds' => 60,
        'expires_at_utc' => gmdate(DATE_ATOM, $now + 900),
    ], 'contract version is invalid'],
    [[
        'enabled' => true,
        'contract_version' => RuntimePrimaryStagingRequestSessionConfig::CONTRACT_VERSION,
        'allowed_entrypoints' => ['webhook'],
        'baseline_revision' => 7,
        'max_revision_delta' => 1,
        'max_worker_ticks' => 1,
        'lease_seconds' => 60,
        'expires_at_utc' => gmdate(DATE_ATOM, $now + 900),
    ], 'supports only api'],
    [[
        'enabled' => true,
        'contract_version' => RuntimePrimaryStagingRequestSessionConfig::CONTRACT_VERSION,
        'allowed_entrypoints' => ['api'],
        'baseline_revision' => 0,
        'max_revision_delta' => 1,
        'max_worker_ticks' => 1,
        'lease_seconds' => 60,
        'expires_at_utc' => gmdate(DATE_ATOM, $now + 900),
    ], 'baseline revision must be positive'],
    [[
        'enabled' => true,
        'contract_version' => RuntimePrimaryStagingRequestSessionConfig::CONTRACT_VERSION,
        'allowed_entrypoints' => ['api'],
        'baseline_revision' => 7,
        'max_revision_delta' => 4,
        'max_worker_ticks' => 3,
        'lease_seconds' => 60,
        'expires_at_utc' => gmdate(DATE_ATOM, $now + 900),
    ], 'must cover the revision delta'],
    [[
        'enabled' => true,
        'contract_version' => RuntimePrimaryStagingRequestSessionConfig::CONTRACT_VERSION,
        'allowed_entrypoints' => ['api'],
        'baseline_revision' => 7,
        'max_revision_delta' => 1,
        'max_worker_ticks' => 1,
        'lease_seconds' => 10,
        'expires_at_utc' => gmdate(DATE_ATOM, $now + 900),
    ], 'lease must be between 30 and 300'],
];
foreach ($invalidCases as [$settings, $message]) {
    $application = array_key_exists('staging_db_primary_request_session', $settings)
        ? $settings
        : ['staging_db_primary_request_session' => $settings];
    $assertThrows(
        static fn() => RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($application),
        $message
    );
}

fwrite(STDOUT, "RuntimePrimaryStagingRequestSessionConfigTest passed: {$assertions} assertions.\n");
