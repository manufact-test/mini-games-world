<?php
declare(strict_types=1);

trait JsonGamesSettlementTrait
{
    private function finishGame(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        array &$game,
        ?string $winnerId,
        string $reason,
        DateTimeImmutable $now,
        array $config
    ): array {
        if (!empty($game['payout_done']) || (string)($game['status'] ?? '') === 'finished') {
            $game['status'] = 'finished';
            $this->releasePlayers($state, $game);
            return [];
        }

        $room = (string)($game['room'] ?? 'match') === 'gold' ? 'gold' : 'match';
        $balanceKey = $room === 'gold' ? 'balance_gold' : 'balance_match';
        $bet = max(0, (int)($game['bet'] ?? 0));
        $players = array_values(array_map('strval', $game['player_ids'] ?? []));
        $bank = $bet * max(2, count($players));
        $loserId = $winnerId === null ? null : $this->otherPlayerId($game, $winnerId);
        $ledger = [];
        $commission = 0;
        $payout = 0;

        $game['status'] = 'finished';
        $game['winner_id'] = $winnerId;
        $game['loser_id'] = $loserId;
        $game['finish_reason'] = $winnerId === null ? 'draw' : $reason;
        $game['finished_at'] = $now->format('c');
        $game['updated_at'] = $now->format('c');
        $game['bank'] = $bank;

        if ($winnerId === null) {
            foreach ($players as $playerId) {
                if (!isset($state['users'][$playerId])) continue;
                $state['users'][$playerId][$balanceKey] = (int)($state['users'][$playerId][$balanceKey] ?? 0) + $bet;
                $transaction = $this->balanceTransaction(
                    $fixture,
                    $state['users'][$playerId],
                    'game_refund',
                    $room,
                    $bet,
                    $game,
                    $now,
                    ['finish_reason' => 'draw', 'is_bot_game' => !empty($game['is_bot_game'])]
                );
                $state['transactions'][] = $transaction;
                $ledger[] = $transaction;
            }
            $game['payout'] = $bet;
            $game['commission'] = 0;
        } else {
            $commission = (int)ceil($bank * (float)($config['commission_rate'] ?? 0.10));
            $payout = max(0, $bank - $commission);
            if (isset($state['users'][$winnerId])) {
                $state['users'][$winnerId][$balanceKey] = (int)($state['users'][$winnerId][$balanceKey] ?? 0) + $payout;
                $transaction = $this->balanceTransaction(
                    $fixture,
                    $state['users'][$winnerId],
                    'game_win',
                    $room,
                    $payout,
                    $game,
                    $now,
                    [
                        'finish_reason' => $reason,
                        'loser_id' => $loserId,
                        'commission' => $commission,
                        'is_bot_game' => !empty($game['is_bot_game']),
                    ]
                );
                $state['transactions'][] = $transaction;
                $ledger[] = $transaction;
            }
            $state['system']['fees_' . $room] = (int)($state['system']['fees_' . $room] ?? 0) + $commission;
            $game['payout'] = $payout;
            $game['commission'] = $commission;
        }

        foreach ($players as $playerId) {
            if (!isset($state['users'][$playerId])) continue;
            $user =& $state['users'][$playerId];
            $user['status'] = 'idle';
            $user['current_game_id'] = null;
            if (!isset($user['stats']) || !is_array($user['stats'])) $user['stats'] = [];
            $user['stats']['games_played'] = (int)($user['stats']['games_played'] ?? 0) + 1;
            if ($room === 'match') {
                $user['stats']['match_games_this_week'] = (int)($user['stats']['match_games_this_week'] ?? 0) + 1;
            } else {
                $user['gold_wagered_total'] = (int)($user['gold_wagered_total'] ?? 0) + $bet;
            }
            if ($winnerId === null) {
                $user['stats']['draws'] = (int)($user['stats']['draws'] ?? 0) + 1;
            } elseif ($playerId === $winnerId) {
                $user['stats']['wins'] = (int)($user['stats']['wins'] ?? 0) + 1;
            } else {
                $user['stats']['losses'] = (int)($user['stats']['losses'] ?? 0) + 1;
            }
            unset($user);
        }

        $game['payout_done'] = true;
        $game['payout_done_at'] = $now->format('c');
        $finish = [
            'id' => $fixture->nextId('tx'),
            'type' => 'game_finish',
            'game_id' => (string)($game['id'] ?? ''),
            'game_type' => (string)($game['game_type'] ?? 'tictactoe'),
            'room' => $room,
            'winner_id' => $winnerId,
            'loser_id' => $loserId,
            'finish_reason' => $game['finish_reason'],
            'bank' => $bank,
            'commission' => $commission,
            'payout' => $payout,
            'is_bot_game' => !empty($game['is_bot_game']),
            'bot_difficulty' => $game['bot_difficulty'] ?? null,
            'created_at' => $now->format('c'),
        ];
        $state['transactions'][] = $finish;
        $ledger[] = $finish;
        return $ledger;
    }

