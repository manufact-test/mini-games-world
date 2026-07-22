<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryCurrentPortableCiEvidenceVerifier.php';

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
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $child = $path . '/' . $name;
        if (is_dir($child) && !is_link($child)) $remove($child);
        else @unlink($child);
    }
    @rmdir($path);
};

$roots = [
    [
        'script' => 'ops/checks/db-primary-projection-outbox-local.sh',
        'success_marker' => 'DB-primary projection outbox focused verification passed.',
    ],
    [
        'script' => 'ops/checks/db-primary-projection-worker-local.sh',
        'success_marker' => 'DB-primary projection worker focused verification passed.',
    ],
    [
        'script' => 'ops/checks/db-primary-staging-api-read-only-smoke-evidence-verifier-local.sh',
        'success_marker' => 'DB-primary staging API read-only smoke evidence verifier focused verification passed.',
    ],
];
$chainTuples = [
    ['ops/checks/db-primary-staging-api-read-only-smoke-evidence-verifier-local.sh', 'ops/checks/db-primary-staging-api-read-only-smoke-local.sh', 'DB-primary staging API read-only smoke evidence verifier focused verification passed.'],
    ['ops/checks/db-primary-staging-api-read-only-smoke-local.sh', 'ops/checks/db-primary-staging-api-session-integration-local.sh', 'DB-primary staging API read-only smoke focused verification passed.'],
    ['ops/checks/db-primary-staging-api-session-integration-local.sh', 'ops/checks/db-primary-staging-request-finalizer-local.sh', 'DB-primary staging API session integration focused verification passed.'],
    ['ops/checks/db-primary-staging-request-finalizer-local.sh', 'ops/checks/db-primary-staging-entrypoint-selector-local.sh', 'DB-primary staging request finalizer focused verification passed.'],
    ['ops/checks/db-primary-staging-entrypoint-selector-local.sh', 'ops/checks/db-primary-staging-synthetic-suite-local.sh', 'DB-primary staging entrypoint selector focused verification passed.'],
    ['ops/checks/db-primary-staging-synthetic-suite-local.sh', 'ops/checks/db-primary-staging-storage-resolver-local.sh', 'DB-primary staging synthetic suite focused verification passed.'],
    ['ops/checks/db-primary-staging-storage-resolver-local.sh', 'ops/checks/db-primary-staging-activation-local.sh', 'DB-primary staging storage resolver focused verification passed.'],
    ['ops/checks/db-primary-staging-activation-local.sh', 'ops/checks/db-primary-staging-evidence-collector-local.sh', 'DB-primary staging activation focused verification passed.'],
    ['ops/checks/db-primary-staging-evidence-collector-local.sh', 'ops/checks/db-primary-staging-evidence-local.sh', 'DB-primary staging evidence collector focused verification passed.'],
    ['ops/checks/db-primary-staging-evidence-local.sh', 'ops/checks/db-primary-staging-rehearsal-local.sh', 'DB-primary staging evidence focused verification passed.'],
    ['ops/checks/db-primary-staging-rehearsal-local.sh', 'ops/checks/db-primary-all-module-projector-local.sh', 'DB-primary staging rehearsal focused verification passed.'],
    ['ops/checks/db-primary-all-module-projector-local.sh', null, 'DB-primary all-module projector focused verification passed.'],
];
$chain = array_map(
    static fn(array $node): array => [
        'script' => $node[0],
        'next_script' => $node[1],
        'success_marker' => $node[2],
    ],
    $chainTuples
);
$manifest = [
    'contract_version' => RuntimePrimaryCurrentPortableCiEvidenceVerifier::MANIFEST_CONTRACT,
    'entrypoint' => 'ops/checks/db-primary-current-portable-validation-local.sh',
    'expected_unique_script_count' => 14,
    'ordered_roots' => $roots,
    'recursive_chain' => $chain,
    'safety' => [
        'live_database_contacted' => false,
        'private_config_required' => false,
        'application_entrypoints_changed' => false,
        'cron_changed' => false,
        'deployment_performed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
    ],
];
$markers = [
    $roots[0]['success_marker'],
    $roots[1]['success_marker'],
    ...array_reverse(array_column($chain, 'success_marker')),
    'DB-primary current portable validation focused verification passed.',
];
$log = "current portable suite start\n" . implode("\n", $markers) . "\n";
$now = 1_800_000_000;
$commit = str_repeat('a', 40);
$finished = $now - 5;
$started = $finished - 12;
$manifestRaw = json_encode(
    $manifest,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
) . "\n";
$summary = [
    'ok' => true,
    'report_type' => RuntimePrimaryCurrentPortableCiEvidenceVerifier::REPORT_TYPE,
    'suite' => RuntimePrimaryCurrentPortableCiEvidenceVerifier::SUITE,
    'suite_manifest_sha256' => hash('sha256', $manifestRaw),
    'suite_manifest_script_count' => 14,
    'repository_commit' => $commit,
    'php_version' => '8.3.22',
    'started_at_utc' => gmdate('Y-m-d\TH:i:s\Z', $started),
    'finished_at_utc' => gmdate('Y-m-d\TH:i:s\Z', $finished),
    'duration_seconds' => 12,
    'exit_code' => 0,
    'log_sha256' => hash('sha256', $log),
    'repository_checkout_unchanged' => true,
    'live_database_contacted' => false,
    'private_config_required' => false,
    'application_entrypoints_changed' => false,
    'cron_changed' => false,
    'deployment_performed' => false,
    'production_changed' => false,
    'sensitive_identifiers_exposed' => false,
];

