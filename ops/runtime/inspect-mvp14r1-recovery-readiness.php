<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80400) {
    fwrite(STDERR, "MVP-14R.1 readiness inspection requires PHP 8.3.x.\n");
    exit(2);
}

umask(0077);

const MGW_MVP14R1_READINESS_CONFIRMATION = 'INSPECT_READ_ONLY_MVP14R1_RECOVERY_READINESS';

$options = [
    'project-root' => null,
    'private-root' => null,
    'checkpoint-dir' => null,
    'expected-checkpoint-id' => null,
    'output-root' => null,
    'confirm' => null,
];

$failUsage = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(2);
};

foreach (array_slice($argv ?? [], 1) as $argument) {
    if (!is_string($argument)
        || preg_match('/\A--([a-z-]+)=(.*)\z/s', $argument, $matches) !== 1) {
        $failUsage('MVP-14R.1 readiness options must use --name=value syntax.');
    }
    $name = $matches[1];
    $value = $matches[2];
    if (!array_key_exists($name, $options)) {
        $failUsage('Unknown MVP-14R.1 readiness option.');
    }
    if ($options[$name] !== null) {
        $failUsage('MVP-14R.1 readiness option may be specified only once.');
    }
    if ($value === '' || str_contains($value, "\0") || str_contains($value, '\\')) {
        $failUsage('MVP-14R.1 readiness option value is empty or invalid.');
    }
    $options[$name] = $value;
}

foreach ($options as $name => $value) {
    if (!is_string($value) || $value === '') {
        $failUsage('Missing required MVP-14R.1 readiness option: ' . $name . '.');
    }
}

if (!hash_equals(MGW_MVP14R1_READINESS_CONFIRMATION, $options['confirm'])) {
    $failUsage('MVP-14R.1 readiness confirmation phrase is invalid.');
}
if (preg_match('/\AMGW_SAFETY_CHECKPOINT_[A-Z0-9_-]{10,120}\z/', $options['expected-checkpoint-id']) !== 1) {
    $failUsage('MVP-14R.1 expected checkpoint ID is invalid.');
}

$exactAbsolutePath = static function (string $path, string $label): string {
    if (!str_starts_with($path, '/') || ($path !== '/' && str_ends_with($path, '/'))) {
        throw new RuntimeException($label . ' must be an exact absolute Linux path.');
    }
    return $path;
};

$canonicalDirectory = static function (string $path, string $label) use ($exactAbsolutePath): string {
    $path = $exactAbsolutePath($path, $label);
    if (is_link($path) || !is_dir($path)) {
        throw new RuntimeException($label . ' is unavailable or is a symlink.');
    }
    $canonical = realpath($path);
    if (!is_string($canonical) || !hash_equals($path, $canonical)) {
        throw new RuntimeException($label . ' is not canonical.');
    }
    return $canonical;
};

$outsideProject = static function (string $path, string $projectRoot, string $label): void {
    if ($path === $projectRoot || str_starts_with($path . '/', $projectRoot . '/')) {
        throw new RuntimeException($label . ' must remain outside the deployed project.');
    }
};

$mode = static function (string $path): int {
    clearstatcache(true, $path);
    $permissions = fileperms($path);
    if (!is_int($permissions)) {
        throw new RuntimeException('Filesystem permissions are unavailable.');
    }
    return $permissions & 0777;
};

$blockers = [];
$checks = [
    'checkpoint_present' => false,
    'checkpoint_complete' => false,
    'private_inputs_present' => false,
    'output_root_ready' => false,
    'export_cli_present' => false,
    'temporary_json_cutover_cli_present' => false,
];

