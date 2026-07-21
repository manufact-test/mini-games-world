<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$manifestPath = $projectRoot . '/ops/ci/portable-focused-suite-manifest.json';
if (!is_file($manifestPath) || is_link($manifestPath)) {
    throw new RuntimeException('Portable focused-suite manifest is unavailable or unsafe.');
}
$manifest = json_decode(
    (string)file_get_contents($manifestPath),
    true,
    512,
    JSON_THROW_ON_ERROR
);
if (!is_array($manifest) || array_is_list($manifest)) {
    throw new RuntimeException('Portable focused-suite manifest must be an object.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$exactKeys = static function (array $value, array $expected, string $label) use ($assertTrue): void {
    $actual = array_keys($value);
    sort($actual, SORT_STRING);
    sort($expected, SORT_STRING);
    $assertTrue($actual === $expected, $label . ' fields are not exact');
};
$normalizeScript = static function (mixed $value): string {
    $path = trim((string)$value);
    if ($path === ''
        || !str_starts_with($path, 'ops/checks/')
        || !str_ends_with($path, '.sh')
        || str_contains($path, '..')
        || str_contains($path, '\\')
        || str_starts_with($path, '/')) {
        throw new RuntimeException('Portable focused-suite script path is invalid.');
    }
    return $path;
};
$loadScript = static function (string $relative) use ($projectRoot): string {
    $path = $projectRoot . '/' . $relative;
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('Portable focused-suite script is unavailable or unsafe: ' . $relative);
    }
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Portable focused-suite script could not be read: ' . $relative);
    }
    return $source;
};

$exactKeys($manifest, [
    'contract_version',
    'entrypoint',
    'expected_unique_script_count',
    'ordered_roots',
    'recursive_chain',
    'safety',
], 'Manifest');
$assertTrue(
    ($manifest['contract_version'] ?? '') === 'v1-portable-db-primary-focused-suite',
    'Portable focused-suite contract version is invalid'
);
$entrypoint = $normalizeScript($manifest['entrypoint'] ?? '');
$assertTrue(
    $entrypoint === 'ops/checks/db-primary-portable-self-hosted-ci-local.sh',
    'Portable focused-suite entrypoint is invalid'
);
$entrypointSource = $loadScript($entrypoint);
$assertTrue(
    str_starts_with($entrypointSource, "#!/usr/bin/env bash\n")
        && str_contains($entrypointSource, 'set -euo pipefail'),
    'Portable focused-suite entrypoint must be strict Bash'
);

$roots = $manifest['ordered_roots'] ?? null;
$assertTrue(is_array($roots) && array_is_list($roots) && count($roots) === 3, 'Portable roots must contain exactly three scripts');
$expectedRoots = [
    'ops/checks/db-primary-projection-outbox-local.sh',
    'ops/checks/db-primary-projection-worker-local.sh',
    'ops/checks/db-primary-staging-api-read-only-smoke-local.sh',
];
$rootPositions = [];
$allScripts = [];
foreach ($roots as $index => $root) {
    if (!is_array($root) || array_is_list($root)) {
        throw new RuntimeException('Portable focused-suite root must be an object.');
    }
    $exactKeys($root, ['script', 'success_marker'], 'Root');
    $script = $normalizeScript($root['script'] ?? '');
    $marker = trim((string)($root['success_marker'] ?? ''));
    $assertTrue($script === $expectedRoots[$index], 'Portable root order is invalid');
    $assertTrue($marker !== '', 'Portable root success marker is missing');
    $source = $loadScript($script);
    $assertTrue(str_contains($source, $marker), 'Portable root success marker does not match source');
    $call = 'bash ' . $script;
    $position = strpos($entrypointSource, $call);
    $assertTrue($position !== false, 'Portable entrypoint is missing root call: ' . $script);
    $rootPositions[] = $position;
    $allScripts[$script] = true;
}
$assertTrue(
    $rootPositions[0] < $rootPositions[1] && $rootPositions[1] < $rootPositions[2],
    'Portable entrypoint root execution order is invalid'
);

$chain = $manifest['recursive_chain'] ?? null;
$assertTrue(is_array($chain) && array_is_list($chain) && count($chain) === 11, 'Portable recursive chain must contain exactly eleven scripts');
foreach ($chain as $index => $node) {
    if (!is_array($node) || array_is_list($node)) {
        throw new RuntimeException('Portable focused-suite chain node must be an object.');
    }
    $exactKeys($node, ['script', 'next_script', 'success_marker'], 'Chain node');
    $script = $normalizeScript($node['script'] ?? '');
    $marker = trim((string)($node['success_marker'] ?? ''));
    $source = $loadScript($script);
    $assertTrue($marker !== '' && str_contains($source, $marker), 'Chain success marker does not match source: ' . $script);
    $assertTrue(
        str_starts_with($source, "#!/usr/bin/env bash\n")
            && str_contains($source, 'set -euo pipefail'),
        'Chain script must be strict Bash: ' . $script
    );
    $allScripts[$script] = true;

    $next = $node['next_script'] ?? null;
    if ($index === count($chain) - 1) {
        $assertTrue($next === null, 'Final chain node must not have a next script');
        continue;
    }
    $nextScript = $normalizeScript($next);
    $assertTrue(
        ($chain[$index + 1]['script'] ?? '') === $nextScript,
        'Recursive chain order does not match next_script'
    );
    $callPosition = strpos($source, 'bash ' . $nextScript);
    $markerPosition = strpos($source, $marker);
    $assertTrue(
        $callPosition !== false
            && $markerPosition !== false
            && $callPosition < $markerPosition,
        'Chain script must call the next script before its success marker: ' . $script
    );
}

$expectedUnique = (int)($manifest['expected_unique_script_count'] ?? 0);
$assertTrue($expectedUnique === 13, 'Portable manifest expected script count is invalid');
$assertTrue(count($allScripts) === $expectedUnique, 'Portable manifest unique script coverage is incomplete');

$safety = $manifest['safety'] ?? null;
$assertTrue(is_array($safety) && !array_is_list($safety), 'Portable manifest safety must be an object');
$exactKeys($safety, [
    'live_database_contacted',
    'private_config_required',
    'application_entrypoints_changed',
    'cron_changed',
    'deployment_performed',
    'production_changed',
    'sensitive_identifiers_exposed',
], 'Safety');
foreach ($safety as $name => $value) {
    $assertTrue($value === false, 'Portable manifest safety flag must be false: ' . $name);
}

fwrite(STDOUT, "RuntimePrimaryPortableFocusedSuiteManifestTest passed: {$assertions} assertions.\n");
