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
        && str_contains($source, '--confirm='),
    'Checkpoint script must require all explicit paths, identity and confirmation'
);
$assertTrue(
    str_contains($source, 'CREATE_READ_ONLY_MVP14R_SAFETY_CHECKPOINT')
        && str_contains($source, 'Refusing to overwrite it'),
    'Checkpoint script must be confirmation-gated and non-overwriting'
);
$assertTrue(
    str_contains($source, 'paths_overlap()')
        && str_contains($source, 'outside every archived source tree'),
    'Checkpoint output must be disjoint from all archived source trees'
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

fwrite(STDOUT, "Mvp14rSafetyCheckpointScriptContractTest passed: {$assertions} assertions.\n");
