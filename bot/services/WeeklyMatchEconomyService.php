<?php
declare(strict_types=1);

require_once __DIR__ . '/../economy/UnifiedBalanceRuntimeState.php';
require_once __DIR__ . '/../economy/EconomyConfigDefinition.php';
require_once __DIR__ . '/../economy/EconomyConfigService.php';

final class WeeklyMatchEconomyService
{
    private const CANONICAL_TIMEZONE = 'Europe/Moscow';
    private const DEFAULT_START_AT = '2026-07-13 12:00:00';
    private const GAME_TYPES = [
        'tictactoe',
        'four_in_a_row',
        'battleship',
        'checkers',
        'reversi',
        'chess',
        'go',
        'domino',
    ];

    private ?array $resolvedBonuses = null;

    public function __construct(
        private array $config,
        private ?NotificationService $notifications = null,
        private ?array $canonicalBonuses = null
    ) {}

    public function ensureWelcomeGrant(array &$db, array &$user): array
    {
        UnifiedBalanceRuntimeState::ensureUser($user);
        $userId = trim((string)($user['id'] ?? ''));
        if ($userId === '' || !empty($user['is_dev_user'])) {
            return [
                'processed' => false,
                'awarded' => false,
                'reason' => $userId === '' ? 'missing_user' : 'dev_user',
            ];
        }

        if (!empty($user['weekly_match_welcome_grant_done'])) {
            return [
                'processed' => false,
                'awarded' => false,
                'reason' => 'already_awarded',
            ];
        }

        // Compatibility with the historical v45 starter grant. Never pay a
        // second starter grant when an account already owns that legacy marker.
        if (!empty($user['weekly_match_first_grant_done'])
            || (string)($user['weekly_match_bonus_last_qualification'] ?? '') === 'first_grant') {
            $user['weekly_match_welcome_grant_done'] = true;
            $user['weekly_match_welcome_grant_migrated_at'] = now_iso();
            return [
                'processed' => true,
                'awarded' => false,
                'reason' => 'migrated_existing_grant',
            ];
        }

        $amount = $this->starterAmount();
        $before = (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0);
        $after = $before + $amount;
        $createdAt = now_iso();

        $user[UnifiedBalanceRuntimeState::FIELD] = $after;
        $user['weekly_match_welcome_grant_done'] = true;
        $user['weekly_match_welcome_grant_at'] = $createdAt;
        $user['weekly_match_welcome_grant_amount'] = $amount;
        $user['weekly_match_first_grant_done'] = true;

        $this->appendTransaction($db, [
            'id' => make_id('tx'),
            'type' => 'balance_change',
            'category' => 'welcome_bonus',
            'user_id' => $userId,
            'account_ref' => $this->accountRef($user),
            'username' => (string)($user['username'] ?? ''),
            'room' => 'match',
            'currency' => 'mgw_coin',
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
            'description' => 'Стартовый бонус Mini Games World',
            'created_at' => $createdAt,
        ]);

        if ($this->notifications !== null) {
            $this->notifications->addWelcomeMatchGrant($db, $user, [
                'amount' => $amount,
                'created_at' => $createdAt,
            ]);
        }

        return [
            'processed' => true,
            'awarded' => true,
            'reason' => 'awarded',
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
        ];
    }

