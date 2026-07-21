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

$now = strtotime('2026-07-20T12:00:00+00:00');
$config = [
    'staging_db_primary_request_session' => [
        'enabled' => true,
        'contract_version' => RuntimePrimaryStagingRequestSessionConfig::CONTRACT_VERSION,
        'allowed_entrypoints' => ['api'],
        'baseline_revision' => 10,
        'max_revision_delta' => 4,
        'max_worker_ticks' => 6,
        'lease_seconds' => 60,
        'expires_at_utc' => '2026-07-20T12:20:00+00:00',
    ],
];
$session = RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($config);
$session->assertEnabledForApi(10, 12, $now);
$assertTrue($session->enabled(), 'Enabled request session must report enabled');
$assertTrue($session->baselineRevision() === 10, 'Request session must preserve baseline');
$assertTrue($session->maximumRevision() === 14, 'Request session must compute bounded maximum');
$assertTrue($session->remainingRevisions(12) === 2, 'Request session must compute remaining revisions');
$assertTrue($session->maxWorkerTicks() === 6, 'Request session must preserve worker bound');
$assertTrue($session->leaseSeconds() === 60, 'Request session must preserve lease seconds');
$assertTrue(($session->safeSummary(12)['api_only'] ?? false) === true, 'Request session must remain API-only');

$zulu = $config;
$zulu['staging_db_primary_request_session']['expires_at_utc'] = '2026-07-20T12:20:00Z';
RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($zulu)
    ->assertEnabledForApi(10, 12, $now);
$assertions++;

$assertThrows(
    static fn() => $session->assertEnabledForApi(9, 12, $now),
    'baseline does not match evidence'
);
$assertThrows(
    static fn() => $session->assertEnabledForApi(10, 15, $now),
    'exceeds the bounded request session'
);
$assertThrows(
    static fn() => $session->assertEnabledForApi(10, 9, $now),
    'behind the request session baseline'
);
$assertThrows(
    static fn() => $session->assertEnabledForApi(10, 12, 0),
    'verification time is invalid'
);

$expired = $config;
$expired['staging_db_primary_request_session']['expires_at_utc'] = '2026-07-20T11:59:59+00:00';
$expiredSession = RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($expired);
$assertThrows(
    static fn() => $expiredSession->assertEnabledForApi(10, 10, $now),
    'has expired'
);

$tooLong = $config;
$tooLong['staging_db_primary_request_session']['expires_at_utc'] = '2026-07-20T12:31:00+00:00';
$tooLongSession = RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($tooLong);
$assertThrows(
    static fn() => $tooLongSession->assertEnabledForApi(10, 10, $now),
    'more than 30 minutes away'
);

$wrongEntrypoint = $config;
$wrongEntrypoint['staging_db_primary_request_session']['allowed_entrypoints'] = ['api', 'webhook'];
$assertThrows(
    static fn() => RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($wrongEntrypoint),
    'supports only api'
);
foreach ([['API'], [' api'], ['api '], [1]] as $malformedEntrypoints) {
    $changed = $config;
    $changed['staging_db_primary_request_session']['allowed_entrypoints'] = $malformedEntrypoints;
    $assertThrows(
        static fn() => RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($changed),
        $malformedEntrypoints === [1] ? 'values must be strings' : 'supports only api'
    );
}
$duplicates = $config;
$duplicates['staging_db_primary_request_session']['allowed_entrypoints'] = ['api', 'api'];
$assertThrows(
    static fn() => RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($duplicates),
    'contains duplicates'
);

$wrongContract = $config;
$wrongContract['staging_db_primary_request_session']['contract_version'] = 'other';
$assertThrows(
    static fn() => RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($wrongContract),
    'contract version is invalid'
);
foreach ([
    ' ' . RuntimePrimaryStagingRequestSessionConfig::CONTRACT_VERSION,
    RuntimePrimaryStagingRequestSessionConfig::CONTRACT_VERSION . ' ',
] as $malformedContract) {
    $changed = $config;
    $changed['staging_db_primary_request_session']['contract_version'] = $malformedContract;
    $assertThrows(
        static fn() => RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($changed),
        'contract version is invalid'
    );
}

foreach ([
    'baseline_revision',
    'max_revision_delta',
    'max_worker_ticks',
    'lease_seconds',
] as $integerField) {
    foreach (['10', 10.0, true, null] as $badValue) {
        $changed = $config;
        $changed['staging_db_primary_request_session'][$integerField] = $badValue;
        $assertThrows(
            static fn() => RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($changed),
            'must be an integer value'
        );
    }
}

$invalidWorkerBound = $config;
$invalidWorkerBound['staging_db_primary_request_session']['max_worker_ticks'] = 2;
$assertThrows(
    static fn() => RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($invalidWorkerBound),
    'must cover the revision delta'
);
$invalidLease = $config;
$invalidLease['staging_db_primary_request_session']['lease_seconds'] = 20;
$assertThrows(
    static fn() => RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($invalidLease),
    'lease must be between 30 and 300'
);

foreach ([
    '2026-07-20 12:20:00',
    ' 2026-07-20T12:20:00+00:00',
    '2026-07-20T12:20:00+00:00 ',
    '2026-02-30T12:20:00+00:00',
    '2026-07-20T12:20+00:00',
] as $malformedExpiry) {
    $changed = $config;
    $changed['staging_db_primary_request_session']['expires_at_utc'] = $malformedExpiry;
    $assertThrows(
        static fn() => RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($changed),
        str_contains($malformedExpiry, '02-30') ? 'is invalid' : 'exact iso-8601 timestamp'
    );
}

foreach (['true', 1, 0, null] as $ambiguousEnabled) {
    $changed = $config;
    $changed['staging_db_primary_request_session']['enabled'] = $ambiguousEnabled;
    $assertThrows(
        static fn() => RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($changed),
        'strict boolean'
    );
}
foreach (['contract_version', 'expires_at_utc'] as $stringField) {
    $changed = $config;
    $changed['staging_db_primary_request_session'][$stringField] = 123;
    $assertThrows(
        static fn() => RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($changed),
        'must be a string value'
    );
}

$malformedBlock = ['staging_db_primary_request_session' => 'enabled'];
$assertThrows(
    static fn() => RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($malformedBlock),
    'configuration array'
);

$disabled = RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig([]);
$assertTrue(!$disabled->enabled(), 'Missing request session config must stay disabled');
$assertThrows(
    static fn() => $disabled->assertEnabledForApi(1, 1, $now),
    'request session is disabled'
);

fwrite(STDOUT, "RuntimePrimaryStagingRequestSessionConfigTest passed: {$assertions} assertions.\n");
