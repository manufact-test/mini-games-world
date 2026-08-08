<?php
declare(strict_types=1);

/**
 * Owns post-create launch finalization that must happen exactly once after a
 * stored game exists. Phase B will extend this owner with preparation state;
 * for now it only centralizes the already-accepted Tic-Tac-Toe X/O launch
 * randomization without changing game creation, stakes, settlement or status.
 */
final class GameLaunchFinalizationService
{
    public static function finalizeStoredGame(array &$db, string $gameId): ?array
    {
        $gameId = trim($gameId);
        if ($gameId === '' || !isset($db['games'][$gameId]) || !is_array($db['games'][$gameId])) {
            return null;
        }

        $game =& $db['games'][$gameId];
        $game['game_type'] = (string)($game['game_type'] ?? 'tictactoe');

        if ($game['game_type'] !== 'tictactoe'
            || (string)($game['status'] ?? '') !== 'active'
            || !empty($game['symbols_randomized'])) {
            return $game;
        }

        $boardSize = max(1, (int)($game['board_size'] ?? 3));
        $emptyBoard = str_repeat('-', $boardSize * $boardSize);
        if ((string)($game['board'] ?? '') !== $emptyBoard) {
            // A legacy game that has already received a move must never be
            // re-randomized on a later compatibility read.
            $game['symbols_randomized'] = true;
            return $game;
        }

        $playerIds = array_values(array_map('strval', $game['player_ids'] ?? []));
        if (count($playerIds) < 2) {
            $game['symbols_randomized'] = true;
            return $game;
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

        return $game;
    }
}