    public function applyFirstGameBonuses(array &$db, array &$user): array
    {
        UnifiedBalanceRuntimeState::ensureUser($user);
        $userId = trim((string)($user['id'] ?? ''));
        if ($userId === '' || !empty($user['is_dev_user'])) {
            return [
                'processed' => false,
                'awarded' => false,
                'reason' => $userId === '' ? 'missing_user' : 'dev_user',
                'awarded_games' => [],
            ];
        }

        $completed = $this->completedGameTypes($db, $userId);
        if ($completed === []) {
            return [
                'processed' => true,
                'awarded' => false,
                'reason' => 'no_completed_games',
                'awarded_games' => [],
            ];
        }

        $grants = is_array($user['weekly_match_first_game_grants'] ?? null)
            ? $user['weekly_match_first_game_grants']
            : [];
        $amount = $this->firstGameAmount();
        $awardedGames = [];
        $recoveredGames = [];

        foreach (self::GAME_TYPES as $gameType) {
            if (!isset($completed[$gameType])) continue;
            if (isset($grants[$gameType]) && is_array($grants[$gameType])) continue;

            $existing = $this->existingFirstGameTransaction($db, $user, $gameType);
            if ($existing !== null) {
                $grants[$gameType] = [
                    'amount' => max(0, (int)($existing['amount'] ?? $amount)),
                    'granted_at' => (string)($existing['created_at'] ?? now_iso()),
                    'source_game_id' => (string)($existing['source_game_id'] ?? $completed[$gameType]['id'] ?? ''),
                    'recovered_from_transaction' => true,
                ];
                $recoveredGames[] = $gameType;
                continue;
            }

            $before = (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0);
            $after = $before + $amount;
            $createdAt = now_iso();
            $sourceGameId = (string)($completed[$gameType]['id'] ?? '');

            $user[UnifiedBalanceRuntimeState::FIELD] = $after;
            $grants[$gameType] = [
                'amount' => $amount,
                'granted_at' => $createdAt,
                'source_game_id' => $sourceGameId,
            ];

            $this->appendTransaction($db, [
                'id' => make_id('tx'),
                'type' => 'balance_change',
                'category' => 'first_game_bonus',
                'event_key' => 'first_game_bonus:' . $this->grantIdentity($user) . ':' . $gameType,
                'user_id' => $userId,
                'account_ref' => $this->accountRef($user),
                'username' => (string)($user['username'] ?? ''),
                'room' => 'match',
                'currency' => 'mgw_coin',
                'game_type' => $gameType,
                'source_game_id' => $sourceGameId,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'description' => 'Бонус за первую завершённую партию в игре',
                'created_at' => $createdAt,
            ]);

            if ($this->notifications !== null) {
                $this->notifications->addFirstGameBonus($db, $user, [
                    'game_type' => $gameType,
                    'amount' => $amount,
                    'created_at' => $createdAt,
                ]);
            }
            $awardedGames[] = $gameType;
        }

        $user['weekly_match_first_game_grants'] = $grants;
        $user['weekly_match_first_game_total'] = count($grants);

        if (count($grants) >= count(self::GAME_TYPES)
            && empty($user['weekly_match_all_games_reward_triggered_at'])) {
            $user['weekly_match_all_games_reward_triggered_at'] = now_iso();
            $user['weekly_match_all_games_reward_pending'] = true;
        }

        return [
            'processed' => true,
            'awarded' => $awardedGames !== [],
            'reason' => $awardedGames !== [] ? 'awarded' : ($recoveredGames !== [] ? 'recovered' : 'already_awarded'),
            'awarded_games' => $awardedGames,
            'recovered_games' => $recoveredGames,
            'completed_game_types' => array_keys($completed),
            'grant_count' => count($grants),
            'amount_each' => $amount,
            'amount_awarded' => count($awardedGames) * $amount,
            'all_games_reward_pending' => !empty($user['weekly_match_all_games_reward_pending']),
        ];
    }

