<?php
declare(strict_types=1);

final class MatchPreparationClockService
{
    public const PREPARATION_TIMEOUT_SEC = 10;
    public const COUNTDOWN_SEC = 3;
    public const TURN_HANDOFF_SEC = 1;
    public const MOVE_TIMEOUT_SEC = 60;
    public const RECENT_GAME_WINDOW_SEC = 20;

    public function initializeLaunch(array &$game): void
    {
        if (isset($game['launch_phase'])) return;

        $createdAt = strtotime((string)($game['created_at'] ?? '')) ?: 0;
        $isRecent = $createdAt > 0 && time() - $createdAt <= self::RECENT_GAME_WINDOW_SEC;
        if ((string)($game['status'] ?? '') !== 'active' || !$isRecent) {
            $game['launch_phase'] = (string)($game['status'] ?? '') === 'active' ? 'active' : 'finished';
            $game['v111_clock_turn'] = (string)($game['turn'] ?? '');
            $game['v111_clock_revision'] = max(1, (int)($game['v111_clock_revision'] ?? 0));
            if (empty($game['turn_starts_at'])) {
                $game['turn_starts_at'] = (string)($game['turn_started_at'] ?? $game['last_move_at'] ?? $game['created_at'] ?? now_iso());
            }
            if (empty($game['turn_deadline_at'])) {
                $started = strtotime((string)$game['turn_starts_at']) ?: time();
                $game['turn_deadline_at'] = gmdate('c', $started + self::MOVE_TIMEOUT_SEC);
            }
            return;
        }

        $now = time();
        $deadline = $now + self::PREPARATION_TIMEOUT_SEC;
        $game['launch_phase'] = 'preparing';
        $game['preparing_started_at'] = gmdate('c', $now);
        $game['preparation_deadline_at'] = gmdate('c', $deadline);
        $game['v111_ready_devices'] = [];
        $game['starts_at'] = null;
        $game['turn_starts_at'] = null;
        $game['turn_deadline_at'] = null;
        $game['v111_clock_turn'] = '';
        $game['v111_clock_revision'] = 0;
        // Keep legacy cleanup from consuming the turn while both clients prepare.
        $game['turn_started_at'] = gmdate('c', $deadline);
        unset($game['bot_move_after_at']);
        $game['updated_at'] = now_iso();
    }

    public function markReady(array &$game, string $userId, string $sessionId): void
    {
        if ((string)($game['launch_phase'] ?? '') !== 'preparing') return;
        if (!isset($game['v111_ready_devices']) || !is_array($game['v111_ready_devices'])) {
            $game['v111_ready_devices'] = [];
        }

        $game['v111_ready_devices'][$userId] = [
            'device' => hash('sha256', $sessionId),
            'ready_at' => now_iso(),
        ];

        foreach (array_map('strval', $game['player_ids'] ?? []) as $playerId) {
            if ($playerId !== '' && str_starts_with($playerId, 'bot_')) {
                $game['v111_ready_devices'][$playerId] = [
                    'device' => 'server-bot',
                    'ready_at' => now_iso(),
                ];
            }
        }
    }

    public function startCountdownIfReady(array &$game): void
    {
        if ((string)($game['launch_phase'] ?? '') !== 'preparing' || !$this->allReady($game)) return;

        $startsAt = time() + self::COUNTDOWN_SEC;
        $game['launch_phase'] = 'countdown';
        $game['starts_at'] = gmdate('c', $startsAt);
        $game['turn_started_at'] = gmdate('c', $startsAt);
        $game['turn_starts_at'] = gmdate('c', $startsAt);
        $game['turn_deadline_at'] = gmdate('c', $startsAt + self::MOVE_TIMEOUT_SEC);
        $game['v111_clock_turn'] = (string)($game['turn'] ?? '');
        $game['v111_clock_revision'] = 1;
        $game['updated_at'] = now_iso();
        $this->scheduleBotAfterStart($game, $startsAt);
    }

    public function activateIfDue(array &$game): void
    {
        if ((string)($game['launch_phase'] ?? '') !== 'countdown') return;
        $startsAt = strtotime((string)($game['starts_at'] ?? '')) ?: 0;
        if ($startsAt > 0 && $startsAt <= time()) {
            $game['launch_phase'] = 'active';
            $game['updated_at'] = now_iso();
        }
    }

