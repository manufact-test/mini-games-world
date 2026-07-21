<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryPortableCiEvidenceVerifier.php';

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

$manifestSource = $projectRoot . '/ops/ci/portable-focused-suite-manifest.json';
$manifestRaw = file_get_contents($manifestSource);
if (!is_string($manifestRaw)) {
    throw new RuntimeException('Portable focused-suite manifest fixture is unavailable.');
}
$manifest = json_decode($manifestRaw, true, 512, JSON_THROW_ON_ERROR);
$rootMarkers = array_map(
    static fn(array $node): string => (string)$node['success_marker'],
    (array)$manifest['ordered_roots']
);
$chainMarkers = array_map(
    static fn(array $node): string => (string)$node['success_marker'],
    (array)$manifest['recursive_chain']
);
$orderedMarkers = [
    $rootMarkers[0],
    $rootMarkers[1],
    ...array_reverse($chainMarkers),
    'DB-primary portable self-hosted CI focused verification passed.',
];
$logRaw = implode("\n", $orderedMarkers) . "\n";
$commit = str_repeat('a', 40);
$baseSummary = [
    'ok' => true,
    'report_type' => RuntimePrimaryPortableCiEvidenceVerifier::REPORT_TYPE,
    'suite' => RuntimePrimaryPortableCiEvidenceVerifier::SUITE,
    'suite_manifest_sha256' => hash('sha256', $manifestRaw),
    'suite_manifest_script_count' => 13,
    'repository_commit' => $commit,
    'php_version' => '8.3.25',
    'started_at_utc' => '2026-07-21T16:00:00Z',
    'finished_at_utc' => '2026-07-21T16:00:05Z',
    'duration_seconds' => 5,
    'exit_code' => 0,
    'log_sha256' => hash('sha256', $logRaw),
    'tracked_worktree_unchanged' => true,
    'live_database_contacted' => false,
    'private_config_required' => false,
    'application_entrypoints_changed' => false,
    'cron_changed' => false,
    'deployment_performed' => false,
    'production_changed' => false,
    'sensitive_identifiers_exposed' => false,
];

$temp = sys_get_temp_dir() . '/mgw-portable-ci-evidence-' . bin2hex(random_bytes(6));
mkdir($temp, 0700, true);
$temp = str_replace('\\', '/', (string)realpath($temp));
$writeBundle = static function (
    array $summary,
    string $manifestContent,
    string $logContent
) use ($temp): void {
    chmod($temp, 0700);
    foreach (scandir($temp) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        @unlink($temp . '/' . $name);
    }
    $summaryPath = $temp . '/focused-suite-summary.json';
    $manifestPath = $temp . '/focused-suite-manifest.json';
    $logPath = $temp . '/focused-suite.log';
    file_put_contents(
        $summaryPath,
        json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
    );
    file_put_contents($manifestPath, $manifestContent);
    file_put_contents($logPath, $logContent);
    chmod($summaryPath, 0600);
    chmod($manifestPath, 0600);
    chmod($logPath, 0600);
    clearstatcache(true, $summaryPath);
    clearstatcache(true, $manifestPath);
    clearstatcache(true, $logPath);
};
$verify = static function (string $expectedCommit = '') use ($temp, $commit): array {
    return (new RuntimePrimaryPortableCiEvidenceVerifier(
        $temp,
        $expectedCommit !== '' ? $expectedCommit : $commit
    ))->verify();
};

