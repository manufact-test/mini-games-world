<?php
declare(strict_types=1);

final class GameSettlementService
{
    public function __construct(private array $config) {}

    public function finish(
        array &$db,
        array &$game,
        ?string $winnerId,
        ?string $reason = null,
        ?string $loserId = null
    ): void {
        if (!empty($game['payout_done'])) {
            $game['status'] = 'finished';
            $game['updated_at'] = now_iso();
            $this->releaseGamePlayers($db, $game);
            return;
        }

        if (($game['status'] ?? '') === 'finished') {
            return;
        }

        $room = (string)($game['room'] ?? 'match');
        $balanceKey = $room === 'gold' ? 'balance_gold' : 'balance_match';
        $bet = (int)($game['bet'] ?? 0);
        $playerCount = max(2, count($game['player_ids'] ?? []));
        $bank = $bet * $playerCount;
        $game['bank'] = $bank;
        $commission = 0;
        $payout = 0;
        $isBotGame = !empty($game['is_bot_game']);
        $botId = (string)($game['bot_id'] ?? '');
        $gameType = (string)($game['game_type'] ?? 'tictactoe');

        if ($winnerId === null) {
            $reason = $reason ?: 'draw';
        } else {
            $reason = $reason ?: 'normal_win';
            $loserId = $loserId ?: $this->otherPlayerId($game, $winnerId);
        }

        $game['status'] = 'finished';
        $game['winner_id'] = $winnerId;
        $game['loser_id'] = $loserId;
        $game['finish_reason'] = $reason;
        $game['finished_at'] = now_iso();
        $game['updated_at'] = now_iso();

        if ($winnerId === null) {
            foreach ($game['player_ids'] ?? [] as $playerId) {
                $pid = (string)$playerId;
                if (!isset($db['users'][$pid])) {
                    continue;
                }

                $db['users'][$pid][$balanceKey] = (int)($db['users'][$pid][$balanceKey] ?? 0) + $bet;
                $this->addBalanceChange(
                    $db,
                    $db['users'][$pid],
                    'game_refund',
                    $room,
                    $bet,
                    (string)($game['id'] ?? ''),
                    'Возврат коинов при ничьей',
                    [
                        'finish_reason' => 'draw',
                        'is_bot_game' => $isBotGame,
                        'game_type' => $gameType,
                    ]
                );
            }
            $game['payout'] = $bet;
            $game['commission'] = 0;
        } else {
            $commission = (int)ceil($bank * (float)($this->config['commission_rate'] ?? 0.10));
            $payout = max(0, $bank - $commission);

            if (isset($db['users'][$winnerId])) {
                $db['users'][$winnerId][$balanceKey] = (int)($db['users'][$winnerId][$balanceKey] ?? 0) + $payout;
                $this->addBalanceChange(
                    $db,
                    $db['users'][$winnerId],
                    'game_win',
                    $room,
                    $payout,
                    (string)($game['id'] ?? ''),
                    'Выигрыш за матч',
                    [
                        'finish_reason' => $reason,
                        'loser_id' => $loserId,
                        'commission' => $commission,
                        'is_bot_game' => $isBotGame,
                        'bot_difficulty' => $game['bot_difficulty'] ?? null,
                        'game_type' => $gameType,
                    ]
                );
            }

            if (!isset($db['system']) || !is_array($db['system'])) {
                $db['system'] = [];
            }
            $db['system']['fees_' . $room] = (int)($db['system']['fees_' . $room] ?? 0) + $commission;
            $game['payout'] = $payout;
            $game['commission'] = $commission;
        }

        foreach ($game['player_ids'] ?? [] as $playerId) {
            $pid = (string)$playerId;
            if (!isset($db['users'][$pid])) {
                continue;
            }

            $db['users'][$pid]['status'] = 'idle';
            $db['users'][$pid]['current_game_id'] = null;
            $db['users'][$pid]['stats']['games_played'] = (int)($db['users'][$pid]['stats']['games_played'] ?? 0) + 1;

            if ($room === 'match') {
                $db['users'][$pid]['stats']['match_games_this_week'] = (int)($db['users'][$pid]['stats']['match_games_this_week'] ?? 0) + 1;
            }

            if ($room === 'gold') {
                $db['users'][$pid]['gold_wagered_total'] = (int)($db['users'][$pid]['gold_wagered_total'] ?? 0) + $bet;
            }

            if ($winnerId === null) {
                $db['users'][$pid]['stats']['draws'] = (int)($db['users'][$pid]['stats']['draws'] ?? 0) + 1;
            } elseif ($pid === $winnerId) {
                $db['users'][$pid]['stats']['wins'] = (int)($db['users'][$pid]['stats']['wins'] ?? 0) + 1;
            } else {
                $db['users'][$pid]['stats']['losses'] = (int)($db['users'][$pid]['stats']['losses'] ?? 0) + 1;
            }

            if ($isBotGame && $pid !== $botId) {
                $this->updateBotStats($db['users'][$pid], $winnerId, $pid);
            }
        }

        $game['payout_done'] = true;
        $game['payout_done_at'] = now_iso();

        if (!isset($db['transactions']) || !is_array($db['transactions'])) {
            $db['transactions'] = [];
        }

        $db['transactions'][] = [
            'id' => make_id('tx'),
            'type' => 'game_finish',
            'game_id' => (string)($game['id'] ?? ''),
            'game_type' => $gameType,
            'room' => $room,
            'winner_id' => $winnerId,
            'loser_id' => $loserId,
            'finish_reason' => $reason,
            'bank' => $bank,
            'commission' => $commission,
            'payout' => $payout,
            'is_bot_game' => $isBotGame,
            'bot_difficulty' => $game['bot_difficulty'] ?? null,
            'created_at' => now_iso(),
        ];
    }

