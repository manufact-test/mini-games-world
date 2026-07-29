<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'webhook' => $root . '/bot/webhook.php',
    'webhook_guard' => $root . '/bot/helpers/MaintenanceWebhookGuard.php',
    'request_guard' => $root . '/bot/core/RuntimeRequestGuard.php',
    'feature_flags' => $root . '/bot/services/FeatureFlagService.php',
    'export_cli' => $root . '/ops/runtime/run-production-primary-rollback-export.php',
    'export_gate' => $root . '/bot/runtime/ProductionPrimaryRollbackExportGate.php',
];

$sources = [];
foreach ($files as $name => $path) {
    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        throw new RuntimeException("Required source is unavailable: {$name}");
    }
    $sources[$name] = $raw;
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertBefore = static function (string $source, string $first, string $second, string $message) use (&$assertions): void {
    $assertions++;
    $firstPosition = strpos($source, $first);
    $secondPosition = strpos($source, $second);
    if (!is_int($firstPosition) || !is_int($secondPosition) || $firstPosition >= $secondPosition) {
        throw new RuntimeException($message);
    }
};

$assertTrue(
    str_contains($sources['webhook'], "require_once __DIR__ . '/helpers/MaintenanceWebhookGuard.php';"),
    'Webhook must load the maintenance response guard.'
);
$assertBefore(
    $sources['webhook'],
    '$maintenanceGuard->handle($update)',
    'StorageFactory::createJson',
    'Maintenance Telegram handling must run before production storage bootstrap.'
);
$assertBefore(
    $sources['webhook'],
    "unset(\$GLOBALS['mgw_webhook_success_hook'])",
    "exit('ok')",
    'Maintenance completion must clear deferred projection hooks before returning.'
);
$assertTrue(
    !str_contains($sources['webhook_guard'], 'StorageFactory')
        && !str_contains($sources['webhook_guard'], 'transaction('),
    'Maintenance Telegram guard must remain storage-free.'
);
$assertTrue(
    str_contains($sources['webhook_guard'], "'🛠 ' . (string)\$response['message']"),
    'Maintenance Telegram response must send the configured maintenance message.'
);

foreach ([
    'api.php',
    'invites.php',
    'notifications.php',
    'invite-opponents.php',
    'game-clock.php',
    'game-live-v108.php',
    'search-speed.php',
    'shop-history.php',
] as $script) {
    $assertTrue(
        str_contains($sources['request_guard'], "'{$script}'"),
        "Maintenance request guard must cover {$script}."
    );
}
$assertTrue(
    str_contains($sources['request_guard'], 'if ($flags->maintenanceEnabled())')
        && str_contains($sources['request_guard'], 'return $flags->maintenanceMessage();'),
    'Maintenance must block before action-specific routing.'
);
$assertTrue(
    !str_contains($sources['request_guard'], "'webhook.php',")
        && !str_contains($sources['request_guard'], "'weekly-match.php',"),
    'Telegram webhook and Cron must stay outside the generic user request guard.'
);
$assertTrue(
    str_contains($sources['feature_flags'], 'return !$this->maintenanceEnabled();'),
    'Maintenance must explicitly deny active-game writes.'
);

$assertTrue(
    str_contains($sources['export_gate'], "maintenance_enabled_exact")
        && str_contains($sources['export_gate'], "financial_read_only_exact")
        && str_contains($sources['export_gate'], "runtime_enabled_exact")
        && str_contains($sources['export_gate'], "all_modules_enabled_exact"),
    'Fresh export must still require maintenance, read-only and the exact active DB route.'
);
$assertTrue(
    str_contains($sources['export_cli'], 'DATABASE_WRITE_EXECUTED=false')
        && str_contains($sources['export_cli'], 'LIVE_JSON_CHANGED=false')
        && str_contains($sources['export_cli'], 'WEBHOOK_CHANGED=false')
        && str_contains($sources['export_cli'], 'CRON_CHANGED=false'),
    'Export CLI safety markers must remain unchanged.'
);
$assertTrue(
    !str_contains($sources['webhook'], 'setWebhook')
        && !str_contains($sources['webhook_guard'], 'setWebhook'),
    'Maintenance fix must not change webhook registration.'
);

fwrite(STDOUT, "Mvp14r1MaintenanceBoundaryContractTest: {$assertions} assertions passed\n");
