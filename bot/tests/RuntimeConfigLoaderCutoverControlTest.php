<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/core/RuntimeConfigLoader.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$root = sys_get_temp_dir() . '/mgw-runtime-loader-cutover-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0700, true) || !chmod($root, 0700)) {
    throw new RuntimeException('Runtime loader fixture directory could not be created.');
}
$configFile = $root . '/config.php';
$runtimeFile = $root . '/runtime.php';
$controlFile = $root . '/cutover-rehearsal.json';

try {
    $configPayload = "<?php\ndeclare(strict_types=1);\nreturn [];\n";
    if (file_put_contents($configFile, $configPayload, LOCK_EX) !== strlen($configPayload)) {
        throw new RuntimeException('Primary config fixture could not be written.');
    }
    $runtimePayload = "<?php\ndeclare(strict_types=1);\nreturn ['maintenance_mode' => true];\n";
    if (file_put_contents($runtimeFile, $runtimePayload, LOCK_EX) !== strlen($runtimePayload)) {
        throw new RuntimeException('Runtime fixture could not be written.');
    }

    $normal = RuntimeConfigLoader::merge(
        ['environment' => 'production', 'feature_flags' => []],
        $configFile
    );
    $assertTrue(
        ($normal['feature_flags']['maintenance_mode'] ?? false) === true,
        'Normal runtime must continue loading runtime.php'
    );

    $brokenPayload = "<?php\ndeclare(strict_types=1);\nreturn 'not-an-array';\n";
    if (file_put_contents($runtimeFile, $brokenPayload, LOCK_EX) !== strlen($brokenPayload)) {
        throw new RuntimeException('Broken runtime fixture could not be written.');
    }
    $controlPayload = json_encode([
        'state' => 'sealed',
        'environment' => 'staging',
    ], JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($controlFile, $controlPayload, LOCK_EX) !== strlen($controlPayload)) {
        throw new RuntimeException('Control fixture could not be written.');
    }

    define('MINIGAMES_CUTOVER_CONTROL_BOOTSTRAP', true);
    $base = [
        'environment' => 'production',
        'feature_flags' => ['maintenance_mode' => false],
    ];
    $controlled = RuntimeConfigLoader::merge($base, $configFile);
    $assertTrue($controlled === $base, 'Control bootstrap must return exact base config');
    $assertTrue(
        ($controlled['feature_flags']['maintenance_mode'] ?? true) === false,
        'Control bootstrap must not consume malformed runtime overlay'
    );
} finally {
    foreach ([$controlFile, $runtimeFile, $configFile] as $path) {
        if ((is_file($path) || is_link($path)) && !unlink($path)) {
            throw new RuntimeException('Runtime loader fixture file could not be removed.');
        }
    }
    if (is_dir($root) && !rmdir($root)) {
        throw new RuntimeException('Runtime loader fixture directory could not be removed.');
    }
}

fwrite(STDOUT, "RuntimeConfigLoaderCutoverControlTest passed: {$assertions} assertions.\n");
