<?php
declare(strict_types=1);

final class MatchPreparationClockService
{
    public const PREPARATION_TIMEOUT_SEC = 10;
    public const COUNTDOWN_SEC = 3;
    public const TURN_HANDOFF_SEC = 1;
    public const TICTACTOE_TURN_HANDOFF_SEC = 0;
    public const TURN_SYNC_TIMEOUT_SEC = 5;
    public const MOVE_TIMEOUT_SEC = 60;

    public function initializeNewGame(array &$game): void
    {
        if (isset($game['launch_phase'])) return;
        if ((string)($game['status'] ?? '') !== 'active') {
            $this->normalizeExisting($game);
            return;
        }

        $now = time();
        $deadline = $now + self::PREPARATION_TIMEOUT_SEC;
        $game['launch_phase'] = 'preparing';
        $game['preparing_started_at'] = gmdate('c', $now);
        $game['preparation_deadline_at'] = gmdate('c', $deadline);
        $game['preparation_ready_devices'] = [];
        $game['activation_ready_devices'] = [];
        $game['turn_ready_devices'] = [];
        $game['turn_clock_phase'] = 'pending_launch';
        $game['turn_sync_deadline_at'] = null;
        $game['starts_at'] = null;
        $game['turn_starts_at'] = null;
        $game['turn_deadline_at'] = null;
        $game['clock_turn'] = '';
        $game['clock_revision'] = 0;

        // Legacy cleanup still reads turn_started_at. Keep that owner safely in
        // the future while the synchronized preparation phase is in progress.
        $game['turn_started_at'] = gmdate('c', $deadline);
        unset($game['bot_move_after_at']);
        $game['updated_at'] = now_iso();
    }

    public function normalizeExisting(array &$game): void
    {
        if (isset($game['launch_phase'])) return;

        $status = (string)($game['status'] ?? '');
        $game['launch_phase'] = $status === 'active' ? 'active' : 'finished';
        $game['clock_turn'] = (string)($game['turn'] ?? '');
        $game['clock_revision'] = max(1, (int)($game['clock_revision'] ?? 0));
        $game['turn_clock_phase'] = 'active';
        $game['turn_ready_devices'] = [];
        $game['turn_sync_deadline_at'] = null;

        $startedAt = (string)($game['turn_started_at'] ?? $game['last_move_at'] ?? $game['created_at'] ?? now_iso());
        if (empty($game['turn_starts_at'])) $game['turn_starts_at'] = $startedAt;
        if (empty($game['turn_deadline_at'])) {
            $started = strtotime((string)$game['turn_starts_at']) ?: time();
            $game['turn_deadline_at'] = gmdate('c', $started + self::MOVE_TIMEOUT_SEC);
        }
    }

    public function markReady(array &$game, string $userId, string $sessionId, string $deviceId): void
    {
        if ((string)($game['launch_phase'] ?? '') !== 'preparing') return;
        if ($userId === '' || $sessionId === '' || $deviceId === '') return;
        if (!in_array($userId, array_map('strval', $game['player_ids'] ?? []), true)) return;

        if (!isset($game['preparation_ready_devices']) || !is_array($game['preparation_ready_devices'])) {
            $game['preparation_ready_devices'] = [];
        }

        $deviceHash = hash('sha256', $sessionId . '|' . $deviceId);
        $existing = $game['preparation_ready_devices'][$userId] ?? null;
        if (is_array($existing) && hash_equals((string)($existing['device_hash'] ?? ''), $deviceHash)) {
            return;
        }

        $game['preparation_ready_devices'][$userId] = [
            'device_hash' => $deviceHash,
            'ready_at' => now_iso(),
        ];

        foreach (array_map('strval', $game['player_ids'] ?? []) as $playerId) {
            if ($playerId !== '' && str_starts_with($playerId, 'bot_')
                && !isset($game['preparation_ready_devices'][$playerId])) {
                $game['preparation_ready_devices'][$playerId] = [
                    'device_hash' => 'server-bot',
                    'ready_at' => now_iso(),
                ];
            }
        }
        $game['updated_at'] = now_iso();
    }

    public function markActivationReady(array &$game, string $userId, string $sessionId, string $deviceId): void
    {
        if ((string)($game['launch_phase'] ?? '') !== 'countdown') return;
        $startsAt = strtotime((string)($game['starts_at'] ?? '')) ?: 0;
        if ($startsAt <= 0 || $startsAt > time()) return;
        if ($userId === '' || $sessionId === '' || $deviceId === '') return;
        if (!in_array($userId, array_map('strval', $game['player_ids'] ?? []), true)) return;

        if (!isset($game['activation_ready_devices']) || !is_array($game['activation_ready_devices'])) {
            $game['activation_ready_devices'] = [];
        }

        $game['activation_ready_devices'][$userId] = [
            'device_hash' => hash('sha256', $sessionId . '|' . $deviceId),
            'ready_at' => now_iso(),
        ];

        foreach (array_map('strval', $game['player_ids'] ?? []) as $playerId) {
            if ($playerId !== '' && str_starts_with($playerId, 'bot_')
                && !isset($game['activation_ready_devices'][$playerId])) {
                $game['activation_ready_devices'][$playerId] = [
                    'device_hash' => 'server-bot',
                    'ready_at' => now_iso(),
                ];
            }
        }
        $game['updated_at'] = now_iso();
    }

