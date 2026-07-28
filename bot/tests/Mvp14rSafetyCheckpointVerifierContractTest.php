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
    str_contains($source, 'PRODUCTION_RUNTIME_CHANGED=false')
        && str_contains($source, 'DATABASE_WRITE_EXECUTED=false')
        && !str_contains($source, 'mysql <')
        && !str_contains($source, 'mariadb <'),
    'Verifier must remain read-only and must not import into a live database'
);

fwrite(STDOUT, "Mvp14rSafetyCheckpointVerifierContractTest passed: {$assertions} assertions.\n");
