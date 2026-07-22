<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointSelectorConfig.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingRequestSessionConfig.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingRequestLifecycleEvidence.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $messagePart) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($messagePart))) return;
        throw new RuntimeException('Unexpected exception: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected exception was not thrown.');
};
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $child = $path . '/' . $name;
        if (is_dir($child) && !is_link($child)) $remove($child);
        else @unlink($child);
    }
    @rmdir($path);
};

$baseline = [
    'state_revision' => 3,
    'state_sha256' => str_repeat('a', 64),
    'json_sha256' => str_repeat('b', 64),
    'inventory_fingerprint' => str_repeat('c', 64),
];
$current = RuntimePrimaryStagingRequestLifecycleEvidence::inspect($projectRoot, $baseline);
$assertTrue(
    ($current['ready'] ?? false) === true,
    'Current API request lifecycle must satisfy evidence contract: '
        . implode('; ', array_map('strval', (array)($current['blockers'] ?? [])))
);
$assertTrue(($current['blockers'] ?? ['unexpected']) === [], 'Current lifecycle evidence must have no blockers');
$assertTrue(($current['contract_version'] ?? '') === RuntimePrimaryStagingRequestLifecycleEvidence::CONTRACT_VERSION, 'Lifecycle evidence must expose exact contract');
$assertTrue(($current['baseline'] ?? []) === $baseline, 'Lifecycle evidence must preserve normalized baseline');
$assertTrue(($current['api_only'] ?? false) === true, 'Lifecycle evidence must be API-only');
$assertTrue(($current['webhook_allowed'] ?? true) === false, 'Lifecycle evidence must forbid webhook');
$assertTrue(($current['session_enabled_by_evidence'] ?? true) === false, 'Evidence alone must not enable session');
$assertTrue(($current['finalizer_registered_by_evidence'] ?? true) === false, 'Evidence alone must not register finalizer');
$assertTrue(count((array)($current['sources'] ?? [])) === 17, 'Lifecycle evidence must fingerprint seventeen sources');
foreach ((array)$current['checks'] as $passed) {
    $assertTrue($passed === true, 'Every current lifecycle evidence check must pass');
}

$assertThrows(
    static fn() => RuntimePrimaryStagingRequestLifecycleEvidence::inspect(
        $projectRoot,
        array_replace($baseline, ['state_revision' => 0])
    ),
    'baseline revision must be positive'
);
$assertThrows(
    static fn() => RuntimePrimaryStagingRequestLifecycleEvidence::inspect(
        $projectRoot,
        array_replace($baseline, ['json_sha256' => 'bad'])
    ),
    'baseline field is invalid'
);

