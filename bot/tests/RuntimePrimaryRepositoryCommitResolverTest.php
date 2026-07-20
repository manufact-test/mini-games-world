<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryRepositoryCommitResolver.php';

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

$envSha = str_repeat('a', 40);
putenv('MGW_REHEARSAL_COMMIT_SHA=' . $envSha);
$assertTrue(
    RuntimePrimaryRepositoryCommitResolver::resolve(sys_get_temp_dir()) === $envSha,
    'Environment commit override must take precedence'
);
putenv('MGW_REHEARSAL_COMMIT_SHA=invalid');
$assertThrows(
    static fn() => RuntimePrimaryRepositoryCommitResolver::resolve(sys_get_temp_dir()),
    'must be a full 40-character git sha'
);
putenv('MGW_REHEARSAL_COMMIT_SHA');

$fixture = sys_get_temp_dir() . '/mgw-commit-resolver-' . bin2hex(random_bytes(6));
mkdir($fixture . '/.git', 0700, true);
try {
    $detachedSha = str_repeat('b', 40);
    file_put_contents($fixture . '/.git/HEAD', $detachedSha . "\n");
    $assertTrue(
        RuntimePrimaryRepositoryCommitResolver::resolve($fixture) === $detachedSha,
        'Detached HEAD must resolve'
    );

    $looseSha = str_repeat('c', 40);
    mkdir($fixture . '/.git/refs/heads', 0700, true);
    file_put_contents($fixture . '/.git/HEAD', "ref: refs/heads/staging\n");
    file_put_contents($fixture . '/.git/refs/heads/staging', $looseSha . "\n");
    $assertTrue(
        RuntimePrimaryRepositoryCommitResolver::resolve($fixture) === $looseSha,
        'Loose branch ref must resolve'
    );

    unlink($fixture . '/.git/refs/heads/staging');
    $packedSha = str_repeat('d', 40);
    file_put_contents(
        $fixture . '/.git/packed-refs',
        "# pack-refs with: peeled fully-peeled sorted\n{$packedSha} refs/heads/staging\n"
    );
    $assertTrue(
        RuntimePrimaryRepositoryCommitResolver::resolve($fixture) === $packedSha,
        'Packed branch ref must resolve'
    );
} finally {
    $remove($fixture);
}

$worktree = sys_get_temp_dir() . '/mgw-commit-worktree-' . bin2hex(random_bytes(6));
$gitDir = sys_get_temp_dir() . '/mgw-commit-gitdir-' . bin2hex(random_bytes(6));
mkdir($worktree, 0700, true);
mkdir($gitDir, 0700, true);
try {
    $worktreeSha = str_repeat('e', 40);
    file_put_contents($worktree . '/.git', 'gitdir: ' . $gitDir . "\n");
    file_put_contents($gitDir . '/HEAD', $worktreeSha . "\n");
    $assertTrue(
        RuntimePrimaryRepositoryCommitResolver::resolve($worktree) === $worktreeSha,
        'Worktree gitdir pointer must resolve'
    );
} finally {
    @unlink($worktree . '/.git');
    $remove($worktree);
    $remove($gitDir);
}

$missing = sys_get_temp_dir() . '/mgw-commit-missing-' . bin2hex(random_bytes(6));
mkdir($missing, 0700, true);
try {
    $assertThrows(
        static fn() => RuntimePrimaryRepositoryCommitResolver::resolve($missing),
        'metadata is unavailable'
    );
} finally {
    $remove($missing);
}

fwrite(STDOUT, "RuntimePrimaryRepositoryCommitResolverTest passed: {$assertions} assertions.\n");
