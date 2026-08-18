<?php
declare(strict_types=1);

require_once __DIR__ . '/MatchPreparationClockService.php';
require_once __DIR__ . '/GameSettlementService.php';
require_once __DIR__ . '/PresenceService.php';
require_once __DIR__ . '/ReconnectLifecycleService.php';

final class MatchPreparationRuntimeService
{
    private MatchPreparationClockService $clock;
    private GameSettlementService $settlement;
    private ReconnectLifecycleService $reconnect;

    public function __construct(array $config)
    {
        $this->clock = new MatchPreparationClockService();
        $this->settlement = new GameSettlementService($config);
        $this->reconnect = new ReconnectLifecycleService($config, new PresenceService());
    }

    public function synchronizeCurrentGame(
        array &$db,
        array &$user,
        string $gameId,
        string $requestedGameId,
        string $sessionId,
        string $deviceId
    ): ?array {
        $gameId = trim($gameId);
        if ($gameId === '' || !isset($db['games'][$gameId]) || !is_array($db['games'][$gameId])) {
            return null;
        }

        $game =& $db['games'][$gameId];
        $userId = (string)($user['id'] ?? '');
        $playerIds = array_map('strval', $game['player_ids'] ?? []);
        $isCurrentParticipant = $userId !== ''
            && (string)($user['current_game_id'] ?? '') === $gameId
            && in_array($userId, $playerIds, true);

        // A stale response may still project an older game, but it must never
        // advance, settle or claim reconnect/session ownership for lifecycle the
        // user no longer holds.
        if (!$isCurrentParticipant) {
            return $game;
        }

        // Reconnect ownership applies to both Phase-B games and compatible active
        // games that predate launch_phase. An authenticated game_state request
        // from the current participant is authoritative proof that the client has
        // returned, so release a live reconnect pause before any Phase-B-only
        // early return. Reuse the single reconnect owner; do not duplicate clocks.
        $this->reconnect->synchronize($db, $userId, $sessionId, 'ping', []);
        if (($game['status'] ?? '') !== 'active') {
            return $game;
        }

        // Compatibility reads must not migrate an existing legacy match into the
        // newer preparation state machine. Reconnect has already been restored;
        // only Phase-B clock/readiness work is gated by launch_phase.
        if (!array_key_exists('launch_phase', $game)) {
            return $game;
        }

        $requestedGameId = trim($requestedGameId);
        $readyIntent = $requestedGameId !== '' && $requestedGameId === $gameId;
        if ($readyIntent) {
            $this->clock->markReady($game, $userId, $sessionId, $deviceId);
        }

        $this->clock->advance($game);
        if ((string)($game['launch_phase'] ?? '') === 'preparation_timeout') {
            $this->settlement->cancelPreparation($db, $game);
            return $game;
        }

        $this->clock->synchronizeObservedTurn($game);
        return $game;
    }
}