try {
    $projectRoot = $canonicalDirectory($options['project-root'], 'Production project root');
    $privateRoot = $canonicalDirectory($options['private-root'], 'Production private root');
    $checkpointDir = $canonicalDirectory($options['checkpoint-dir'], 'MVP-14R.0 checkpoint directory');
    $outputRoot = $canonicalDirectory($options['output-root'], 'MVP-14R.1 rollback export root');

    $outsideProject($privateRoot, $projectRoot, 'Production private root');
    $outsideProject($checkpointDir, $projectRoot, 'MVP-14R.0 checkpoint directory');
    $outsideProject($outputRoot, $projectRoot, 'MVP-14R.1 rollback export root');

    if (($mode($privateRoot) & 0022) !== 0) {
        $blockers[] = 'PRIVATE_ROOT_GROUP_OR_WORLD_WRITABLE';
    }
    if (($mode($checkpointDir) & 0022) !== 0) {
        $blockers[] = 'CHECKPOINT_DIR_GROUP_OR_WORLD_WRITABLE';
    }
    if ($mode($outputRoot) !== 0700) {
        $blockers[] = 'OUTPUT_ROOT_MODE_NOT_0700';
    } else {
        $checks['output_root_ready'] = true;
    }
    if ($outputRoot === $privateRoot || $outputRoot === $checkpointDir) {
        $blockers[] = 'OUTPUT_ROOT_NOT_DEDICATED';
        $checks['output_root_ready'] = false;
    }

    if (basename($checkpointDir) !== $options['expected-checkpoint-id']) {
        $blockers[] = 'CHECKPOINT_ID_MISMATCH';
    }

    $checkpointFiles = [
        'database.sql.gz',
        'public_html.tar.gz',
        'private_mgw.tar.gz',
        'mgw_data.tar.gz',
        'checkpoint-info.txt',
        'checksums.sha256',
        'COMPLETE',
    ];
    $checkpointOk = true;
    foreach ($checkpointFiles as $file) {
        $path = $checkpointDir . '/' . $file;
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            $checkpointOk = false;
            $blockers[] = 'CHECKPOINT_FILE_MISSING_OR_UNSAFE_' . strtoupper(str_replace(['.', '-'], '_', $file));
        }
    }
    $checks['checkpoint_present'] = $checkpointOk;
    $checks['checkpoint_complete'] = $checkpointOk && is_file($checkpointDir . '/COMPLETE');

    $privateFiles = ['config.php', 'database.php', 'production-cutover.json'];
    $privateOk = true;
    foreach ($privateFiles as $file) {
        $path = $privateRoot . '/' . $file;
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            $privateOk = false;
            $blockers[] = 'PRIVATE_INPUT_FILE_MISSING_OR_UNSAFE_' . strtoupper(str_replace(['.', '-'], '_', $file));
            continue;
        }
        if ($mode($path) !== 0600) {
            $privateOk = false;
            $blockers[] = 'PRIVATE_INPUT_MODE_NOT_0600_' . strtoupper(str_replace(['.', '-'], '_', $file));
        }
    }
    $runtimePath = $privateRoot . '/runtime.php';
    if (file_exists($runtimePath) || is_link($runtimePath)) {
        if (is_link($runtimePath) || !is_file($runtimePath) || !is_readable($runtimePath)) {
            $privateOk = false;
            $blockers[] = 'RUNTIME_OVERLAY_UNSAFE';
        } elseif ($mode($runtimePath) !== 0600) {
            $privateOk = false;
            $blockers[] = 'RUNTIME_OVERLAY_MODE_NOT_0600';
        }
    }
    $checks['private_inputs_present'] = $privateOk;

    $exportCli = $projectRoot . '/ops/runtime/run-production-primary-rollback-export.php';
    $liveRollbackCli = $projectRoot . '/ops/runtime/run-production-primary-live-rollback.php';
    $checks['export_cli_present'] = !is_link($exportCli) && is_file($exportCli) && is_readable($exportCli);
    $checks['temporary_json_cutover_cli_present'] = !is_link($liveRollbackCli)
        && is_file($liveRollbackCli)
        && is_readable($liveRollbackCli);
    if (!$checks['export_cli_present']) {
        $blockers[] = 'EXPORT_CLI_UNAVAILABLE';
    }
    if (!$checks['temporary_json_cutover_cli_present']) {
        $blockers[] = 'TEMPORARY_JSON_CUTOVER_CLI_UNAVAILABLE';
    }
} catch (Throwable $error) {
    $blockers[] = 'READINESS_INPUTS_INVALID_' . substr(hash('sha256', $error->getMessage()), 0, 16);
}

$blockers = array_values(array_unique($blockers));
sort($blockers, SORT_STRING);
$ready = $blockers === [] && !in_array(false, $checks, true);

printf("MGW_MVP14R1_STATIC_READINESS=%s\n", $ready ? 'PASSED' : 'BLOCKED');
foreach ($checks as $name => $passed) {
    printf("%s=%s\n", strtoupper($name), $passed ? 'true' : 'false');
}
printf("BLOCKER_COUNT=%d\n", count($blockers));
foreach ($blockers as $index => $blocker) {
    printf("BLOCKER_%d=%s\n", $index + 1, $blocker);
}
printf("DATABASE_CONTACTED=false\n");
printf("DATABASE_WRITE_EXECUTED=false\n");
printf("DB_TO_JSON_EXPORT_EXECUTED=false\n");
printf("LIVE_JSON_CHANGED=false\n");
printf("PERSISTENT_CONFIG_CHANGED=false\n");
printf("WEBHOOK_CHANGED=false\n");
printf("CRON_CHANGED=false\n");
printf("PRODUCTION_CHANGED=false\n");

exit($ready ? 0 : 1);
