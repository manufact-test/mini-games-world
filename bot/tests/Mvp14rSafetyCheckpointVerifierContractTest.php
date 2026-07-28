<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$path = $projectRoot . '/ops/runtime/verify-mvp14r-safety-checkpoint.sh';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('MVP-14R checkpoint verifier is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(
    str_contains($source, 'set -euo pipefail')
        && str_contains($source, 'umask 077'),
    'Verifier must use strict shell mode and private temporary permissions'
);
$assertTrue(
    str_contains($source, '--checkpoint-dir=')
        && str_contains($source, '--expected-id=')
        && str_contains($source, 'VERIFY_READ_ONLY_MVP14R_SAFETY_CHECKPOINT'),
    'Verifier must require an exact checkpoint path, identity and confirmation'
);
$invalidNulExpression = '[[ "$value" == *$\'\\0\'* ]]';
$assertTrue(
    str_contains($source, 'if [[ -z "$value" ]]')
        && !str_contains($source, $invalidNulExpression),
    'Verifier argument validation must accept non-empty Bash strings without the invalid NUL expression'
);
$assertTrue(
    str_contains($source, 'sha256sum -c checksums.sha256')
        && str_contains($source, 'gzip -t "$CHECKPOINT_DIR/database.sql.gz"'),
    'Verifier must validate artifact hashes and compressed database integrity'
);
$assertTrue(
    str_contains($source, 'unsafe path')
        && str_contains($source, 'tar -xzf'),
    'Verifier must reject unsafe archive paths before isolated extraction'
);
$assertTrue(
    str_contains($source, 'bot/core/bootstrap.php')
        && str_contains($source, 'app/index.html')
        && str_contains($source, 'config.php')
        && str_contains($source, 'database.php'),
    'Verifier must require restored application and private runtime files'
);
$assertTrue(
    str_contains($source, "find \"\$JSON_RESTORE\" -type f -name '*.json'")
        && str_contains($source, 'JSON_THROW_ON_ERROR'),
    'Verifier must decode every restored JSON file strictly'
);
$assertTrue(
    str_contains($source, 'JSON_FILE_LIST="$TMP_ROOT/json-files.list"')
        && str_contains($source, 'done < "$JSON_FILE_LIST"')
        && !str_contains($source, '< <(')
        && !str_contains($source, '/dev/fd'),
    'Verifier must use a regular temporary file instead of process substitution or /dev/fd'
);
$assertTrue(
    str_contains($source, 'SQL_DUMP_LOOKS_VALID=false')
        && str_contains($source, 'set +o pipefail')
        && str_contains($source, '| grep -Eq')
        && !str_contains($source, 'if ! gzip -cd'),
    'SQL dump marker probe must isolate the expected gzip SIGPIPE from global pipefail handling'
);
$assertTrue(
    str_contains($source, 'PRODUCTION_RUNTIME_CHANGED=false')
        && str_contains($source, 'DATABASE_WRITE_EXECUTED=false')
        && !str_contains($source, 'mysql <')
        && !str_contains($source, 'mariadb <'),
    'Verifier must remain read-only and must not import into a live database'
);

$missingCheckpoint = sys_get_temp_dir() . '/MGW_SAFETY_CHECKPOINT_VERIFIER_CONTRACT_TEST_123456';
$command = 'bash ' . escapeshellarg($path)
    . ' --checkpoint-dir=' . escapeshellarg($missingCheckpoint)
    . ' --expected-id=' . escapeshellarg('MGW_SAFETY_CHECKPOINT_VERIFIER_CONTRACT_TEST_123456')
    . ' --confirm=' . escapeshellarg('VERIFY_READ_ONLY_MVP14R_SAFETY_CHECKPOINT')
    . ' 2>&1';
$output = [];
$exitCode = 0;
exec($command, $output, $exitCode);
$combinedOutput = implode("\n", $output);
$assertTrue(
    $exitCode === 1
        && str_contains($combinedOutput, 'Checkpoint directory is unavailable or is a symlink')
        && !str_contains($combinedOutput, 'Checkpoint verification option value is empty or invalid'),
    'Real verifier parser must accept every non-empty option and fail only at the intentionally missing checkpoint directory'
);

$plainSqlPath = tempnam(sys_get_temp_dir(), 'mgw-sigpipe-sql-');
if (!is_string($plainSqlPath)) {
    throw new RuntimeException('Unable to create SIGPIPE contract fixture.');
}
$gzipSqlPath = $plainSqlPath . '.gz';
$plainSql = fopen($plainSqlPath, 'wb');
if ($plainSql === false) {
    throw new RuntimeException('Unable to open SIGPIPE contract fixture.');
}
fwrite($plainSql, "CREATE TABLE checkpoint_probe (id INT);\n");
$paddingLine = str_repeat('x', 4096) . "\n";
for ($i = 0; $i < 4096; $i++) {
    fwrite($plainSql, $paddingLine);
}
fclose($plainSql);

$gzipCommand = 'gzip -c ' . escapeshellarg($plainSqlPath) . ' > ' . escapeshellarg($gzipSqlPath);
$gzipOutput = [];
$gzipExitCode = 0;
exec($gzipCommand, $gzipOutput, $gzipExitCode);
if ($gzipExitCode !== 0) {
    @unlink($plainSqlPath);
    @unlink($gzipSqlPath);
    throw new RuntimeException('Unable to compress SIGPIPE contract fixture.');
}

$sqlMarkerPattern = '^[[:space:]]*(--|/\*!|CREATE |INSERT |USE |DROP |SET |LOCK |UNLOCK |ALTER )';
$probePipeline = 'gzip -cd ' . escapeshellarg($gzipSqlPath)
    . ' | grep -Eq ' . escapeshellarg($sqlMarkerPattern);

$controlOutput = [];
$controlExitCode = 0;
exec('bash -o pipefail -c ' . escapeshellarg($probePipeline) . ' 2>&1', $controlOutput, $controlExitCode);

$fixedOutput = [];
$fixedExitCode = 0;
exec(
    'bash -o pipefail -c ' . escapeshellarg('( set +o pipefail; ' . $probePipeline . ' )') . ' 2>&1',
    $fixedOutput,
    $fixedExitCode
);

@unlink($plainSqlPath);
@unlink($gzipSqlPath);

$assertTrue(
    $controlExitCode !== 0 && $fixedExitCode === 0,
    'SIGPIPE fixture must fail under global pipefail and pass when the early-exit marker probe isolates pipefail'
);

fwrite(STDOUT, "Mvp14rSafetyCheckpointVerifierContractTest passed: {$assertions} assertions.\n");
