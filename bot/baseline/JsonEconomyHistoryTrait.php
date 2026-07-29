<?php
declare(strict_types=1);

trait JsonEconomyHistoryTrait
{
    private function reserveGame(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        array $step,
        DateTimeImmutable $now,
        array $config
    ): array {
        $gameId = trim((string)($step['game_id'] ?? ''));
        $players = array_values(array_map('strval', $step['player_ids'] ?? []));
        $room = (string)($step['room'] ?? 'match') === 'gold' ? 'gold' : 'match';
        $bet = $room === 'match' ? (int)($config['match_bet'] ?? 10) : (int)($step['bet'] ?? 0);
        if ($gameId === '' || count($players) !== 2) throw new RuntimeException('Не удалось создать игру.');
        if (isset($state['games'][$gameId])) return ['public' => $state['games'][$gameId], 'ledger' => [], 'event_type' => 'game_reused', 'game_id' => $gameId];
        $balanceKey = $room === 'gold' ? 'balance_gold' : 'balance_match';
        foreach ($players as $playerId) {
            if (!isset($state['users'][$playerId])) throw new RuntimeException('Пользователь не найден.');
            if ((int)($state['users'][$playerId][$balanceKey] ?? 0) < $bet) {
                throw new RuntimeException('Недостаточно коинов для участия.');
            }
        }
        foreach ($players as $playerId) {
            $state['users'][$playerId][$balanceKey] = (int)$state['users'][$playerId][$balanceKey] - $bet;
            $state['users'][$playerId]['status'] = 'playing';
            $state['users'][$playerId]['current_game_id'] = $gameId;
        }
        $game = [
            'id' => $gameId,
            'game_type' => (string)($step['game_type'] ?? 'tictactoe'),
            'room' => $room,
            'bet' => $bet,
            'bank' => $bet * 2,
            'board_size' => (int)($step['board_size'] ?? 3),
            'board_columns' => (int)($step['board_columns'] ?? $step['board_size'] ?? 3),
            'board_rows' => (int)($step['board_rows'] ?? $step['board_size'] ?? 3),
            'player_ids' => $players,
            'player_names' => [
                $players[0] => (string)($state['users'][$players[0]]['first_name'] ?? 'Игрок'),
                $players[1] => (string)($state['users'][$players[1]]['first_name'] ?? 'Игрок'),
            ],
            'turn' => $players[0],
            'status' => 'active',
            'winner_id' => null,
            'loser_id' => null,
            'finish_reason' => null,
            'payout_done' => false,
            'created_at' => $now->format(DATE_ATOM),
            'updated_at' => $now->format(DATE_ATOM),
            'turn_started_at' => $now->format(DATE_ATOM),
            'last_move_at' => $now->format(DATE_ATOM),
            'is_bot_game' => false,
        ];
        $state['games'][$gameId] = $game;
        $tx = [
            'id' => $fixture->nextId('tx'),
            'type' => 'game_start',
            'game_id' => $gameId,
            'game_type' => $game['game_type'],
            'room' => $room,
            'bet' => $bet,
            'players' => $players,
            'created_at' => $now->format(DATE_ATOM),
        ];
        $state['transactions'][] = $tx;
        return ['public' => $game, 'ledger' => [$tx], 'event_type' => 'game_reserved', 'game_id' => $gameId];
    }

    private function finishEconomyGame(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        array $step,
        DateTimeImmutable $now,
        array $config
    ): array {
        $gameId = trim((string)($step['game_id'] ?? ''));
        if ($gameId === '' || !isset($state['games'][$gameId])) throw new RuntimeException('Игра не найдена.');
        $game =& $state['games'][$gameId];
        $winnerId = array_key_exists('winner_id', $step) ? ($step['winner_id'] === null ? null : (string)$step['winner_id']) : null;
        $reason = (string)($step['reason'] ?? ($winnerId === null ? 'draw' : 'normal_win'));
        $ledger = $this->settleEconomyGame($fixture, $state, $game, $winnerId, $reason, $now, $config);
        return ['public' => $game, 'ledger' => $ledger, 'event_type' => 'game_finished', 'game_id' => $gameId];
    }

