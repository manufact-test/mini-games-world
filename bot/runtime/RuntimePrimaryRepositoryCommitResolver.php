<?php
declare(strict_types=1);

final class RuntimePrimaryRepositoryCommitResolver
{
    public static function resolve(string $projectRoot): string
    {
        $environmentSha = strtolower(trim((string)(getenv('MGW_REHEARSAL_COMMIT_SHA') ?: '')));
        if ($environmentSha !== '') {
            self::assertSha($environmentSha, 'MGW_REHEARSAL_COMMIT_SHA');
            return $environmentSha;
        }

        $projectRoot = rtrim(str_replace('\\', '/', trim($projectRoot)), '/');
        if ($projectRoot === '' || !is_dir($projectRoot)) {
            throw new InvalidArgumentException('Repository commit project root is unavailable.');
        }
        $gitDir = self::gitDirectory($projectRoot);
        $head = self::readSmallFile($gitDir . '/HEAD');
        if (str_starts_with($head, 'ref: ')) {
            $ref = trim(substr($head, 5));
            if ($ref === ''
                || str_contains($ref, '..')
                || str_starts_with($ref, '/')
                || preg_match('~^[A-Za-z0-9._/-]+$~', $ref) !== 1) {
                throw new RuntimeException('Repository HEAD ref is invalid.');
            }
            $loosePath = $gitDir . '/' . $ref;
            if (is_file($loosePath)) {
                $sha = strtolower(trim(self::readSmallFile($loosePath)));
                self::assertSha($sha, 'repository loose ref');
                return $sha;
            }
            return self::packedRef($gitDir . '/packed-refs', $ref);
        }

        $sha = strtolower(trim($head));
        self::assertSha($sha, 'detached repository HEAD');
        return $sha;
    }

    private static function gitDirectory(string $projectRoot): string
    {
        $dotGit = $projectRoot . '/.git';
        if (is_dir($dotGit)) return $dotGit;
        if (!is_file($dotGit)) {
            throw new RuntimeException(
                'Repository metadata is unavailable. Set MGW_REHEARSAL_COMMIT_SHA explicitly.'
            );
        }
        $content = self::readSmallFile($dotGit);
        if (!str_starts_with(strtolower($content), 'gitdir:')) {
            throw new RuntimeException('Repository gitdir pointer is invalid.');
        }
        $path = trim(substr($content, strlen('gitdir:')));
        if ($path === '') throw new RuntimeException('Repository gitdir pointer is empty.');
        if (!str_starts_with(str_replace('\\', '/', $path), '/')) {
            $path = $projectRoot . '/' . $path;
        }
        $resolved = realpath($path);
        if (!is_string($resolved) || !is_dir($resolved)) {
            throw new RuntimeException('Repository gitdir target is unavailable.');
        }
        return rtrim(str_replace('\\', '/', $resolved), '/');
    }

    private static function packedRef(string $path, string $ref): string
    {
        if (!is_file($path) || filesize($path) > 4 * 1024 * 1024) {
            throw new RuntimeException('Repository ref is not available in loose or packed refs.');
        }
        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) throw new RuntimeException('Repository packed refs are unreadable.');
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || $line[0] === '^') continue;
                $parts = preg_split('/\s+/', $line, 2);
                if (count($parts) !== 2 || $parts[1] !== $ref) continue;
                $sha = strtolower(trim($parts[0]));
                self::assertSha($sha, 'repository packed ref');
                return $sha;
            }
        } finally {
            fclose($handle);
        }
        throw new RuntimeException('Repository HEAD ref is missing from packed refs.');
    }

    private static function readSmallFile(string $path): string
    {
        if (!is_file($path)) throw new RuntimeException('Repository metadata file is missing.');
        $size = filesize($path);
        if (!is_int($size) || $size < 1 || $size > 4096) {
            throw new RuntimeException('Repository metadata file size is invalid.');
        }
        $content = file_get_contents($path);
        if (!is_string($content)) throw new RuntimeException('Repository metadata file is unreadable.');
        return trim($content);
    }

    private static function assertSha(string $sha, string $label): void
    {
        if (preg_match('/^[a-f0-9]{40}$/', $sha) !== 1) {
            throw new RuntimeException($label . ' must be a full 40-character Git SHA.');
        }
    }
}