$fixture = sys_get_temp_dir() . '/mgw-lifecycle-evidence-' . bin2hex(random_bytes(6));
$paths = [
    'bot/api.php',
    'bot/helpers/response.php',
    'bot/runtime/RuntimePrimaryStagingEntrypointBootstrap.php',
    'bot/runtime/RuntimePrimaryStagingEntrypointStorageSelector.php',
    'bot/runtime/RuntimePrimaryStagingEntrypointSelectorConfig.php',
    'bot/runtime/RuntimePrimaryEntrypointStorageContext.php',
    'bot/runtime/RuntimePrimaryStagingApiSessionCoordinator.php',
    'bot/runtime/RuntimePrimaryStagingApiRequestFinalizationHook.php',
    'bot/runtime/RuntimePrimaryStagingRequestSessionConfig.php',
    'bot/runtime/RuntimePrimaryStagingRequestFinalizer.php',
    'bot/runtime/RuntimePrimaryStagingRequestSessionReadiness.php',
    'bot/runtime/RuntimePrimaryProjectionWorkerInterface.php',
    'bot/runtime/RuntimePrimaryProjectionAuditorInterface.php',
    'bot/runtime/RuntimePrimaryProjectionWorkerAdapter.php',
    'bot/runtime/RuntimePrimaryProjectionAuditorAdapter.php',
    'bot/runtime/RuntimePrimaryProjectionWorker.php',
    'bot/runtime/RuntimePrimaryAllModuleProjector.php',
];
try {
    foreach ($paths as $relative) {
        $destination = $fixture . '/' . $relative;
        if (!is_dir(dirname($destination))
            && !mkdir(dirname($destination), 0700, true)
            && !is_dir(dirname($destination))) {
            throw new RuntimeException('Could not create lifecycle fixture directory.');
        }
        if (!copy($projectRoot . '/' . $relative, $destination)) {
            throw new RuntimeException('Could not copy lifecycle fixture source: ' . $relative);
        }
    }
    $ready = RuntimePrimaryStagingRequestLifecycleEvidence::inspect($fixture, $baseline);
    $assertTrue(($ready['ready'] ?? false) === true, 'Copied lifecycle fixture must remain ready');

    $responsePath = $fixture . '/bot/helpers/response.php';
    $response = (string)file_get_contents($responsePath);
    $response = str_replace(
        "    foreach (\$hooks as \$hook) {\n        if (is_callable(\$hook)) \$hook();\n    }\n",
        '',
        $response
    );
    file_put_contents($responsePath, $response);
    $wrongOrder = RuntimePrimaryStagingRequestLifecycleEvidence::inspect($fixture, $baseline);
    $assertTrue(($wrongOrder['ready'] ?? true) === false, 'Missing pre-response success hooks must block lifecycle evidence');
    $assertTrue(
        in_array('Request lifecycle evidence check failed: success_hooks_before_filters_and_response.', (array)($wrongOrder['blockers'] ?? []), true),
        'Wrong success-hook order must remain explicit'
    );
    copy($projectRoot . '/bot/helpers/response.php', $responsePath);

    $coordinatorPath = $fixture . '/bot/runtime/RuntimePrimaryStagingApiSessionCoordinator.php';
    $coordinator = (string)file_get_contents($coordinatorPath);
    $withoutPreparation = str_replace('array_unshift($hooks, $hook);', '/* hook preparation removed */', $coordinator);
    file_put_contents($coordinatorPath, $withoutPreparation);
    $missingPreparation = RuntimePrimaryStagingRequestLifecycleEvidence::inspect($fixture, $baseline);
    $assertTrue(($missingPreparation['ready'] ?? true) === false, 'Missing hook preparation before context must block lifecycle evidence');
    $assertTrue(
        in_array('Request lifecycle evidence check failed: coordinator_finalizer_prepared_before_context.', (array)($missingPreparation['blockers'] ?? []), true),
        'Missing pre-context hook preparation must remain explicit'
    );

    $withoutPublication = str_replace(
        "\$GLOBALS['mgw_api_success_hooks'] = \$hooks;",
        '/* hook publication removed */',
        $coordinator
    );
    file_put_contents($coordinatorPath, $withoutPublication);
    $missingPublication = RuntimePrimaryStagingRequestLifecycleEvidence::inspect($fixture, $baseline);
    $assertTrue(($missingPublication['ready'] ?? true) === false, 'Missing post-context hook publication must block lifecycle evidence');
    $assertTrue(
        in_array('Request lifecycle evidence check failed: coordinator_context_before_hook_publication.', (array)($missingPublication['blockers'] ?? []), true),
        'Missing post-context hook publication must remain explicit'
    );
    copy($projectRoot . '/bot/runtime/RuntimePrimaryStagingApiSessionCoordinator.php', $coordinatorPath);

    unlink($fixture . '/bot/runtime/RuntimePrimaryStagingRequestFinalizer.php');
    $missingFinalizer = RuntimePrimaryStagingRequestLifecycleEvidence::inspect($fixture, $baseline);
    $assertTrue(($missingFinalizer['ready'] ?? true) === false, 'Missing request finalizer must block lifecycle evidence');
    $assertTrue(
        in_array('Request lifecycle evidence source is unavailable: request_finalizer.', (array)($missingFinalizer['blockers'] ?? []), true),
        'Missing request finalizer source must remain explicit'
    );
} finally {
    $remove($fixture);
}

fwrite(STDOUT, "RuntimePrimaryStagingRequestLifecycleEvidenceTest passed: {$assertions} assertions.\n");
