<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/localization/ServerLocalization.php';

final class SessionService
{
    private const HEARTBEAT_WRITE_INTERVAL_SEC = 30;

    public function __construct(private array $config) {}

    public function ensureSessionShape(array &$user): void
    {
        $user['active_session_id'] = $user['active_session_id'] ?? null;
        $user['active_session_at'] = $user['active_session_at'] ?? null;
    }

    public function touch(array &$user, string $sessionId): void
    {
        $this->ensureSessionShape($user);
        if ($sessionId === '') {
            return;
        }

        if ($this->canTakeSession($user, $sessionId)) {
            $previousId = (string)($user['active_session_id'] ?? '');
            $user['active_session_id'] = $sessionId;
            if ($previousId !== $sessionId || $this->heartbeatWriteIsDue($user)) {
                $user['active_session_at'] = now_iso();
            }
        }
    }

    public function assertCanPlay(array $user, string $sessionId): void
    {
        if ($sessionId === '') {
            throw new RuntimeException(ServerLocalization::copy('session.device_session_unknown', 'The device session could not be identified. Close the app and reopen it from Telegram.'));
        }

        if ($this->canTakeSession($user, $sessionId)) {
            return;
        }

        $status = $user['status'] ?? 'idle';
        if ($status === 'playing') {
            throw new RuntimeException(ServerLocalization::copy('session.active_game_other_device', 'You already have an active game on another device. Continue the game there.'));
        }

        if ($status === 'searching') {
            throw new RuntimeException(ServerLocalization::copy('session.search_other_device', 'You are already searching for a match on another device. Finish the search there or wait a few minutes.'));
        }

        throw new RuntimeException(ServerLocalization::copy('session.lock_default', 'The game is already open on another device.'));
    }

    public function canTakeSession(array $user, string $sessionId): bool
    {
        if ($sessionId === '') {
            return false;
        }

        $status = (string)($user['status'] ?? 'idle');
        $activeId = (string)($user['active_session_id'] ?? '');

        // If the user is neither searching nor playing, a new device may become active.
        if (!in_array($status, ['searching', 'playing'], true)) {
            return true;
        }

        if ($activeId === '' || $activeId === $sessionId) {
            return true;
        }

        // MVP-17.4: a different supported client may take ownership only while
        // the server has an explicit, still-live reconnect window for this same
        // active game. Generic search/game session locking remains unchanged.
        if ($this->hasActiveReconnectWindow($user)) {
            return true;
        }

        return $this->isExpired($user);
    }

    public function publicState(array &$user, string $sessionId): array
    {
        $this->ensureSessionShape($user);

        $status = (string)($user['status'] ?? 'idle');
        $activeId = (string)($user['active_session_id'] ?? '');

        // Polling from the device that already owns the active search/game keeps
        // that ownership alive. Requests from another session never refresh it.
        // The timestamp is persisted at a coarse interval so background polling
        // does not rewrite users.json on every request.
        if ($sessionId !== ''
            && in_array($status, ['searching', 'playing'], true)
            && $activeId !== ''
            && $activeId === $sessionId
            && $this->heartbeatWriteIsDue($user)) {
            $user['active_session_at'] = now_iso();
        }

        $locked = $sessionId !== ''
            && in_array($status, ['searching', 'playing'], true)
            && $activeId !== ''
            && $activeId !== $sessionId
            && !$this->hasActiveReconnectWindow($user)
            && !$this->isExpired($user);

        $message = null;
        if ($locked && $status === 'playing') {
            $message = ServerLocalization::copy('session.active_game_other_device', 'You already have an active game on another device. Continue the game there.');
        } elseif ($locked && $status === 'searching') {
            $message = ServerLocalization::copy('session.search_other_device', 'You are already searching for a match on another device. Finish the search there or wait a few minutes.');
        }

        return [
            'id' => $sessionId,
            'active_session_id' => $activeId ?: null,
            'locked' => $locked,
            'message' => $message,
            'timeout_sec' => $this->timeoutSec(),
        ];
    }

    public function releaseIfCurrent(array &$user, string $sessionId): void
    {
        $this->ensureSessionShape($user);
        if (in_array((string)($user['status'] ?? 'idle'), ['searching', 'playing'], true)) {
            return;
        }
        if ($sessionId !== '' && ($user['active_session_id'] ?? null) === $sessionId) {
            $user['active_session_id'] = null;
            $user['active_session_at'] = null;
        }
    }

    private function hasActiveReconnectWindow(array $user): bool
    {
        if ((string)($user['status'] ?? '') !== 'playing') return false;

        $currentGameId = trim((string)($user['current_game_id'] ?? ''));
        $reconnectGameId = trim((string)($user['reconnect_game_id'] ?? ''));
        if ($currentGameId === '' || $reconnectGameId === '' || $currentGameId !== $reconnectGameId) {
            return false;
        }

        $reconnectUntil = strtotime((string)($user['reconnect_until'] ?? '')) ?: 0;
        return $reconnectUntil > time();
    }

    private function isExpired(array $user): bool
    {
        $activeAt = strtotime((string)($user['active_session_at'] ?? '')) ?: 0;
        if ($activeAt <= 0) {
            return true;
        }

        return time() - $activeAt > $this->timeoutSec();
    }

    private function heartbeatWriteIsDue(array $user): bool
    {
        $activeAt = strtotime((string)($user['active_session_at'] ?? '')) ?: 0;
        if ($activeAt <= 0) {
            return true;
        }

        $safeInterval = min(
            self::HEARTBEAT_WRITE_INTERVAL_SEC,
            max(10, intdiv(max(30, $this->timeoutSec()), 3))
        );
        return time() - $activeAt >= $safeInterval;
    }

    private function timeoutSec(): int
    {
        return (int)($this->config['active_session_timeout_sec'] ?? 180);
    }
}