try {
    $writeBundle($baseSummary, $manifestRaw, $logRaw);
    $report = $verify();
    $assertTrue(($report['ok'] ?? false) === true, 'Exact portable CI evidence must pass');
    $assertTrue(($report['repository_commit'] ?? '') === $commit, 'Verified report must preserve commit');
    $assertTrue(($report['suite_manifest_script_count'] ?? 0) === 13, 'Verified report must preserve script count');
    $assertTrue(($report['success_marker_count'] ?? 0) === count($orderedMarkers), 'Verified report must count every marker');
    $assertTrue(($report['duration_seconds'] ?? -1) === 5, 'Verified report must preserve duration');
    $assertTrue(($report['production_changed'] ?? true) === false, 'Verified report must preserve production safety');

    $assertThrows(static fn() => $verify(str_repeat('b', 40)), 'different repository commit');
    $assertThrows(static fn() => $verify(strtoupper($commit)), 'full lowercase sha-1');
    $assertThrows(static fn() => $verify($commit . "\n"), 'full lowercase sha-1');

    $changed = $baseSummary;
    $changed['repository_commit'] = strtoupper($commit);
    $writeBundle($changed, $manifestRaw, $logRaw);
    $assertThrows(static fn() => $verify(), 'repository commit is invalid');

    $changed = $baseSummary;
    $changed['repository_commit'] = $commit . ' ';
    $writeBundle($changed, $manifestRaw, $logRaw);
    $assertThrows(static fn() => $verify(), 'repository commit is invalid');

    $changed = $baseSummary;
    $changed['suite_manifest_sha256'] = strtoupper((string)$baseSummary['suite_manifest_sha256']);
    $writeBundle($changed, $manifestRaw, $logRaw);
    $assertThrows(static fn() => $verify(), 'manifest sha-256 does not match');

    $changed = $baseSummary;
    $changed['php_version'] = ' 8.3.25';
    $writeBundle($changed, $manifestRaw, $logRaw);
    $assertThrows(static fn() => $verify(), 'not produced by php 8.3.x');

    $changed = $baseSummary;
    $changed['exit_code'] = '0';
    $writeBundle($changed, $manifestRaw, $logRaw);
    $assertThrows(static fn() => $verify(), 'successful immutable run');

    $changed = $baseSummary;
    $changed['suite_manifest_script_count'] = '13';
    $writeBundle($changed, $manifestRaw, $logRaw);
    $assertThrows(static fn() => $verify(), 'script count does not match');

    $changed = $baseSummary;
    $changed['duration_seconds'] = '5';
    $writeBundle($changed, $manifestRaw, $logRaw);
    $assertThrows(static fn() => $verify(), 'duration does not match');

    $logPath = $temp . '/focused-suite.log';
    $writeBundle($baseSummary, $manifestRaw, $logRaw);
    file_put_contents($logPath, $logRaw . "tampered\n");
    clearstatcache(true, $logPath);
    $assertThrows(static fn() => $verify(), 'log sha-256 does not match');

    $duplicateLog = $logRaw . $orderedMarkers[0] . "\n";
    $duplicateSummary = $baseSummary;
    $duplicateSummary['log_sha256'] = hash('sha256', $duplicateLog);
    $writeBundle($duplicateSummary, $manifestRaw, $duplicateLog);
    $assertThrows(static fn() => $verify(), 'duplicate success marker');

    $embeddedLog = str_replace(
        $orderedMarkers[0],
        'prefix ' . $orderedMarkers[0],
        $logRaw
    );
    $embeddedSummary = $baseSummary;
    $embeddedSummary['log_sha256'] = hash('sha256', $embeddedLog);
    $writeBundle($embeddedSummary, $manifestRaw, $embeddedLog);
    $assertThrows(static fn() => $verify(), 'order is incomplete or invalid');

    $unsafeSummary = $baseSummary;
    $unsafeSummary['production_changed'] = true;
    $writeBundle($unsafeSummary, $manifestRaw, $logRaw);
    $assertThrows(static fn() => $verify(), 'safety flag must be false');

    $timeSummary = $baseSummary;
    $timeSummary['duration_seconds'] = 4;
    $writeBundle($timeSummary, $manifestRaw, $logRaw);
    $assertThrows(static fn() => $verify(), 'duration does not match');

    $timeSummary = $baseSummary;
    $timeSummary['started_at_utc'] = '2026-02-30T16:00:00Z';
    $writeBundle($timeSummary, $manifestRaw, $logRaw);
    $assertThrows(static fn() => $verify(), 'timestamps are invalid');

    $timeSummary = $baseSummary;
    $timeSummary['started_at_utc'] = '2026-07-21T16:00:00Z ';
    $writeBundle($timeSummary, $manifestRaw, $logRaw);
    $assertThrows(static fn() => $verify(), 'exact utc z format');

    $tamperedManifest = $manifest;
    $tamperedManifest['recursive_chain'][3]['script'] = 'ops/checks/fake-local.sh';
    $tamperedManifest['recursive_chain'][2]['next_script'] = 'ops/checks/fake-local.sh';
    $tamperedManifestRaw = json_encode(
        $tamperedManifest,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . "\n";
    $manifestSummary = $baseSummary;
    $manifestSummary['suite_manifest_sha256'] = hash('sha256', $tamperedManifestRaw);
    $writeBundle($manifestSummary, $tamperedManifestRaw, $logRaw);
    $assertThrows(static fn() => $verify(), 'exact script graph is invalid');

    $writeBundle($baseSummary, $manifestRaw, $logRaw);
    file_put_contents($temp . '/unexpected.txt', 'unexpected');
    clearstatcache(true, $temp . '/unexpected.txt');
    $assertThrows(static fn() => $verify(), 'exactly three evidence files');

    $writeBundle($baseSummary, $manifestRaw, $logRaw);
    chmod($temp . '/focused-suite.log', 0666);
    clearstatcache(true, $temp . '/focused-suite.log');
    $assertThrows(static fn() => $verify(), 'must not be world-writable');

    $writeBundle($baseSummary, $manifestRaw, $logRaw);
    chmod($temp, 0777);
    clearstatcache(true, $temp);
    $assertThrows(static fn() => $verify(), 'directory must not be world-writable');
} finally {
    @chmod($temp, 0700);
    $remove($temp);
}

fwrite(STDOUT, "RuntimePrimaryPortableCiEvidenceVerifierTest passed: {$assertions} assertions.\n");
