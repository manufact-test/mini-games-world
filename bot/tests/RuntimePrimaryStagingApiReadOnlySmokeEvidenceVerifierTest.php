<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifier.php';

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

$now = 1_800_000_000;
$commit = str_repeat('a', 40);
$databaseIdentity = str_repeat('b', 64);
$evidenceFingerprint = str_repeat('c', 64);
$base = [
    'ok' => true,
    'report_type' => RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifier::REPORT_TYPE,
    'action' => RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifier::ACTION,
    'state_revision' => 3,
    'state_sha256' => str_repeat('d', 64),
    'projection_contract_version' => RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifier::PROJECTION_VERSION,
    'outbox_event_count' => 3,
    'outbox_fingerprint' => str_repeat('e', 64),
    'top_level_count' => 9,
    'top_level_keys_fingerprint' => str_repeat('f', 64),
    'evidence_manifest_version' => RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifier::EVIDENCE_VERSION,
    'evidence_fingerprint' => $evidenceFingerprint,
    'repository_commit' => $commit,
    'database_identity_fingerprint' => $databaseIdentity,
    'ttl_seconds' => 300,
    'json_default_verified' => true,
    'rollback_data_dir_external' => true,
    'rollback_data_dir_canonical' => true,
    'bootstrap_legacy_hook_count' => 5,
    'bootstrap_legacy_filter_count' => 2,
    'api_bootstrap_hooks_preserved' => true,
    'api_bootstrap_filters_preserved' => true,
    'worker_tick_count' => 0,
    'context_state_matched' => true,
    'lifecycle_v4_verified' => true,
    'legacy_json_bridges_suppressed' => true,
    'completed_events_lease_free' => true,
    'state_unchanged' => true,
    'snapshot_unchanged' => true,
    'outbox_unchanged' => true,
    'data_filters_unchanged' => true,
    'request_finalizer_completed' => true,
    'persistent_config_changed' => false,
    'selector_enabled_in_memory_only' => true,
    'request_session_enabled_in_memory_only' => true,
    'activation_enabled_in_memory_only' => true,
    'http_route_added' => false,
    'api_only' => true,
    'webhook_allowed' => false,
    'cron_changed' => false,
    'production_changed' => false,
    'sensitive_identifiers_exposed' => false,
    'generated_at_utc' => gmdate(DATE_ATOM, $now - 5),
];

$temp = sys_get_temp_dir() . '/mgw-read-only-smoke-evidence-' . bin2hex(random_bytes(6));
mkdir($temp, 0700, true);
$temp = str_replace('\\', '/', (string)realpath($temp));
$path = $temp . '/read-only-smoke.json';
$write = static function (array $report, int $mode = 0600) use ($path): void {
    file_put_contents(
        $path,
        json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
    );
    chmod($path, $mode);
    clearstatcache(true, $path);
};
$verify = static function (
    string $expectedCommit = '',
    string $expectedDatabase = '',
    string $expectedEvidence = '',
    int $maximumAge = 3600,
    int $hooks = 5,
    int $filters = 2
) use ($path, $commit, $databaseIdentity, $evidenceFingerprint, $now): array {
    return (new RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifier(
        $path,
        $expectedCommit !== '' ? $expectedCommit : $commit,
        $expectedDatabase !== '' ? $expectedDatabase : $databaseIdentity,
        $expectedEvidence !== '' ? $expectedEvidence : $evidenceFingerprint,
        $maximumAge,
        $hooks,
        $filters
    ))->verify($now);
};

try {
    $write($base);
    $result = $verify();
    $assertTrue(($result['ok'] ?? false) === true, 'Exact read-only smoke report must pass');
    $assertTrue(($result['state_revision'] ?? 0) === 3, 'Verified report must preserve revision');
    $assertTrue(($result['outbox_event_count'] ?? 0) === 3, 'Verified report must preserve outbox count');
    $assertTrue(($result['worker_tick_count'] ?? -1) === 0, 'Verified report must prove zero worker ticks');
    $assertTrue(($result['report_age_seconds'] ?? -1) === 5, 'Verified report must expose exact age');
    $assertTrue(preg_match('/^[a-f0-9]{64}$/', (string)($result['report_sha256'] ?? '')) === 1, 'Verified report must expose report SHA');

    $assertThrows(static fn() => $verify(str_repeat('1', 40)), 'different repository commit');
    $assertThrows(static fn() => $verify('', str_repeat('2', 64)), 'different database identity');
    $assertThrows(static fn() => $verify('', '', str_repeat('3', 64)), 'different lifecycle evidence');
    $assertThrows(static fn() => $verify(strtoupper($commit)), 'full lowercase sha-1');
    $assertThrows(static fn() => $verify('', strtoupper($databaseIdentity)), 'must be sha-256');
    $assertThrows(static fn() => $verify('', '', strtoupper($evidenceFingerprint)), 'must be sha-256');

    $changed = $base;
    $changed['outbox_event_count'] = 2;
    $write($changed);
    $assertThrows(static fn() => $verify(), 'outbox count does not match');

    $changed = $base;
    $changed['worker_tick_count'] = 1;
    $write($changed);
    $assertThrows(static fn() => $verify(), 'not zero-worker');

    $write($base);
    $assertThrows(static fn() => $verify('', '', '', 3600, 4, 2), 'hook/filter contour');

    $changed = $base;
    $changed['state_unchanged'] = false;
    $write($changed);
    $assertThrows(static fn() => $verify(), 'required proof is not true');

    $changed = $base;
    $changed['production_changed'] = true;
    $write($changed);
    $assertThrows(static fn() => $verify(), 'safety flag must be false');

    $changed = $base;
    $changed['generated_at_utc'] = gmdate(DATE_ATOM, $now - 3601);
    $write($changed);
    $assertThrows(static fn() => $verify(), 'too old');

    $changed = $base;
    $changed['generated_at_utc'] = gmdate(DATE_ATOM, $now + 31);
    $write($changed);
    $assertThrows(static fn() => $verify(), 'unexpectedly in the future');

    $changed = $base;
    $changed['unexpected'] = true;
    $write($changed);
    $assertThrows(static fn() => $verify(), 'fields are not exact');

    $changed = $base;
    $changed['state_sha256'] = strtoupper((string)$base['state_sha256']);
    $write($changed);
    $assertThrows(static fn() => $verify(), 'sha-256 field is invalid');

    $write($base, 0666);
    $assertThrows(static fn() => $verify(), 'must not be world-writable');
} finally {
    $remove($temp);
}

fwrite(STDOUT, "RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifierTest passed: {$assertions} assertions.\n");