    private function balanceTransaction(
        JsonBehaviorBaselineFixture $fixture,
        array $user,
        string $category,
        string $room,
        int $amount,
        array $game,
        DateTimeImmutable $now,
        array $extra
    ): array {
        $balanceKey = $room === 'gold' ? 'balance_gold' : 'balance_match';
        $transaction = array_merge([
            'id' => $fixture->nextId('tx'),
            'type' => 'balance_change',
            'category' => $category,
            'user_id' => (string)($user['id'] ?? ''),
            'username' => (string)($user['username'] ?? ''),
            'room' => $room,
            'amount' => $amount,
            'balance_after' => (int)($user[$balanceKey] ?? 0),
            'game_id' => (string)($game['id'] ?? ''),
            'description' => $category === 'game_refund' ? 'Возврат коинов при ничьей' : 'Выигрыш за матч',
            'created_at' => $now->format('c'),
            'game_type' => (string)($game['game_type'] ?? 'tictactoe'),
        ], $extra);
        return $transaction;
    }

    private function releasePlayers(array &$state, array $game): void
    {
        foreach ($game['player_ids'] ?? [] as $playerId) {
            $playerId = (string)$playerId;
            if (!isset($state['users'][$playerId])) continue;
            $state['users'][$playerId]['status'] = 'idle';
            $state['users'][$playerId]['current_game_id'] = null;
        }
    }

    private function rematchProjection(array $state, array $game, string $viewerId): array
    {
        $players = array_values(array_map('strval', $game['player_ids'] ?? []));
        $available = (string)($game['status'] ?? '') === 'finished'
            && empty($game['is_bot_game'])
            && count($players) === 2
            && in_array($viewerId, $players, true);
        $opponentId = $available ? $this->otherPlayerId($game, $viewerId) : '';
        return [
            'available' => $available,
            'source_game_id' => $available ? (string)($game['id'] ?? '') : '',
            'game_type' => $available ? (string)($game['game_type'] ?? '') : '',
            'room' => $available ? (string)($game['room'] ?? 'match') : '',
            'bet' => $available ? (int)($game['bet'] ?? 0) : 0,
            'board_size' => $available ? (int)($game['board_size'] ?? 0) : 0,
            'opponent_id' => $opponentId,
            'opponent_name' => $opponentId !== ''
                ? (string)($game['player_names'][$opponentId] ?? 'Игрок')
                : '',
        ];
    }

    private function publicGame(array $game, string $viewerId, DateTimeImmutable $now, int $moveTimeout): array
    {
        $started = new DateTimeImmutable((string)($game['turn_started_at'] ?? $now->format('c')));
        $elapsed = max(0, $now->getTimestamp() - $started->getTimestamp());
        $timeLeft = (string)($game['status'] ?? '') === 'active' ? max(0, $moveTimeout - $elapsed) : 0;
        $base = [
            'id' => (string)($game['id'] ?? ''),
            'game_type' => (string)($game['game_type'] ?? ''),
            'room' => (string)($game['room'] ?? 'match'),
            'bet' => (int)($game['bet'] ?? 0),
            'board_size' => (int)($game['board_size'] ?? 0),
            'board_columns' => (int)($game['board_columns'] ?? $game['board_size'] ?? 0),
            'board_rows' => (int)($game['board_rows'] ?? $game['board_size'] ?? 0),
            'turn' => (string)($game['turn'] ?? ''),
            'status' => (string)($game['status'] ?? 'active'),
            'winner_id' => $game['winner_id'] ?? null,
            'loser_id' => $game['loser_id'] ?? null,
            'finish_reason' => $game['finish_reason'] ?? null,
            'payout' => $game['payout'] ?? null,
            'commission' => (int)($game['commission'] ?? 0),
            'time_left' => $timeLeft,
            'move_timeout_sec' => $moveTimeout,
            'is_bot_game' => !empty($game['is_bot_game']),
        ];
        return $base + $this->enginePublicFields($game, $viewerId);
    }

