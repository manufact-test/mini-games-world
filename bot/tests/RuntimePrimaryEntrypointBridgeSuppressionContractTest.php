<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$bootstrap = file_get_contents($projectRoot . '/bot/core/bootstrap.php');
$guard = file_get_contents($projectRoot . '/bot/runtime/RuntimePrimaryEntrypointBridgeGuard.php');
if (!is_string($bootstrap) || !is_string($guard)) {
    throw new RuntimeException('Entrypoint bridge suppression sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(
    str_contains($bootstrap, "require_once __DIR__ . '/../runtime/RuntimePrimaryEntrypointBridgeGuard.php';"),
    'Application bootstrap must load the request bridge guard before registering hooks'
);
$assertTrue(
    str_contains($guard, "class_exists('RuntimePrimaryEntrypointStorageContext', false)")
        && str_contains($guard, 'RuntimePrimaryEntrypointStorageContext::installed()'),
    'Bridge guard must suppress legacy bridges only for an installed DB-primary context'
);
$assertTrue(
    substr_count(
        $bootstrap,
        'RuntimePrimaryEntrypointBridgeGuard::legacyJsonBridgeAllowed()'
    ) >= 8,
    'Every API hook/filter group and webhook hook batch must consult the bridge guard'
);
$assertTrue(
    substr_count(
        $bootstrap,
        'if (!RuntimePrimaryEntrypointBridgeGuard::legacyJsonBridgeAllowed()) return $data;'
    ) === 2,
    'Payment and weekly API filters must preserve DB-primary response data unchanged'
);
$assertTrue(
    str_contains(
        $bootstrap,
        "if (!RuntimePrimaryEntrypointBridgeGuard::legacyJsonBridgeAllowed()) return;\n        foreach (\$runtimeWebhookSuccessHooks as \$hook) \$hook();"
    ),
    'Webhook legacy JSON hooks must be suppressed as one guarded batch'
);
$assertTrue(
    substr_count($bootstrap, 'synchronizeCurrentJson();') === 8,
    'Known legacy bridge synchronization calls must remain fully accounted for'
);
$assertTrue(
    substr_count($bootstrap, 'normalizeApiData(') === 2,
    'Known legacy API normalization filters must remain fully accounted for'
);

fwrite(STDOUT, "RuntimePrimaryEntrypointBridgeSuppressionContractTest passed: {$assertions} assertions.\n");
