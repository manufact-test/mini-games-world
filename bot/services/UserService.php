<?php
declare(strict_types=1);

final class UserService
{
    private const LAST_SEEN_WRITE_INTERVAL_SEC = 30;

    public function __construct(private array $config) {}

    public function ensureUser(array &$db, array $tgUser): array
    {
        $id = (string)$tgUser['id'];
        $now = now_iso();
        if (!isset($db['users'][$id])) {
            $isDevUser = !empty($tgUser['is_dev_user']);
            $db['users'][$id] = [
                'id' => $id,
                'telegram_id' => $id,
                'is_dev_user' => $isDevUser,
                'first_name' => clean_string($tgUser['first_name'] ?? 'Игрок', 80),
                'username' => clean_string($tgUser['username'] ?? ($tgUser['first_name'] ?? 'Игрок'), 80),
                // Real Telegram users receive their single +50 grant through
                // WeeklyMatchEconomyService so it has its own history and notice.
                // Browser dev users keep the configured test balance because the
                // welcome-grant service intentionally skips development accounts.
                'balance_match' => $isDevUser ? (int)$this->config['initial_match_coins'] : 0,
                'balance_gold' => (int)$this->config['initial_gold_coins'],
                'gold_deposited_total' => 0,
                'gold_wagered_total' => 0,
                'gold_shop_spent_total' => 0,
                'status' => 'idle',
                'current_game_id' => null,
                'registered_at' => $now,
                'last_seen_at' => $now,
                'weekly_bonus_last' => null,
                'stats' => [
                    'games_played' => 0,
                    'wins' => 0,
                    'losses' => 0,
                    'draws' => 0,
                    'match_games_this_week' => 0,
                    'match_games_prev_week' => 0,
                    'bot_games_played' => 0,
                    'bot_wins' => 0,
                    'bot_losses' => 0,
                    'bot_draws' => 0,
                    'bot_win_streak' => 0,
                    'week_key' => gmdate('o-W'),
                ],
            ];
        } else {
            $db['users'][$id]['first_name'] = clean_string($tgUser['first_name'] ?? $db['users'][$id]['first_name'] ?? 'Игрок', 80);
            $db['users'][$id]['username'] = clean_string($tgUser['username'] ?? $db['users'][$id]['username'] ?? $db['users'][$id]['first_name'], 80);
            if ($this->activityWriteIsDue($db['users'][$id]['last_seen_at'] ?? null)) {
                $db['users'][$id]['last_seen_at'] = $now;
            }
            if (!empty($tgUser['is_dev_user'])) {
                $db['users'][$id]['is_dev_user'] = true;
            }
            $this->ensureStatsShape($db['users'][$id]);
            $this->ensureEconomyShape($db['users'][$id]);
            $this->rotateWeeklyStats($db['users'][$id]);
        }
        return $db['users'][$id];
    }

    public function publicUser(array $user): array
    {
        return [
            'id' => $user['id'],
            'first_name' => $user['first_name'] ?? 'Игрок',
            'username' => $user['username'] ?? ($user['first_name'] ?? 'Игрок'),
            'balance_match' => (int)($user['balance_match'] ?? 0),
            'balance_gold' => (int)($user['balance_gold'] ?? 0),
            'gold_deposited_total' => (int)($user['gold_deposited_total'] ?? 0),
            'gold_wagered_total' => (int)($user['gold_wagered_total'] ?? 0),
            'gold_shop_spent_total' => (int)($user['gold_shop_spent_total'] ?? 0),
            'gold_shop_available' => $this->goldShopAvailable($user),
            'shop_test_mode' => $this->shopTestMode($user),
            'shop_min_order' => (int)($this->config['shop_min_order'] ?? 1000),
            'registered_at' => $user['registered_at'] ?? null,
            'status' => $user['status'] ?? 'idle',
        ];
    }

