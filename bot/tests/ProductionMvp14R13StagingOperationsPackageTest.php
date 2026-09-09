<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$fingerprintScript = file_get_contents($root . '/ops/runtime/compute-secret-sha256.php');
$auditScript = file_get_contents($root . '/ops/runtime/audit-staging-outbox.php');
$runbook = file_get_contents($root . '/docs/MVP14R13_STAGING_OPERATIONS_RUNBOOK.md');

$assert(is_string($fingerprintScript) && is_string($auditScript) && is_string($runbook), 'The complete R13.2 operations package must exist.');
$assert(str_contains($fingerprintScript, "PHP_SAPI !== 'cli'")
    && str_contains($fingerprintScript, '$argc !== 1')
    && str_contains($fingerprintScript, 'stream_get_contents(STDIN)')
    && str_contains($fingerprintScript, "hash('sha256', \$secret)")
    && !str_contains($fingerprintScript, 'getenv('),
    'The fingerprint helper must accept a secret only through CLI STDIN and emit only its SHA-256 fingerprint.');

$assert(str_contains($auditScript, "MGW_R13_STAGING_HOST = 'seashell-okapi-889488.hostingersite.com'")
    && str_contains($auditScript, "(\$config['environment'] ?? '') !== 'staging'")
    && str_contains($auditScript, 'hash_equals($productionFingerprint, $stagingFingerprint)')
    && str_contains($auditScript, "SET SESSION TRANSACTION READ ONLY")
    && str_contains($auditScript, "START TRANSACTION READ ONLY"),
    'The database audit must fail closed on environment/identity and force a read-only transaction.');

foreach ([
    'COUNT(*) AS row_count',
    'COUNT(*) AS completed_rows',
    'OCTET_LENGTH(state_json)',
    'information_schema.tables',
    'table_schema = DATABASE()',
    'completed <= MGW_R13_COMPLETED_LIMIT',
    "--expect-quiet",
] as $required) {
    $assert(str_contains($auditScript, $required), 'The database audit is missing required evidence: ' . $required);
}

$assert(preg_match('/\b(?:INSERT|UPDATE|DELETE|TRUNCATE|CREATE|ALTER|DROP|REPLACE)\b\s+(?:INTO|FROM|TABLE|DATABASE)?/i', $auditScript) !== 1,
    'The staging audit source must not contain mutating SQL.');
$assert(!str_contains($auditScript, "['host']")
    && !str_contains($auditScript, "['name']")
    && !str_contains($auditScript, "['user']")
    && !str_contains($auditScript, "['password']"),
    'The safe audit report must not expose private database coordinates or credentials.');

$assert(str_contains($runbook, 'не отправлять')
    && str_contains($runbook, '--expect-quiet')
    && str_contains($runbook, 'completed')
    && str_contains($runbook, 'production_bot_identity_protected')
    && str_contains($runbook, 'BotFather')
    && str_contains($runbook, 'Cron'),
    'The runbook must cover both isolation gates and remaining staging routing checks.');

fwrite(STDOUT, "ProductionMvp14R13StagingOperationsPackageTest: {$assertions} assertions passed\n");
