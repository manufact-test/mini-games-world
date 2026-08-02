<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $relative) use ($root): string {
    $source = file_get_contents($root . '/' . $relative);
    if (!is_string($source)) throw new RuntimeException('Missing Playwright foundation source: ' . $relative);
    return $source;
};

$workflow = $read('.github/workflows/staging-playwright-e2e.yml');
$config = $read('e2e/playwright.config.mjs');
$spec = $read('e2e/staging/two-context.spec.mjs');
$readiness = $read('bot/staging-e2e-readiness.php');
$package = $read('package.json');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

foreach (['e2e/playwright.config.mjs', 'e2e/staging/two-context.spec.mjs'] as $relative) {
    $command = 'node --check ' . escapeshellarg($root . '/' . $relative) . ' 2>&1';
    exec($command, $output, $status);
    $assert($status === 0, $relative . ' must pass Node syntax validation: ' . implode("\n", $output));
}

$decodedPackage = json_decode($package, true, 32, JSON_THROW_ON_ERROR);
$assert(($decodedPackage['private'] ?? false) === true
    && ($decodedPackage['type'] ?? null) === 'module'
    && ($decodedPackage['scripts']['test:e2e:staging'] ?? null) === 'playwright test --config=e2e/playwright.config.mjs',
    'Package entrypoint must remain private, ESM and bound to the staging Playwright config.');

$assert(str_contains($workflow, "branches:\n      - agent/mvp-13-2-staging")
    && !str_contains($workflow, 'workflow_dispatch:')
    && str_contains($workflow, 'id-token: write')
    && str_contains($workflow, 'contents: read')
    && str_contains($workflow, 'statuses: write'),
    'The live browser workflow must run only on staging pushes with minimal OIDC and status permissions.');

$assert(str_contains($workflow, 'runs-on: ubuntu-latest')
    && str_contains($workflow, 'timeout-minutes: 18')
    && str_contains($workflow, 'sleep 300')
    && str_contains($workflow, '/bot/staging-e2e-readiness.php'),
    'The workflow must use a bounded ephemeral runner and wait for Hostinger deployment evidence.');

$assert(str_contains($workflow, '@playwright/test@1.62.0')
    && str_contains($workflow, 'playwright install --with-deps chromium')
    && str_contains($workflow, 'npm run test:e2e:staging'),
    'The workflow must install one pinned Playwright version and Chromium before the exact test command.');

$assert(!str_contains($workflow, 'secrets.')
    && !str_contains($workflow, 'setup_secret')
    && !str_contains($workflow, 'lemonchiffon-gerbil-545102.hostingersite.com')
    && !str_contains($workflow, 'mini-games-world.com'),
    'The Playwright workflow must require no long-lived secret and must never target production.');

$assert(str_contains($workflow, 'actions/upload-artifact@v4')
    && str_contains($workflow, 'artifacts/playwright')
    && str_contains($workflow, 'retention-days: 7')
    && str_contains($config, "const outputRoot = '../artifacts/playwright'")
    && !str_contains($config, "const outputRoot = 'artifacts/playwright'"),
    'Playwright evidence must resolve to the repository-root directory uploaded by the workflow.');

$assert(str_contains($workflow, 'Publish pending commit status')
    && str_contains($workflow, 'Publish final commit status')
    && str_contains($workflow, '--arg context staging-playwright-e2e')
    && str_contains($workflow, '--arg state pending')
    && str_contains($workflow, "state='success'")
    && str_contains($workflow, "state='failure'")
    && str_contains($workflow, "state='error'")
    && str_contains($workflow, '/statuses/${{ github.sha }}')
    && str_contains($workflow, 'if: always()'),
    'The push workflow must publish one readable pending and final commit status for autonomous monitoring.');

$assert(str_contains($workflow, 'GITHUB_TOKEN: ${{ github.token }}')
    && str_contains($workflow, 'RUN_URL: ${{ github.server_url }}/${{ github.repository }}/actions/runs/${{ github.run_id }}')
    && !str_contains($workflow, 'echo "${GITHUB_TOKEN}"')
    && !str_contains($workflow, 'set -x'),
    'Commit status publication must use only the ephemeral GitHub token without logging it.');

