<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointSelectorConfig.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingSelectorEvidence.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
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

$current = RuntimePrimaryStagingSelectorEvidence::inspect($projectRoot);
$assertTrue(($current['ready'] ?? false) === true, 'Current guarded selector sources must satisfy evidence contract');
$assertTrue(($current['blockers'] ?? ['unexpected']) === [], 'Current selector evidence must have no blockers');
$assertTrue(($current['default_storage_driver'] ?? '') === 'json', 'Selector evidence must preserve JSON default');
$assertTrue(($current['api_only'] ?? false) === true, 'Selector evidence must be API-only');
$assertTrue(($current['webhook_allowed'] ?? true) === false, 'Selector evidence must forbid webhook');
$assertTrue(($current['production_selector_allowed'] ?? true) === false, 'Selector evidence must forbid production routing');
$assertTrue(count((array)($current['sources'] ?? [])) === 19, 'Selector evidence must fingerprint the complete nineteen-file request contour');
foreach ((array)$current['sources'] as $sha) {
    $assertTrue(preg_match('/^[a-f0-9]{64}$/', (string)$sha) === 1, 'Every selector source fingerprint must be SHA-256');
}
foreach ((array)$current['checks'] as $passed) {
    $assertTrue($passed === true, 'Every current selector evidence check must pass');
}

$fixture = sys_get_temp_dir() . '/mgw-selector-evidence-' . bin2hex(random_bytes(6));
$paths = [
    'bot/api.php',
    'bot/webhook.php',
    'bot/handlers/WebhookHandler.php',
    'bot/helpers/RuntimeAdminGuard.php',
    'bot/helpers/AdminPaymentRejectGuard.php',
    'bot/helpers/AdminShopOrderNotificationGuard.php',
    'bot/helpers/AdminShopOrderUiGuard.php',
    'bot/helpers/AdminGoldTopupNotificationGuard.php',
    'bot/helpers/AdminSystemCheckGuard.php',
    'bot/helpers/UserWelcomeGuard.php',
    'bot/core/bootstrap.php',
    'bot/runtime/RuntimePrimaryEntrypointBridgeGuard.php',
    'bot/storage/StorageFactory.php',
    'bot/runtime/RuntimePrimaryStagingEntrypointBootstrap.php',
    'bot/runtime/RuntimePrimaryStagingEntrypointStorageSelector.php',
    'bot/runtime/RuntimePrimaryStagingEntrypointSelectorConfig.php',
    'bot/runtime/RuntimePrimaryEntrypointStorageContext.php',
    'bot/runtime/RuntimePrimaryStagingApiSessionCoordinator.php',
    'bot/runtime/RuntimePrimaryStagingApiRequestFinalizationHook.php',
];
try {
    foreach ($paths as $relative) {
        $destination = $fixture . '/' . $relative;
        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0700, true) && !is_dir(dirname($destination))) {
            throw new RuntimeException('Could not create selector fixture directory.');
        }
        if (!copy($projectRoot . '/' . $relative, $destination)) {
            throw new RuntimeException('Could not copy selector fixture source: ' . $relative);
        }
    }
    $readyFixture = RuntimePrimaryStagingSelectorEvidence::inspect($fixture);
    $assertTrue(($readyFixture['ready'] ?? false) === true, 'Copied selector fixture must remain ready');

    $bridgePath = $fixture . '/bot/runtime/RuntimePrimaryEntrypointBridgeGuard.php';
    unlink($bridgePath);
    $missingBridge = RuntimePrimaryStagingSelectorEvidence::inspect($fixture);
    $assertTrue(($missingBridge['ready'] ?? true) === false, 'Missing legacy bridge guard must block evidence');
    $assertTrue(
        in_array('Selector evidence source is unavailable: bridge_guard.', (array)($missingBridge['blockers'] ?? []), true),
        'Missing legacy bridge guard must remain explicit'
    );
    copy($projectRoot . '/bot/runtime/RuntimePrimaryEntrypointBridgeGuard.php', $bridgePath);

    $factoryPath = $fixture . '/bot/storage/StorageFactory.php';
    $factory = (string)file_get_contents($factoryPath);
    $factory = str_replace('installGuardedEntrypointContextIfEligible()', 'disabledSelectorHook()', $factory);
    file_put_contents($factoryPath, $factory);
    $blocked = RuntimePrimaryStagingSelectorEvidence::inspect($fixture);
    $assertTrue(($blocked['ready'] ?? true) === false, 'Missing lazy selector hook must block evidence');
    $assertTrue(
        in_array('Selector evidence check failed: storage_factory_lazy_selector_present.', (array)($blocked['blockers'] ?? []), true),
        'Blocked selector evidence must identify the missing lazy hook'
    );

    copy($projectRoot . '/bot/storage/StorageFactory.php', $factoryPath);
    $guardPath = $fixture . '/bot/helpers/RuntimeAdminGuard.php';
    $guard = (string)file_get_contents($guardPath);
    $guard .= "\n/* forced bypass */ new JsonStorageAdapter('/tmp');\n";
    file_put_contents($guardPath, $guard);
    $direct = RuntimePrimaryStagingSelectorEvidence::inspect($fixture);
    $assertTrue(($direct['ready'] ?? true) === false, 'Direct JSON construction inside request contour must block evidence');
    $assertTrue(
        in_array('Selector evidence check failed: request_direct_json_constructor_absent.', (array)($direct['blockers'] ?? []), true),
        'Direct JSON bypass must remain explicit'
    );

    unlink($fixture . '/bot/runtime/RuntimePrimaryStagingApiSessionCoordinator.php');
    $missingCoordinator = RuntimePrimaryStagingSelectorEvidence::inspect($fixture);
    $assertTrue(($missingCoordinator['ready'] ?? true) === false, 'Missing API session coordinator must block selector evidence');
    $assertTrue(
        in_array('Selector evidence source is unavailable: api_session_coordinator.', (array)($missingCoordinator['blockers'] ?? []), true),
        'Missing coordinator source must remain explicit'
    );
} finally {
    $remove($fixture);
}

fwrite(STDOUT, "RuntimePrimaryStagingSelectorEvidenceTest passed: {$assertions} assertions.\n");
