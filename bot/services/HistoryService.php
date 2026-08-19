<?php
declare(strict_types=1);

final class HistoryService
{
    public const PRESENTATION_VERSION = 'mvp17-5-history-economy-live-owner-v3';

    public function __construct(private array $config, private UserService $users) {}

    public function userHistory(array $db, string $userId, int $limit = 24): array
    {
        $router = new RuntimeStorageRouter($this->config);
        if ($router->routeFor('history') !== RuntimeStorageRouter::DRIVER_DATABASE) {
            $history = $this->formatHistory($db, $userId, $limit);
            $history['presentation_version'] = self::PRESENTATION_VERSION;
            return $history;
        }

        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('History DB runtime requires an enabled database configuration.');
        }
        $repository = new RuntimeHistoryRepository(
            $this->config,
            $router,
            PdoConnectionFactory::create($databaseConfig),
            $this
        );

        // History is a read path. The old flow re-synchronized the whole realtime
        // and economy shadow on every user read, which made the result sheet wait
        // several seconds after a match. Read the staged DB snapshot directly and
        // merge the current request snapshot for the newest match presentation.
        $history = $repository->read($userId, $limit);
        $history = $this->mergeCurrentMatchPresentation($history, $db, $userId);
        $history['presentation_version'] = self::PRESENTATION_VERSION;
        return $history;
    }

    public function formatHistory(array $db, string $userId, int $limit = 24): array
    {
        return [
            'operations' => $this->balanceOperations($db, $userId, $limit),
            'matches' => $this->matchHistory($db, $userId, 12),
        ];
    }

    private function mergeCurrentMatchPresentation(array $history, array $db, string $userId): array
    {
        $databaseMatches = is_array($history['matches'] ?? null) ? $history['matches'] : [];
        $currentMatches = $this->matchHistory($db, $userId, 12);
        if ($currentMatches === []) {
            $history['matches'] = array_slice($databaseMatches, 0, 12);
            return $history;
        }

        $merged = [];
        $seen = [];
        foreach ($currentMatches as $match) {
            $id = trim((string)($match['id'] ?? ''));
            if ($id !== '') $seen[$id] = true;
            $merged[] = $match;
        }
        foreach ($databaseMatches as $match) {
            if (!is_array($match)) continue;
            $id = trim((string)($match['id'] ?? ''));
            if ($id !== '' && isset($seen[$id])) continue;
            $merged[] = $match;
            if ($id !== '') $seen[$id] = true;
        }

        $history['matches'] = array_slice($merged, 0, 12);
        return $history;
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

            unset($item['room'], $item['room_label']);
            $items[] = $item;
            if (count($items) >= $limit) break;
        }

        return $items;
    }

    public function matchHistory(array $db, string $userId, int $limit = 12): array
    {
        $items = [];
        $games = array_values(array_filter(
            is_array($db['games'] ?? null) ? $db['games'] : [],
            static fn($game): bool => is_array($game)
        ));
        usort($games, fn(array $left, array $right): int => $this->compareMatchRecency($left, $right));

        foreach ($games as $game) {
            $players = array_map('strval', $game['player_ids'] ?? []);
            if (!in_array($userId, $players, true)) continue;
            $item = $this->matchItem($db, $game, $userId);
            unset($item['room'], $item['room_label']);
            $items[] = $item;
            if (count($items) >= $limit) break;
        }
        return $items;
    }

    private function compareMatchRecency(array $left, array $right): int
    {
        $time = $this->matchRecencyTimestamp($right) <=> $this->matchRecencyTimestamp($left);
        if ($time !== 0) return $time;
        return strcmp((string)($right['id'] ?? ''), (string)($left['id'] ?? ''));
    }

    private function matchRecencyTimestamp(array $game): int
    {
        foreach (['finished_at', 'updated_at', 'created_at'] as $field) {
            $value = trim((string)($game[$field] ?? ''));
            if ($value === '') continue;
            $timestamp = strtotime($value);
            if ($timestamp !== false) return $timestamp;
        }
        return 0;
    }

    public function matchEconomy(array $db, string $userId, string $gameId): ?array
    {
        $gameId = trim($gameId);
        if ($gameId === '' || $userId === '') return null;

        $entry = 0;
        $reward = 0;
        $ledgerDelta = 0;
        $newBalance = null;
        $matched = 0;

        foreach ($db['transactions'] ?? [] as $tx) {
            if (!is_array($tx)
                || (string)($tx['type'] ?? '') !== 'balance_change'
                || (string)($tx['user_id'] ?? '') !== $userId
                || (string)($tx['game_id'] ?? '') !== $gameId) {
                continue;
            }

            $category = (string)($tx['category'] ?? '');
            if (!in_array($category, ['game_entry', 'game_win', 'game_refund'], true)) continue;

            $amount = (int)($tx['amount'] ?? 0);
            if ($category === 'game_entry' && $amount < 0) $entry += abs($amount);
            if (in_array($category, ['game_win', 'game_refund'], true) && $amount > 0) $reward += $amount;
            $ledgerDelta += $amount;
            if (array_key_exists('balance_after', $tx)) $newBalance = (int)$tx['balance_after'];
            $matched++;
        }

        if ($matched === 0) return null;

        return [
            'entry' => $entry,
            'reward' => $reward,
            'net' => $ledgerDelta,
            'ledger_delta' => $ledgerDelta,
            'new_balance' => $newBalance,
        ];
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
                'description' => $game ? $this->operationGameDescription($game, $userId, 'game_entry') : 'Обычный матч',
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
                    'title' => $this->operationTitle('game_refund', $game, $userId),
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

    private function matchItem(array $db, array $game, string $userId): array
    {
        $winnerId = isset($game['winner_id']) ? (string)$game['winner_id'] : null;
        $status = (string)($game['status'] ?? '');
        $reason = (string)($game['finish_reason'] ?? '');
        $opponentId = $this->otherPlayerId($game, $userId);
        $opponentName = (string)($game['player_names'][$opponentId] ?? 'Соперник');

        if ($status !== 'finished') { $result = 'Игра активна'; $tone = 'zero'; }
        elseif ($reason === 'preparation_timeout') { $result = 'Матч не начался'; $tone = 'zero'; }
        elseif ($winnerId === null || $winnerId === '') { $result = 'Ничья'; $tone = 'zero'; }
        elseif ($winnerId === $userId) { $result = $reason === 'timeout' ? 'Победа по таймауту' : ($reason === 'player_left' ? 'Победа: соперник вышел' : 'Победа'); $tone = 'pos'; }
        else { $result = in_array($reason, ['timeout', 'player_left'], true) ? 'Техническое поражение' : 'Поражение'; $tone = 'neg'; }

        $item = [
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
            'created_at' => (string)($game['created_at'] ?? ''),
            'finished_at' => (string)($game['finished_at'] ?? ''),
        ];

        $economy = $this->matchEconomy($db, $userId, (string)($game['id'] ?? ''));
        if ($economy !== null) $item['economy'] = $economy;
        return $item;
    }

    private function operationGameDescription(array $game, string $userId, string $category): string
    {
        $parts = [$this->gameLabel($game)];
        if ($category === 'game_refund') {
            $parts[] = (string)($game['finish_reason'] ?? '') === 'preparation_timeout'
                ? 'соперник не подключился'
                : 'ничья';
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

        if ($category === 'game_refund' && $reason === 'preparation_timeout') {
            return 'Возврат: соперник не подключился';
        }

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
            'reversi' => 'Реверси',
            'chess' => 'Шахматы',
            'go' => 'Го',
            'domino' => 'Домино',
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
        return in_array($category, ['payment_draft','payment_paid','payment_apply','payment_reject'], true);
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
            'welcome_bonus' => 'Стартовые коины',
            'weekly_bonus' => 'Еженедельное начисление',
            'admin_gold_topup' => 'Начисление',
            default => 'Операция баланса',
        };
    }

    private function balanceDescription(array $tx): string { return ''; }
    private function amountLabel(int $amount): string { if ($amount > 0) return '+' . $amount . ' коинов'; if ($amount < 0) return (string)$amount . ' коинов'; return '0 коинов'; }
    private function roomLabel(string $room): string { return $room === 'gold' ? 'Gold-комната' : ($room === 'match' ? 'Match-комната' : ''); }
    private function prettyMatchId(string $id): string { $id = preg_replace('/^(game_|tx_|support_|queue_)/', '', $id); $id = strtoupper(substr((string)$id, 0, 6)); return $id !== '' ? $id : '-'; }
    private function otherPlayerId(array $game, string $userId): string { foreach ($game['player_ids'] ?? [] as $playerId) if ((string)$playerId !== $userId) return (string)$playerId; return ''; }
}