    public function applyDueForUser(
        array &$db,
        array &$user,
        ?DateTimeImmutable $now = null,
        bool $allowWelcomeGrant = true
    ): array {
        UnifiedBalanceRuntimeState::ensureUser($user);
        $userId = trim((string)($user['id'] ?? ''));
        if ($userId === '' || !empty($user['is_dev_user'])) {
            return [
                'processed' => false,
                'awarded' => false,
                'reason' => $userId === '' ? 'missing_user' : 'dev_user',
            ];
        }

        $welcomeResult = $allowWelcomeGrant
            ? $this->ensureWelcomeGrant($db, $user)
            : ['processed' => false, 'awarded' => false, 'reason' => 'not_requested'];
        $firstGameResult = $this->applyFirstGameBonuses($db, $user);
        $otherAwarded = !empty($welcomeResult['awarded']) || !empty($firstGameResult['awarded']);

        $now = $this->localNow($now);
        $cycleAt = $this->latestDueCycle($now);
        if ($cycleAt === null) {
            return [
                'processed' => !empty($welcomeResult['processed']) || !empty($firstGameResult['processed']),
                'awarded' => $otherAwarded,
                'reason' => $otherAwarded ? 'bonus_awarded' : 'not_started',
                'welcome' => $welcomeResult,
                'first_games' => $firstGameResult,
            ];
        }

        $cycleKey = $this->cycleKey($cycleAt);
        $checkedKey = (string)($user['weekly_match_bonus_checked_key'] ?? '');
        if ($checkedKey === $cycleKey) {
            return [
                'processed' => !empty($welcomeResult['processed']) || !empty($firstGameResult['processed']),
                'awarded' => $otherAwarded || (string)($user['weekly_match_bonus_last_key'] ?? '') === $cycleKey,
                'reason' => $otherAwarded ? 'bonus_awarded' : 'already_checked',
                'cycle_key' => $cycleKey,
                'qualifying_games' => (int)($user['weekly_match_bonus_checked_games'] ?? 0),
                'welcome' => $welcomeResult,
                'first_games' => $firstGameResult,
            ];
        }

        $from = $cycleAt->modify('-7 days');
        $games = $this->countCompletedGames($db, $userId, $from, $cycleAt);

        $user['weekly_match_bonus_checked_key'] = $cycleKey;
        $user['weekly_match_bonus_checked_at'] = now_iso();
        $user['weekly_match_bonus_checked_games'] = $games;

        if ($games < $this->minGames()) {
            return [
                'processed' => true,
                'awarded' => $otherAwarded,
                'reason' => $otherAwarded ? 'bonus_awarded' : 'not_eligible',
                'cycle_key' => $cycleKey,
                'qualifying_games' => $games,
                'welcome' => $welcomeResult,
                'first_games' => $firstGameResult,
            ];
        }

        if ((string)($user['weekly_match_bonus_last_key'] ?? '') === $cycleKey) {
            return [
                'processed' => true,
                'awarded' => $otherAwarded,
                'reason' => $otherAwarded ? 'bonus_awarded' : 'already_awarded',
                'cycle_key' => $cycleKey,
                'qualifying_games' => $games,
                'welcome' => $welcomeResult,
                'first_games' => $firstGameResult,
            ];
        }

        $amount = $this->weeklyAmount();
        $before = (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0);
        $after = $before + $amount;
        $awardedAt = now_iso();

        $user[UnifiedBalanceRuntimeState::FIELD] = $after;
        $user['weekly_match_bonus_last_key'] = $cycleKey;
        $user['weekly_match_bonus_last_at'] = $awardedAt;
        $user['weekly_match_bonus_last_amount'] = $amount;
        $user['weekly_match_bonus_last_qualification'] = 'activity';
        $user['weekly_bonus_last'] = $cycleKey;

        $this->appendTransaction($db, [
            'id' => make_id('tx'),
            'type' => 'balance_change',
            'category' => 'weekly_bonus',
            'user_id' => $userId,
            'account_ref' => $this->accountRef($user),
            'username' => (string)($user['username'] ?? ''),
            'room' => 'match',
            'currency' => 'mgw_coin',
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
            'cycle_key' => $cycleKey,
            'qualification' => 'activity',
            'qualifying_from' => $from->format(DATE_ATOM),
            'qualifying_to' => $cycleAt->format(DATE_ATOM),
            'qualifying_games' => $games,
            'description' => 'Еженедельный бонус за игровую активность',
            'created_at' => $awardedAt,
        ]);

        if ($this->notifications !== null) {
            $this->notifications->addWeeklyMatchBonus($db, $user, [
                'cycle_key' => $cycleKey,
                'amount' => $amount,
                'qualifying_games' => $games,
                'created_at' => $awardedAt,
            ]);
        }

        return [
            'processed' => true,
            'awarded' => true,
            'reason' => 'awarded',
            'cycle_key' => $cycleKey,
            'qualifying_games' => $games,
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
            'welcome' => $welcomeResult,
            'first_games' => $firstGameResult,
        ];
    }

