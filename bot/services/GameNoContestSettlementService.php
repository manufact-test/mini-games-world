<?php
declare(strict_types=1);

require_once __DIR__ . '/../economy/UnifiedBalanceRuntimeState.php';

/**
 * Owns active-match cancellation cases that must refund entry cost without
 * recording a played game, win/loss or draw. Normal/technical match finishes
 * remain owned by GameSettlementService.
 */
final class GameNoContestSettlementService
{
    public function __construct(private array $config) {}

    public function cancel(
        array &$db,
        array &$game,
        string $reason,
        string $description,
        array $metadata = []
    ): void {
        UnifiedBalanceRuntimeState::migrateAll($db);

        if (($game['status'] ?? '') === 'finished' || !empty($game['payout_done'])) {
            return;
        }

        $gameId = (string)($game['id'] ?? '');
        $room = (string)($game['room'] ?? 'match');
        $gameType = (string)($game['game_type'] ?? 'tictactoe');
        $bet = max(0, (int)($game['bet'] ?? 0));
        $playerCount = max(2, count($game['player_ids'] ?? []));
        $bank = $bet * $playerCount;
        $balanceKey = UnifiedBalanceRuntimeState::FIELD;
        $now = now_iso();

        $game['status'] = 'finished';
        if (array_key_exists('launch_phase', $game)) {
            $game['launch_phase'] = 'cancelled';
        }
        $game['winner_id'] = null;
        $game['loser_id'] = null;
        $game['finish_reason'] = $reason;
        $game['no_contest'] = true;
        $game['bank'] = $bank;
        $game['payout'] = 0;
        $game['commission'] = 0;
        $game['finished_at'] = $now;
        $game['updated_at'] = $now;
        $game['no_contest_settled_at'] = $now;
        $game['payout_done'] = true;
        $game['payout_done_at'] = $now;
        unset($game['bot_move_after_at'], $game['reconnect_v2']);

        if (!isset($db['transactions']) || !is_array($db['transactions'])) {
            $db['transactions'] = [];
        }

        foreach ($game['player_ids'] ?? [] as $playerId) {
            $pid = (string)$playerId;
            if ($pid === '' || str_starts_with($pid, 'bot_') || !isset($db['users'][$pid])) {
                continue;
            }

            $db['users'][$pid][$balanceKey] = (int)($db['users'][$pid][$balanceKey] ?? 0) + $bet;
            $db['transactions'][] = array_merge([
                'id' => make_id('tx'),
                'type' => 'balance_change',
                'category' => 'game_refund',
                'user_id' => $pid,
                'username' => (string)($db['users'][$pid]['username'] ?? ''),
                'room' => $room,
                'amount' => $bet,
                'balance_after' => (int)($db['users'][$pid][$balanceKey] ?? 0),
                'game_id' => $gameId,
                'description' => $description,
                'finish_reason' => $reason,
                'no_contest' => true,
                'match_started' => true,
                'game_type' => $gameType,
                'created_at' => $now,
            ], $metadata);

            if ((string)($db['users'][$pid]['current_game_id'] ?? '') === $gameId
                || (string)($db['users'][$pid]['status'] ?? '') === 'playing') {
                $db['users'][$pid]['status'] = 'idle';
                $db['users'][$pid]['current_game_id'] = null;
            }
            unset($db['users'][$pid]['reconnect_game_id'], $db['users'][$pid]['reconnect_until']);
        }

        $db['transactions'][] = array_merge([
            'id' => make_id('tx'),
            'type' => 'game_cancel',
            'game_id' => $gameId,
            'game_type' => $gameType,
            'room' => $room,
            'winner_id' => null,
            'loser_id' => null,
            'finish_reason' => $reason,
            'bank' => $bank,
            'commission' => 0,
            'payout' => 0,
            'no_contest' => true,
            'match_started' => true,
            'is_bot_game' => !empty($game['is_bot_game']),
            'bot_difficulty' => $game['bot_difficulty'] ?? null,
            'created_at' => $now,
        ], $metadata);
    }
}