    private function enginePublicFields(array $game, string $viewerId): array
    {
        $type = (string)($game['game_type'] ?? '');
        return match ($type) {
            'tictactoe' => ['board' => (string)($game['board'] ?? ''), 'symbols' => $game['symbols'] ?? []],
            'four_in_a_row' => [
                'board' => (string)($game['board'] ?? ''),
                'connect_length' => 4,
                'winning_cells' => array_values(array_map('intval', $game['winning_cells'] ?? [])),
                'last_move' => isset($game['last_move']) ? (int)$game['last_move'] : null,
            ],
            'battleship' => [
                'phase' => (string)($game['phase'] ?? 'setup'),
                'last_shot' => isset($game['battleship_last_shot']) ? (int)$game['battleship_last_shot'] : null,
                'last_result' => $game['battleship_last_result'] ?? null,
                'my_shots' => $game['battleship_shots'][$viewerId] ?? [],
            ],
            'checkers' => [
                'board' => array_values($game['board'] ?? []),
                'last_move' => $game['checkers_last_move'] ?? null,
                'last_captured_cells' => array_values(array_map('intval', $game['checkers_last_captured_cells'] ?? [])),
            ],
            'reversi' => [
                'board' => (string)($game['board'] ?? ''),
                'black_count' => substr_count((string)($game['board'] ?? ''), 'B'),
                'white_count' => substr_count((string)($game['board'] ?? ''), 'W'),
                'last_flipped_cells' => array_values(array_map('intval', $game['reversi_last_flipped_cells'] ?? [])),
            ],
            'chess' => [
                'board' => array_values($game['board'] ?? []),
                'last_move' => $game['chess_last_move'] ?? null,
                'move_count' => (int)($game['chess_move_count'] ?? 0),
                'chess_end_reason' => $game['chess_end_reason'] ?? null,
            ],
            'go' => [
                'board' => (string)($game['board'] ?? ''),
                'last_move' => $game['go_last_move'] ?? null,
                'last_captured_cells' => array_values(array_map('intval', $game['go_last_captured_cells'] ?? [])),
                'pass_sequence' => (int)($game['go_consecutive_passes'] ?? 0),
                'captures' => $game['go_captures'] ?? ['black' => 0, 'white' => 0],
                'komi' => 6.5,
                'final_score' => $game['go_final_score'] ?? null,
            ],
            'domino' => [
                'chain' => array_values($game['domino_chain'] ?? []),
                'viewer_hand' => array_values($game['domino_hands'][$viewerId] ?? []),
                'opponent_tile_count' => count($game['domino_hands'][$this->otherPlayerId($game, $viewerId)] ?? []),
                'move_count' => (int)($game['domino_move_count'] ?? 0),
                'end_reason' => $game['domino_end_reason'] ?? null,
                'final_points' => $game['domino_final_points'] ?? null,
            ],
            default => [],
        };
    }

    private function otherPlayerId(array $game, string $userId): string
    {
        foreach ($game['player_ids'] ?? [] as $playerId) {
            if ((string)$playerId !== $userId) return (string)$playerId;
        }
        return $userId;
    }

    private function snapshot(array $state): array
    {
        foreach (['users', 'games'] as $field) {
            if (isset($state[$field]) && is_array($state[$field])) ksort($state[$field], SORT_STRING);
        }
        foreach (['transactions', 'invites', 'notifications'] as $field) {
            $state[$field] = array_values(is_array($state[$field] ?? null) ? $state[$field] : []);
        }
        $state['system'] = is_array($state['system'] ?? null) ? $state['system'] : [];
        ksort($state['system'], SORT_STRING);
        return [
            'users' => $state['users'] ?? [],
            'games' => $state['games'] ?? [],
            'transactions' => $state['transactions'],
            'invites' => $state['invites'],
            'notifications' => $state['notifications'],
            'system' => $state['system'],
        ];
    }
}
