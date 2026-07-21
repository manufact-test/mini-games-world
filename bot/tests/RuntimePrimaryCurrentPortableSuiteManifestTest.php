<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$manifestPath = $projectRoot . '/ops/ci/current-portable-suite-manifest.json';
$raw = file_get_contents($manifestPath);
if (!is_string($raw)) {
    throw new RuntimeException('Current portable suite manifest is unavailable.');
}
$manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($manifest) || array_is_list($manifest)) {
    throw new RuntimeException('Current portable suite manifest must be a JSON object.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertExactKeys = static function (array $value, array $expected, string $label) use ($assertTrue): void {
    $actual = array_keys($value);
    sort($actual, SORT_STRING);
    sort($expected, SORT_STRING);
    $assertTrue($actual === $expected, $label . ' fields are not exact');
};

$assertExactKeys($manifest, [
    'contract_version', 'entrypoint', 'expected_unique_script_count',
    'ordered_roots', 'recursive_chain', 'safety',
], 'Manifest');
$assertTrue(
    ($manifest['contract_version'] ?? '') === 'v2-current-db-primary-focused-suite'
        && ($manifest['entrypoint'] ?? '') === 'ops/checks/db-primary-current-portable-validation-local.sh'
        && ($manifest['expected_unique_script_count'] ?? null) === 14,
    'Manifest identity or exact script count is invalid'
);

$expectedRoots = [
    [
        'script' => 'ops/checks/db-primary-projection-outbox-local.sh',
        'success_marker' => 'DB-primary projection outbox focused verification passed.',
    ],
    [
        'script' => 'ops/checks/db-primary-projection-worker-local.sh',
        'success_marker' => 'DB-primary projection worker focused verification passed.',
    ],
    [
        'script' => 'ops/checks/db-primary-staging-api-read-only-smoke-evidence-verifier-local.sh',
        'success_marker' => 'DB-primary staging API read-only smoke evidence verifier focused verification passed.',
    ],
];
$expectedChain = [
    ['ops/checks/db-primary-staging-api-read-only-smoke-evidence-verifier-local.sh', 'ops/checks/db-primary-staging-api-read-only-smoke-local.sh', 'DB-primary staging API read-only smoke evidence verifier focused verification passed.'],
    ['ops/checks/db-primary-staging-api-read-only-smoke-local.sh', 'ops/checks/db-primary-staging-api-session-integration-local.sh', 'DB-primary staging API read-only smoke focused verification passed.'],
    ['ops/checks/db-primary-staging-api-session-integration-local.sh', 'ops/checks/db-primary-staging-request-finalizer-local.sh', 'DB-primary staging API session integration focused verification passed.'],
    ['ops/checks/db-primary-staging-request-finalizer-local.sh', 'ops/checks/db-primary-staging-entrypoint-selector-local.sh', 'DB-primary staging request finalizer focused verification passed.'],
    ['ops/checks/db-primary-staging-entrypoint-selector-local.sh', 'ops/checks/db-primary-staging-synthetic-suite-local.sh', 'DB-primary staging entrypoint selector focused verification passed.'],
    ['ops/checks/db-primary-staging-synthetic-suite-local.sh', 'ops/checks/db-primary-staging-storage-resolver-local.sh', 'DB-primary staging synthetic suite focused verification passed.'],
    ['ops/checks/db-primary-staging-storage-resolver-local.sh', 'ops/checks/db-primary-staging-activation-local.sh', 'DB-primary staging storage resolver focused verification passed.'],
    ['ops/checks/db-primary-staging-activation-local.sh', 'ops/checks/db-primary-staging-evidence-collector-local.sh', 'DB-primary staging activation focused verification passed.'],
    ['ops/checks/db-primary-staging-evidence-collector-local.sh', 'ops/checks/db-primary-staging-evidence-local.sh', 'DB-primary staging evidence collector focused verification passed.'],
    ['ops/checks/db-primary-staging-evidence-local.sh', 'ops/checks/db-primary-staging-rehearsal-local.sh', 'DB-primary staging evidence focused verification passed.'],
    ['ops/checks/db-primary-staging-rehearsal-local.sh', 'ops/checks/db-primary-all-module-projector-local.sh', 'DB-primary staging rehearsal focused verification passed.'],
    ['ops/checks/db-primary-all-module-projector-local.sh', null, 'DB-primary all-module projector focused verification passed.'],
];
$expectedChain = array_map(
    static fn(array $node): array => [
        'script' => $node[0],
        'next_script' => $node[1],
        'success_marker' => $node[2],
    ],
    $expectedChain
);
$assertTrue(($manifest['ordered_roots'] ?? null) === $expectedRoots, 'Manifest ordered roots are not exact');
$assertTrue(($manifest['recursive_chain'] ?? null) === $expectedChain, 'Manifest recursive chain is not exact');

$safety = $manifest['safety'] ?? null;
$assertTrue(is_array($safety) && !array_is_list($safety), 'Manifest safety object is invalid');
$assertExactKeys($safety, [
    'live_database_contacted', 'private_config_required',
    'application_entrypoints_changed', 'cron_changed',
    'deployment_performed', 'production_changed',
    'sensitive_identifiers_exposed',
], 'Manifest safety');
foreach ($safety as $field => $value) {
    $assertTrue($value === false, 'Manifest safety flag must be false: ' . $field);
}

$scripts = [];
foreach ($expectedRoots as $node) $scripts[$node['script']] = true;
foreach ($expectedChain as $node) $scripts[$node['script']] = true;
$assertTrue(count($scripts) === 14, 'Manifest must contain exactly fourteen unique scripts');
foreach (array_keys($scripts) as $script) {
    $assertTrue(
        preg_match('~^ops/checks/[a-z0-9-]+\.sh$~', $script) === 1,
        'Manifest script path is unsafe: ' . $script
    );
    $path = $projectRoot . '/' . $script;
    $assertTrue(is_file($path) && !is_link($path), 'Manifest script is unavailable or symlinked: ' . $script);
    $source = file_get_contents($path);
    $assertTrue(
        is_string($source) && str_contains($source, 'set -euo pipefail'),
        'Manifest script must use strict Bash: ' . $script
    );
}

$entrypointSource = file_get_contents($projectRoot . '/' . $manifest['entrypoint']);
$assertTrue(is_string($entrypointSource), 'Current portable entrypoint source is unavailable');
$rootPositions = [];
foreach ($expectedRoots as $node) {
    $rootPositions[] = strpos($entrypointSource, 'bash ' . $node['script']);
}
$assertTrue(
    !in_array(false, $rootPositions, true)
        && $rootPositions[0] < $rootPositions[1]
        && $rootPositions[1] < $rootPositions[2],
    'Portable root invocation order is invalid'
);

foreach ($expectedChain as $node) {
    $source = file_get_contents($projectRoot . '/' . $node['script']);
    $assertTrue(is_string($source), 'Recursive script source is unavailable: ' . $node['script']);
    $markerPosition = strpos($source, $node['success_marker']);
    $assertTrue($markerPosition !== false, 'Recursive success marker is missing: ' . $node['script']);
    if ($node['next_script'] !== null) {
        $nextPosition = strpos($source, 'bash ' . $node['next_script']);
        $assertTrue(
            $nextPosition !== false && $nextPosition < $markerPosition,
            'Recursive next-script ordering is invalid: ' . $node['script']
        );
    }
}

fwrite(STDOUT, "RuntimePrimaryCurrentPortableSuiteManifestTest passed: {$assertions} assertions.\n");