    private function settleAgain(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        array $step,
        DateTimeImmutable $now,
        array $config
    ): array {
        $gameId = trim((string)($step['game_id'] ?? ''));
        if (!isset($state['games'][$gameId])) throw new RuntimeException('Игра не найдена.');
        $game =& $state['games'][$gameId];
        $ledger = $this->settleEconomyGame(
            $fixture,
            $state,
            $game,
            $game['winner_id'] ?? null,
            (string)($game['finish_reason'] ?? 'draw'),
            $now,
            $config
        );
        return ['public' => $game, 'ledger' => $ledger, 'event_type' => 'settlement_replayed', 'game_id' => $gameId];
    }

    private function settleEconomyGame(
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
            foreach ($game['player_ids'] ?? [] as $playerId) {
                $playerId = (string)$playerId;
                if (!isset($state['users'][$playerId])) continue;
                $state['users'][$playerId]['status'] = 'idle';
                $state['users'][$playerId]['current_game_id'] = null;
            }
            return [];
        }
        $room = (string)($game['room'] ?? 'match') === 'gold' ? 'gold' : 'match';
        $balanceKey = $room === 'gold' ? 'balance_gold' : 'balance_match';
        $bet = (int)($game['bet'] ?? 0);
        $players = array_values(array_map('strval', $game['player_ids'] ?? []));
        $bank = $bet * max(2, count($players));
        $loserId = $winnerId === null ? null : $this->otherEconomyPlayer($game, $winnerId);
        $ledger = [];
        $commission = 0;
        $payout = 0;

        $game['status'] = 'finished';
        $game['winner_id'] = $winnerId;
        $game['loser_id'] = $loserId;
        $game['finish_reason'] = $winnerId === null ? 'draw' : $reason;
        $game['finished_at'] = $now->format(DATE_ATOM);
        $game['updated_at'] = $now->format(DATE_ATOM);
        $game['bank'] = $bank;

        if ($winnerId === null) {
            foreach ($players as $playerId) {
                if (!isset($state['users'][$playerId])) continue;
                $state['users'][$playerId][$balanceKey] = (int)($state['users'][$playerId][$balanceKey] ?? 0) + $bet;
                $tx = [
                    'id' => $fixture->nextId('tx'),
                    'type' => 'balance_change',
                    'category' => 'game_refund',
                    'user_id' => $playerId,
                    'username' => (string)($state['users'][$playerId]['username'] ?? ''),
                    'room' => $room,
                    'amount' => $bet,
                    'balance_after' => (int)$state['users'][$playerId][$balanceKey],
                    'game_id' => (string)$game['id'],
                    'description' => 'Возврат коинов при ничьей',
                    'finish_reason' => 'draw',
                    'is_bot_game' => false,
                    'game_type' => (string)$game['game_type'],
                    'created_at' => $now->format(DATE_ATOM),
                ];
                $state['transactions'][] = $tx;
                $ledger[] = $tx;
            }
            $game['payout'] = $bet;
            $game['commission'] = 0;
        } else {
            $commission = (int)ceil($bank * (float)($config['commission_rate'] ?? 0.10));
            $payout = max(0, $bank - $commission);
            if (isset($state['users'][$winnerId])) {
                $state['users'][$winnerId][$balanceKey] = (int)($state['users'][$winnerId][$balanceKey] ?? 0) + $payout;
                $tx = [
                    'id' => $fixture->nextId('tx'),
                    'type' => 'balance_change',
                    'category' => 'game_win',
                    'user_id' => $winnerId,
                    'username' => (string)($state['users'][$winnerId]['username'] ?? ''),
                    'room' => $room,
                    'amount' => $payout,
                    'balance_after' => (int)$state['users'][$winnerId][$balanceKey],
                    'game_id' => (string)$game['id'],
                    'description' => 'Выигрыш за матч',
                    'finish_reason' => $reason,
                    'loser_id' => $loserId,
                    'commission' => $commission,
                    'is_bot_game' => false,
                    'game_type' => (string)$game['game_type'],
                    'created_at' => $now->format(DATE_ATOM),
                ];
                $state['transactions'][] = $tx;
                $ledger[] = $tx;
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
            if ($room === 'match') $user['stats']['match_games_this_week'] = (int)($user['stats']['match_games_this_week'] ?? 0) + 1;
            if ($room === 'gold') $user['gold_wagered_total'] = (int)($user['gold_wagered_total'] ?? 0) + $bet;
            if ($winnerId === null) $user['stats']['draws'] = (int)($user['stats']['draws'] ?? 0) + 1;
            elseif ($playerId === $winnerId) $user['stats']['wins'] = (int)($user['stats']['wins'] ?? 0) + 1;
            else $user['stats']['losses'] = (int)($user['stats']['losses'] ?? 0) + 1;
            unset($user);
        }
        $game['payout_done'] = true;
        $game['payout_done_at'] = $now->format(DATE_ATOM);
        $finish = [
            'id' => $fixture->nextId('tx'),
            'type' => 'game_finish',
            'game_id' => (string)$game['id'],
            'game_type' => (string)$game['game_type'],
            'room' => $room,
            'winner_id' => $winnerId,
            'loser_id' => $loserId,
            'finish_reason' => $game['finish_reason'],
            'bank' => $bank,
            'commission' => $commission,
            'payout' => $payout,
            'is_bot_game' => false,
            'bot_difficulty' => null,
            'created_at' => $now->format(DATE_ATOM),
        ];
        $state['transactions'][] = $finish;
        $ledger[] = $finish;
        return $ledger;
    }