$assert(str_contains($config, 'fullyParallel: false')
    && str_contains($config, 'workers: 1')
    && str_contains($config, "trace: 'retain-on-failure'")
    && str_contains($config, "video: 'retain-on-failure'")
    && str_contains($config, "screenshot: 'only-on-failure'"),
    'Playwright config must remain deterministic and preserve failure evidence.');

$assert(str_contains($spec, 'ACTIONS_ID_TOKEN_REQUEST_URL')
    && str_contains($spec, 'ACTIONS_ID_TOKEN_REQUEST_TOKEN')
    && str_contains($spec, "url.searchParams.set('audience', OIDC_AUDIENCE)")
    && str_contains($spec, "authorization_mode: 'github_actions_oidc'"),
    'The test must request short-lived GitHub OIDC credentials instead of reading a stored secret.');

$preflightOffset = strpos($spec, 'const preflight = await preflightProfile(context, slot, identity)');
$pageOffset = strpos($spec, 'const page = await context.newPage()');
$assert(str_contains($spec, 'async function preflightProfile(')
    && str_contains($spec, "safeApiDiagnostic(slot, 'server_profile_preflight'")
    && $preflightOffset !== false
    && $pageOffset !== false
    && $preflightOffset < $pageOffset,
    'Each test player must prove server authentication before opening the application UI.');

$assert(str_contains($spec, 'await context.addInitScript(')
    && str_contains($spec, 'localStorage.setItem(sessionKey, sessionId)')
    && str_contains($spec, 'localStorage.setItem(deviceKey, deviceId)')
    && str_contains($spec, 'window.__MGW_E2E_APP_READY__ = false')
    && str_contains($spec, "document.addEventListener('mgw:app-ready'"),
    'The browser must boot with the exact preflight session/device identity and retain an app-ready signal.');

$assert(str_contains($spec, 'async function waitForApplicationBoot(')
    && str_contains($spec, "document.getElementById('bootFailureBanner')")
    && str_contains($spec, "phase: 'application_boot'"),
    'The test must distinguish successful application boot from the visible boot-failure state.');

$assert(str_contains($spec, "openPlayer(browser, 'A', testInfo)")
    && str_contains($spec, "openPlayer(browser, 'B', testInfo)")
    && str_contains($spec, 'browser.newContext')
    && str_contains($spec, "const SESSION_KEY = 'mgw_device_session_id'")
    && str_contains($spec, "const DEVICE_KEY = 'mgw_device_id'"),
    'TEST PLAYER A and B must use independent browser contexts, sessions and devices.');

$assert(str_contains($spec, "toBe('stg_test_player_a')")
    && str_contains($spec, "toBe('stg_test_player_b')")
    && str_contains($spec, 'sessionsDistinct: true')
    && str_contains($spec, 'devicesDistinct: true')
    && str_contains($spec, 'cookiesDistinct: true'),
    'The live test must prove distinct accounts, cookies, sessions and devices.');

$assert(str_contains($spec, "sessionId: 'sess_replay_context'")
    && str_contains($spec, 'copiedCookieReplayRejected: true')
    && str_contains($spec, 'expect(replay.status()).toBeGreaterThanOrEqual(400)'),
    'The live browser suite must prove copied-cookie replay is rejected.');

$assert(str_contains($spec, 'page.screenshot')
    && str_contains($spec, "testInfo.attach('staging-two-context-report'")
    && !str_contains($spec, 'oidcToken,')
    && !str_contains($spec, 'cookie.value,'),
    'The test must preserve visual and safe diagnostic evidence without attaching credentials.');

$assert(str_contains($readiness, "'build' => 'mgw-staging-playwright-r13.4-v1'")
    && str_contains($readiness, "['GET', 'HEAD']")
    && str_contains($readiness, "if (\$_GET !== [])")
    && str_contains($readiness, "'live_payments_disabled' => true")
    && !str_contains($readiness, "\$config['setup_secret']"),
    'Public E2E readiness must be read-only, isolated and free of credentials.');

fwrite(STDOUT, "ProductionMvp14R13StagingPlaywrightFoundationTest: {$assertions} assertions passed\n");
