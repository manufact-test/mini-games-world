<?php
declare(strict_types=1);

final class HistoryService
{
    public function __construct(private array $config, private UserService $users) {}

    public function userHistory(array $db, string $userId, int $limit = 24): array
    {
        return [
            'operations' => $this->balanceOperations($db, $userId, $limit),
            'matches' => $this->matchHistory($db, $userId, 12),
        ];
    }

    public function balanceOperations(array $db, string $userId, int $limit = 24): array
    {
        $items = [];
        $seen = [];
        $transactions = array_reverse($db['transactions'] ?? []);

        foreach ($transactions as $tx) {
            $item = $this->operationFromTransaction($db, $tx, $userId);
            if ($item === null) continue;

            $gameId = (string)($item['game_id'] ?? '');
            if ($gameId !== '') {
                $key = implode('|', [
                    (string)($item['title'] ?? ''),
                    $gameId,
                    (string)($item['amount'] ?? 0),
                ]);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
            }

            $items[] = $item;
            if (count($items) >= $limit) break;
        }

        return $items;
    }

    public function matchHistory(array $db, string $userId, int $limit = 12): array
    {
        $items = [];
        foreach (array_reverse($db['games'] ?? []) as $game) {
            $players = array_map('strval', $game['player_ids'] ?? []);
            if (!in_array($userId, $players, true)) continue;
            $items[] = $this->matchItem($game, $userId);
            if (count($items) >= $limit) break;
        }
        return $items;
    }

    private function operationFromTransaction(array $db, array $tx, string $userId): ?array
    {
        $type = (string)($tx['type'] ?? '');
        $createdAt = (string)($tx['created_at'] ?? '');

        if ($type === 'balance_change') {
            if ((string)($tx['user_id'] ?? '') !== $userId) return null;
            $category = (string)($tx['category'] ?? '');
            if ($this->isTopupCategory($category)) return null;

            $amount = (int)($tx['amount'] ?? 0);
            $gameId = (string)($tx['game_id'] ?? '');
            $game = $gameId !== '' ? ($db['games'][$gameId] ?? null) : null;
            $description = $this->cleanDescription((string)($tx['description'] ?? ''));
            if ($game) $description = $this->operationGameDescription($game, $userId, $category);
            elseif ($description === '') $description = $this->balanceDescription($tx);

            return [
                'id' => (string)($tx['id'] ?? ''),
                'title' => $this->operationTitle($category, is_array($game) ? $game : null, $userId),
                'description' => $description,
                'amount' => $amount,
                'amount_label' => $this->amountLabel($amount),
                'tone' => $amount > 0 ? 'pos' : ($amount < 0 ? 'neg' : 'zero'),
                'room' => (string)($tx['room'] ?? ''),
                'game_id' => $gameId,
                'created_at' => $createdAt,
            ];
        }

        if ($type === 'shop_order' && (string)($tx['user_id'] ?? '') === $userId) {
            $amount = -abs((int)($tx['amount'] ?? 0));
            return [
                'id' => (string)($tx['id'] ?? ''),
                'title' => 'Заказ приза',
                'description' => 'Магазин призов · ' . (string)($tx['provider'] ?? 'приз'),
                'amount' => $amount,
                'amount_label' => $this->amountLabel($amount),
                'tone' => 'neg',
                'room' => 'gold',
                'game_id' => '',
                'created_at' => $createdAt,
            ];
        }

        if ($type === 'game_start' && in_array($userId, array_map('strval', $tx['players'] ?? []), true)) {
            $amount = -abs((int)($tx['bet'] ?? 0));
            $room = (string)($tx['room'] ?? 'match');
            $gameId = (string)($tx['game_id'] ?? '');
            $game = $gameId !== '' ? ($db['games'][$gameId] ?? null) : null;
            return [
                'id' => (string)($tx['id'] ?? ''),
                'title' => $this->operationTitle('game_entry', is_array($game) ? $game : null, $userId),
                'description' => $game ? $this->operationGameDescription($game, $userId, 'game_entry') : $this->roomLabel($room),
                'amount' => $amount,
                'amount_label' => $this->amountLabel($amount),
                'tone' => 'neg',
                'room' => $room,
                'game_id' => $gameId,
                'created_at' => $createdAt,
            ];
        }

        if ($type === 'game_finish') {
            $gameId = (string)($tx['game_id'] ?? '');
            $game = $db['games'][$gameId] ?? null;
            if (!$game || !in_array($userId, array_map('strval', $game['player_ids'] ?? []), true)) return null;

            $winnerId = isset($tx['winner_id']) ? (string)$tx['winner_id'] : null;
            $room = (string)($tx['room'] ?? ($game['room'] ?? 'match'));
            $reason = (string)($tx['finish_reason'] ?? 'normal_win');

            if ($winnerId === $userId) {
                $amount = (int)($tx['payout'] ?? 0);
                return [
                    'id' => (string)($tx['id'] ?? ''),
                    'title' => $reason === 'timeout' ? 'Победа по таймауту' : ($reason === 'player_left' ? 'Победа: соперник вышел' : 'Выигрыш'),
                    'description' => $this->operationGameDescription($game, $userId, 'game_win'),
                    'amount' => $amount,
                    'amount_label' => $this->amountLabel($amount),
                    'tone' => $amount > 0 ? 'pos' : 'zero',
                    'room' => $room,
                    'game_id' => $gameId,
                    'created_at' => $createdAt,
                ];
            }

            if (($tx['winner_id'] ?? null) === null) {
                $amount = (int)($game['bet'] ?? 0);
                return [
                    'id' => (string)($tx['id'] ?? ''),
                    'title' => 'Возврат при ничьей',
                    'description' => $this->operationGameDescription($game, $userId, 'game_refund'),
                    'amount' => $amount,
                    'amount_label' => $this->amountLabel($amount),
                    'tone' => 'pos',
                    'room' => $room,
                    'game_id' => $gameId,
                    'created_at' => $createdAt,
                ];
            }
        }

        return null;
    }

