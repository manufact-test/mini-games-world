<?php
declare(strict_types=1);

require_once __DIR__ . '/MatchPreparationClockService.php';
require_once __DIR__ . '/GameSettlementService.php';

final class MatchPreparationRuntimeService
{
    private MatchPreparationClockService $clock;
    private GameSettlementService $settlement;

    public function __construct(array $config)
    {
        $this->clock = new MatchPreparationClockService();
        $this->settlement = new GameSettlementService($config);
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
        if (!array_key_exists('launch_phase', $game)) {
            return $game;
        }

        $userId = (string)($user['id'] ?? '');
        $playerIds = array_map('strval', $game['player_ids'] ?? []);
        $isCurrentParticipant = $userId !== ''
            && (string)($user['current_game_id'] ?? '') === $gameId
            && in_array($userId, $playerIds, true);

        // A stale response may still project an older game, but it must never
        // advance, settle or mark readiness for lifecycle ownership the user no
        // longer holds.
        if (!$isCurrentParticipant) {
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
