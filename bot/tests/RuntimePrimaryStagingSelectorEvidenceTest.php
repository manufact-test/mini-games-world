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
$assertTrue(($current['production_selector_allowed'] ?? true) === false, 'Selector evidence must forbid production routing');
$assertTrue(count((array)($current['sources'] ?? [])) === 8, 'Selector evidence must fingerprint eight source files');
foreach ((array)$current['sources'] as $sha) {
    $assertTrue(preg_match('/^[a-f0-9]{64}$/', (string)$sha) === 1, 'Every selector source fingerprint must be SHA-256');
}
foreach ((array)$current['checks'] as $passed) {
    $assertTrue($passed === true, 'Every current selector evidence check must pass');
}

$fixture = sys_get_temp_dir() . '/mgw-selector-evidence-' . bin2hex(random_bytes(6));
$paths = [
    'bot/api.php',
    'bot/handlers/WebhookHandler.php',
    'bot/core/bootstrap.php',
    'bot/storage/StorageFactory.php',
    'bot/runtime/RuntimePrimaryStagingEntrypointBootstrap.php',
    'bot/runtime/RuntimePrimaryStagingEntrypointStorageSelector.php',
    'bot/runtime/RuntimePrimaryStagingEntrypointSelectorConfig.php',
    'bot/runtime/RuntimePrimaryEntrypointStorageContext.php',
];
try {
    foreach ($paths as $relative) {
        $destination = $fixture . '/' . $relative;
        mkdir(dirname($destination), 0700, true);
        copy($projectRoot . '/' . $relative, $destination);
    }
    $readyFixture = RuntimePrimaryStagingSelectorEvidence::inspect($fixture);
    $assertTrue(($readyFixture['ready'] ?? false) === true, 'Copied selector fixture must remain ready');

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

    unlink($fixture . '/bot/runtime/RuntimePrimaryEntrypointStorageContext.php');
    $missing = RuntimePrimaryStagingSelectorEvidence::inspect($fixture);
    $assertTrue(($missing['ready'] ?? true) === false, 'Missing context source must block selector evidence');
    $assertTrue(
        in_array('Selector evidence source is unavailable: storage_context.', (array)($missing['blockers'] ?? []), true),
        'Missing selector source must remain explicit'
    );
} finally {
    $remove($fixture);
}

fwrite(STDOUT, "RuntimePrimaryStagingSelectorEvidenceTest passed: {$assertions} assertions.\n");