    public function runDue(array &$db, ?DateTimeImmutable $now = null): array
    {
        UnifiedBalanceRuntimeState::migrateAll($db);
        $now = $this->localNow($now);
        $cycleAt = $this->latestDueCycle($now);

        $summary = [
            'started' => $cycleAt !== null,
            'cycle_key' => $cycleAt ? $this->cycleKey($cycleAt) : null,
            'cycle_at' => $cycleAt?->format(DATE_ATOM),
            'checked' => 0,
            'awarded' => 0,
            'ineligible' => 0,
            'already_checked' => 0,
            'skipped_dev' => 0,
            'starter_amount' => $this->starterAmount(),
            'bonus_amount' => $this->weeklyAmount(),
            'first_game_amount' => $this->firstGameAmount(),
            'min_completed_games' => $this->minGames(),
            'timezone' => $this->timezone()->getName(),
            'run_at' => $now->format(DATE_ATOM),
        ];

        if ($cycleAt !== null) {
            foreach (array_keys($db['users'] ?? []) as $userId) {
                if (!isset($db['users'][$userId]) || !is_array($db['users'][$userId])) continue;

                $user =& $db['users'][$userId];
                if (!empty($user['is_dev_user'])) {
                    $summary['skipped_dev']++;
                    unset($user);
                    continue;
                }

                $result = $this->applyDueForUser($db, $user, $now, false);
                $reason = (string)($result['reason'] ?? '');
                if ($reason === 'already_checked') {
                    $summary['already_checked']++;
                    unset($user);
                    continue;
                }
                if (!empty($result['processed'])) $summary['checked']++;
                if (!empty($result['awarded'])) $summary['awarded']++;
                elseif ($reason === 'not_eligible') $summary['ineligible']++;
                unset($user);
            }
        }

        if (!isset($db['system']) || !is_array($db['system'])) $db['system'] = [];
        $db['system']['weekly_match_economy'] = array_merge(
            is_array($db['system']['weekly_match_economy'] ?? null) ? $db['system']['weekly_match_economy'] : [],
            [
                'enabled' => true,
                'start_at' => $this->startAt()->format(DATE_ATOM),
                'timezone' => $this->timezone()->getName(),
                'starter_amount' => $this->starterAmount(),
                'bonus_amount' => $this->weeklyAmount(),
                'first_game_amount' => $this->firstGameAmount(),
                'min_completed_games' => $this->minGames(),
                'last_run_at' => $now->format(DATE_ATOM),
                'last_cycle_key' => $summary['cycle_key'],
                'last_result' => $summary,
            ]
        );

        return $summary;
    }

    public function status(array $db, array $user, ?DateTimeImmutable $now = null): array
    {
        $now = $this->localNow($now);
        $nextCycle = $this->nextScheduledCycle($now);
        if ($nextCycle < $this->startAt()) $nextCycle = $this->startAt();

        $from = $nextCycle->modify('-7 days');
        $to = $nextCycle;
        $countTo = $now < $to ? $now : $to;
        $games = $this->countCompletedGames($db, (string)($user['id'] ?? ''), $from, $countTo);
        $min = $this->minGames();
        $lastKey = (string)($user['weekly_match_bonus_last_key'] ?? '');
        $firstGameGrants = is_array($user['weekly_match_first_game_grants'] ?? null)
            ? $user['weekly_match_first_game_grants']
            : [];

        return [
            'enabled' => true,
            'bonus_amount' => $this->weeklyAmount(),
            'starter_amount' => $this->starterAmount(),
            'first_game_amount' => $this->firstGameAmount(),
            'first_game_grant_count' => count($firstGameGrants),
            'first_game_grant_max' => count(self::GAME_TYPES),
            'min_completed_games' => $min,
            'completed_games' => $games,
            'remaining_games' => max(0, $min - $games),
            'eligible_for_next' => $games >= $min,
            'next_bonus_at' => $nextCycle->format(DATE_ATOM),
            'qualifying_from' => $from->format(DATE_ATOM),
            'qualifying_to' => $to->format(DATE_ATOM),
            'timezone' => $this->timezone()->getName(),
            'last_bonus_key' => $lastKey !== '' ? $lastKey : null,
            'last_bonus_at' => $user['weekly_match_bonus_last_at'] ?? null,
            'last_bonus_amount' => (int)($user['weekly_match_bonus_last_amount'] ?? 0),
            'all_games_reward_pending' => !empty($user['weekly_match_all_games_reward_pending']),
            'min_completed_matches' => $min,
            'completed_match_games' => $games,
            'remaining_match_games' => max(0, $min - $games),
            'first_grant_pending' => false,
        ];
    }

