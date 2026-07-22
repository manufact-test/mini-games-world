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
$project = (string)realpath($project);
$external = (string)realpath($external);
$prefixSibling = (string)realpath($prefixSibling);

try {
    $externalConfig = $external . '/config.php';
    file_put_contents($externalConfig, "<?php\nreturn ['environment' => 'staging'];\n");
    chmod($externalConfig, 0600);
    $guarded = RuntimePrimaryPrivateConfigGuard::assertExternal($externalConfig, $project);
    $assertTrue(($guarded['config_external'] ?? false) === true, 'External private config must pass');
    $assertTrue(($guarded['private_dir'] ?? '') === $external, 'Guard must return the canonical private directory');
    $assertTrue(($guarded['config_mode'] ?? '') === '0600', 'Guard must prove exact config mode');
    $assertTrue(
        ($guarded['private_dir_not_group_world_writable'] ?? false) === true,
        'Guard must prove private directory write safety'
    );
    $assertTrue(
        preg_match('/^[a-f0-9]{64}$/', (string)($guarded['config_fingerprint'] ?? '')) === 1,
        'Guard must return a non-sensitive config fingerprint'
    );
    $assertTrue(($guarded['path_exposed'] ?? true) === false, 'Guard report must not expose paths');

    $insideConfig = $project . '/config.php';
    file_put_contents($insideConfig, "<?php\nreturn [];\n");
    chmod($insideConfig, 0600);
    $assertThrows(
        static fn() => RuntimePrimaryPrivateConfigGuard::assertExternal($insideConfig, $project),
        'outside the deployed project'
    );

    $prefixConfig = $prefixSibling . '/config.php';
    file_put_contents($prefixConfig, "<?php\nreturn [];\n");
    chmod($prefixConfig, 0600);
    $prefixGuarded = RuntimePrimaryPrivateConfigGuard::assertExternal($prefixConfig, $project);
    $assertTrue(($prefixGuarded['config_external'] ?? false) === true, 'Prefix-sibling directory must not be mistaken for a project child');

    chmod($externalConfig, 0644);
    $assertThrows(
        static fn() => RuntimePrimaryPrivateConfigGuard::assertExternal($externalConfig, $project),
        'exact mode 0600'
    );
    chmod($externalConfig, 0600);

    chmod($external, 0770);
    $assertThrows(
        static fn() => RuntimePrimaryPrivateConfigGuard::assertExternal($externalConfig, $project),
        'must not be group/world writable'
    );
    chmod($external, 0700);

    foreach ([
        'relative/config.php',
        ' ' . $externalConfig,
        $externalConfig . ' ',
        str_replace('/', '\\', $externalConfig),
        $externalConfig . '/',
    ] as $malformedPath) {
        $assertThrows(
            static fn() => RuntimePrimaryPrivateConfigGuard::assertExternal($malformedPath, $project),
            str_contains($malformedPath, '\\') || !str_starts_with($malformedPath, '/')
                ? 'exact absolute linux path'
                : (str_ends_with($malformedPath, '/') ? 'trailing separators' : 'unavailable or unreadable')
        );
    }
    $assertThrows(
        static fn() => RuntimePrimaryPrivateConfigGuard::assertExternal($externalConfig, $project . '/'),
        'trailing separators'
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
                'must not be symbolic links'
            );
        }
        $parentLink = $fixture . '/private-link';
        if (@symlink($external, $parentLink)) {
            $assertThrows(
                static fn() => RuntimePrimaryPrivateConfigGuard::assertExternal(
                    $parentLink . '/config.php',
                    $project
                ),
                'exact canonical path'
            );
        }
    }
} finally {
    @chmod($external, 0700);
    $remove($fixture);
}

fwrite(STDOUT, "RuntimePrimaryPrivateConfigGuardTest passed: {$assertions} assertions.\n");
