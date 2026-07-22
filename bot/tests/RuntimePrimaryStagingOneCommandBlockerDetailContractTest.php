<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$launcher = file_get_contents($projectRoot . '/r');
$runner = file_get_contents($projectRoot . '/ops/runtime/run-staging-read-only-checkpoint.sh');
if (!is_string($launcher) || !is_string($runner)) {
    throw new RuntimeException('One-command blocker detail sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

foreach ([
    'staging-read-only-preflight-*.json',
    'staging-lifecycle-collector-*.json',
    'staging-api-read-only-smoke-*.json',
    'staging-api-read-only-smoke-verification-*.json',
] as $pattern) {
    $assertTrue(
        str_contains($launcher, $pattern),
        'Launcher must inspect the bounded private report pattern: ' . $pattern
    );
}

foreach ([
    'staging_read_only_prerequisites_blocked_or_failed',
    'api_lifecycle_evidence_v4_blocked_or_failed',
    'staging_api_read_only_smoke_blocked_or_failed',
    'staging_api_read_only_smoke_evidence_verification_failed',
] as $action) {
    $assertTrue(
        str_contains($launcher, '"' . $action . '"'),
        'Launcher must recognize only the explicit safe blocker action: ' . $action
    );
}

foreach ([
    '($data["path_exposed"] ?? null) !== false',
    '($data["session_enabled_by_evidence"] ?? null) !== false',
    '($data["finalizer_registered_by_evidence"] ?? null) !== false',
    '($data["persistent_config_changed"] ?? null) !== false',
    '($data["http_route_added"] ?? null) !== false',
    '($data["api_only"] ?? null) !== true',
    '($data["webhook_allowed"] ?? null) !== false',
    '($data["live_database_contacted"] ?? null) !== false',
    '($data["private_config_required"] ?? null) !== false',
    '($data["application_entrypoints_changed"] ?? null) !== false',
    '($data["deployment_performed"] ?? null) !== false',
    '($data["cron_changed"] ?? null) !== false',
    '($data["production_changed"] ?? null) !== false',
    '($data["sensitive_identifiers_exposed"] ?? null) !== false',
] as $guard) {
    $assertTrue(
        str_contains($launcher, $guard),
        'Launcher must fail closed before displaying blocker detail: ' . $guard
    );
}

$assertTrue(
    str_contains($launcher, 'strlen($message) > 500')
        && str_contains($launcher, 'echo "DETAIL="')
        && !str_contains($launcher, 'cat "$LATEST_REPORT"')
        && !str_contains($launcher, 'print_r(')
        && !str_contains($launcher, 'var_dump('),
    'Launcher may print only one bounded sanitized error_message field'
);

$assertTrue(
    str_contains($runner, 'smoke_exit=0')
        && str_contains($runner, 'chmod 0600 "$REPORT_FILE"')
        && str_contains($runner, '[[ "$smoke_exit" -eq 0 ]]')
        && str_contains($runner, 'verification_exit=0')
        && str_contains($runner, 'chmod 0600 "$VERIFICATION_FILE"')
        && str_contains($runner, '[[ "$verification_exit" -eq 0 ]]'),
    'Runner must secure smoke and verification reports before propagating their failures'
);

fwrite(
    STDOUT,
    "RuntimePrimaryStagingOneCommandBlockerDetailContractTest passed: {$assertions} assertions.\n"
);
