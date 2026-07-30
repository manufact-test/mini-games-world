<?php
declare(strict_types=1);

namespace Mgw\CleanRuntime\Server\Storage;

use Mgw\CleanRuntime\Server\Contracts\RuntimeStateStore;

final class JsonFileRuntimeStore implements RuntimeStateStore
{
    private const SCHEMA_VERSION = 3;

    private string $stateFile;
    private string $lockFile;

    public function __construct(private readonly string $dataDirectory)
    {
        $directory = rtrim($this->dataDirectory, '/\\');
        if ($directory === '') {
            throw new \InvalidArgumentException('Staging store directory is required.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create clean runtime staging directory.');
        }
        if (!is_writable($directory)) {
            throw new \RuntimeException('Clean runtime staging directory is not writable.');
        }

        $this->stateFile = $directory . '/runtime-state-v3.json';
        $this->lockFile = $directory . '/runtime-state-v3.lock';
    }

    public function read(callable $operation): mixed
    {
        $lock = $this->openLock();

        try {
            if (!flock($lock, LOCK_SH)) {
                throw new \RuntimeException('Cannot acquire clean runtime staging read lock.');
            }

            return $operation($this->readState());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function transaction(callable $operation): mixed
    {
        $lock = $this->openLock();

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock clean runtime staging storage.');
            }

            $state = $this->readState();
            $state['schema_version'] = self::SCHEMA_VERSION;
            $state['revision'] = max(0, (int)($state['revision'] ?? 0)) + 1;
            $state['updated_at'] = gmdate('c');

            $result = $operation($state);
            $this->writeState($state);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function health(): array
    {
        return [
            'adapter' => 'json_file_staging',
            'schema_version' => self::SCHEMA_VERSION,
            'writable' => is_writable(dirname($this->stateFile)),
            'state_present' => is_file($this->stateFile),
            'location_fingerprint' => substr(hash('sha256', dirname($this->stateFile)), 0, 12),
        ];
    }

    /** @return resource */
    private function openLock()
    {
        $lock = fopen($this->lockFile, 'c+');
        if ($lock === false) {
            throw new \RuntimeException('Cannot open clean runtime staging lock.');
        }
        return $lock;
    }

    /** @return array<string,mixed> */
    private function readState(): array
    {
        if (!is_file($this->stateFile)) {
            return $this->emptyState();
        }

        $json = file_get_contents($this->stateFile);
        if (!is_string($json) || trim($json) === '') {
            throw new \RuntimeException('Clean runtime staging state is unreadable.');
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Clean runtime staging state must be an object.');
        }
        if ((int)($decoded['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            throw new \RuntimeException('Unsupported clean runtime staging schema.');
        }

        foreach (['installations', 'accounts', 'sessions', 'presence', 'queue', 'games', 'commands', 'ledger', 'system'] as $key) {
            if (!isset($decoded[$key]) || !is_array($decoded[$key])) {
                $decoded[$key] = [];
            }
        }
        return $decoded;
    }

    /** @return array<string,mixed> */
    private function emptyState(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'revision' => 0,
            'updated_at' => null,
            'installations' => [],
            'accounts' => [],
            'sessions' => [],
            'presence' => [],
            'queue' => [],
            'games' => [],
            'commands' => [],
            'ledger' => [],
            'system' => ['fees_match' => 0],
        ];
    }

    /** @param array<string,mixed> $state */
    private function writeState(array $state): void
    {
        $json = json_encode(
            $state,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n";

        $temporary = $this->stateFile . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write clean runtime staging state.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $this->stateFile)) {
            @unlink($temporary);
            throw new \RuntimeException('Cannot publish clean runtime staging state.');
        }
        @chmod($this->stateFile, 0600);
    }
}
