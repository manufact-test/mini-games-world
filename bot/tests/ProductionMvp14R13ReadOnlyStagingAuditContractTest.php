<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read R13.1 audit source: ' . $path);
    return $content;
};

$probe = $read('scripts/audit/mvp14r13-staging-public-probe.sh');
$runner = $read('scripts/ci/run.sh');
$document = $read('docs/MVP14R13_STAGING_READONLY_AUDIT.md');
$evidence = $read('docs/MVP14R13_STAGING_READONLY_AUDIT_EVIDENCE.md');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($probe, "STAGING_BASE='https://seashell-okapi-889488.hostingersite.com'")
        && str_contains($probe, "PRODUCTION_BASE='https://lemonchiffon-gerbil-545102.hostingersite.com'"),
    'The public audit must be pinned to the two documented Hostinger projects.'
);

$assert(
    !preg_match('/(?:^|\s)(?:-X|--request)(?:\s|=)/m', $probe)
        && !preg_match('/(?:^|\s)(?:-d|--data|--data-raw|--data-binary)(?:\s|=)/m', $probe)
        && !preg_match('/(?:^|\s)(?:-b|--cookie|--cookie-jar)(?:\s|=)/m', $probe)
        && !preg_match('/(?:^|\s)(?:-H|--header)\s+[\'\"]?(?:authorization|cookie)\s*:/im', $probe)
        && !str_contains(strtolower($probe), 'bearer '),
    'The audit must not send method overrides, request bodies, authentication or cookies.'
);

$assert(
    !str_contains($probe, '/bot/api.php')
        && !str_contains($probe, '/bot/invites.php')
        && !str_contains($probe, '/bot/notifications.php')
        && !str_contains($probe, '/bot/webhook.php')
        && !str_contains($probe, '/bot/cron/')
        && !str_contains($probe, 'action=bootstrap')
        && !str_contains($probe, 'action=match_'),
    'The audit may not call mutating application, invite, webhook, Cron or match endpoints.'
);

$assert(
    substr_count($probe, '/bot/health.php') === 2
        && substr_count($probe, '/app/runtime/api.php?action=health') === 2
        && str_contains($probe, '/app/v110.php?v=1123')
        && str_contains($probe, '/app/runtime/index.php'),
    'The audit must stay limited to public health and entrypoint evidence.'
);

$assert(
    str_contains($probe, 'TOTAL_PROBES=9')
        && str_contains($probe, 'if (( NETWORK_FAILURES == TOTAL_PROBES )); then')
        && str_contains($probe, 'audit_result: external_network_blocked')
        && str_contains($probe, 'if (( NETWORK_FAILURES > 0 )); then')
        && str_contains($probe, 'audit_result: partial_network_failure'),
    'A complete two-host runner block must be recorded as evidence while a partial failure remains a hard failure.'
);

$assert(
    str_contains($runner, "if [[ \"\${GITHUB_HEAD_REF:-}\" == 'agent/mvp14r13-staging-readonly-audit' ]]; then")
        && str_contains($runner, 'bash scripts/audit/mvp14r13-staging-public-probe.sh')
        && substr_count($runner, 'mvp14r13-staging-public-probe.sh') === 1,
    'The external probe must execute only on the exact read-only audit branch.'
);

$assert(
    str_contains($document, '591139b71d3042646f725b9f34f3638124faa578')
        && str_contains($document, '6e6bbcf7da3bfd5e517695e150d45f451a94b9e0')
        && str_contains($document, '1270 commits ahead and 0 behind')
        && str_contains($document, 'non-forced fast-forward'),
    'The audit document must bind the exact production/staging Git topology and synchronization method.'
);

$assert(
    str_contains($document, 'MGW_CONFIG_FILE')
        && str_contains($document, 'MGW_DATABASE_CONFIG_FILE')
        && str_contains($document, '_private_mgw/runtime_staging')
        && str_contains($document, 'Production must receive a fail-closed guard'),
    'The audit must map private config/data boundaries and the production test-auth risk.'
);

$assert(
    str_contains($document, '## 9. Exact synchronization plan for R13.2')
        && str_contains($document, '## 10. Rollback plan')
        && str_contains($document, 'No webhook registration or Cron schedule is changed during R13.1.')
        && str_contains($document, 'Status: **COMPLETE WITH EXTERNAL CONNECTIVITY BLOCKER**'),
    'The audit must include exact synchronization, rollback, external-infrastructure boundaries and a final status.'
);

$assert(
    str_contains($evidence, 'Mini Games World CI #1328')
        && str_contains($evidence, '930e15ed69322e1e3bc2025ac461c12fd1d3dcfe')
        && str_contains($evidence, 'Every request failed before an HTTP response')
        && str_contains($evidence, 'GitHub-hosted runner used by CI could not establish an HTTPS connection'),
    'The exact failed public probe and its bounded interpretation must be preserved as audit evidence.'
);

fwrite(STDOUT, "ProductionMvp14R13ReadOnlyStagingAuditContractTest: {$assertions} assertions passed\n");
