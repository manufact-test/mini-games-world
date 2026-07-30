<?php
declare(strict_types=1);

namespace Mgw\CleanRuntime\Server\Storage;

use Mgw\CleanRuntime\Server\Contracts\RuntimeRepository;

final class JsonFileRuntimeRepository implements RuntimeRepository
{
    private const SCHEMA_VERSION = 1;

    private string $stateFile;
    private string $lockFile;

    public function __construct(private readonly string $dataDirectory)
    {
        $directory = rtrim($this->dataDirectory, '/\\');
        if ($directory === '') {
            throw new \InvalidArgumentException('Staging repository directory is required.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create clean runtime staging directory.');
        }
        if (!is_writable($directory)) {
            throw new \RuntimeException('Clean runtime staging directory is not writable.');
        }

        $this->stateFile = $directory . '/runtime-state.json';
        $this->lockFile = $directory . '/runtime-state.lock';
    }

    public function bootstrap(string $installationId, array $launch): array
    {
        $installationId = $this->validateInstallationId($installationId);
        $now = gmdate('c');

        return $this->transaction(function (array &$state) use ($installationId, $launch, $now): array {
            $installations = is_array($state['installations'] ?? null) ? $state['installations'] : [];
            $existing = is_array($installations[$installationId] ?? null)
                ? $installations[$installationId]
                : [];

            $record = [
                'id' => $installationId,
                'first_seen_at' => (string)($existing['first_seen_at'] ?? $now),
                'last_seen_at' => $now,
                'launch_count' => max(0, (int)($existing['launch_count'] ?? 0)) + 1,
                'last_launch' => [
                    'runtime' => (string)($launch['runtime'] ?? ''),
                    'path' => (string)($launch['path'] ?? ''),
                    'source' => (string)($launch['source'] ?? 'standard'),
                    'invite_present' => (bool)($launch['invite_present'] ?? false),
                    'telegram_available' => (bool)($launch['telegram_available'] ?? false),
                ],
            ];

            $installations[$installationId] = $record;
            $state['schema_version'] = self::SCHEMA_VERSION;
            $state['revision'] = max(0, (int)($state['revision'] ?? 0)) + 1;
            $state['updated_at'] = $now;
            $state['installations'] = $installations;

            return [
                'installation' => [
                    'id' => $record['id'],
                    'first_seen_at' => $record['first_seen_at'],
                    'last_seen_at' => $record['last_seen_at'],
                    'launch_count' => $record['launch_count'],
                ],
                'storage' => [
                    'adapter' => 'json_file_staging',
                    'schema_version' => self::SCHEMA_VERSION,
                    'revision' => $state['revision'],
                ],
            ];
        });
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

    /**
     * @template T
     * @param callable(array<string,mixed>&):T $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        $lock = fopen($this->lockFile, 'c+');
        if ($lock === false) {
            throw new \RuntimeException('Cannot open clean runtime staging lock.');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock clean runtime staging storage.');
            }

            $state = $this->readState();
            $result = $callback($state);
            $this->writeState($state);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string,mixed> */
    private function readState(): array
    {
        if (!is_file($this->stateFile)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'revision' => 0,
                'updated_at' => null,
                'installations' => [],
            ];
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
        return $decoded;
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

    private function validateInstallationId(string $installationId): string
    {
        $value = trim($installationId);
        if (!preg_match('/^[a-zA-Z0-9_-]{20,80}$/', $value)) {
            throw new \InvalidArgumentException('Invalid clean runtime installation identifier.');
        }
        return $value;
    }
}
