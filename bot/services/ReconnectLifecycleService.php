<?php
declare(strict_types=1);

require_once __DIR__ . '/GameSettlementService.php';
require_once __DIR__ . '/GameNoContestSettlementService.php';
require_once __DIR__ . '/PresenceService.php';

/**
 * Single server-side owner for active-match disconnect/reconnect lifecycle.
 * Game engines keep owning their normal clocks and rules; while reconnect is
 * active this service freezes their existing clock fields and restores the
 * exact remaining time after the player returns.
 */
final class ReconnectLifecycleService
{
    public const RECONNECT_WINDOW_SEC = 60;
    private const FREEZE_GUARD_SEC = 86400;

    private GameSettlementService $settlement;
    private GameNoContestSettlementService $noContest;

    public function __construct(
        private array $config,
        private PresenceService $presence
    ) {
        $this->settlement = new GameSettlementService($config);
        $this->noContest = new GameNoContestSettlementService($config);
    }

    public function needsMutation(
        array $db,
        string $accountId,
        string $sessionId,
        string $action,
        array $previousPresence = []
    ): bool {
        $nowMs = $this->nowMs();
        $action = trim($action);
        $accountId = trim($accountId);

        foreach ($db['games'] ?? [] as $game) {
            if (!is_array($game) || !$this->isReconnectManagedGame($game)) continue;

            $reconnect = $game['reconnect_v2'] ?? null;
            if (is_array($reconnect) && !empty($reconnect['paused'])) {
                $players = is_array($reconnect['players'] ?? null) ? $reconnect['players'] : [];
                if (count($players) >= 2) return true;

                foreach ($players as $playerState) {
                    $deadlineMs = (int)($playerState['deadline_ms'] ?? 0);
                    if ($deadlineMs > 0 && $deadlineMs <= $nowMs) return true;
                }

                if (in_array($action, ['ping', 'status'], true)
                    && $accountId !== ''
                    && isset($players[$accountId])) {
                    return true;
                }
                continue;
            }

            foreach ($this->humanPlayerIds($game) as $playerId) {
                if ($playerId === $accountId
                    && in_array($action, ['ping', 'status'], true)
                    && (string)($previousPresence['state'] ?? '') === 'disconnected') {
                    return true;
                }

                if ($this->presence->gameplaySnapshot($playerId)['state'] === 'disconnected') {
                    return true;
                }
            }
        }

        return false;
    }

    public function synchronize(
        array &$db,
        string $accountId,
        string $sessionId,
        string $action,
        array $previousPresence = []
    ): void {
        $nowMs = $this->nowMs();

        // Reconnect deadlines are authoritative. A late ping must not revive a
        // match after its 60-second reconnect window already expired.
        foreach (array_keys($db['games'] ?? []) as $gameId) {
            if (!isset($db['games'][$gameId]) || !is_array($db['games'][$gameId])) continue;
            $game =& $db['games'][$gameId];
            if (!$this->isReconnectManagedGame($game)) {
                unset($game);
                continue;
            }
            $this->settleReconnectIfDue($db, $game, $nowMs);
            unset($game);
        }

        // A fresh client ping can arrive before an opponent had a chance to
        // observe a stale foreground lease. Preserve that stale state from the
        // pre-ping snapshot, enter reconnect, then restore in the same request.
        if (in_array($action, ['ping', 'status'], true)
            && (string)($previousPresence['state'] ?? '') === 'disconnected') {
            $disconnectedAtMs = $this->disconnectedAtFromPresence($previousPresence, $nowMs);
            $this->markPlayerDisconnected($db, $accountId, $disconnectedAtMs);
        }

        // Reconcile explicit leave and foreground lease loss for every active
        // human match. Background leases are intentionally NOT disconnects.
        foreach (array_keys($db['games'] ?? []) as $gameId) {
            if (!isset($db['games'][$gameId]) || !is_array($db['games'][$gameId])) continue;
            $game =& $db['games'][$gameId];
            if (!$this->isReconnectManagedGame($game) || !empty($game['reconnect_v2']['paused'])) {
                unset($game);
                continue;
            }

            foreach ($this->humanPlayerIds($game) as $playerId) {
                $snapshot = $this->presence->gameplaySnapshot($playerId);
                if ((string)($snapshot['state'] ?? '') !== 'disconnected') continue;
                $this->markPlayerDisconnected(
                    $db,
                    $playerId,
                    $this->disconnectedAtFromPresence($snapshot, $nowMs)
                );
                break;
            }
            unset($game);
        }

        // A different supported client may take ownership only after the match
        // has actually entered reconnect. Normal multi-device session locking is
        // otherwise left unchanged.
        if (in_array($action, ['ping', 'status'], true)) {
            $this->restorePlayerIfReconnecting($db, $accountId, $sessionId, $nowMs);
        }
    }