    public function markPreparationTimeout(array &$game): void
    {
        if ((string)($game['launch_phase'] ?? '') !== 'preparing') return;
        $deadline = strtotime((string)($game['preparation_deadline_at'] ?? '')) ?: 0;
        if ($deadline <= 0 || $deadline > time()) return;

        // Settlement must run through api.php so all economy/ledger hooks execute.
        $game['launch_phase'] = 'preparation_timeout';
        $game['turn_started_at'] = gmdate('c', time() + 3600);
        $game['updated_at'] = now_iso();
    }

    public function assertLaunchReady(array $game): void
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
    }

    public function synchronizeTurnHandoff(array &$game, string $previousTurn): void
    {
        if ((string)($game['status'] ?? '') !== 'active') return;
        $currentTurn = (string)($game['turn'] ?? '');
        if ($currentTurn === '' || $currentTurn === $previousTurn) return;
        $this->assignTurnClock($game, $currentTurn);
    }

    public function synchronizeObservedTurn(array &$game): void
    {
        if ((string)($game['status'] ?? '') !== 'active' || (string)($game['launch_phase'] ?? '') !== 'active') return;
        $turn = (string)($game['turn'] ?? '');
        if ($turn === '') return;

        $knownTurn = (string)($game['v111_clock_turn'] ?? '');
        if ($knownTurn === '') {
            $game['v111_clock_turn'] = $turn;
            $game['v111_clock_revision'] = max(1, (int)($game['v111_clock_revision'] ?? 0));
            return;
        }
        if ($knownTurn !== $turn) $this->assignTurnClock($game, $turn);
    }

    public function settlePreparationTimeout(array &$db, array &$game): array
    {
        $phase = (string)($game['launch_phase'] ?? '');
        if (!in_array($phase, ['preparing', 'preparation_timeout'], true)) return $game;

        $deadline = strtotime((string)($game['preparation_deadline_at'] ?? '')) ?: 0;
        if ($deadline <= 0 || $deadline > time()) {
            throw new RuntimeException('Подготовка матча ещё продолжается.');
        }
        if (!empty($game['v111_preparation_refund_done'])) return $game;

        $room = (string)($game['room'] ?? 'match') === 'gold' ? 'gold' : 'match';
        $balanceKey = $room === 'gold' ? 'balance_gold' : 'balance_match';
        $bet = max(0, (int)($game['bet'] ?? 0));
        $gameId = (string)($game['id'] ?? '');
        $playerIds = array_map('strval', $game['player_ids'] ?? []);

        foreach ($playerIds as $playerId) {
            if ($playerId === '' || str_starts_with($playerId, 'bot_')) continue;
            if (!isset($db['users'][$playerId]) || !is_array($db['users'][$playerId])) continue;

            $db['users'][$playerId][$balanceKey] = (int)($db['users'][$playerId][$balanceKey] ?? 0) + $bet;
            if ((string)($db['users'][$playerId]['current_game_id'] ?? '') === $gameId) {
                $db['users'][$playerId]['status'] = 'idle';
                $db['users'][$playerId]['current_game_id'] = null;
            }
            $db['transactions'][] = [
                'id' => make_id('tx'),
                'type' => 'balance_change',
                'category' => 'game_preparation_refund',
                'user_id' => $playerId,
                'username' => (string)($db['users'][$playerId]['username'] ?? ''),
                'room' => $room,
                'amount' => $bet,
                'balance_after' => (int)$db['users'][$playerId][$balanceKey],
                'game_id' => $gameId,
                'description' => 'Возврат коинов: соперник не подключился к матчу',
                'finish_reason' => 'preparation_timeout',
                'created_at' => now_iso(),
            ];
        }

        $game['status'] = 'finished';
        $game['launch_phase'] = 'cancelled';
        $game['winner_id'] = null;
        $game['loser_id'] = null;
        $game['finish_reason'] = 'preparation_timeout';
        $game['payout'] = $bet;
        $game['commission'] = 0;
        $game['payout_done'] = true;
        $game['payout_done_at'] = now_iso();
        $game['finished_at'] = now_iso();
        $game['updated_at'] = now_iso();
        $game['v111_preparation_refund_done'] = true;
        $db['transactions'][] = [
            'id' => make_id('tx'),
            'type' => 'game_finish',
            'game_id' => $gameId,
            'room' => $room,
            'winner_id' => null,
            'loser_id' => null,
            'finish_reason' => 'preparation_timeout',
            'bank' => $bet * max(2, count($playerIds)),
            'commission' => 0,
            'payout' => 0,
            'is_bot_game' => !empty($game['is_bot_game']),
            'created_at' => now_iso(),
        ];

        return $game;
    }

    public function enrichPublicGame(array $game, array $public): array
    {
        $serverNowMs = (int)round(microtime(true) * 1000);
        $phase = (string)($game['launch_phase'] ?? ((string)($game['status'] ?? '') === 'active' ? 'active' : 'finished'));
        $turnStartsAtMs = $this->epochMs((string)($game['turn_starts_at'] ?? $game['turn_started_at'] ?? ''));
        $turnDeadlineMs = $this->epochMs((string)($game['turn_deadline_at'] ?? ''));
        if ($turnDeadlineMs === null && $turnStartsAtMs !== null) {
            $turnDeadlineMs = $turnStartsAtMs + (self::MOVE_TIMEOUT_SEC * 1000);
        }

        if (in_array($phase, ['preparing', 'countdown', 'preparation_timeout'], true)
            || ($turnStartsAtMs !== null && $serverNowMs < $turnStartsAtMs)) {
            $timeLeft = self::MOVE_TIMEOUT_SEC;
        } elseif ($turnDeadlineMs !== null) {
            $timeLeft = max(0, min(self::MOVE_TIMEOUT_SEC, (int)ceil(($turnDeadlineMs - $serverNowMs) / 1000)));
        } else {
            $timeLeft = max(0, min(self::MOVE_TIMEOUT_SEC, (int)($public['time_left'] ?? self::MOVE_TIMEOUT_SEC)));
        }

        $ready = is_array($game['v111_ready_devices'] ?? null) ? $game['v111_ready_devices'] : [];
        return array_replace($public, [
            'launch_phase' => $phase,
            'preparing_started_at' => $game['preparing_started_at'] ?? null,
            'preparation_deadline_at' => $game['preparation_deadline_at'] ?? null,
            'preparation_deadline_ms' => $this->epochMs((string)($game['preparation_deadline_at'] ?? '')),
            'starts_at' => $game['starts_at'] ?? null,
            'starts_at_ms' => $this->epochMs((string)($game['starts_at'] ?? '')),
            'turn_started_at' => $game['turn_started_at'] ?? null,
            'turn_starts_at' => $game['turn_starts_at'] ?? $game['turn_started_at'] ?? null,
            'turn_starts_at_ms' => $turnStartsAtMs,
            'turn_deadline_at' => $game['turn_deadline_at'] ?? null,
            'turn_deadline_ms' => $turnDeadlineMs,
            'server_now_ms' => $serverNowMs,
            'turn_revision' => (int)($game['v111_clock_revision'] ?? 0),
            'ready_count' => count($ready),
            'ready_required' => count($game['player_ids'] ?? []),
            'time_left' => $timeLeft,
            'move_timeout_sec' => self::MOVE_TIMEOUT_SEC,
        ]);
    }

    private function allReady(array $game): bool
    {
        $ready = is_array($game['v111_ready_devices'] ?? null) ? $game['v111_ready_devices'] : [];
        foreach (array_map('strval', $game['player_ids'] ?? []) as $playerId) {
            if ($playerId === '' || !isset($ready[$playerId])) return false;
        }
        return count($game['player_ids'] ?? []) >= 2;
    }

    private function assignTurnClock(array &$game, string $turn): void
    {
        $startsAt = time() + self::TURN_HANDOFF_SEC;
        $game['launch_phase'] = 'active';
        $game['turn_started_at'] = gmdate('c', $startsAt);
        $game['turn_starts_at'] = gmdate('c', $startsAt);
        $game['turn_deadline_at'] = gmdate('c', $startsAt + self::MOVE_TIMEOUT_SEC);
        $game['v111_clock_turn'] = $turn;
        $game['v111_clock_revision'] = (int)($game['v111_clock_revision'] ?? 0) + 1;
        $game['updated_at'] = now_iso();
        $this->scheduleBotAfterStart($game, $startsAt);
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
        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp * 1000;
    }
}