    public function markTurnReady(array &$game, string $userId, string $sessionId, string $deviceId): void
    {
        if ((string)($game['launch_phase'] ?? '') !== 'active') return;
        if ((string)($game['turn_clock_phase'] ?? 'active') !== 'syncing') return;
        if ($userId === '' || $sessionId === '' || $deviceId === '') return;
        if (!in_array($userId, array_map('strval', $game['player_ids'] ?? []), true)) return;

        if (!isset($game['turn_ready_devices']) || !is_array($game['turn_ready_devices'])) {
            $game['turn_ready_devices'] = [];
        }

        $game['turn_ready_devices'][$userId] = [
            'device_hash' => hash('sha256', $sessionId . '|' . $deviceId),
            'ready_at' => now_iso(),
        ];

        foreach (array_map('strval', $game['player_ids'] ?? []) as $playerId) {
            if ($playerId !== '' && str_starts_with($playerId, 'bot_')
                && !isset($game['turn_ready_devices'][$playerId])) {
                $game['turn_ready_devices'][$playerId] = [
                    'device_hash' => 'server-bot',
                    'ready_at' => now_iso(),
                ];
            }
        }
        $game['updated_at'] = now_iso();
    }

    public function advance(array &$game): void
    {
        $phase = (string)($game['launch_phase'] ?? '');

        if ($phase === 'active') {
            $this->advanceSynchronizedTurnClock($game);
            return;
        }

        if ($phase === 'preparing') {
            $deadline = strtotime((string)($game['preparation_deadline_at'] ?? '')) ?: 0;
            if ($deadline > 0 && $deadline <= time()) {
                $game['launch_phase'] = 'preparation_timeout';
                $game['updated_at'] = now_iso();
                return;
            }

            if ($this->allReady($game)) {
                $startsAt = time() + self::COUNTDOWN_SEC;
                $game['launch_phase'] = 'countdown';
                $game['starts_at'] = gmdate('c', $startsAt);
                $game['turn_started_at'] = gmdate('c', $startsAt);
                $game['turn_starts_at'] = gmdate('c', $startsAt);
                $game['turn_deadline_at'] = gmdate('c', $startsAt + self::MOVE_TIMEOUT_SEC);
                $game['clock_turn'] = (string)($game['turn'] ?? '');
                $game['clock_revision'] = 1;
                $game['activation_ready_devices'] = [];
                $game['updated_at'] = now_iso();
                return;
            }
        }

        if ((string)($game['launch_phase'] ?? '') !== 'countdown') return;
        $startsAt = strtotime((string)($game['starts_at'] ?? '')) ?: 0;
        if ($startsAt > 0 && $startsAt <= time() && $this->allActivationReady($game)) {
            // Start the first playable turn only after both devices have observed
            // the shared countdown boundary. This makes the authoritative clock
            // and the first frame visible to both players one atomic lifecycle.
            $activatedAt = time();
            $game['launch_phase'] = 'active';
            $game['turn_started_at'] = gmdate('c', $activatedAt);
            $game['turn_starts_at'] = gmdate('c', $activatedAt);
            $game['turn_deadline_at'] = gmdate('c', $activatedAt + self::MOVE_TIMEOUT_SEC);
            $game['clock_turn'] = (string)($game['turn'] ?? '');
            $game['clock_revision'] = max(1, (int)($game['clock_revision'] ?? 0));
            $game['turn_clock_phase'] = 'active';
            $game['turn_ready_devices'] = [];
            $game['turn_sync_deadline_at'] = null;
            $game['updated_at'] = now_iso();
            $this->scheduleBotAfterStart($game, $activatedAt);
        }
    }

