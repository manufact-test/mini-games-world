<?php
declare(strict_types=1);

final class PresenceService
{
    private const ONLINE_WINDOW_SEC = 75;
    private const GAME_DISCONNECT_WINDOW_SEC = 8;
    private const LEAVE_GRACE_SEC = 12;
    private const GAMEPLAY_STATE_RETENTION_SEC = 21600;
    private const MARKER_FILE = '.enabled';

    private string $directory;

    public function __construct(?string $directory = null)
    {
        $configuredDataDirectory = trim((string)($GLOBALS['config']['data_dir'] ?? ''));
        $dataDirectory = $configuredDataDirectory !== ''
            ? $configuredDataDirectory
            : dirname(__DIR__) . '/data';
        $defaultDirectory = rtrim($dataDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . '.runtime'
            . DIRECTORY_SEPARATOR . 'presence';

        $this->directory = rtrim($directory ?: $defaultDirectory, DIRECTORY_SEPARATOR);
    }

    public function touch(string $accountId, string $sessionId, string $presenceLeaseId = ''): void
    {
        $this->writeLease($accountId, $sessionId, $presenceLeaseId, 'foreground');
    }

    public function background(string $accountId, string $sessionId, string $presenceLeaseId = ''): void
    {
        $this->writeLease($accountId, $sessionId, $presenceLeaseId, 'background');
    }

    public function leave(string $accountId, string $sessionId, string $presenceLeaseId = ''): void
    {
        $accountId = trim($accountId);
        $sessionId = trim($sessionId);
        $presenceLeaseId = trim($presenceLeaseId);
        if ($accountId === '' || $sessionId === '') return;

        $this->ensureDirectory();
        @touch($this->directory . DIRECTORY_SEPARATOR . self::MARKER_FILE);
        $path = $this->sessionPath($accountId, $sessionId, $presenceLeaseId);
        if (!is_file($path)) return;

        $state = $this->readSessionState($path);
        $payload = json_encode([
            'touched_at' => max(1, (int)($state['touched_at'] ?? time())),
            'leave_after' => time() + self::LEAVE_GRACE_SEC,
            'mode' => 'left',
        ], JSON_UNESCAPED_SLASHES);
        if (is_string($payload)) @file_put_contents($path, $payload, LOCK_EX);
        $this->pruneAccountDirectory($this->accountDirectory($accountId));
    }

    /**
     * Gameplay presence intentionally differs from the public online counter.
     * A background document remains connected-idle even after public online
     * freshness expires; only a foreground lease that stops heartbeating or an
     * explicit leave becomes a gameplay disconnect.
     *
     * @return array{state:string,last_foreground_at:int}
     */
    public function gameplaySnapshot(string $accountId): array
    {
        $accountId = trim($accountId);
        if ($accountId === '' || str_starts_with($accountId, 'bot_')) {
            return ['state' => 'unknown', 'last_foreground_at' => 0];
        }

        $accountDirectory = $this->accountDirectoryPath($accountId);
        if (!is_dir($accountDirectory)) {
            return ['state' => 'unknown', 'last_foreground_at' => 0];
        }

        $this->pruneAccountDirectory($accountDirectory);
        if (!is_dir($accountDirectory)) {
            return ['state' => 'unknown', 'last_foreground_at' => 0];
        }

        $now = time();
        $foregroundCutoff = $now - self::GAME_DISCONNECT_WINDOW_SEC;
        $lastForegroundAt = 0;
        $knownLease = false;
        $hasBackground = false;

        foreach (glob($accountDirectory . DIRECTORY_SEPARATOR . 'session-*.presence') ?: [] as $path) {
            $state = $this->readSessionState($path);
            $knownLease = true;
            $mode = (string)($state['mode'] ?? 'foreground');
            $touchedAt = (int)($state['touched_at'] ?? 0);
            $leaveAfter = (int)($state['leave_after'] ?? 0);

            if ($mode === 'foreground') {
                $lastForegroundAt = max($lastForegroundAt, $touchedAt);
                if ($touchedAt >= $foregroundCutoff && ($leaveAfter <= 0 || $leaveAfter > $now)) {
                    return ['state' => 'foreground', 'last_foreground_at' => $lastForegroundAt];
                }
                continue;
            }

            if ($mode === 'background' && ($leaveAfter <= 0 || $leaveAfter > $now)) {
                $hasBackground = true;
            }
        }

        if ($hasBackground) {
            return ['state' => 'background', 'last_foreground_at' => $lastForegroundAt];
        }
        if ($knownLease) {
            return ['state' => 'disconnected', 'last_foreground_at' => $lastForegroundAt];
        }
        return ['state' => 'unknown', 'last_foreground_at' => 0];
    }

    /** @return list<string> */
    public function onlineAccountIds(): array
    {
        if (!is_dir($this->directory)) return [];

        $online = [];
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . 'account-*') ?: [] as $accountDirectory) {
            if (!is_dir($accountDirectory)) continue;
            $this->pruneAccountDirectory($accountDirectory);
            if (!$this->directoryHasLiveSession($accountDirectory)) continue;

            $accountId = trim((string)@file_get_contents($accountDirectory . DIRECTORY_SEPARATOR . '.account'));
            if ($accountId === '' || str_starts_with($accountId, 'bot_')) continue;
            $online[] = $accountId;
        }
        return array_values(array_unique($online));
    }

    public function isEnabled(): bool
    {
        return is_file($this->directory . DIRECTORY_SEPARATOR . self::MARKER_FILE);
    }

    public function onlineWindowSec(): int
    {
        return self::ONLINE_WINDOW_SEC;
    }

    public function gameDisconnectWindowSec(): int
    {
        return self::GAME_DISCONNECT_WINDOW_SEC;
    }

    private function writeLease(
        string $accountId,
        string $sessionId,
        string $presenceLeaseId,
        string $mode
    ): void {
        $accountId = trim($accountId);
        $sessionId = trim($sessionId);
        $presenceLeaseId = trim($presenceLeaseId);
        if ($accountId === '' || $sessionId === '' || str_starts_with($accountId, 'bot_')) return;

        $this->ensureDirectory();
        @touch($this->directory . DIRECTORY_SEPARATOR . self::MARKER_FILE);

        $accountDirectory = $this->accountDirectory($accountId);
        if (!is_dir($accountDirectory) && !@mkdir($accountDirectory, 0700, true) && !is_dir($accountDirectory)) {
            throw new RuntimeException('Не удалось обновить присутствие игрока.');
        }

        $path = $this->sessionPath($accountId, $sessionId, $presenceLeaseId);
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(4));
        $payload = json_encode([
            'touched_at' => time(),
            'leave_after' => 0,
            'mode' => $mode === 'background' ? 'background' : 'foreground',
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($payload) || @file_put_contents($temporary, $payload, LOCK_EX) === false) {
            @unlink($temporary);
            throw new RuntimeException('Не удалось обновить присутствие игрока.');
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Не удалось обновить присутствие игрока.');
        }

        $this->pruneAccountDirectory($accountDirectory);
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Не удалось подготовить присутствие игроков.');
        }
    }

    private function accountDirectory(string $accountId): string
    {
        $directory = $this->accountDirectoryPath($accountId);
        if (!is_dir($directory) && is_dir($this->directory)) @mkdir($directory, 0700, true);
        if (is_dir($directory)) {
            $idFile = $directory . DIRECTORY_SEPARATOR . '.account';
            if (!is_file($idFile)) {
                @file_put_contents($idFile, $accountId, LOCK_EX);
                @chmod($idFile, 0600);
            }
        }
        return $directory;
    }

    private function accountDirectoryPath(string $accountId): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'account-' . hash('sha256', $accountId);
    }

    private function sessionPath(string $accountId, string $sessionId, string $presenceLeaseId = ''): string
    {
        $leaseKey = $presenceLeaseId === ''
            ? $sessionId
            : $sessionId . "\0presence:" . $presenceLeaseId;
        return $this->accountDirectory($accountId)
            . DIRECTORY_SEPARATOR
            . 'session-' . hash('sha256', $leaseKey) . '.presence';
    }

    private function pruneAccountDirectory(string $accountDirectory): void
    {
        if (!is_dir($accountDirectory)) return;
        $now = time();
        $retentionCutoff = $now - self::GAMEPLAY_STATE_RETENTION_SEC;

        foreach (glob($accountDirectory . DIRECTORY_SEPARATOR . 'session-*.presence') ?: [] as $path) {
            $state = $this->readSessionState($path);
            $touchedAt = (int)($state['touched_at'] ?? 0);
            $leaveAfter = (int)($state['leave_after'] ?? 0);
            $mode = (string)($state['mode'] ?? 'foreground');

            if ($touchedAt <= 0 || $touchedAt < $retentionCutoff) {
                @unlink($path);
                continue;
            }
            if ($mode === 'left' && $leaveAfter > 0 && $leaveAfter <= $now) {
                @unlink($path);
            }
        }

        $leases = glob($accountDirectory . DIRECTORY_SEPARATOR . 'session-*.presence') ?: [];
        if ($leases === []) {
            @unlink($accountDirectory . DIRECTORY_SEPARATOR . '.account');
            @rmdir($accountDirectory);
        }
    }

    private function directoryHasLiveSession(string $accountDirectory): bool
    {
        $now = time();
        $cutoff = $now - self::ONLINE_WINDOW_SEC;
        foreach (glob($accountDirectory . DIRECTORY_SEPARATOR . 'session-*.presence') ?: [] as $path) {
            $state = $this->readSessionState($path);
            $touchedAt = (int)($state['touched_at'] ?? 0);
            $leaveAfter = (int)($state['leave_after'] ?? 0);
            if ($touchedAt >= $cutoff && ($leaveAfter <= 0 || $leaveAfter > $now)) return true;
        }
        return false;
    }

    private function readSessionState(string $path): array
    {
        $raw = trim((string)@file_get_contents($path));
        if ($raw === '') return ['touched_at' => 0, 'leave_after' => 0, 'mode' => 'foreground'];

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $mode = (string)($decoded['mode'] ?? 'foreground');
            if (!in_array($mode, ['foreground', 'background', 'left'], true)) $mode = 'foreground';
            return [
                'touched_at' => (int)($decoded['touched_at'] ?? 0),
                'leave_after' => (int)($decoded['leave_after'] ?? 0),
                'mode' => $mode,
            ];
        }

        return ['touched_at' => (int)$raw, 'leave_after' => 0, 'mode' => 'foreground'];
    }
}