    private function countCompletedGames(
        array $db,
        string $userId,
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): int {
        if ($userId === '' || $to <= $from) return 0;
        $fromTs = $from->getTimestamp();
        $toTs = $to->getTimestamp();
        $count = 0;

        foreach (($db['games'] ?? []) as $game) {
            if (!is_array($game) || !$this->isCompletedNormalMatch($game, $userId)) continue;
            $finishedTs = $this->finishedTimestamp($game);
            if ($finishedTs >= $fromTs && $finishedTs < $toTs) $count++;
        }
        return $count;
    }

    private function completedGameTypes(array $db, string $userId): array
    {
        $completed = [];
        foreach (($db['games'] ?? []) as $game) {
            if (!is_array($game) || !$this->isCompletedNormalMatch($game, $userId)) continue;
            $gameType = trim((string)($game['game_type'] ?? $game['type'] ?? ''));
            if (!in_array($gameType, self::GAME_TYPES, true)) continue;
            $finishedTs = $this->finishedTimestamp($game);
            if ($finishedTs <= 0) continue;
            if (!isset($completed[$gameType]) || $finishedTs < (int)$completed[$gameType]['finished_ts']) {
                $completed[$gameType] = [
                    'id' => (string)($game['id'] ?? ''),
                    'finished_ts' => $finishedTs,
                ];
            }
        }
        return $completed;
    }

    private function isCompletedNormalMatch(array $game, string $userId): bool
    {
        if ((string)($game['status'] ?? '') !== 'finished') return false;
        if ((string)($game['room'] ?? 'match') !== 'match') return false;
        if (!in_array($userId, array_map('strval', $game['player_ids'] ?? []), true)) return false;
        if ($this->finishedTimestamp($game) <= 0) return false;

        // A preparation cancellation is a terminal storage record, not a played
        // normal match. This predicate is shared by weekly and first-game grants.
        if (trim((string)($game['preparation_cancelled_at'] ?? '')) !== '') return false;
        if (strtolower(trim((string)($game['launch_phase'] ?? ''))) === 'cancelled') return false;
        if (in_array(strtolower(trim((string)($game['finish_reason'] ?? ''))), [
            'preparation_timeout',
            'search_cancelled',
        ], true)) return false;
        if (array_key_exists('match_started', $game) && $game['match_started'] === false) return false;

        return true;
    }

    private function finishedTimestamp(array $game): int
    {
        $finishedAt = trim((string)($game['finished_at'] ?? ''));
        return $finishedAt === '' ? 0 : (strtotime($finishedAt) ?: 0);
    }

    private function existingFirstGameTransaction(array $db, array $user, string $gameType): ?array
    {
        $userId = trim((string)($user['id'] ?? ''));
        $accountRef = $this->accountRef($user);
        foreach (($db['transactions'] ?? []) as $transaction) {
            if (!is_array($transaction) || (string)($transaction['category'] ?? '') !== 'first_game_bonus') continue;
            if ((string)($transaction['game_type'] ?? '') !== $gameType) continue;
            $txAccount = trim((string)($transaction['account_ref'] ?? ''));
            $txUser = trim((string)($transaction['user_id'] ?? ''));
            if (($accountRef !== '' && $txAccount === $accountRef) || ($txUser !== '' && $txUser === $userId)) {
                return $transaction;
            }
        }
        return null;
    }

    private function appendTransaction(array &$db, array $transaction): void
    {
        if (!isset($db['transactions']) || !is_array($db['transactions'])) $db['transactions'] = [];
        $db['transactions'][] = $transaction;
    }

    private function accountRef(array $user): string
    {
        foreach (['mgw_account_ref', 'account_ref'] as $field) {
            $value = trim((string)($user[$field] ?? ''));
            if ($value !== '') return $value;
        }
        $mgwId = trim((string)($user['mgw_id'] ?? ''));
        return $mgwId !== '' ? 'mgw:' . $mgwId : '';
    }

    private function grantIdentity(array $user): string
    {
        $accountRef = $this->accountRef($user);
        if ($accountRef !== '') return $accountRef;
        return 'legacy:' . trim((string)($user['id'] ?? 'unknown'));
    }

