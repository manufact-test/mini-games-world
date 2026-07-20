<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryPrivateConfigGuard.php';

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

$fixture = sys_get_temp_dir() . '/mgw-private-config-guard-' . bin2hex(random_bytes(6));
$project = $fixture . '/project';
$external = $fixture . '/private';
$prefixSibling = $fixture . '/project-private';
mkdir($project, 0700, true);
mkdir($external, 0700, true);
mkdir($prefixSibling, 0700, true);

try {
    $externalConfig = $external . '/config.php';
    file_put_contents($externalConfig, "<?php\nreturn ['environment' => 'staging'];\n");
    chmod($externalConfig, 0600);
    $guarded = RuntimePrimaryPrivateConfigGuard::assertExternal($externalConfig, $project);
    $assertTrue(($guarded['config_external'] ?? false) === true, 'External private config must pass');
    $assertTrue(($guarded['private_dir'] ?? '') === realpath($external), 'Guard must return the canonical private directory');
    $assertTrue(
        preg_match('/^[a-f0-9]{64}$/', (string)($guarded['config_fingerprint'] ?? '')) === 1,
        'Guard must return a non-sensitive config fingerprint'
    );
    $assertTrue(($guarded['path_exposed'] ?? true) === false, 'Guard report must not expose paths');

    $insideConfig = $project . '/config.php';
    file_put_contents($insideConfig, "<?php\nreturn [];\n");
    $assertThrows(
        static fn() => RuntimePrimaryPrivateConfigGuard::assertExternal($insideConfig, $project),
        'outside the deployed project'
    );

    $prefixConfig = $prefixSibling . '/config.php';
    file_put_contents($prefixConfig, "<?php\nreturn [];\n");
    $prefixGuarded = RuntimePrimaryPrivateConfigGuard::assertExternal($prefixConfig, $project);
    $assertTrue(($prefixGuarded['config_external'] ?? false) === true, 'Prefix-sibling directory must not be mistaken for a project child');

    $assertThrows(
        static fn() => RuntimePrimaryPrivateConfigGuard::assertExternal('relative/config.php', $project),
        'must be absolute'
    );
    $assertThrows(
        static fn() => RuntimePrimaryPrivateConfigGuard::assertExternal($fixture . '/missing.php', $project),
        'unavailable or unreadable'
    );

    if (function_exists('symlink')) {
        $symlink = $external . '/config-link.php';
        if (@symlink($externalConfig, $symlink)) {
            $assertThrows(
                static fn() => RuntimePrimaryPrivateConfigGuard::assertExternal($symlink, $project),
                'must not be a symbolic link'
            );
        }
    }
} finally {
    $remove($fixture);
}

fwrite(STDOUT, "RuntimePrimaryPrivateConfigGuardTest passed: {$assertions} assertions.\n");
