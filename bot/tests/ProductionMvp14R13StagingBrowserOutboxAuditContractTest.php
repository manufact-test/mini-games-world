<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/bot/staging-outbox-audit.php');
if (!is_string($source)) {
    throw new RuntimeException('Staging browser outbox audit endpoint is missing.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($source, "['GET', 'HEAD']")
    && str_contains($source, 'http_response_code(405)')
    && str_contains($source, "header('Allow: GET, HEAD')"),
    'The endpoint must be read-only at the HTTP boundary.');

$assert(str_contains($source, "MGW_R13_BROWSER_AUDIT_STAGING_HOST = 'seashell-okapi-889488.hostingersite.com'")
    && str_contains($source, "(\$config['environment'] ?? '') !== 'staging'")
    && str_contains($source, 'hash_equals($productionFingerprint, $stagingFingerprint)'),
    'The endpoint must fail closed outside the exact isolated staging database.');

$assert(str_contains($source, "array_diff(\$allowedQueryKeys, ['quiet'])")
    && str_contains($source, "in_array(\$quietRaw, ['0', '1'], true)"),
    'Only the optional quiet audit mode may be requested.');

$assert(str_contains($source, "SET SESSION TRANSACTION READ ONLY")
    && str_contains($source, "START TRANSACTION READ ONLY")
    && str_contains($source, "execute('ROLLBACK')"),
    'All database access must run inside a read-only transaction.');

foreach ([
    'COUNT(*) AS row_count',
    'COUNT(*) AS completed_rows',
    'OCTET_LENGTH(state_json)',
    'information_schema.tables',
    'table_schema = DATABASE()',
    'completed_retention_bounded',
    'quiet_active_rows_zero',
] as $required) {
    $assert(str_contains($source, $required), 'The browser audit is missing required evidence: ' . $required);
}

$assert(preg_match('/\b(?:INSERT|UPDATE|DELETE|TRUNCATE|CREATE|ALTER|DROP|REPLACE)\b\s+(?:INTO|FROM|TABLE|DATABASE)?/i', $source) !== 1,
    'The endpoint source must not contain mutating SQL.');

$assert(!str_contains($source, "['host']")
    && !str_contains($source, "['name']")
    && !str_contains($source, "['user']")
    && !str_contains($source, "['password']")
    && !str_contains(strtolower($source), 'dsn'),
    'The response path must not expose private database coordinates or credentials.');

$assert(str_contains($source, "'error' => 'audit_unavailable'")
    && str_contains($source, 'http_response_code(404)')
    && !str_contains($source, "'message' => \$error->getMessage()"),
    'Failures must return a generic public response while detailed errors stay in server logs.');

$assert(str_contains($source, "'hostinger_database_limit_mb' => 3072")
    && str_contains($source, 'MGW_R13_BROWSER_AUDIT_COMPLETED_LIMIT = 16')
    && str_contains($source, 'MGW_R13_BROWSER_AUDIT_DATABASE_SAFE_MAX_MB = 512.0')
    && str_contains($source, 'MGW_R13_BROWSER_AUDIT_OUTBOX_SAFE_MAX_MB = 128.0'),
    'The endpoint must enforce the accepted retention and size guardrails.');

fwrite(STDOUT, "ProductionMvp14R13StagingBrowserOutboxAuditContractTest: {$assertions} assertions passed\n");