    private function readHistoryStep(array $state, array $step): array
    {
        $userId = trim((string)($step['actor_id'] ?? $step['user_id'] ?? ''));
        if ($userId === '' || !isset($state['users'][$userId])) throw new RuntimeException('Пользователь не найден.');
        return ['public' => $this->formatHistory($state, $userId), 'ledger' => [], 'event_type' => 'history_read'];
    }

    private function allRequestedHistory(array $state, array $input): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $input['history_user_ids'] ?? []))));
        $result = [];
        foreach ($ids as $userId) {
            if (isset($state['users'][$userId])) $result[$userId] = $this->formatHistory($state, $userId);
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    private function formatHistory(array $state, string $userId): array
    {
        $operations = [];
        $seen = [];
        foreach (array_reverse($state['transactions'] ?? []) as $tx) {
            $item = $this->historyOperation($state, $tx, $userId);
            if ($item === null) continue;
            $gameId = (string)($item['game_id'] ?? '');
            if ($gameId !== '') {
                $key = implode('|', [(string)$item['title'], $gameId, (string)$item['amount']]);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
            }
            $operations[] = $item;
            if (count($operations) >= 24) break;
        }
        $matches = [];
        foreach (array_reverse($state['games'] ?? []) as $game) {
            if (!in_array($userId, array_map('strval', $game['player_ids'] ?? []), true)) continue;
            $matches[] = $this->historyMatch($game, $userId);
            if (count($matches) >= 12) break;
        }
        return ['operations' => $operations, 'matches' => $matches];
    }

    private function historyOperation(array $state, array $tx, string $userId): ?array
    {
        $type = (string)($tx['type'] ?? '');
        $createdAt = (string)($tx['created_at'] ?? '');
        if ($type === 'balance_change') {
            if ((string)($tx['user_id'] ?? '') !== $userId) return null;
            $category = (string)($tx['category'] ?? '');
            if (in_array($category, ['payment_apply','admin_gold_topup','admin_match_topup'], true)) return null;
            $amount = (int)($tx['amount'] ?? 0);
            $gameId = (string)($tx['game_id'] ?? '');
            $title = match ($category) {
                'game_win' => 'Выигрыш',
                'game_refund' => 'Возврат при ничьей',
                'shop_order' => 'Заказ приза',
                'shop_refund' => 'Возврат за приз',
                'weekly_bonus' => 'Еженедельный бонус',
                'welcome_bonus' => 'Первые коины',
                default => 'Операция баланса',
            };
            return [
                'id' => (string)($tx['id'] ?? ''),
                'title' => $title,
                'description' => (string)($tx['description'] ?? ''),
                'amount' => $amount,
                'amount_label' => ($amount > 0 ? '+' : '') . $amount,
                'tone' => $amount > 0 ? 'pos' : ($amount < 0 ? 'neg' : 'zero'),
                'room' => (string)($tx['room'] ?? ''),
                'game_id' => $gameId,
                'created_at' => $createdAt,
            ];
        }
        if ($type === 'game_start' && in_array($userId, array_map('strval', $tx['players'] ?? []), true)) {
            $amount = -abs((int)($tx['bet'] ?? 0));
            return [
                'id' => (string)($tx['id'] ?? ''),
                'title' => 'Ставка на игру',
                'description' => (string)($tx['room'] ?? 'match') === 'gold' ? 'Gold-комната' : 'Матч-комната',
                'amount' => $amount,
                'amount_label' => (string)$amount,
                'tone' => 'neg',
                'room' => (string)($tx['room'] ?? 'match'),
                'game_id' => (string)($tx['game_id'] ?? ''),
                'created_at' => $createdAt,
            ];
        }
        if ($type === 'game_finish') {
            $gameId = (string)($tx['game_id'] ?? '');
            $game = $state['games'][$gameId] ?? null;
            if (!is_array($game) || !in_array($userId, array_map('strval', $game['player_ids'] ?? []), true)) return null;
            if (($tx['winner_id'] ?? null) === $userId) {
                $amount = (int)($tx['payout'] ?? 0);
                return [
                    'id' => (string)($tx['id'] ?? ''),
                    'title' => 'Выигрыш',
                    'description' => 'Результат матча',
                    'amount' => $amount,
                    'amount_label' => '+' . $amount,
                    'tone' => 'pos',
                    'room' => (string)($tx['room'] ?? ''),
                    'game_id' => $gameId,
                    'created_at' => $createdAt,
                ];
            }
            if (($tx['winner_id'] ?? null) === null) {
                $amount = (int)($game['bet'] ?? 0);
                return [
                    'id' => (string)($tx['id'] ?? ''),
                    'title' => 'Возврат при ничьей',
                    'description' => 'Результат матча',
                    'amount' => $amount,
                    'amount_label' => '+' . $amount,
                    'tone' => 'pos',
                    'room' => (string)($tx['room'] ?? ''),
                    'game_id' => $gameId,
                    'created_at' => $createdAt,
                ];
            }
        }
        return null;
    }

    private function historyMatch(array $game, string $userId): array
    {
        $winnerId = isset($game['winner_id']) ? (string)$game['winner_id'] : null;
        $status = (string)($game['status'] ?? '');
        $reason = (string)($game['finish_reason'] ?? '');
        $opponentId = $this->otherEconomyPlayer($game, $userId);
        if ($status !== 'finished') { $result = 'Игра активна'; $tone = 'zero'; }
        elseif ($winnerId === null || $winnerId === '') { $result = 'Ничья'; $tone = 'zero'; }
        elseif ($winnerId === $userId) { $result = $reason === 'timeout' ? 'Победа по таймауту' : 'Победа'; $tone = 'pos'; }
        else { $result = in_array($reason, ['timeout','player_left'], true) ? 'Техническое поражение' : 'Поражение'; $tone = 'neg'; }
        return [
            'id' => (string)($game['id'] ?? ''),
            'room' => (string)($game['room'] ?? 'match'),
            'opponent' => (string)($game['player_names'][$opponentId] ?? 'Соперник'),
            'result' => $result,
            'tone' => $tone,
            'game_type' => (string)($game['game_type'] ?? 'tictactoe'),
            'board_size' => (int)($game['board_size'] ?? 3),
            'bet' => (int)($game['bet'] ?? 0),
            'payout' => (int)($game['payout'] ?? 0),
            'commission' => (int)($game['commission'] ?? 0),
            'finish_reason' => $reason,
            'is_bot_game' => !empty($game['is_bot_game']),
            'created_at' => (string)($game['created_at'] ?? ''),
            'finished_at' => (string)($game['finished_at'] ?? ''),
        ];
    }

    private function otherEconomyPlayer(array $game, string $userId): string
    {
        foreach ($game['player_ids'] ?? [] as $playerId) {
            if ((string)$playerId !== $userId) return (string)$playerId;
        }
        return $userId;
    }
}
