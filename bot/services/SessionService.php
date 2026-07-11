<?php
declare(strict_types=1);

final class SessionService
{
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
            $user['active_session_id'] = $sessionId;
            $user['active_session_at'] = now_iso();
        }
    }

    public function assertCanPlay(array $user, string $sessionId): void
    {
        if ($sessionId === '') {
            throw new RuntimeException('Не удалось определить сессию устройства. Закройте приложение и откройте заново из Telegram.');
        }

        if ($this->canTakeSession($user, $sessionId)) {
            return;
        }

        $status = $user['status'] ?? 'idle';
        if ($status === 'playing') {
            throw new RuntimeException('У вас уже идёт активная игра на другом устройстве. Продолжайте игру там.');
        }

        if ($status === 'searching') {
            throw new RuntimeException('Вы уже ищете матч на другом устройстве. Завершите поиск там или подождите несколько минут.');
        }

        throw new RuntimeException('Игра уже открыта на другом устройстве.');
    }

    public function canTakeSession(array $user, string $sessionId): bool
    {
        if ($sessionId === '') {
            return false;
        }

        $status = (string)($user['status'] ?? 'idle');
        $activeId = (string)($user['active_session_id'] ?? '');

        // Если пользователь не ищет и не играет, новое устройство может стать активным.
        if (!in_array($status, ['searching', 'playing'], true)) {
            return true;
        }

        if ($activeId === '' || $activeId === $sessionId) {
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
        if ($sessionId !== ''
            && in_array($status, ['searching', 'playing'], true)
            && $activeId !== ''
            && $activeId === $sessionId) {
            $user['active_session_at'] = now_iso();
        }

        $locked = $sessionId !== ''
            && in_array($status, ['searching', 'playing'], true)
            && $activeId !== ''
            && $activeId !== $sessionId
            && !$this->isExpired($user);

        $message = null;
        if ($locked && $status === 'playing') {
            $message = 'У вас уже идёт активная игра на другом устройстве. Продолжайте игру там.';
        } elseif ($locked && $status === 'searching') {
            $message = 'Вы уже ищете матч на другом устройстве. Завершите поиск там или подождите несколько минут.';
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
        if ($sessionId !== '' && ($user['active_session_id'] ?? null) === $sessionId) {
            $user['active_session_id'] = null;
            $user['active_session_at'] = null;
        }
    }

    private function isExpired(array $user): bool
    {
        $activeAt = strtotime((string)($user['active_session_at'] ?? '')) ?: 0;
        if ($activeAt <= 0) {
            return true;
        }

        return time() - $activeAt > $this->timeoutSec();
    }

    private function timeoutSec(): int
    {
        return (int)($this->config['active_session_timeout_sec'] ?? 180);
    }
}
