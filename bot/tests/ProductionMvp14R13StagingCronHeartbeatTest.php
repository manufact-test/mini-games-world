<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/helpers/StagingCronHeartbeat.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$tempDir = sys_get_temp_dir() . '/mgw-staging-cron-heartbeat-' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0770, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Unable to create cron heartbeat test directory.');
}

$cleanup = static function (string $directory): void {
    if (!is_dir($directory)) return;
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_file($path)) @unlink($path);
    }
    @rmdir($directory);
};

try {
    $baseConfig = [
        'environment' => 'staging',
        'base_url' => 'https://seashell-okapi-889488.hostingersite.com',
        'data_dir' => $tempDir,
    ];

    $productionConfig = $baseConfig;
    $productionConfig['environment'] = 'production';
    $assert(StagingCronHeartbeat::recordSuccessfulRun($productionConfig, true) === false,
        'Heartbeat recording must be disabled outside staging.');
    $assert(glob($tempDir . '/.staging-weekly-match-cron-heartbeat*') === [],
        'Non-staging execution must not create heartbeat files.');

    $wrongHostConfig = $baseConfig;
    $wrongHostConfig['base_url'] = 'https://example.test';
    $assert(StagingCronHeartbeat::recordSuccessfulRun($wrongHostConfig, true) === false,
        'Heartbeat recording must reject an unexpected staging host.');

    $assert(StagingCronHeartbeat::recordSuccessfulRun($baseConfig, true) === true,
        'A successful isolated staging CLI run must write a heartbeat.');
    $first = StagingCronHeartbeat::status($baseConfig);
    $assert(($first['observed_successful_run'] ?? false) === true
        && ($first['fresh_within_eight_days'] ?? false) === true,
        'The first valid heartbeat must be observable and fresh.');
    $assert(($first['transport'] ?? null) === 'cli'
        && (int)($first['run_count'] ?? 0) === 1
        && ($first['recurring_run_observed'] ?? true) === false,
        'The first run must report CLI transport without claiming recurrence.');

    $assert(StagingCronHeartbeat::recordSuccessfulRun($baseConfig, false) === true,
        'A second successful isolated staging HTTP run must update the heartbeat.');
    $second = StagingCronHeartbeat::status($baseConfig);
    $assert(($second['transport'] ?? null) === 'http'
        && (int)($second['run_count'] ?? 0) === 2
        && ($second['recurring_run_observed'] ?? false) === true,
        'The second run must preserve count and recurrence evidence.');
    $assert(is_string($second['previous_executed_at_utc'] ?? null)
        && trim((string)$second['previous_executed_at_utc']) !== '',
        'The second heartbeat must retain the prior execution timestamp.');

    $files = glob($tempDir . '/.staging-weekly-match-cron-heartbeat.json');
    $assert(is_array($files) && count($files) === 1,
        'Heartbeat storage must use one deterministic private JSON file.');
    $raw = file_get_contents($files[0]);
    $assert(is_string($raw)
        && !preg_match('/token|password|secret|dsn|user_id|username/i', $raw),
        'Heartbeat payload must not contain secrets or user data.');

    $cron = file_get_contents($root . '/bot/cron/weekly-match.php');
    $audit = file_get_contents($root . '/bot/staging-routing-audit.php');
    $assert(is_string($cron)
        && str_contains($cron, "require_once dirname(__DIR__) . '/helpers/StagingCronHeartbeat.php'")
        && str_contains($cron, 'StagingCronHeartbeat::recordSuccessfulRun($config, $isCli)'),
        'The real weekly cron must record the heartbeat.');
    $assert(strpos($cron, 'StagingCronHeartbeat::recordSuccessfulRun') > strpos($cron, '$db->transaction'),
        'Heartbeat proof must be written only after the cron business transaction succeeds.');
    $assert(is_string($audit)
        && str_contains($audit, 'StagingCronHeartbeat::status($config)')
        && str_contains($audit, "'cron_successful_run_observed'")
        && str_contains($audit, "'cron_heartbeat_fresh'"),
        'The public staging audit must require safe live heartbeat proof.');
    $assert(!str_contains($audit, "\$config['setup_secret'] ??")
        && !str_contains($audit, 'filePath('),
        'The public audit must not expose the cron secret or private heartbeat path.');
} finally {
    $cleanup($tempDir);
}

fwrite(STDOUT, "ProductionMvp14R13StagingCronHeartbeatTest: {$assertions} assertions passed\n");
