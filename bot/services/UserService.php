<?php
declare(strict_types=1);

require_once __DIR__ . '/../economy/UnifiedBalanceRuntimeState.php';

final class UserService
{
    private const LAST_SEEN_WRITE_INTERVAL_SEC = 30;

    public function __construct(
        private array $config,
        private ?DatabaseConnectionInterface $database = null
    ) {}

    public function ensureUser(array &$db, array $tgUser): array
    {
        $id = (string)$tgUser['id'];
        $now = now_iso();
        $createdRuntimeUser = !isset($db['users'][$id]);
        if ($createdRuntimeUser) {
            $isDevUser = !empty($tgUser['is_dev_user']);
            $db['users'][$id] = [
                'id' => $id,
                'telegram_id' => $id,
                'is_dev_user' => $isDevUser,
                'first_name' => clean_string($tgUser['first_name'] ?? 'Игрок', 80),
                'username' => clean_string($tgUser['username'] ?? ($tgUser['first_name'] ?? 'Игрок'), 80),
                'photo_url' => clean_string($tgUser['photo_url'] ?? '', 2048),
                // Real Telegram users receive their starter grant through the
                // canonical bonus owner. Browser dev users keep the configured
                // test balance because bonus grants intentionally skip them.
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
            $photoUrl = clean_string($tgUser['photo_url'] ?? '', 2048);
            if ($photoUrl !== '') {
                $db['users'][$id]['photo_url'] = $photoUrl;
            }
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

        // AuthService attaches these values only after the provider identity has
        // passed the canonical account resolver. Persist them so downstream
        // economy idempotency can remain provider-neutral instead of keying a
        // one-time reward only by the current Telegram/legacy user id.
        $this->attachVerifiedAccountIdentity($db['users'][$id], $tgUser);
        $this->syncCanonicalGameIdentity($db, $db['users'][$id]);

        UnifiedBalanceRuntimeState::ensureUser($db['users'][$id]);
        if ($createdRuntimeUser) {
            $this->rehydratePostCutoverBalance($db['users'][$id]);
        }
        return $db['users'][$id];
    }

    public function publicUser(array $user): array
    {
        return [
            'id' => $user['id'],
            'first_name' => $user['first_name'] ?? 'Игрок',
            'username' => $user['username'] ?? ($user['first_name'] ?? 'Игрок'),
            'photo_url' => clean_string($user['photo_url'] ?? '', 2048),
            'balance' => (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0),
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
        $balance = max(0, (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0));

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

    private function attachVerifiedAccountIdentity(array &$user, array $authenticatedUser): void
    {
        $incomingMgwId = trim((string)($authenticatedUser['mgw_id'] ?? ''));
        $incomingAccountRef = trim((string)($authenticatedUser['mgw_account_ref'] ?? ''));
        $incomingProvider = trim((string)($authenticatedUser['mgw_identity_provider'] ?? ''));
        $incomingNickname = trim((string)($authenticatedUser['mgw_nickname'] ?? ''));
        $incomingAvatarItemId = trim((string)($authenticatedUser['mgw_avatar_item_id'] ?? ''));

        if ($incomingMgwId === '' && $incomingAccountRef === '' && $incomingProvider === '') {
            return;
        }
        if ($incomingMgwId === '' || $incomingAccountRef === '') {
            throw new RuntimeException('Verified account identity is incomplete.');
        }

        $currentMgwId = trim((string)($user['mgw_id'] ?? ''));
        if ($currentMgwId !== '' && $currentMgwId !== $incomingMgwId) {
            throw new RuntimeException('Verified MGW identity conflicts with the persisted user owner.');
        }

        // Provider metadata stays available for auth/audit, but it no longer
        // owns any visible runtime identity field used by game projections.
        $user['provider_first_name'] = clean_string($authenticatedUser['first_name'] ?? $user['provider_first_name'] ?? '', 80);
        $user['provider_username'] = clean_string($authenticatedUser['username'] ?? $user['provider_username'] ?? '', 80);
        $providerPhotoUrl = clean_string($authenticatedUser['photo_url'] ?? '', 2048);
        if ($providerPhotoUrl !== '') $user['provider_photo_url'] = $providerPhotoUrl;

        // mgw_id is the immutable provider-neutral owner. account_ref is a
        // runtime ownership locator and may legitimately rotate during a future
        // link/merge while the same verified MGW owner remains unchanged.
        $user['mgw_id'] = $incomingMgwId;
        $user['mgw_account_ref'] = $incomingAccountRef;
        if ($incomingProvider !== '') {
            $user['mgw_identity_provider'] = $incomingProvider;
        }
        if ($incomingNickname !== '') {
            $user['mgw_nickname'] = clean_string($incomingNickname, 13);
            $user['first_name'] = $user['mgw_nickname'];
            $user['username'] = '';
            $user['photo_url'] = '';
        }
        if ($incomingAvatarItemId !== '') {
            $user['mgw_avatar_item_id'] = clean_string($incomingAvatarItemId, 80);
        }
    }

    /**
     * A stripped rollback snapshot may legitimately contain no users after the
     * one-time MVP-15.3 cutover. If a verified existing account reappears there,
     * restore only its canonical mgw_coin amount from DB before JSON resumes as
     * the live mutable runtime copy. Never reinterpret legacy Match/Gold rows.
     */
    private function rehydratePostCutoverBalance(array &$user): void
    {
        $accountRef = trim((string)($user['mgw_account_ref'] ?? ''));
        $mgwId = trim((string)($user['mgw_id'] ?? ''));
        $legacyUserId = trim((string)($user['id'] ?? ''));
        if ($accountRef === '' || $mgwId === '' || $legacyUserId === '') return;

        $router = new RuntimeStorageRouter($this->config);
        if ($router->routeFor('economy') !== RuntimeStorageRouter::DRIVER_DATABASE) return;

        $database = $this->database;
        if ($database === null) {
            $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
            if (!$databaseConfig->enabled()) return;
            $database = $this->database = PdoConnectionFactory::create($databaseConfig);
        }

        $cutoverCount = (int)$database->fetchValue(
            "SELECT COUNT(*) FROM mgw_idempotency_keys WHERE operation_type = 'unified_balance_cutover' AND status = 'completed'"
        );
        if ($cutoverCount < 1) return;
        if ($cutoverCount !== 1) {
            throw new RuntimeException('Unified balance cutover marker is ambiguous during runtime rehydration.');
        }

        $rows = $database->fetchAll(
            'SELECT mgw_id, legacy_user_id, available_amount, reserved_amount
             FROM mgw_balances
             WHERE account_ref = :account_ref AND asset_code = :asset_code',
            ['account_ref' => $accountRef, 'asset_code' => UnifiedBalanceMigrationRule::TARGET_ASSET]
        );
        if ($rows === []) return;
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Canonical runtime balance is ambiguous during rehydration.');
        }

        $row = $rows[0];
        if (trim((string)($row['mgw_id'] ?? '')) !== $mgwId
            || trim((string)($row['legacy_user_id'] ?? '')) !== $legacyUserId) {
            throw new RuntimeException('Canonical runtime balance ownership mismatch during rehydration.');
        }
        $available = (int)($row['available_amount'] ?? -1);
        $reserved = (int)($row['reserved_amount'] ?? -1);
        if ($available < 0 || $reserved !== 0) {
            throw new RuntimeException('Canonical runtime balance state is not safe for rehydration.');
        }

        $user[UnifiedBalanceRuntimeState::FIELD] = $available;
    }

    private function syncCanonicalGameIdentity(array &$db, array &$user): void
    {
        $nickname = trim((string)($user['mgw_nickname'] ?? ''));
        $userId = trim((string)($user['id'] ?? ''));
        $gameId = trim((string)($user['current_game_id'] ?? ''));
        if ($nickname === '' || $userId === '' || $gameId === '') return;
        if (!isset($db['games'][$gameId]) || !is_array($db['games'][$gameId])) return;
        if (!in_array($userId, array_map('strval', $db['games'][$gameId]['player_ids'] ?? []), true)) return;

        if (!isset($db['games'][$gameId]['player_names']) || !is_array($db['games'][$gameId]['player_names'])) {
            $db['games'][$gameId]['player_names'] = [];
        }
        $db['games'][$gameId]['player_names'][$userId] = $nickname;
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
