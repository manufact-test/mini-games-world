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

    public function touch(
        string $accountId,
        string $sessionId,
        string $presenceLeaseId = '',
        string $room = ''
    ): void {
        $accountId = trim($accountId);
        $sessionId = trim($sessionId);
        $presenceLeaseId = trim($presenceLeaseId);
        $room = $this->normalizeRoom($room);
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
            'room' => $room,
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
            'room' => $this->normalizeRoom((string)($state['room'] ?? '')),
        ], JSON_UNESCAPED_SLASHES);
        if (is_string($payload)) @file_put_contents($path, $payload, LOCK_EX);
        $this->pruneAccountDirectory($this->accountDirectory($accountId));
    }

    /** @return list<string> */
    public function onlineAccountIds(): array
    {
        return array_keys($this->onlineAccountRooms());
    }

    /**
     * One current room per online account. Multiple live Telegram documents are
     * collapsed to the most recently touched known room. A legacy lease without
     * room metadata may keep the account online but never overwrites a newer or
     * still-live room-aware lease.
     *
     * @return array<string,string>
     */
    public function onlineAccountRooms(): array
    {
        if (!is_dir($this->directory)) return [];

        $online = [];
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . 'account-*') ?: [] as $accountDirectory) {
            if (!is_dir($accountDirectory)) continue;
            $this->pruneAccountDirectory($accountDirectory);
            $presence = $this->liveAccountPresence($accountDirectory);
            if ($presence === null) continue;

            $idFile = $accountDirectory . DIRECTORY_SEPARATOR . '.account';
            $accountId = trim((string)@file_get_contents($idFile));
            if ($accountId === '' || str_starts_with($accountId, 'bot_')) continue;
            $online[$accountId] = (string)($presence['room'] ?? '');
        }
        return $online;
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

        if ($this->liveAccountPresence($accountDirectory) === null) {
            @unlink($accountDirectory . DIRECTORY_SEPARATOR . '.account');
            @rmdir($accountDirectory);
        }
    }

    private function directoryHasLiveSession(string $accountDirectory): bool
    {
        return $this->liveAccountPresence($accountDirectory) !== null;
    }

    /** @return array{touched_at:int,room:string}|null */
    private function liveAccountPresence(string $accountDirectory): ?array
    {
        $now = time();
        $cutoff = $now - self::ONLINE_WINDOW_SEC;
        $latestTouchedAt = 0;
        $latestKnownRoomTouchedAt = 0;
        $latestKnownRoom = '';

        foreach (glob($accountDirectory . DIRECTORY_SEPARATOR . 'session-*.presence') ?: [] as $path) {
            $state = $this->readSessionState($path);
            $touchedAt = (int)($state['touched_at'] ?? 0);
            $leaveAfter = (int)($state['leave_after'] ?? 0);
            if ($touchedAt < $cutoff || ($leaveAfter > 0 && $leaveAfter <= $now)) continue;

            $latestTouchedAt = max($latestTouchedAt, $touchedAt);
            $room = $this->normalizeRoom((string)($state['room'] ?? ''));
            if ($room !== '' && $touchedAt >= $latestKnownRoomTouchedAt) {
                $latestKnownRoomTouchedAt = $touchedAt;
                $latestKnownRoom = $room;
            }
        }

        if ($latestTouchedAt <= 0) return null;
        return ['touched_at' => $latestTouchedAt, 'room' => $latestKnownRoom];
    }

    private function readSessionState(string $path): array
    {
        $raw = trim((string)@file_get_contents($path));
        if ($raw === '') return ['touched_at' => 0, 'leave_after' => 0, 'room' => ''];

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return [
                'touched_at' => (int)($decoded['touched_at'] ?? 0),
                'leave_after' => (int)($decoded['leave_after'] ?? 0),
                'room' => $this->normalizeRoom((string)($decoded['room'] ?? '')),
            ];
        }

        return ['touched_at' => (int)$raw, 'leave_after' => 0, 'room' => ''];
    }

    private function normalizeRoom(string $room): string
    {
        $room = strtolower(trim($room));
        return in_array($room, ['match', 'gold'], true) ? $room : '';
    }
}