    private function addBalanceChange(
        array &$db,
        array $user,
        string $category,
        string $room,
        int $amount,
        string $gameId = '',
        string $description = '',
        array $extra = []
    ): void {
        $balanceKey = $room === 'gold' ? 'balance_gold' : 'balance_match';
        if (!isset($db['transactions']) || !is_array($db['transactions'])) {
            $db['transactions'] = [];
        }

        $db['transactions'][] = array_merge([
            'id' => make_id('tx'),
            'type' => 'balance_change',
            'category' => $category,
            'user_id' => (string)($user['id'] ?? ''),
            'username' => (string)($user['username'] ?? ''),
            'room' => $room,
            'amount' => $amount,
            'balance_after' => (int)($user[$balanceKey] ?? 0),
            'game_id' => $gameId,
            'description' => $description,
            'created_at' => now_iso(),
        ], $extra);
    }

    private function releaseGamePlayers(array &$db, array $game): void
    {
        foreach ($game['player_ids'] ?? [] as $playerId) {
            $pid = (string)$playerId;
            if (!isset($db['users'][$pid])) {
                continue;
            }

            if (($db['users'][$pid]['current_game_id'] ?? null) === ($game['id'] ?? null)
                || ($db['users'][$pid]['status'] ?? '') === 'playing') {
                $db['users'][$pid]['status'] = 'idle';
                $db['users'][$pid]['current_game_id'] = null;
            }
        }
    }

    private function updateBotStats(array &$user, ?string $winnerId, string $humanId): void
    {
        $user['stats']['bot_games_played'] = (int)($user['stats']['bot_games_played'] ?? 0) + 1;

        if ($winnerId === null) {
            $user['stats']['bot_draws'] = (int)($user['stats']['bot_draws'] ?? 0) + 1;
            return;
        }

        if ((string)$winnerId === $humanId) {
            $user['stats']['bot_wins'] = (int)($user['stats']['bot_wins'] ?? 0) + 1;
            $user['stats']['bot_win_streak'] = (int)($user['stats']['bot_win_streak'] ?? 0) + 1;
            return;
        }

        $user['stats']['bot_losses'] = (int)($user['stats']['bot_losses'] ?? 0) + 1;
        $user['stats']['bot_win_streak'] = 0;
    }

    private function otherPlayerId(array $game, string $userId): string
    {
        foreach ($game['player_ids'] ?? [] as $playerId) {
            if ((string)$playerId !== $userId) {
                return (string)$playerId;
            }
        }
        return $userId;
    }
}