    public function cancelActiveGamesForServerFailure(
        array &$db,
        string $incidentId = ''
    ): int {
        $cancelled = 0;
        $incidentId = trim($incidentId);

        foreach (array_keys($db['games'] ?? []) as $gameId) {
            if (!isset($db['games'][$gameId]) || !is_array($db['games'][$gameId])) continue;
            $game =& $db['games'][$gameId];
            if (($game['status'] ?? '') !== 'active') {
                unset($game);
                continue;
            }

            $metadata = $incidentId !== '' ? ['server_incident_id' => $incidentId] : [];
            $this->noContest->cancel(
                $db,
                $game,
                'server_failure',
                'Возврат: матч отменён из-за сбоя сервера',
                $metadata
            );
            $cancelled++;
            unset($game);
        }

        if ($cancelled > 0) {
            if (!isset($db['system']) || !is_array($db['system'])) $db['system'] = [];
            if (!isset($db['system']['telemetry']) || !is_array($db['system']['telemetry'])) {
                $db['system']['telemetry'] = [];
            }
            $db['system']['telemetry']['server_failure_cancelled_games_total'] =
                (int)($db['system']['telemetry']['server_failure_cancelled_games_total'] ?? 0) + $cancelled;
            $db['system']['last_server_failure_recovery_at'] = now_iso();
            if ($incidentId !== '') $db['system']['last_server_failure_incident_id'] = $incidentId;
        }

        return $cancelled;
    }

    private function markPlayerDisconnected(array &$db, string $playerId, int $disconnectedAtMs): void
    {
        $playerId = trim($playerId);
        if ($playerId === '') return;

        $gameId = trim((string)($db['users'][$playerId]['current_game_id'] ?? ''));
        if ($gameId === '' || !isset($db['games'][$gameId]) || !is_array($db['games'][$gameId])) return;

        $game =& $db['games'][$gameId];
        if (!$this->isReconnectManagedGame($game)
            || !in_array($playerId, $this->humanPlayerIds($game), true)) {
            unset($game);
            return;
        }

        if (!isset($game['reconnect_v2']) || !is_array($game['reconnect_v2']) || empty($game['reconnect_v2']['paused'])) {
            $game['reconnect_v2'] = [
                'version' => 2,
                'paused' => true,
                'paused_at_ms' => $disconnectedAtMs,
                'players' => [],
                'clock_snapshot' => $this->freezeClock($game),
            ];
        }

        if (isset($game['reconnect_v2']['players'][$playerId])) {
            unset($game);
            return;
        }

        $deadlineMs = $disconnectedAtMs + (self::RECONNECT_WINDOW_SEC * 1000);
        $game['reconnect_v2']['players'][$playerId] = [
            'disconnected_at_ms' => $disconnectedAtMs,
            'disconnected_at' => gmdate('c', intdiv($disconnectedAtMs, 1000)),
            'deadline_ms' => $deadlineMs,
            'deadline_at' => gmdate('c', intdiv($deadlineMs, 1000)),
        ];
        $game['updated_at'] = now_iso();

        if (isset($db['users'][$playerId])) {
            $db['users'][$playerId]['reconnect_game_id'] = $gameId;
            $db['users'][$playerId]['reconnect_until'] = gmdate('c', intdiv($deadlineMs, 1000));
        }

        if (count($game['reconnect_v2']['players']) >= count($this->humanPlayerIds($game))) {
            $this->noContest->cancel(
                $db,
                $game,
                'both_disconnected',
                'Возврат: оба игрока отключились'
            );
        }
        unset($game);
    }

    private function restorePlayerIfReconnecting(
        array &$db,
        string $playerId,
        string $sessionId,
        int $nowMs
    ): void {
        $playerId = trim($playerId);
        $sessionId = trim($sessionId);
        if ($playerId === '' || $sessionId === '') return;

        $gameId = trim((string)($db['users'][$playerId]['reconnect_game_id'] ?? $db['users'][$playerId]['current_game_id'] ?? ''));
        if ($gameId === '' || !isset($db['games'][$gameId]) || !is_array($db['games'][$gameId])) return;

        $game =& $db['games'][$gameId];
        $reconnect = $game['reconnect_v2'] ?? null;
        if (!is_array($reconnect) || empty($reconnect['paused'])) {
            unset($game);
            return;
        }

        $playerState = $reconnect['players'][$playerId] ?? null;
        if (!is_array($playerState)) {
            unset($game);
            return;
        }

        $deadlineMs = (int)($playerState['deadline_ms'] ?? 0);
        if ($deadlineMs <= 0 || $deadlineMs <= $nowMs) {
            $this->settleReconnectIfDue($db, $game, $nowMs);
            unset($game);
            return;
        }

        $pausedAtMs = (int)($reconnect['paused_at_ms'] ?? $playerState['disconnected_at_ms'] ?? $nowMs);
        $pauseMs = max(0, $nowMs - $pausedAtMs);
        $this->restoreClock($game, $reconnect['clock_snapshot'] ?? [], $pauseMs);
        unset($game['reconnect_v2']);
        $game['updated_at'] = now_iso();

        if (isset($db['users'][$playerId])) {
            $db['users'][$playerId]['active_session_id'] = $sessionId;
            $db['users'][$playerId]['active_session_at'] = now_iso();
            unset($db['users'][$playerId]['reconnect_game_id'], $db['users'][$playerId]['reconnect_until']);
        }
        unset($game);
    }