    public function assertActionAllowed(array $game): void
    {
        $phase = (string)($game['launch_phase'] ?? 'active');
        if ($phase === 'preparing') {
            throw new RuntimeException('Матч ещё синхронизирует игроков.');
        }
        if ($phase === 'preparation_timeout') {
            throw new RuntimeException('Соперник не подключился. Матч отменяется.');
        }
        if ($phase === 'countdown') {
            $startsAt = strtotime((string)($game['starts_at'] ?? '')) ?: 0;
            if ($startsAt <= 0 || $startsAt > time()) {
                throw new RuntimeException('Матч начнётся после обратного отсчёта.');
            }
        }
        if ($phase === 'active') {
            if ((string)($game['turn_clock_phase'] ?? 'active') === 'syncing') {
                throw new RuntimeException('Новый ход ещё синхронизируется между игроками.');
            }
            $turnStartsAt = strtotime((string)($game['turn_starts_at'] ?? '')) ?: 0;
            if ($turnStartsAt > time()) {
                throw new RuntimeException('Ход ещё не начался.');
            }
        }
    }

    public function assertSurrenderAllowed(array $game): void
    {
        if (!array_key_exists('launch_phase', $game)) return;
        if ((string)($game['launch_phase'] ?? '') === 'active') return;

        throw new RuntimeException('Матч ещё не начался.');
    }

    public function synchronizeTurnHandoff(array &$game, string $previousTurn): void
    {
        if ((string)($game['status'] ?? '') !== 'active') return;
        if ((string)($game['launch_phase'] ?? '') !== 'active') return;

        $currentTurn = (string)($game['turn'] ?? '');
        if ($currentTurn === '' || $currentTurn === $previousTurn) return;
        $this->beginTurnClock($game, $currentTurn);
    }

    public function synchronizeObservedTurn(array &$game): void
    {
        if ((string)($game['status'] ?? '') !== 'active') return;
        if ((string)($game['launch_phase'] ?? '') !== 'active') return;

        $turn = (string)($game['turn'] ?? '');
        if ($turn === '') return;
        $knownTurn = (string)($game['clock_turn'] ?? '');
        if ($knownTurn === '') {
            $game['clock_turn'] = $turn;
            $game['clock_revision'] = max(1, (int)($game['clock_revision'] ?? 0));
            return;
        }
        if ($knownTurn !== $turn) $this->beginTurnClock($game, $turn);
    }

    public function enrichPublicGame(array $game, array $public): array
    {
        $serverNowMs = (int)round(microtime(true) * 1000);
        $phase = (string)($game['launch_phase'] ?? ((string)($game['status'] ?? '') === 'active' ? 'active' : 'finished'));
        $turnClockPhase = (string)($game['turn_clock_phase'] ?? 'active');
        $turnStartsAtMs = $this->epochMs((string)($game['turn_starts_at'] ?? $game['turn_started_at'] ?? ''));
        $turnDeadlineMs = $this->epochMs((string)($game['turn_deadline_at'] ?? ''));
        if ($turnDeadlineMs === null && $turnStartsAtMs !== null) {
            $turnDeadlineMs = $turnStartsAtMs + (self::MOVE_TIMEOUT_SEC * 1000);
        }

        if (in_array($phase, ['preparing', 'countdown', 'preparation_timeout'], true)
            || $turnClockPhase === 'syncing'
            || ($turnStartsAtMs !== null && $serverNowMs < $turnStartsAtMs)) {
            $timeLeft = self::MOVE_TIMEOUT_SEC;
        } elseif ($turnDeadlineMs !== null) {
            $timeLeft = max(0, min(self::MOVE_TIMEOUT_SEC, (int)ceil(($turnDeadlineMs - $serverNowMs) / 1000)));
        } else {
            $timeLeft = max(0, min(self::MOVE_TIMEOUT_SEC, (int)($public['time_left'] ?? self::MOVE_TIMEOUT_SEC)));
        }

        $ready = is_array($game['preparation_ready_devices'] ?? null) ? $game['preparation_ready_devices'] : [];
        return array_replace($public, [
            'launch_phase' => $phase,
            'turn_clock_phase' => $turnClockPhase,
            'clock_pending_authority' => $turnClockPhase === 'syncing',
            'turn_sync_deadline_at' => $game['turn_sync_deadline_at'] ?? null,
            'preparing_started_at' => $game['preparing_started_at'] ?? null,
            'preparation_deadline_at' => $game['preparation_deadline_at'] ?? null,
            'preparation_deadline_ms' => $this->epochMs((string)($game['preparation_deadline_at'] ?? '')),
            'starts_at' => $game['starts_at'] ?? null,
            'starts_at_ms' => $this->epochMs((string)($game['starts_at'] ?? '')),
            'turn_starts_at' => $game['turn_starts_at'] ?? $game['turn_started_at'] ?? null,
            'turn_starts_at_ms' => $turnStartsAtMs,
            'turn_deadline_at' => $game['turn_deadline_at'] ?? null,
            'turn_deadline_ms' => $turnDeadlineMs,
            'server_now_ms' => $serverNowMs,
            'turn_revision' => (int)($game['clock_revision'] ?? 0),
            'ready_count' => count($ready),
            'ready_required' => count($game['player_ids'] ?? []),
            'time_left' => $timeLeft,
            'move_timeout_sec' => self::MOVE_TIMEOUT_SEC,
        ]);
    }