    private function latestDueCycle(DateTimeImmutable $now): ?DateTimeImmutable
    {
        $candidate = $this->mondayAtNoon($now);
        if ($now < $candidate) $candidate = $candidate->modify('-7 days');
        return $candidate < $this->startAt() ? null : $candidate;
    }

    private function nextScheduledCycle(DateTimeImmutable $now): DateTimeImmutable
    {
        $candidate = $this->mondayAtNoon($now);
        if ($now >= $candidate) $candidate = $candidate->modify('+7 days');
        return $candidate;
    }

    private function mondayAtNoon(DateTimeImmutable $value): DateTimeImmutable
    {
        return $value->modify('monday this week')->setTime(12, 0, 0);
    }

    private function cycleKey(DateTimeImmutable $cycleAt): string
    {
        return $cycleAt->format('Y-m-d');
    }

    private function localNow(?DateTimeImmutable $now): DateTimeImmutable
    {
        if ($now === null) return new DateTimeImmutable('now', $this->timezone());
        return $now->setTimezone($this->timezone());
    }

    private function startAt(): DateTimeImmutable
    {
        $raw = trim((string)($this->config['weekly_match_start_at'] ?? self::DEFAULT_START_AT));
        try {
            return new DateTimeImmutable($raw, $this->timezone());
        } catch (Throwable) {
            return new DateTimeImmutable(self::DEFAULT_START_AT, $this->timezone());
        }
    }

    private function timezone(): DateTimeZone
    {
        return new DateTimeZone(self::CANONICAL_TIMEZONE);
    }

    private function starterAmount(): int
    {
        return $this->bonusConfig()['starter'];
    }

    private function weeklyAmount(): int
    {
        return $this->bonusConfig()['weekly'];
    }

    private function minGames(): int
    {
        return $this->bonusConfig()['weekly_match_threshold'];
    }

    private function firstGameAmount(): int
    {
        return $this->bonusConfig()['first_game'];
    }

    private function bonusConfig(): array
    {
        if ($this->resolvedBonuses !== null) return $this->resolvedBonuses;

        $candidate = $this->canonicalBonuses;
        if (!is_array($candidate)) {
            $candidate = $this->config['canonical_economy_bonuses'] ?? null;
        }
        if (is_array($candidate)) {
            return $this->resolvedBonuses = $this->normalizeBonusConfig($candidate);
        }

        try {
            if (!class_exists('DatabaseConfig') || !class_exists('PdoConnectionFactory')) {
                throw new RuntimeException('Database economy-config dependencies are unavailable.');
            }
            $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
            if (!$databaseConfig->enabled()) {
                throw new RuntimeException('Canonical economy config requires an enabled database.');
            }
            $database = PdoConnectionFactory::create($databaseConfig);
            $snapshot = (new EconomyConfigService($database))->current();
            $candidate = $snapshot['config']['bonuses'] ?? null;
            if (!is_array($candidate)) {
                throw new RuntimeException('Canonical economy bonus config is missing.');
            }
            return $this->resolvedBonuses = $this->normalizeBonusConfig($candidate);
        } catch (Throwable $error) {
            $environment = strtolower(trim((string)($this->config['environment'] ?? 'production')));
            if ($environment === 'local' || !empty($this->config['allow_economy_defaults_for_tests'])) {
                return $this->resolvedBonuses = $this->normalizeBonusConfig(
                    EconomyConfigDefinition::defaults()['bonuses']
                );
            }
            throw new RuntimeException('Canonical economy bonus config is unavailable.', 0, $error);
        }
    }

    private function normalizeBonusConfig(array $candidate): array
    {
        $normalized = [
            'starter' => (int)($candidate['starter'] ?? 0),
            'weekly' => (int)($candidate['weekly'] ?? 0),
            'weekly_match_threshold' => (int)($candidate['weekly_match_threshold'] ?? 0),
            'first_game' => (int)($candidate['first_game'] ?? 0),
        ];
        foreach ($normalized as $name => $value) {
            if ($value <= 0) throw new RuntimeException('Canonical economy bonus value is invalid: ' . $name);
        }
        return $normalized;
    }
}