    private function matchItem(array $game, string $userId): array
    {
        $winnerId = isset($game['winner_id']) ? (string)$game['winner_id'] : null;
        $status = (string)($game['status'] ?? '');
        $reason = (string)($game['finish_reason'] ?? '');
        $opponentId = $this->otherPlayerId($game, $userId);
        $opponentName = (string)($game['player_names'][$opponentId] ?? 'Соперник');

        if ($status !== 'finished') { $result = 'Игра активна'; $tone = 'zero'; }
        elseif ($winnerId === null || $winnerId === '') { $result = 'Ничья'; $tone = 'zero'; }
        elseif ($winnerId === $userId) { $result = $reason === 'timeout' ? 'Победа по таймауту' : ($reason === 'player_left' ? 'Победа: соперник вышел' : 'Победа'); $tone = 'pos'; }
        else { $result = in_array($reason, ['timeout', 'player_left'], true) ? 'Техническое поражение' : 'Поражение'; $tone = 'neg'; }

        return [
            'id' => (string)($game['id'] ?? ''),
            'short_id' => $this->prettyMatchId((string)($game['id'] ?? '')),
            'room' => (string)($game['room'] ?? 'match'),
            'room_label' => $this->roomLabel((string)($game['room'] ?? 'match')),
            'opponent' => $opponentName,
            'result' => $result,
            'tone' => $tone,
            'game_type' => (string)($game['game_type'] ?? 'tictactoe'),
            'game_title' => $this->gameLabel($game),
            'board_size' => (int)($game['board_size'] ?? 3),
            'board_columns' => (int)($game['board_columns'] ?? $game['board_size'] ?? 3),
            'board_rows' => (int)($game['board_rows'] ?? $game['board_size'] ?? 3),
            'bet' => (int)($game['bet'] ?? 0),
            'payout' => (int)($game['payout'] ?? 0),
            'commission' => (int)($game['commission'] ?? 0),
            'finish_reason' => $reason,
            'is_bot_game' => !empty($game['is_bot_game']),
            'bot_difficulty' => (string)($game['bot_difficulty'] ?? ''),
            'created_at' => (string)($game['created_at'] ?? ''),
            'finished_at' => (string)($game['finished_at'] ?? ''),
        ];
    }

