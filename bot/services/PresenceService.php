<?php
declare(strict_types=1);

final class PresenceService
{
    // Telegram deactivation/backgrounding is not the same as leaving the app.
    // A longer bounded window keeps two genuinely open accounts visible while
    // pagehide still removes a closed client after a short handoff grace.
    private const ONLINE_WINDOW_SEC = 75;
    private const LEAVE_GRACE_SEC = 12;
    private const MARKER_FILE = '.enabled';

    private string $directory;

    public function __construct(?string $directory = null)
    {
        // Every request must resolve the same configured data root. Production
        // may place runtime JSON outside bot/data, so deriving presence only from
        // __DIR__ can split writers and readers even inside one deployment.
        $configuredDataDirectory = trim((string)($GLOBALS['config']['data_dir'] ?? ''));
        $dataDirectory = $configuredDataDirectory !== ''
            ? $configuredDataDirectory
            : dirname(__DIR__) . '/data';
        $defaultDirectory = rtrim($dataDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . '.runtime'
            . DIRECTORY_SEPARATOR . 'presence';

        $this->directory = rtrim(
            $directory ?: $defaultDirectory,
            DIRECTORY_SEPARATOR
        );
    }

    public function touch(string $accountId, string $sessionId, string $presenceLeaseId = ''): void
    {
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
        ], JSON_UNESCAPED_SLASHES);
        if (is_string($payload)) @file_put_contents($path, $payload, LOCK_EX);
        $this->pruneAccountDirectory($this->accountDirectory($accountId));
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

            $idFile = $accountDirectory . DIRECTORY_SEPARATOR . '.account';
            $accountId = trim((string)@file_get_contents($idFile));
            if ($accountId === '' || str_starts_with($accountId, 'bot_')) continue;
            $online[$accountId] = true;
        }
        return array_keys($online);
    }

    public function isEnabled(): bool
    {
        return is_file($this->directory . DIRECTORY_SEPARATOR . self::MARKER_FILE);
    }

    public function onlineWindowSec(): int
    {
        return self::ONLINE_WINDOW_SEC;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Не удалось подготовить присутствие игроков.');
        }
    }

    private function accountDirectory(string $accountId): string
    {
        $directory = $this->directory . DIRECTORY_SEPARATOR . 'account-' . hash('sha256', $accountId);
        if (!is_dir($directory) && is_dir($this->directory)) {
            @mkdir($directory, 0700, true);
        }
        if (is_dir($directory)) {
            $idFile = $directory . DIRECTORY_SEPARATOR . '.account';
            if (!is_file($idFile)) {
                @file_put_contents($idFile, $accountId, LOCK_EX);
                @chmod($idFile, 0600);
            }
        }
        return $directory;
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
        $cutoff = $now - self::ONLINE_WINDOW_SEC;
        foreach (glob($accountDirectory . DIRECTORY_SEPARATOR . 'session-*.presence') ?: [] as $path) {
            $state = $this->readSessionState($path);
            $touchedAt = (int)($state['touched_at'] ?? 0);
            $leaveAfter = (int)($state['leave_after'] ?? 0);
            if ($touchedAt <= 0
                || $touchedAt < $cutoff
                || ($leaveAfter > 0 && $leaveAfter <= $now)) {
                @unlink($path);
            }
        }

        if (!$this->directoryHasLiveSession($accountDirectory)) {
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
        if ($raw === '') return ['touched_at' => 0, 'leave_after' => 0];

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return [
                'touched_at' => (int)($decoded['touched_at'] ?? 0),
                'leave_after' => (int)($decoded['leave_after'] ?? 0),
            ];
        }

        return ['touched_at' => (int)$raw, 'leave_after' => 0];
    }
}
