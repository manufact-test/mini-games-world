<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingActivationEvidenceLoader.php';

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

$fixture = sys_get_temp_dir() . '/mgw-activation-evidence-' . bin2hex(random_bytes(6));
$private = $fixture . '/private';
$other = $fixture . '/other';
mkdir($private, 0700, true);
mkdir($other, 0700, true);
try {
    $manifest = ['manifest_version' => 'v2-staging-db-primary-evidence', 'safe' => true];
    $path = $private . '/evidence.json';
    $raw = json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    file_put_contents($path, $raw);
    chmod($path, 0600);

    $loader = new RuntimePrimaryStagingActivationEvidenceLoader($projectRoot, $private);
    $loaded = $loader->load($path);
    $assertTrue(($loaded['manifest'] ?? []) === $manifest, 'Evidence loader must preserve the manifest');
    $assertTrue(($loaded['permissions'] ?? '') === '0600', 'Evidence loader must require 0600 permissions');
    $assertTrue(($loaded['bytes'] ?? 0) === strlen($raw), 'Evidence loader must report exact bytes');
    $assertTrue(hash_equals(hash('sha256', $raw), (string)($loaded['file_sha256'] ?? '')), 'Evidence loader must fingerprint exact file bytes');
    $assertTrue(($loaded['path_exposed'] ?? true) === false, 'Evidence loader must not expose private paths');

    chmod($path, 0644);
    $assertThrows(static fn() => $loader->load($path), 'permissions must be 0600');
    chmod($path, 0600);

    $outside = $other . '/evidence.json';
    file_put_contents($outside, $raw);
    chmod($outside, 0600);
    $assertThrows(static fn() => $loader->load($outside), 'verified private directory');

    $invalid = $private . '/invalid.json';
    file_put_contents($invalid, '{broken');
    chmod($invalid, 0600);
    $assertThrows(static fn() => $loader->load($invalid), 'json is invalid');

    $list = $private . '/list.json';
    file_put_contents($list, '[1,2,3]');
    chmod($list, 0600);
    $assertThrows(static fn() => $loader->load($list), 'must be a json object');

    $large = $private . '/large.json';
    file_put_contents($large, json_encode(['padding' => str_repeat('x', 525000)], JSON_THROW_ON_ERROR));
    chmod($large, 0600);
    $assertThrows(static fn() => $loader->load($large), 'file size is invalid');

    if (function_exists('symlink')) {
        $link = $private . '/link.json';
        if (@symlink($path, $link)) {
            $assertThrows(static fn() => $loader->load($link), 'must not be a symbolic link');
        }
    }
} finally {
    $remove($fixture);
}

fwrite(STDOUT, "RuntimePrimaryStagingActivationEvidenceLoaderTest passed: {$assertions} assertions.\n");
