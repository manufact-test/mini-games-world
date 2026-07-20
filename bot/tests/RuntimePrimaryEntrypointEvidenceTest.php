<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryEntrypointEvidence.php';

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
        if (is_dir($child)) $remove($child);
        else @unlink($child);
    }
    @rmdir($path);
};

$current = RuntimePrimaryEntrypointEvidence::inspect($projectRoot);
$assertTrue(($current['contract_version'] ?? '') === 'v1-json-first-entrypoints', 'Current evidence contract version must match');
foreach (['api', 'webhook_handler'] as $name) {
    $entrypoint = $current['entrypoints'][$name] ?? [];
    $assertTrue(($entrypoint['direct_json_factory_present'] ?? false) === true, 'Current entrypoint must remain JSON-first: ' . $name);
    $assertTrue(($entrypoint['db_primary_coordinator_present'] ?? true) === false, 'Current entrypoint must not use DB-primary coordinator: ' . $name);
    $assertTrue(preg_match('/^[a-f0-9]{64}$/', (string)($entrypoint['source_sha256'] ?? '')) === 1, 'Current entrypoint SHA must be valid: ' . $name);
}
$assertTrue(preg_match('/^[a-f0-9]{64}$/', (string)($current['contract_fingerprint'] ?? '')) === 1, 'Current evidence fingerprint must be valid');

$fixture = sys_get_temp_dir() . '/mgw-entrypoint-evidence-' . bin2hex(random_bytes(6));
mkdir($fixture . '/bot/handlers', 0700, true);
try {
    $source = "<?php\n\$coordinator = new ProductionPrimaryRuntimeCoordinator();\n";
    file_put_contents($fixture . '/bot/api.php', $source);
    file_put_contents($fixture . '/bot/handlers/WebhookHandler.php', $source);
    $switched = RuntimePrimaryEntrypointEvidence::inspect($fixture);
    foreach (['api', 'webhook_handler'] as $name) {
        $entrypoint = $switched['entrypoints'][$name] ?? [];
        $assertTrue(($entrypoint['direct_json_factory_present'] ?? true) === false, 'Switched fixture must not report direct JSON: ' . $name);
        $assertTrue(($entrypoint['db_primary_coordinator_present'] ?? false) === true, 'Switched fixture must report coordinator: ' . $name);
    }
    @unlink($fixture . '/bot/api.php');
    $assertThrows(
        static fn() => RuntimePrimaryEntrypointEvidence::inspect($fixture),
        'source is unavailable: api'
    );
} finally {
    $remove($fixture);
}

fwrite(STDOUT, "RuntimePrimaryEntrypointEvidenceTest passed: {$assertions} assertions.\n");
