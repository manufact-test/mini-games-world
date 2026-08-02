<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$cli = $projectRoot . '/ops/runtime/inspect-mvp14r1-recovery-readiness.php';
$source = file_get_contents($cli);
if (!is_string($source)) {
    throw new RuntimeException('MVP-14R.1 readiness inspector source is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

foreach ([
    'INSPECT_READ_ONLY_MVP14R1_RECOVERY_READINESS',
    'MGW_MVP14R1_STATIC_READINESS=',
    'DATABASE_CONTACTED=false',
    'DATABASE_WRITE_EXECUTED=false',
    'DB_TO_JSON_EXPORT_EXECUTED=false',
    'LIVE_JSON_CHANGED=false',
    'PERSISTENT_CONFIG_CHANGED=false',
    'WEBHOOK_CHANGED=false',
    'CRON_CHANGED=false',
    'PRODUCTION_CHANGED=false',
] as $required) {
    $assertTrue(
        str_contains($source, $required),
        'Readiness inspector is missing required fail-safe marker: ' . $required
    );
}
foreach ([
    'PdoConnectionFactory',
    'new PDO',
    'mysqli',
    'mysql ',
    'mariadb ',
    'file_put_contents(',
    'mkdir(',
    'rename(',
    'unlink(',
    'exec(',
    'shell_exec(',
    'passthru(',
    'system(',
] as $forbidden) {
    $assertTrue(
        !str_contains($source, $forbidden),
        'Readiness inspector must not contact databases, execute commands or mutate files: ' . $forbidden
    );
}

$root = sys_get_temp_dir() . '/mgw-mvp14r1-readiness-' . bin2hex(random_bytes(8));
$fixtureProject = $root . '/public_html';
$fixturePrivate = $root . '/_private_mgw';
$checkpointId = 'MGW_SAFETY_CHECKPOINT_TEST_MVP14R1_123456';
$fixtureCheckpoint = $root . '/mgw_checkpoints/' . $checkpointId;
$fixtureOutput = $root . '/mgw_rollback_exports';

$removeDirectory = static function (string $path) use (&$removeDirectory): void {
    if (!is_dir($path)) return;
    $items = scandir($path);
    if (!is_array($items)) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $child = $path . '/' . $item;
        if (is_dir($child) && !is_link($child)) {
            $removeDirectory($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
};

$writePrivate = static function (string $path, string $contents = "fixture\n"): void {
    $bytes = file_put_contents($path, $contents);
    if (!is_int($bytes) || $bytes !== strlen($contents) || !chmod($path, 0600)) {
        throw new RuntimeException('Could not create private fixture file.');
    }
};

$run = static function (array $arguments) use ($cli): array {
    $command = array_merge([PHP_BINARY, $cli], $arguments);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('MVP-14R.1 readiness subprocess could not start.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    return [
        'exit' => $exit,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
};

try {
    foreach ([
        $fixtureProject . '/ops/runtime',
        $fixturePrivate,
        $fixtureCheckpoint,
        $fixtureOutput,
    ] as $directory) {
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create MVP-14R.1 fixture directory.');
        }
        if (!chmod($directory, 0700)) {
            throw new RuntimeException('Could not secure MVP-14R.1 fixture directory.');
        }
    }

    foreach ([
        $fixtureProject . '/ops/runtime/run-production-primary-rollback-export.php',
        $fixtureProject . '/ops/runtime/run-production-primary-live-rollback.php',
        $fixturePrivate . '/config.php',
        $fixturePrivate . '/database.php',
        $fixturePrivate . '/production-cutover.json',
        $fixturePrivate . '/runtime.php',
    ] as $file) {
        $writePrivate($file);
    }
    foreach ([
        'database.sql.gz',
        'public_html.tar.gz',
        'private_mgw.tar.gz',
        'mgw_data.tar.gz',
        'checkpoint-info.txt',
        'checksums.sha256',
        'COMPLETE',
    ] as $file) {
        $writePrivate($fixtureCheckpoint . '/' . $file);
    }

    $arguments = [
        '--project-root=' . $fixtureProject,
        '--private-root=' . $fixturePrivate,
        '--checkpoint-dir=' . $fixtureCheckpoint,
        '--expected-checkpoint-id=' . $checkpointId,
        '--output-root=' . $fixtureOutput,
        '--confirm=INSPECT_READ_ONLY_MVP14R1_RECOVERY_READINESS',
    ];
    $passed = $run($arguments);
    $assertTrue($passed['exit'] === 0, 'Complete readiness fixture must pass.');
    foreach ([
        'MGW_MVP14R1_STATIC_READINESS=PASSED',
        'CHECKPOINT_PRESENT=true',
        'CHECKPOINT_COMPLETE=true',
        'PRIVATE_INPUTS_PRESENT=true',
        'OUTPUT_ROOT_READY=true',
        'EXPORT_CLI_PRESENT=true',
        'TEMPORARY_JSON_CUTOVER_CLI_PRESENT=true',
        'BLOCKER_COUNT=0',
        'DATABASE_CONTACTED=false',
        'DB_TO_JSON_EXPORT_EXECUTED=false',
        'PRODUCTION_CHANGED=false',
    ] as $marker) {
        $assertTrue(
            str_contains($passed['stdout'], $marker),
            'Passing readiness output is missing marker: ' . $marker
        );
    }
    $assertTrue($passed['stderr'] === '', 'Passing readiness inspection must not write stderr.');

    unlink($fixturePrivate . '/production-cutover.json');
    $blocked = $run($arguments);
    $assertTrue($blocked['exit'] === 1, 'Missing private input must block readiness.');
    $assertTrue(
        str_contains($blocked['stdout'], 'MGW_MVP14R1_STATIC_READINESS=BLOCKED')
            && str_contains($blocked['stdout'], 'PRIVATE_INPUTS_PRESENT=false')
            && str_contains($blocked['stdout'], 'PRIVATE_INPUT_FILE_MISSING_OR_UNSAFE_PRODUCTION_CUTOVER_JSON')
            && str_contains($blocked['stdout'], 'DATABASE_CONTACTED=false')
            && str_contains($blocked['stdout'], 'PRODUCTION_CHANGED=false'),
        'Blocked readiness must remain explicit and read-only.'
    );

    $badConfirmation = $run(array_map(
        static fn(string $argument): string => str_starts_with($argument, '--confirm=')
            ? '--confirm=WRONG'
            : $argument,
        $arguments
    ));
    $assertTrue($badConfirmation['exit'] === 2, 'Wrong confirmation must fail with usage status.');
    $assertTrue(
        str_contains($badConfirmation['stderr'], 'confirmation phrase is invalid'),
        'Wrong confirmation must fail before readiness inspection.'
    );
} finally {
    $removeDirectory($root);
}

fwrite(STDOUT, "Mvp14r1RecoveryReadinessInspectorTest passed: {$assertions} assertions.\n");