$temp = sys_get_temp_dir() . '/mgw-current-portable-evidence-' . bin2hex(random_bytes(6));
mkdir($temp, 0700, true);
$temp = str_replace('\\', '/', (string)realpath($temp));
$summaryPath = $temp . '/current-focused-suite-summary.json';
$manifestPath = $temp . '/current-focused-suite-manifest.json';
$logPath = $temp . '/current-focused-suite.log';
$writeBundle = static function (
    array $summaryValue,
    array $manifestValue,
    string $logValue
) use ($temp, $summaryPath, $manifestPath, $logPath): void {
    foreach (scandir($temp) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        @unlink($temp . '/' . $name);
    }
    chmod($temp, 0700);
    file_put_contents(
        $manifestPath,
        json_encode($manifestValue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
    );
    file_put_contents($logPath, $logValue);
    $manifestBytes = file_get_contents($manifestPath);
    if (!is_string($manifestBytes)) throw new RuntimeException('manifest fixture unavailable');
    $summaryValue['suite_manifest_sha256'] = hash('sha256', $manifestBytes);
    $summaryValue['log_sha256'] = hash('sha256', $logValue);
    file_put_contents(
        $summaryPath,
        json_encode($summaryValue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
    );
    chmod($summaryPath, 0600);
    chmod($manifestPath, 0600);
    chmod($logPath, 0600);
    clearstatcache();
};
$verify = static function (int $maximumAge = 604_800) use ($temp, $commit, $now): array {
    return (new RuntimePrimaryCurrentPortableCiEvidenceVerifier(
        $temp,
        $commit,
        $maximumAge
    ))->verify($now);
};

try {
    $writeBundle($summary, $manifest, $log);
    $result = $verify();
    $assertTrue(($result['ok'] ?? false) === true, 'Exact current portable bundle must pass');
    $assertTrue(($result['suite_manifest_script_count'] ?? 0) === 14, 'Verifier must preserve script count');
    $assertTrue(($result['success_marker_count'] ?? 0) === 15, 'Verifier must require all fifteen success markers');
    $assertTrue(($result['evidence_age_seconds'] ?? -1) === 5, 'Verifier must expose exact evidence age');
    $assertTrue(preg_match('/^[a-f0-9]{64}$/', (string)($result['summary_sha256'] ?? '')) === 1, 'Verifier must expose summary SHA');

    $assertThrows(
        static fn() => (new RuntimePrimaryCurrentPortableCiEvidenceVerifier(
            $temp,
            str_repeat('b', 40)
        ))->verify($now),
        'different repository commit'
    );
    $assertThrows(
        static fn() => new RuntimePrimaryCurrentPortableCiEvidenceVerifier(
            $temp,
            strtoupper($commit)
        ),
        'full lowercase sha-1'
    );

    $changedSummary = $summary;
    $changedSummary['repository_commit'] = strtoupper($commit);
    $writeBundle($changedSummary, $manifest, $log);
    $assertThrows(static fn() => $verify(), 'repository commit is invalid');

    $changedSummary = $summary;
    $changedSummary['suite_manifest_script_count'] = '14';
    $writeBundle($changedSummary, $manifest, $log);
    $assertThrows(static fn() => $verify(), 'script count does not match');

    $changedManifest = $manifest;
    $changedManifest['recursive_chain'][0]['next_script'] = 'ops/checks/other.sh';
    $writeBundle($summary, $changedManifest, $log);
    $assertThrows(static fn() => $verify(), 'exact script graph is invalid');

    $duplicateLog = $log . $markers[0] . "\n";
    $writeBundle($summary, $manifest, $duplicateLog);
    $assertThrows(static fn() => $verify(), 'duplicate success marker');

    $substringLog = str_replace(
        $markers[3],
        'prefix ' . $markers[3] . ' suffix',
        $log
    );
    $writeBundle($summary, $manifest, $substringLog);
    $assertThrows(static fn() => $verify(), 'order is incomplete or invalid');

    $reorderedMarkers = $markers;
    [$reorderedMarkers[4], $reorderedMarkers[5]] = [$reorderedMarkers[5], $reorderedMarkers[4]];
    $reorderedLog = implode("\n", $reorderedMarkers) . "\n";
    $writeBundle($summary, $manifest, $reorderedLog);
    $assertThrows(static fn() => $verify(), 'order is incomplete or invalid');

    $missingFinalLog = implode("\n", array_slice($markers, 0, -1)) . "\n";
    $writeBundle($summary, $manifest, $missingFinalLog);
    $assertThrows(static fn() => $verify(), 'order is incomplete or invalid');

    $changedSummary = $summary;
    $changedSummary['duration_seconds'] = '12';
    $writeBundle($changedSummary, $manifest, $log);
    $assertThrows(static fn() => $verify(), 'duration does not match');

    $changedSummary = $summary;
    $changedSummary['finished_at_utc'] = '2027-02-30T00:00:00Z';
    $changedSummary['started_at_utc'] = '2027-02-29T23:59:48Z';
    $writeBundle($changedSummary, $manifest, $log);
    $assertThrows(static fn() => $verify(), 'timestamps are invalid');

    $changedSummary = $summary;
    $changedSummary['duration_seconds'] = 11;
    $writeBundle($changedSummary, $manifest, $log);
    $assertThrows(static fn() => $verify(), 'duration does not match');

    $changedSummary = $summary;
    $changedSummary['finished_at_utc'] = gmdate('Y-m-d\TH:i:s\Z', $now - 61);
    $changedSummary['started_at_utc'] = gmdate('Y-m-d\TH:i:s\Z', $now - 73);
    $writeBundle($changedSummary, $manifest, $log);
    $assertThrows(static fn() => $verify(60), 'too old');

    $changedSummary = $summary;
    $changedSummary['finished_at_utc'] = gmdate('Y-m-d\TH:i:s\Z', $now + 31);
    $changedSummary['started_at_utc'] = gmdate('Y-m-d\TH:i:s\Z', $now + 19);
    $writeBundle($changedSummary, $manifest, $log);
    $assertThrows(static fn() => $verify(), 'unexpectedly in the future');

    $changedSummary = $summary;
    $changedSummary['production_changed'] = true;
    $writeBundle($changedSummary, $manifest, $log);
    $assertThrows(static fn() => $verify(), 'safety flag must be false');

    $changedSummary = $summary;
    $changedSummary['php_version'] = '8.4.0';
    $writeBundle($changedSummary, $manifest, $log);
    $assertThrows(static fn() => $verify(), 'not produced by php 8.3');

    $changedSummary = $summary;
    $changedSummary['unexpected'] = true;
    $writeBundle($changedSummary, $manifest, $log);
    $assertThrows(static fn() => $verify(), 'fields are not exact');

    $writeBundle($summary, $manifest, $log);
    file_put_contents($temp . '/extra.txt', "extra\n");
    $assertThrows(static fn() => $verify(), 'exactly three evidence files');

    $writeBundle($summary, $manifest, $log);
    chmod($summaryPath, 0666);
    clearstatcache(true, $summaryPath);
    $assertThrows(static fn() => $verify(), 'must not be world-writable');

    $writeBundle($summary, $manifest, $log);
    chmod($temp, 0777);
    clearstatcache(true, $temp);
    $assertThrows(static fn() => $verify(), 'directory must not be world-writable');
} finally {
    @chmod($temp, 0700);
    $remove($temp);
}

fwrite(STDOUT, "RuntimePrimaryCurrentPortableCiEvidenceVerifierTest passed: {$assertions} assertions.\n");
