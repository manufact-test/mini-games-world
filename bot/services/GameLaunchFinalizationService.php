<?php
declare(strict_types=1);

require_once __DIR__ . '/MatchPreparationClockService.php';

/**
 * Owns post-create launch finalization that must happen exactly once after a
 * stored game exists. Tic-Tac-Toe keeps its one-time X/O finalization here,
 * while every explicitly new supported PvP game may enter the same shared
 * Phase B preparation lifecycle. Compatibility reads never activate legacy
 * games that were created before the shared launch lifecycle was enabled.
 */
final class GameLaunchFinalizationService
{
    private const PHASE_B_GAME_TYPES = [
        'tictactoe',
        'four_in_a_row',
        'battleship',
        'checkers',
        'reversi',
        'chess',
        'go',
        'domino',
    ];

    public static function finalizeStoredGame(
        array &$db,
        string $gameId,
        bool $activatePreparationForNewGame = false
    ): ?array {
        $gameId = trim($gameId);
        if ($gameId === '' || !isset($db['games'][$gameId]) || !is_array($db['games'][$gameId])) {
            return null;
        }

        $game =& $db['games'][$gameId];
        $game['game_type'] = (string)($game['game_type'] ?? 'tictactoe');

        if ((string)($game['status'] ?? '') !== 'active') {
            return $game;
        }

        if ($game['game_type'] === 'tictactoe') {
            self::finalizeTicTacToe($game);
        }

        if ($activatePreparationForNewGame) {
            self::activatePreparation($game);
        }

        return $game;
    }

    private static function finalizeTicTacToe(array &$game): void
    {
        if (!empty($game['symbols_randomized'])) {
            return;
        }

        $boardSize = max(1, (int)($game['board_size'] ?? 3));
        $emptyBoard = str_repeat('-', $boardSize * $boardSize);
        if ((string)($game['board'] ?? '') !== $emptyBoard) {
            // A legacy game that has already received a move must never be
            // re-randomized or pushed back into preparation on a later read.
            $game['symbols_randomized'] = true;
            return;
        }

        $playerIds = array_values(array_map('strval', $game['player_ids'] ?? []));
        if (count($playerIds) < 2) {
            $game['symbols_randomized'] = true;
            return;
        }

        if (random_int(0, 1) === 1) {
            [$playerIds[0], $playerIds[1]] = [$playerIds[1], $playerIds[0]];
        }

        $xPlayerId = $playerIds[0];
        $oPlayerId = $playerIds[1];
        $now = now_iso();

        $game['symbols'] = [$xPlayerId => 'X', $oPlayerId => 'O'];
        $game['turn'] = $xPlayerId;
        $game['turn_started_at'] = $now;
        $game['last_move_at'] = $now;
        $game['updated_at'] = $now;
        $game['symbols_randomized'] = true;

        if (!empty($game['is_bot_game'])) {
            $botId = (string)($game['bot_id'] ?? '');
            if ($botId !== '' && $xPlayerId === $botId) {
                $game['bot_move_after_at'] = gmdate('c', time() + 1);
            } else {
                unset($game['bot_move_after_at']);
            }
        }
    }

    private static function activatePreparation(array &$game): void
    {
        if (array_key_exists('launch_phase', $game)) return;
        if ((string)($game['status'] ?? '') !== 'active') return;
        if (!in_array((string)($game['game_type'] ?? ''), self::PHASE_B_GAME_TYPES, true)) return;

        $playerIds = array_values(array_filter(
            array_map('strval', $game['player_ids'] ?? []),
            static fn(string $playerId): bool => $playerId !== ''
        ));
        if (count(array_unique($playerIds)) < 2) return;

        // One shared activation owner for every supported game. Game-specific
        // board/turn setup has already completed before callers explicitly mark
        // this stored game as newly created. initializeNewGame() is idempotent
        // and clears any legacy early bot schedule before readiness begins.
        (new MatchPreparationClockService())->initializeNewGame($game);
    }
}