    private function settleReconnectIfDue(array &$db, array &$game, int $nowMs): void
    {
        $reconnect = $game['reconnect_v2'] ?? null;
        if (!is_array($reconnect) || empty($reconnect['paused'])) return;

        $players = is_array($reconnect['players'] ?? null) ? $reconnect['players'] : [];
        if (count($players) >= count($this->humanPlayerIds($game))) {
            $this->noContest->cancel(
                $db,
                $game,
                'both_disconnected',
                'Возврат: оба игрока отключились'
            );
            return;
        }

        foreach ($players as $loserId => $playerState) {
            $deadlineMs = (int)($playerState['deadline_ms'] ?? 0);
            if ($deadlineMs <= 0 || $deadlineMs > $nowMs) continue;

            $loserId = (string)$loserId;
            $winnerId = $this->otherPlayerId($game, $loserId);
            unset($game['reconnect_v2']);
            if (isset($db['users'][$loserId])) {
                unset($db['users'][$loserId]['reconnect_game_id'], $db['users'][$loserId]['reconnect_until']);
            }
            $this->settlement->finish($db, $game, $winnerId, 'disconnect_timeout', $loserId);
            return;
        }
    }

    private function freezeClock(array &$game): array
    {
        $fields = [
            'turn_started_at',
            'turn_starts_at',
            'turn_starts_epoch_ms',
            'turn_deadline_at',
            'turn_deadline_epoch_ms',
            'bot_move_after_at',
            'setup_deadline_at',
        ];
        $snapshot = [];
        $futureSec = time() + self::FREEZE_GUARD_SEC;
        $futureMs = $this->nowMs() + (self::FREEZE_GUARD_SEC * 1000);

        foreach ($fields as $field) {
            if (!array_key_exists($field, $game)) continue;
            $snapshot[$field] = $game[$field];
            $game[$field] = str_ends_with($field, '_epoch_ms') ? $futureMs : gmdate('c', $futureSec);
        }

        unset($game['bot_move_after_at']);
        if (array_key_exists('bot_move_after_at', $snapshot)) {
            $game['bot_move_after_at'] = gmdate('c', $futureSec);
        }
        return $snapshot;
    }

    private function restoreClock(array &$game, mixed $rawSnapshot, int $pauseMs): void
    {
        if (!is_array($rawSnapshot)) return;
        $pauseSec = (int)ceil($pauseMs / 1000);

        foreach ($rawSnapshot as $field => $value) {
            if (str_ends_with((string)$field, '_epoch_ms')) {
                if ($value === null || $value === '') {
                    $game[$field] = $value;
                } elseif (is_numeric($value)) {
                    $game[$field] = (int)$value + $pauseMs;
                }
                continue;
            }

            if ($value === null || $value === '') {
                $game[$field] = $value;
                continue;
            }

            $epoch = strtotime((string)$value);
            $game[$field] = $epoch === false ? $value : gmdate('c', $epoch + $pauseSec);
        }
    }

    private function disconnectedAtFromPresence(array $snapshot, int $nowMs): int
    {
        $lastForeground = (int)($snapshot['last_foreground_at'] ?? 0);
        if ($lastForeground <= 0) return $nowMs;

        $detectedMs = ($lastForeground + $this->presence->gameDisconnectWindowSec()) * 1000;
        return max(0, min($nowMs, $detectedMs));
    }

    private function isReconnectManagedGame(array $game): bool
    {
        if (($game['status'] ?? '') !== 'active') return false;
        if (!array_key_exists('launch_phase', $game)) return true;
        return (string)($game['launch_phase'] ?? '') === 'active';
    }

    private function humanPlayerIds(array $game): array
    {
        $botId = (string)($game['bot_id'] ?? '');
        $result = [];
        foreach (array_map('strval', $game['player_ids'] ?? []) as $playerId) {
            if ($playerId === '' || $playerId === $botId || str_starts_with($playerId, 'bot_')) continue;
            $result[] = $playerId;
        }
        return array_values(array_unique($result));
    }

    private function otherPlayerId(array $game, string $playerId): string
    {
        foreach (array_map('strval', $game['player_ids'] ?? []) as $candidate) {
            if ($candidate !== '' && $candidate !== $playerId) return $candidate;
        }
        return $playerId;
    }

    private function nowMs(): int
    {
        return (int)floor(microtime(true) * 1000);
    }
}
