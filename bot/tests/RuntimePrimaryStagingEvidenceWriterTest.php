<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceWriter.php';

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

$private = sys_get_temp_dir() . '/mgw-evidence-writer-' . bin2hex(random_bytes(6));
mkdir($private, 0700, true);
try {
    $writer = new RuntimePrimaryStagingEvidenceWriter($projectRoot);
    $path = $private . '/evidence.json';
    $manifest = [
        'manifest_version' => 'v1-staging-db-primary-evidence',
        'repository_commit' => str_repeat('a', 40),
        'safe' => true,
    ];
    $result = $writer->write($path, $manifest);
    $assertTrue(($result['ok'] ?? false) === true, 'Private evidence writer must succeed');
    $assertTrue(is_file($path), 'Private evidence output must exist');
    $assertTrue(($result['permissions'] ?? '') === '0600', 'Private evidence writer must report 0600');
    $assertTrue((fileperms($path) & 0777) === 0600, 'Private evidence output must have 0600 permissions');
    $decoded = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $assertTrue($decoded === $manifest, 'Private evidence output must preserve the exact manifest');
    $assertTrue(
        hash_equals((string)$result['file_sha256'], hash('sha256', (string)file_get_contents($path))),
        'Private evidence output fingerprint must match the written bytes'
    );
    $assertTrue(($result['path_exposed'] ?? true) === false, 'Private evidence writer must not expose the output path');

    $assertThrows(
        static fn() => $writer->write($path, $manifest),
        'already exists'
    );

    $inside = $projectRoot . '/evidence-writer-test-' . bin2hex(random_bytes(4)) . '.json';
    $assertThrows(
        static fn() => $writer->write($inside, $manifest),
        'outside the deployed project'
    );
    $assertTrue(!file_exists($inside), 'Deployment-local evidence output must not be created');

    if (function_exists('symlink')) {
        $target = $private . '/target.json';
        file_put_contents($target, '{}');
        $link = $private . '/link.json';
        if (@symlink($target, $link)) {
            $assertThrows(
                static fn() => $writer->write($link, $manifest),
                'must not be a symbolic link'
            );
        }
    }

    $oversizedPath = $private . '/oversized.json';
    $oversized = ['padding' => str_repeat('x', 513 * 1024)];
    $assertThrows(
        static fn() => $writer->write($oversizedPath, $oversized),
        'exceeds 512 kib'
    );
    $assertTrue(!file_exists($oversizedPath), 'Oversized evidence output must not be created');

    $temporaryFiles = glob($private . '/.*.tmp-*') ?: [];
    $assertTrue($temporaryFiles === [], 'Evidence writer must clean every temporary file');
} finally {
    $remove($private);
}

fwrite(STDOUT, "RuntimePrimaryStagingEvidenceWriterTest passed: {$assertions} assertions.\n");
