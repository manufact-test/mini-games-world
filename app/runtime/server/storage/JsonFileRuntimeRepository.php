<?php
declare(strict_types=1);

namespace Mgw\CleanRuntime\Server\Storage;

use Mgw\CleanRuntime\Server\Contracts\RuntimeRepository;

final class JsonFileRuntimeRepository implements RuntimeRepository
{
    private const SCHEMA_VERSION = 2;

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

        $this->stateFile = $directory . '/runtime-state-v2.json';
        $this->lockFile = $directory . '/runtime-state-v2.lock';
    }

    public function bootstrap(
        array $identity,
        string $installationId,
        string $sessionId,
        array $launch,
        array $presence,
        int $sessionTimeoutSec,
        int $presenceTtlSec,
    ): array {
        $identity = $this->validateIdentity($identity);
        $installationId = $this->validateIdentifier($installationId, 'installation');
        $sessionId = $this->validateIdentifier($sessionId, 'session');
        $nowEpoch = time();
        $now = gmdate('c', $nowEpoch);

        return $this->transaction(function (array &$state) use (
            $identity,
            $installationId,
            $sessionId,
            $launch,
            $presence,
            $sessionTimeoutSec,
            $presenceTtlSec,
            $nowEpoch,
            $now,
        ): array {
            $installation = $this->touchInstallation($state, $installationId, $launch, $now);
            $account = $this->touchAccount($state, $identity, $now);
            [$account, $session] = $this->touchSession(
                $state,
                $account,
                $sessionId,
                $installationId,
                $sessionTimeoutSec,
                $nowEpoch,
                $now,
            );
            $presenceRecord = $this->touchPresence(
                $state,
                (string)$account['id'],
                $sessionId,
                $presence,
                $presenceTtlSec,
                $nowEpoch,
                $now,
            );
            $state['accounts'][(string)$account['id']] = $account;
            $this->advanceRevision($state, $now);

            return $this->projection($state, $installation, $account, $session, $presenceRecord, $sessionTimeoutSec);
        });
    }

    public function heartbeat(
        array $identity,
        string $installationId,
        string $sessionId,
        array $presence,
        int $sessionTimeoutSec,
        int $presenceTtlSec,
    ): array {
        $identity = $this->validateIdentity($identity);
        $installationId = $this->validateIdentifier($installationId, 'installation');
        $sessionId = $this->validateIdentifier($sessionId, 'session');
        $accountId = (string)$identity['id'];
        $nowEpoch = time();
        $now = gmdate('c', $nowEpoch);

        return $this->transaction(function (array &$state) use (
            $identity,
            $installationId,
            $sessionId,
            $presence,
            $sessionTimeoutSec,
            $presenceTtlSec,
            $accountId,
            $nowEpoch,
            $now,
        ): array {
            $account = is_array($state['accounts'][$accountId] ?? null)
                ? $state['accounts'][$accountId]
                : null;
            $session = is_array($state['sessions'][$sessionId] ?? null)
                ? $state['sessions'][$sessionId]
                : null;
            $installation = is_array($state['installations'][$installationId] ?? null)
                ? $state['installations'][$installationId]
                : null;

            if ($account === null || $session === null || $installation === null) {
                throw new \RuntimeException('Clean staging session is not initialized.');
            }
            if ((string)($session['account_id'] ?? '') !== $accountId
                || (string)($session['installation_id'] ?? '') !== $installationId) {
                throw new \RuntimeException('Clean staging session ownership mismatch.');
            }

            $account = array_replace($account, $identity, ['updated_at' => $now]);
            [$account, $session] = $this->touchSession(
                $state,
                $account,
                $sessionId,
                $installationId,
                $sessionTimeoutSec,
                $nowEpoch,
                $now,
            );
            $presenceRecord = $this->touchPresence(
                $state,
                $accountId,
                $sessionId,
                $presence,
                $presenceTtlSec,
                $nowEpoch,
                $now,
            );
            $state['accounts'][$accountId] = $account;
            $this->advanceRevision($state, $now);

            return $this->projection($state, $installation, $account, $session, $presenceRecord, $sessionTimeoutSec);
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

    /** @param array<string,mixed> $state */
    private function touchInstallation(array &$state, string $installationId, array $launch, string $now): array
    {
        $existing = is_array($state['installations'][$installationId] ?? null)
            ? $state['installations'][$installationId]
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
        $state['installations'][$installationId] = $record;
        return $record;
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $identity */
    private function touchAccount(array &$state, array $identity, string $now): array
    {
        $accountId = (string)$identity['id'];
        $existing = is_array($state['accounts'][$accountId] ?? null)
            ? $state['accounts'][$accountId]
            : [];
        return array_replace($existing, $identity, [
            'status' => (string)($existing['status'] ?? 'idle'),
            'active_session_id' => $existing['active_session_id'] ?? null,
            'active_session_at' => $existing['active_session_at'] ?? null,
            'created_at' => (string)($existing['created_at'] ?? $now),
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $account
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function touchSession(
        array &$state,
        array $account,
        string $sessionId,
        string $installationId,
        int $sessionTimeoutSec,
        int $nowEpoch,
        string $now,
    ): array {
        $accountId = (string)$account['id'];
        $status = (string)($account['status'] ?? 'idle');
        $activeSessionId = trim((string)($account['active_session_id'] ?? ''));
        $activeAt = strtotime((string)($account['active_session_at'] ?? '')) ?: 0;
        $activeExpired = $activeAt <= 0 || $nowEpoch - $activeAt > $sessionTimeoutSec;
        $locked = in_array($status, ['searching', 'playing'], true)
            && $activeSessionId !== ''
            && $activeSessionId !== $sessionId
            && !$activeExpired;

        if (!$locked) {
            $account['active_session_id'] = $sessionId;
            $account['active_session_at'] = $now;
            $activeSessionId = $sessionId;
        }

        $existing = is_array($state['sessions'][$sessionId] ?? null)
            ? $state['sessions'][$sessionId]
            : [];
        if ($existing !== [] && (string)($existing['account_id'] ?? '') !== $accountId) {
            throw new \RuntimeException('Clean staging session identifier collision.');
        }

        $session = [
            'id' => $sessionId,
            'account_id' => $accountId,
            'installation_id' => $installationId,
            'first_seen_at' => (string)($existing['first_seen_at'] ?? $now),
            'last_seen_at' => $now,
            'locked' => $locked,
            'active_session_id' => $activeSessionId !== '' ? $activeSessionId : null,
        ];
        $state['sessions'][$sessionId] = $session;
        return [$account, $session];
    }

    /** @param array<string,mixed> $state */
    private function touchPresence(
        array &$state,
        string $accountId,
        string $sessionId,
        array $presence,
        int $presenceTtlSec,
        int $nowEpoch,
        string $now,
    ): array {
        $record = [
            'account_id' => $accountId,
            'session_id' => $sessionId,
            'state' => 'online',
            'visibility' => (string)($presence['visibility'] ?? 'unknown'),
            'platform' => (string)($presence['platform'] ?? 'unknown'),
            'timezone_offset' => (int)($presence['timezone_offset'] ?? 0),
            'last_seen_at' => $now,
            'expires_at' => gmdate('c', $nowEpoch + $presenceTtlSec),
        ];
        $state['presence'][$accountId] = $record;
        return $record;
    }

    /** @param array<string,mixed> $state */
    private function advanceRevision(array &$state, string $now): void
    {
        $state['schema_version'] = self::SCHEMA_VERSION;
        $state['revision'] = max(0, (int)($state['revision'] ?? 0)) + 1;
        $state['updated_at'] = $now;
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $installation
     * @param array<string,mixed> $account
     * @param array<string,mixed> $session
     * @param array<string,mixed> $presence
     * @return array<string,mixed>
     */
    private function projection(
        array $state,
        array $installation,
        array $account,
        array $session,
        array $presence,
        int $sessionTimeoutSec,
    ): array {
        return [
            'installation' => [
                'id' => $installation['id'],
                'first_seen_at' => $installation['first_seen_at'],
                'last_seen_at' => $installation['last_seen_at'],
                'launch_count' => $installation['launch_count'],
            ],
            'account' => [
                'id' => $account['id'],
                'auth_method' => $account['auth_method'],
                'telegram_id' => $account['telegram_id'],
                'first_name' => $account['first_name'],
                'last_name' => $account['last_name'],
                'username' => $account['username'],
                'language_code' => $account['language_code'],
                'status' => $account['status'],
            ],
            'session' => [
                'id' => $session['id'],
                'active_session_id' => $session['active_session_id'],
                'locked' => $session['locked'],
                'timeout_sec' => $sessionTimeoutSec,
            ],
            'presence' => [
                'state' => $presence['state'],
                'visibility' => $presence['visibility'],
                'last_seen_at' => $presence['last_seen_at'],
                'expires_at' => $presence['expires_at'],
            ],
            'storage' => [
                'adapter' => 'json_file_staging',
                'schema_version' => self::SCHEMA_VERSION,
                'revision' => $state['revision'],
            ],
        ];
    }

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    private function validateIdentity(array $identity): array
    {
        $id = trim((string)($identity['id'] ?? ''));
        $method = trim((string)($identity['auth_method'] ?? ''));
        if (!preg_match('/^[a-zA-Z0-9_-]{6,96}$/', $id)
            || !in_array($method, ['telegram', 'browser_staging'], true)) {
            throw new \InvalidArgumentException('Invalid clean runtime identity.');
        }
        return [
            'id' => $id,
            'auth_method' => $method,
            'telegram_id' => isset($identity['telegram_id']) ? (string)$identity['telegram_id'] : null,
            'first_name' => substr(trim((string)($identity['first_name'] ?? '')), 0, 80),
            'last_name' => substr(trim((string)($identity['last_name'] ?? '')), 0, 80),
            'username' => substr(trim((string)($identity['username'] ?? '')), 0, 64),
            'language_code' => substr(trim((string)($identity['language_code'] ?? '')), 0, 16),
        ];
    }

    private function validateIdentifier(string $value, string $label): string
    {
        $value = trim($value);
        if (!preg_match('/^[a-zA-Z0-9_-]{20,96}$/', $value)) {
            throw new \InvalidArgumentException('Invalid clean runtime ' . $label . ' identifier.');
        }
        return $value;
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
                'accounts' => [],
                'sessions' => [],
                'presence' => [],
            ];
        }
        $json = file_get_contents($this->stateFile);
        if (!is_string($json) || trim($json) === '') {
            throw new \RuntimeException('Clean runtime staging state is unreadable.');
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || (int)($decoded['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
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
}
