<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/.github/workflows/staging-e2e-route-probe.yml';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('Staging E2E runner route workflow is missing.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($source, 'runs-on: ubuntu-latest')
    && str_contains($source, 'timeout-minutes: 6'),
    'The route probe must use one bounded ephemeral GitHub-hosted runner.');

$assert(str_contains($source, 'https://seashell-okapi-889488.hostingersite.com')
    && !str_contains($source, 'lemonchiffon-gerbil-545102.hostingersite.com')
    && !str_contains($source, 'mini-games-world.com'),
    'The route probe must target only the exact staging origin.');

foreach ([
    '/bot/staging-readiness.php',
    '/bot/staging-routing-audit.php',
    '/app/',
    '/bot/staging-test-auth.php',
] as $route) {
    $assert(str_contains($source, $route), 'Missing staging route probe: ' . $route);
}

$assert(str_contains($source, "test \"\$auth_status\" = '405'")
    && str_contains($source, "auth.get('error') == 'method_not_allowed'"),
    'The protected auth endpoint must be reached only through its non-mutating GET rejection.');

$assert(!str_contains($source, 'secrets.')
    && !str_contains($source, 'Authorization:')
    && !str_contains($source, '--request POST')
    && !str_contains($source, '-X POST')
    && !str_contains($source, 'setup_secret'),
    'The route probe must not read credentials or issue mutating requests.');

$assert(str_contains($source, '--connect-timeout 12')
    && str_contains($source, '--max-time 35')
    && str_contains($source, '--retry-all-errors'),
    'Every public route probe must be bounded and retry transient network failures.');

$assert(str_contains($source, "'secrets_used': False")
    && str_contains($source, "'state_changed': False"),
    'The safe report must explicitly declare that no secret or state change was involved.');

$artifactMarker = "      - name: Upload safe route report\n";
$artifactOffset = strpos($source, $artifactMarker);
$artifactSection = $artifactOffset === false ? '' : substr($source, $artifactOffset);
$assert($artifactSection !== ''
    && str_contains($artifactSection, 'actions/upload-artifact@v4')
    && str_contains($artifactSection, 'route-probe/safe-report.json')
    && str_contains($artifactSection, 'route-probe/auth-get-status.txt')
    && !str_contains($artifactSection, 'route-probe/readiness.json')
    && !str_contains($artifactSection, 'route-probe/routing.json')
    && !str_contains($artifactSection, 'route-probe/app.html'),
    'Artifacts must contain only the reduced safe report, DNS evidence and status code.');

fwrite(STDOUT, "ProductionMvp14R13StagingE2eRunnerRouteProbeTest: {$assertions} assertions passed\n");