    private function allReady(array $game): bool
    {
        $ready = is_array($game['preparation_ready_devices'] ?? null) ? $game['preparation_ready_devices'] : [];
        $players = array_map('strval', $game['player_ids'] ?? []);
        if (count($players) < 2) return false;
        foreach ($players as $playerId) {
            if ($playerId === '' || !isset($ready[$playerId])) return false;
        }
        return true;
    }

    private function allActivationReady(array $game): bool
    {
        $ready = is_array($game['activation_ready_devices'] ?? null) ? $game['activation_ready_devices'] : [];
        $players = array_map('strval', $game['player_ids'] ?? []);
        if (count($players) < 2) return false;
        foreach ($players as $playerId) {
            if ($playerId === '' || !isset($ready[$playerId])) return false;
        }
        return true;
    }

    private function beginTurnClock(array &$game, string $turn): void
    {
        if ((string)($game['game_type'] ?? '') !== 'tictactoe') {
            $this->assignTurnClock($game, $turn);
            return;
        }

        $startsAt = time() + self::TURN_SYNC_TIMEOUT_SEC;
        $game['turn_started_at'] = gmdate('c', $startsAt);
        $game['turn_starts_at'] = gmdate('c', $startsAt);
        $game['turn_deadline_at'] = gmdate('c', $startsAt + self::MOVE_TIMEOUT_SEC);
        $game['turn_sync_deadline_at'] = gmdate('c', $startsAt);
        $game['turn_clock_phase'] = 'syncing';
        $game['turn_ready_devices'] = [];
        $game['clock_turn'] = $turn;
        $game['clock_revision'] = (int)($game['clock_revision'] ?? 0) + 1;
        $game['updated_at'] = now_iso();
    }

    private function advanceSynchronizedTurnClock(array &$game): void
    {
        if ((string)($game['turn_clock_phase'] ?? 'active') !== 'syncing') return;
        $syncDeadline = strtotime((string)($game['turn_sync_deadline_at'] ?? '')) ?: 0;
        if (!$this->allTurnReady($game) && ($syncDeadline <= 0 || $syncDeadline > time())) return;

        $startsAt = time();
        $game['turn_started_at'] = gmdate('c', $startsAt);
        $game['turn_starts_at'] = gmdate('c', $startsAt);
        $game['turn_deadline_at'] = gmdate('c', $startsAt + self::MOVE_TIMEOUT_SEC);
        $game['turn_clock_phase'] = 'active';
        $game['updated_at'] = now_iso();
        $this->scheduleBotAfterStart($game, $startsAt);
    }

    private function allTurnReady(array $game): bool
    {
        $ready = is_array($game['turn_ready_devices'] ?? null) ? $game['turn_ready_devices'] : [];
        $players = array_map('strval', $game['player_ids'] ?? []);
        if (count($players) < 2) return false;
        foreach ($players as $playerId) {
            if ($playerId === '' || !isset($ready[$playerId])) return false;
        }
        return true;
    }

    private function assignTurnClock(array &$game, string $turn): void
    {
        $startsAt = time() + $this->turnHandoffSeconds($game);
        $game['turn_started_at'] = gmdate('c', $startsAt);
        $game['turn_starts_at'] = gmdate('c', $startsAt);
        $game['turn_deadline_at'] = gmdate('c', $startsAt + self::MOVE_TIMEOUT_SEC);
        $game['clock_turn'] = $turn;
        $game['clock_revision'] = (int)($game['clock_revision'] ?? 0) + 1;
        $game['turn_clock_phase'] = 'active';
        $game['turn_ready_devices'] = [];
        $game['turn_sync_deadline_at'] = null;
        $game['updated_at'] = now_iso();
        $this->scheduleBotAfterStart($game, $startsAt);
    }

    /** @param array<string,mixed> $game */
    private function turnHandoffSeconds(array $game): int
    {
        return (string)($game['game_type'] ?? '') === 'tictactoe'
            ? self::TICTACTOE_TURN_HANDOFF_SEC
            : self::TURN_HANDOFF_SEC;
    }

    private function scheduleBotAfterStart(array &$game, int $startsAt): void
    {
        $botId = (string)($game['bot_id'] ?? '');
        if (!empty($game['is_bot_game']) && $botId !== '' && (string)($game['turn'] ?? '') === $botId) {
            $game['bot_move_after_at'] = gmdate('c', $startsAt + 1);
        }
    }

    private function epochMs(string $value): ?int
    {
        if ($value === '') return null;
        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp * 1000;
    }
}
