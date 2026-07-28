<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$path = $projectRoot . '/ops/runtime/create-mvp14r-safety-checkpoint.sh';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('MVP-14R checkpoint script is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(
    str_contains($source, 'set -euo pipefail')
        && str_contains($source, 'umask 077'),
    'Checkpoint script must use strict shell mode and private permissions'
);
$assertTrue(
    str_contains($source, '--project-root=')
        && str_contains($source, '--private-root=')
        && str_contains($source, '--json-root=')
        && str_contains($source, '--output-root=')
        && str_contains($source, '--checkpoint-id=')
        && str_contains($source, '--expected-git-commit=')
        && str_contains($source, '--confirm='),
    'Checkpoint script must require all explicit paths, identity, commit and confirmation'
);
$assertTrue(
    str_contains($source, 'CREATE_READ_ONLY_MVP14R_SAFETY_CHECKPOINT')
        && str_contains($source, 'Refusing to overwrite it'),
    'Checkpoint script must be confirmation-gated and non-overwriting'
);
$assertTrue(
    str_contains($source, 'if [[ -z "$value" ]]')
        && !str_contains($source, '$\'\\0\''),
    'Checkpoint argument validation must accept non-empty Bash strings without an impossible NUL pattern'
);
$assertTrue(
    str_contains($source, 'paths_overlap()')
        && str_contains($source, 'outside every archived source tree'),
    'Checkpoint output must be disjoint from all archived source trees'
);
$assertTrue(
    str_contains($source, 'Deployed Git commit does not match')
        && str_contains($source, 'Production checkout is dirty')
        && str_contains($source, 'git -C "$PROJECT_ROOT" rev-parse --verify HEAD')
        && str_contains($source, 'git -C "$PROJECT_ROOT" status --porcelain'),
    'Checkpoint must bind to the exact clean deployed Git commit'
);
$assertTrue(
    str_contains($source, '--single-transaction')
        && str_contains($source, '--skip-lock-tables')
        && str_contains($source, 'DATABASE_WRITE_EXECUTED=false'),
    'Database snapshot must use a consistent read-only dump contract'
);
$assertTrue(
    str_contains($source, 'public_html.tar.gz')
        && str_contains($source, 'private_mgw.tar.gz')
        && str_contains($source, 'mgw_data.tar.gz')
        && str_contains($source, 'database.sql.gz'),
    'Checkpoint must include database, deployment, private and JSON artifacts'
);
$assertTrue(
    str_contains($source, 'sha256sum -c checksums.sha256')
        && str_contains($source, 'touch "$TEMP_DIR/COMPLETE"')
        && str_contains($source, 'mv "$TEMP_DIR" "$FINAL_DIR"'),
    'Checkpoint must verify hashes and publish atomically only after completion'
);
$assertTrue(
    !str_contains($source, 'git checkout')
        && !str_contains($source, 'git reset')
        && !str_contains($source, 'production-cutover.json" >')
        && !str_contains($source, 'runtime.php" >'),
    'Checkpoint script must not switch code or rewrite production runtime controls'
);

$missingRoot = sys_get_temp_dir() . '/mgw-checkpoint-contract-' . bin2hex(random_bytes(6));
$command = 'bash ' . escapeshellarg($path)
    . ' --project-root=' . escapeshellarg($missingRoot . '/public_html')
    . ' --private-root=' . escapeshellarg($missingRoot . '/_private_mgw')
    . ' --json-root=' . escapeshellarg($missingRoot . '/mgw_data')
    . ' --output-root=' . escapeshellarg($missingRoot . '/mgw_checkpoints')
    . ' --checkpoint-id=' . escapeshellarg('MGW_SAFETY_CHECKPOINT_CONTRACT_TEST_123456')
    . ' --expected-git-commit=' . escapeshellarg(str_repeat('a', 40))
    . ' --confirm=' . escapeshellarg('CREATE_READ_ONLY_MVP14R_SAFETY_CHECKPOINT')
    . ' 2>&1';
$output = [];
$exitCode = 0;
exec($command, $output, $exitCode);
$combinedOutput = implode("\n", $output);
$assertTrue(
    $exitCode === 1
        && str_contains($combinedOutput, 'Required checkpoint source directory is unavailable')
        && !str_contains($combinedOutput, 'Checkpoint option value is empty or invalid'),
    'Real parser must accept every non-empty option and fail only at the intentionally missing source directories'
);

fwrite(STDOUT, "Mvp14rSafetyCheckpointScriptContractTest passed: {$assertions} assertions.\n");
