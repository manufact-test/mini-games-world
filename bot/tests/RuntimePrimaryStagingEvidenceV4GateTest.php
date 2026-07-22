<?php
declare(strict_types=1);

final class RuntimePrimaryStagingEvidenceV4Verifier
{
    public const MANIFEST_VERSION = 'v4-staging-db-primary-api-lifecycle-evidence';
    public static bool $valid = true;
    public static array $report = [];
    public function __construct(string $projectRoot) {}
    public function verify(array $manifest): array
    {
        if (!self::$valid) {
            return ['ok' => false, 'blockers' => ['forced v4 failure']];
        }
        return self::$report + [
            'ok' => true,
            'evidence_fingerprint' => str_repeat('a', 64),
            'repository_commit' => str_repeat('b', 40),
            'database_identity_fingerprint' => str_repeat('c', 64),
            'selector_evidence_fingerprint' => str_repeat('d', 64),
            'request_session_evidence_fingerprint' => str_repeat('e', 64),
            'projected_modules' => ['balances', 'matches'],
            'lifecycle_proof_count' => 11,
            'api_only' => true,
            'webhook_allowed' => false,
            'production_allowed' => false,
        ];
    }
}
final class RuntimePrimaryStagingEvidenceV3Gate
{
    public static bool $valid = true;
    public function __construct(string $projectRoot) {}
    public function verify(array $manifest): array
    {
        return self::$valid
            ? ['ok' => true]
            : ['ok' => false, 'blockers' => ['forced selector gate failure']];
    }
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV4Gate.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$gate = new RuntimePrimaryStagingEvidenceV4Gate($projectRoot);
$manifest = [
    'generated_at_utc' => '2026-07-20T15:00:00+00:00',
    'selector_evidence' => ['manifest_version' => 'v3'],
];
$valid = $gate->verify($manifest);
$assertTrue(($valid['ok'] ?? false) === true, 'Valid lifecycle evidence must pass');
$assertTrue(($valid['action'] ?? '') === 'api_lifecycle_evidence_v4_verified', 'Valid lifecycle evidence must expose action');
$assertTrue(($valid['generated_at_utc'] ?? '') === $manifest['generated_at_utc'], 'Gate must preserve exact generated timestamp');
$assertTrue(($valid['api_only'] ?? false) === true, 'Lifecycle gate must remain API-only');
$assertTrue(($valid['webhook_allowed'] ?? true) === false, 'Lifecycle gate must block webhook');
$assertTrue(($valid['production_allowed'] ?? true) === false, 'Lifecycle gate must block production');
$assertTrue(($valid['lifecycle_proof_count'] ?? 0) === 11, 'Lifecycle gate must expose proof count');

$zulu = $manifest;
$zulu['generated_at_utc'] = '2026-07-20T15:00:00Z';
$assertTrue(($gate->verify($zulu)['ok'] ?? false) === true, 'Exact UTC Z timestamp must pass');

foreach ([
    123,
    '',
    ' 2026-07-20T15:00:00+00:00',
    '2026-07-20T15:00:00+00:00 ',
    '2026-07-20T17:00:00+02:00',
    '2026-02-30T15:00:00+00:00',
    '2026-07-20T15:00+00:00',
] as $badTimestamp) {
    $changed = $manifest;
    $changed['generated_at_utc'] = $badTimestamp;
    $blocked = $gate->verify($changed);
    $assertTrue(($blocked['ok'] ?? true) === false, 'Malformed generated timestamp must block lifecycle gate');
    $assertTrue(($blocked['action'] ?? '') === 'api_lifecycle_evidence_v4_blocked', 'Malformed timestamp must expose blocked action');
}

RuntimePrimaryStagingEvidenceV4Verifier::$valid = false;
$blockedV4 = $gate->verify($manifest);
$assertTrue(($blockedV4['ok'] ?? true) === false, 'Invalid v4 evidence must be blocked');
$assertTrue(($blockedV4['blockers'][0] ?? '') === 'forced v4 failure', 'V4 blockers must propagate');
$assertTrue(($blockedV4['session_enabled_by_evidence'] ?? true) === false, 'Invalid evidence must not enable session');
RuntimePrimaryStagingEvidenceV4Verifier::$valid = true;

RuntimePrimaryStagingEvidenceV3Gate::$valid = false;
$blockedSelector = $gate->verify($manifest);
$assertTrue(($blockedSelector['ok'] ?? true) === false, 'Invalid nested selector evidence must be blocked');
$assertTrue(($blockedSelector['blockers'][0] ?? '') === 'forced selector gate failure', 'Nested selector blockers must propagate');
$assertTrue(($blockedSelector['finalizer_registered_by_evidence'] ?? true) === false, 'Invalid evidence must not register finalizer');
RuntimePrimaryStagingEvidenceV3Gate::$valid = true;

foreach ([
    'evidence_fingerprint' => strtoupper(str_repeat('a', 64)),
    'database_identity_fingerprint' => 123,
    'selector_evidence_fingerprint' => ' ' . str_repeat('d', 64),
    'request_session_evidence_fingerprint' => str_repeat('e', 63),
    'repository_commit' => strtoupper(str_repeat('b', 40)),
] as $field => $badValue) {
    RuntimePrimaryStagingEvidenceV4Verifier::$report = [$field => $badValue];
    $blocked = $gate->verify($manifest);
    $assertTrue(($blocked['ok'] ?? true) === false, 'Malformed verified identity must block: ' . $field);
}
RuntimePrimaryStagingEvidenceV4Verifier::$report = [];

foreach ([
    ['api_only' => false],
    ['webhook_allowed' => true],
    ['production_allowed' => true],
    ['api_only' => 'true'],
] as $unsafeReport) {
    RuntimePrimaryStagingEvidenceV4Verifier::$report = $unsafeReport;
    $blocked = $gate->verify($manifest);
    $assertTrue(($blocked['ok'] ?? true) === false, 'Unsafe verified API-only fields must block lifecycle gate');
}
RuntimePrimaryStagingEvidenceV4Verifier::$report = [];

fwrite(STDOUT, "RuntimePrimaryStagingEvidenceV4GateTest passed: {$assertions} assertions.\n");
