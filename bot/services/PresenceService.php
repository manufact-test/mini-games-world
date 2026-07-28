<?php
declare(strict_types=1);

final class PresenceService
{
    private const ONLINE_WINDOW_SEC = 10;
    private const MAX_SESSIONS_PER_ACCOUNT = 8;

    public function touch(array &$user, string $sessionId): void
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') return;

        $this->ensureShape($user);
        $this->prune($user);

        $now = now_iso();
        $user['presence_sessions'][$sessionId] = [
            'last_seen_at' => $now,
        ];
        $user['last_seen_at'] = $now;

        if (count($user['presence_sessions']) > self::MAX_SESSIONS_PER_ACCOUNT) {
            uasort($user['presence_sessions'], static function (array $left, array $right): int {
                $leftAt = strtotime((string)($left['last_seen_at'] ?? '')) ?: 0;
                $rightAt = strtotime((string)($right['last_seen_at'] ?? '')) ?: 0;
                return $rightAt <=> $leftAt;
            });
            $user['presence_sessions'] = array_slice(
                $user['presence_sessions'],
                0,
                self::MAX_SESSIONS_PER_ACCOUNT,
                true
            );
        }
    }

    public function leave(array &$user, string $sessionId): void
    {
        $sessionId = trim($sessionId);
        $this->ensureShape($user);
        if ($sessionId !== '') unset($user['presence_sessions'][$sessionId]);
        $this->prune($user);

        if ($user['presence_sessions'] === []) {
            $user['last_seen_at'] = gmdate('c', time() - self::ONLINE_WINDOW_SEC - 1);
            return;
        }

        $latest = 0;
        foreach ($user['presence_sessions'] as $session) {
            if (!is_array($session)) continue;
            $latest = max($latest, strtotime((string)($session['last_seen_at'] ?? '')) ?: 0);
        }
        if ($latest > 0) $user['last_seen_at'] = gmdate('c', $latest);
    }

    public function isOnline(array $user, ?int $now = null): bool
    {
        $now ??= time();
        $sessions = $user['presence_sessions'] ?? null;
        if (is_array($sessions)) {
            foreach ($sessions as $session) {
                if (!is_array($session)) continue;
                $last = strtotime((string)($session['last_seen_at'] ?? '')) ?: 0;
                if ($last > 0 && $now - $last <= self::ONLINE_WINDOW_SEC) return true;
            }
            return false;
        }

        $legacyLast = strtotime((string)($user['last_seen_at'] ?? '')) ?: 0;
        return $legacyLast > 0 && $now - $legacyLast <= self::ONLINE_WINDOW_SEC;
    }

    public function onlineWindowSec(): int
    {
        return self::ONLINE_WINDOW_SEC;
    }

    private function ensureShape(array &$user): void
    {
        if (!isset($user['presence_sessions']) || !is_array($user['presence_sessions'])) {
            $user['presence_sessions'] = [];
        }
    }

    private function prune(array &$user): void
    {
        $cutoff = time() - self::ONLINE_WINDOW_SEC;
        foreach ($user['presence_sessions'] as $sessionId => $session) {
            if (!is_array($session)) {
                unset($user['presence_sessions'][$sessionId]);
                continue;
            }
            $last = strtotime((string)($session['last_seen_at'] ?? '')) ?: 0;
            if ($last <= 0 || $last < $cutoff) unset($user['presence_sessions'][$sessionId]);
        }
    }
}