    private function operationGameDescription(array $game, string $userId, string $category): string
    {
        $parts = [$this->roomLabel((string)($game['room'] ?? 'match')), $this->gameLabel($game)];
        if ($category === 'game_refund') {
            $parts[] = 'ничья';
            return implode(' · ', array_filter($parts));
        }
        $opponentId = $this->otherPlayerId($game, $userId);
        $opponentName = trim((string)($game['player_names'][$opponentId] ?? ''));
        if ($opponentName !== '') $parts[] = 'против ' . $opponentName;
        return implode(' · ', array_filter($parts));
    }

    private function operationTitle(string $category, ?array $game, string $userId): string
    {
        if (!$game || (string)($game['status'] ?? '') !== 'finished') return $this->balanceTitle($category);

        $winnerId = isset($game['winner_id']) ? (string)$game['winner_id'] : '';
        $reason = (string)($game['finish_reason'] ?? '');

        if ($category === 'game_win' && $winnerId === $userId) {
            return $reason === 'timeout'
                ? 'Победа по таймауту'
                : ($reason === 'player_left' ? 'Победа: соперник вышел' : 'Выигрыш');
        }

        if ($category !== 'game_entry' || $winnerId === '' || $winnerId === $userId) {
            return $this->balanceTitle($category);
        }

        return in_array($reason, ['timeout', 'player_left'], true) ? 'Техническое поражение' : 'Поражение';
    }

    private function gameLabel(array $game): string
    {
        return match ((string)($game['game_type'] ?? 'tictactoe')) {
            'four_in_a_row' => '4 в ряд',
            'battleship' => 'Морской бой',
            'checkers' => 'Шашки',
            default => 'Крестики-нолики',
        };
    }

    private function cleanDescription(string $description): string
    {
        $description = trim($description);
        if ($description === '') return '';
        if (str_contains($description, '#game_') || str_contains($description, 'game_')) return '';
        return $description;
    }

    private function isTopupCategory(string $category): bool
    {
        return in_array($category, ['payment_draft','payment_paid','payment_apply','payment_reject','admin_gold_topup'], true);
    }

    private function balanceTitle(string $category): string
    {
        return match ($category) {
            'game_entry' => 'Участие в матче',
            'game_win' => 'Выигрыш',
            'game_refund' => 'Возврат при ничьей',
            'shop_order' => 'Заказ приза',
            'shop_refund' => 'Возврат за приз',
            'system_migration' => 'Системная миграция',
            'weekly_bonus' => 'Еженедельное начисление',
            default => 'Операция баланса',
        };
    }

    private function balanceDescription(array $tx): string { return $this->roomLabel((string)($tx['room'] ?? '')); }
    private function amountLabel(int $amount): string { if ($amount > 0) return '+' . $amount . ' коинов'; if ($amount < 0) return (string)$amount . ' коинов'; return '0 коинов'; }
    private function roomLabel(string $room): string { return $room === 'gold' ? 'Gold-комната' : ($room === 'match' ? 'Match-комната' : ''); }
    private function prettyMatchId(string $id): string { $id = preg_replace('/^(game_|tx_|support_|queue_)/', '', $id); $id = strtoupper(substr((string)$id, 0, 6)); return $id !== '' ? $id : '-'; }
    private function otherPlayerId(array $game, string $userId): string { foreach ($game['player_ids'] ?? [] as $playerId) if ((string)$playerId !== $userId) return (string)$playerId; return ''; }
}