    public function profileStats(array $user, ?array $db = null): array
    {
        // Не доверяем старому user.stats: в ранних MVP счётчики могли раздуться
        // из-за повторного завершения одного и того же матча.
        // Для профиля игрока считаем статистику заново по реальным finished games.
        $calculated = $db ? $this->calculatedStatsFromGames($db, (string)($user['id'] ?? '')) : null;
        $stats = $calculated ?? ($user['stats'] ?? []);

        return [
            'games_played' => (int)($stats['games_played'] ?? 0),
            'wins' => (int)($stats['wins'] ?? 0),
            'losses' => (int)($stats['losses'] ?? 0),
            'draws' => (int)($stats['draws'] ?? 0),
            'match_games' => (int)($stats['match_games'] ?? 0),
            'gold_games' => (int)($stats['gold_games'] ?? 0),
            'gold_wagered_total' => (int)($user['gold_wagered_total'] ?? 0),
            'gold_shop_available' => $this->goldShopAvailable($user),
        ];
    }

    private function calculatedStatsFromGames(array $db, string $userId): array
    {
        $stats = [
            'games_played' => 0,
            'wins' => 0,
            'losses' => 0,
            'draws' => 0,
            'match_games' => 0,
            'gold_games' => 0,
        ];

        if ($userId === '') {
            return $stats;
        }

        foreach ($db['games'] ?? [] as $game) {
            if (($game['status'] ?? '') !== 'finished') {
                continue;
            }

            $players = array_map('strval', $game['player_ids'] ?? []);
            if (!in_array($userId, $players, true)) {
                continue;
            }

            $stats['games_played']++;

            if (($game['room'] ?? 'match') === 'gold') {
                $stats['gold_games']++;
            } else {
                $stats['match_games']++;
            }

            $winnerId = isset($game['winner_id']) ? (string)$game['winner_id'] : '';
            if ($winnerId === '') {
                $stats['draws']++;
            } elseif ($winnerId === $userId) {
                $stats['wins']++;
            } else {
                $stats['losses']++;
            }
        }

        return $stats;
    }

    public function goldShopAvailable(array $user): int
    {
        $balance = max(0, (int)($user['balance_gold'] ?? 0));

        // Администраторы могут проверять магазин на текущем тестовом Gold без
        // искусственного отыгрыша сотен матчей. Для обычных игроков правило
        // оборота Gold остаётся неизменным.
        if ($this->shopTestMode($user)) {
            return $balance;
        }

        $wagered = (int)($user['gold_wagered_total'] ?? 0);
        $spent = (int)($user['gold_shop_spent_total'] ?? 0);
        $turnoverAvailable = max(0, $wagered - $spent);
        return max(0, min($balance, $turnoverAvailable));
    }

    public function shopTestMode(array $user): bool
    {
        $userId = (string)($user['telegram_id'] ?? $user['id'] ?? '');
        if ($userId === '') return false;

        foreach (($this->config['admin_ids'] ?? []) as $adminId) {
            if ((string)$adminId === $userId) {
                return true;
            }
        }

        return false;
    }

    private function ensureStatsShape(array &$user): void
    {
        $user['stats'] = array_merge([
            'games_played' => 0,
            'wins' => 0,
            'losses' => 0,
            'draws' => 0,
            'match_games_this_week' => 0,
            'match_games_prev_week' => 0,
            'bot_games_played' => 0,
            'bot_wins' => 0,
            'bot_losses' => 0,
            'bot_draws' => 0,
            'bot_win_streak' => 0,
            'week_key' => gmdate('o-W'),
        ], $user['stats'] ?? []);
    }

    private function ensureEconomyShape(array &$user): void
    {
        $user['gold_deposited_total'] = (int)($user['gold_deposited_total'] ?? 0);
        $user['gold_wagered_total'] = (int)($user['gold_wagered_total'] ?? 0);
        $user['gold_shop_spent_total'] = (int)($user['gold_shop_spent_total'] ?? 0);
    }

    private function rotateWeeklyStats(array &$user): void
    {
        $currentWeek = gmdate('o-W');
        if (($user['stats']['week_key'] ?? $currentWeek) !== $currentWeek) {
            $user['stats']['match_games_prev_week'] = (int)($user['stats']['match_games_this_week'] ?? 0);
            $user['stats']['match_games_this_week'] = 0;
            $user['stats']['week_key'] = $currentWeek;
        }
    }

    private function activityWriteIsDue(mixed $value): bool
    {
        $lastSeen = strtotime((string)$value) ?: 0;
        return $lastSeen <= 0 || time() - $lastSeen >= self::LAST_SEEN_WRITE_INTERVAL_SEC;
    }
}
