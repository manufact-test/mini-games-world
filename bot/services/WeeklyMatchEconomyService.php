<?php
declare(strict_types=1);

require_once __DIR__ . '/../economy/UnifiedBalanceRuntimeState.php';
require_once __DIR__ . '/../economy/EconomyConfigDefinition.php';
require_once __DIR__ . '/../economy/EconomyConfigSimulator.php';
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

    private array $resolvedBonuses;

    public function __construct(
        private array $config,
        private ?NotificationService $notifications = null,
        ?array $canonicalBonuses = null
    ) {
        // Resolve before any JSON write transaction begins. Staging/production
        // fail closed if the versioned MVP-15.8 DB config cannot be read.
        $this->resolvedBonuses = $this->resolveBonusConfig($canonicalBonuses);
    }

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

        // Compatibility with the historical starter implementation: an account
        // that already owns the legacy marker must never receive a second starter.
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

        // Provider-neutral transaction ownership protects a linked/merged MGW
        // account even if its current legacy user state lost the local marker.
        $existing = $this->findBonusTransaction($db, $user, 'welcome_bonus');
        if ($existing !== null) {
            $user['weekly_match_welcome_grant_done'] = true;
            $user['weekly_match_welcome_grant_migrated_at'] = now_iso();
            $user['weekly_match_welcome_grant_amount'] = max(0, (int)($existing['amount'] ?? 0));
            $user['weekly_match_first_grant_done'] = true;
            return [
                'processed' => true,
                'awarded' => false,
                'reason' => 'recovered_existing_transaction',
            ];
        }

        $amount = $this->starterAmount();
        $before = (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0);
        $after = $before + $amount;
        $createdAt = now_iso();
        $grantIdentity = $this->grantIdentity($user);

        $user[UnifiedBalanceRuntimeState::FIELD] = $after;
        $user['weekly_match_welcome_grant_done'] = true;
        $user['weekly_match_welcome_grant_at'] = $createdAt;
        $user['weekly_match_welcome_grant_amount'] = $amount;
        $user['weekly_match_first_grant_done'] = true;

        $this->appendTransaction($db, [
            'id' => make_id('tx'),
            'type' => 'balance_change',
            'category' => 'welcome_bonus',
            'event_key' => 'welcome_bonus:' . $grantIdentity,
            'grant_identity' => $grantIdentity,
            'user_id' => $userId,
            'mgw_id' => $this->mgwId($user),
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

        $amount = $this->firstGameAmount();
        $grants = $this->normalizedFirstGameGrants($user);
        $recoveredGames = [];
        $latestGrantAt = trim((string)($user['weekly_match_first_game_last_at'] ?? ''));

        // Rehydrate provider-neutral grant history before looking at the current
        // legacy user's games. This is what makes a future account link/merge
        // unable to mint the same per-game reward again.
        foreach (self::GAME_TYPES as $gameType) {
            if (isset($grants[$gameType])) continue;
            $existing = $this->existingFirstGameTransaction($db, $user, $gameType);
            if ($existing === null) continue;

            $grantedAt = (string)($existing['created_at'] ?? now_iso());
            $grants[$gameType] = [
                'amount' => max(0, (int)($existing['amount'] ?? $amount)),
                'granted_at' => $grantedAt,
                'source_game_id' => (string)($existing['source_game_id'] ?? ''),
                'recovered_from_transaction' => true,
            ];
            $latestGrantAt = $this->latestTimestampText($latestGrantAt, $grantedAt);
            $recoveredGames[] = $gameType;
        }

        $completed = $this->completedGameTypes($db, $userId);
        $awardedGames = [];

        foreach (self::GAME_TYPES as $gameType) {
            if (!isset($completed[$gameType]) || isset($grants[$gameType])) continue;

            $before = (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0);
            $after = $before + $amount;
            $createdAt = now_iso();
            $sourceGameId = (string)($completed[$gameType]['id'] ?? '');
            $grantIdentity = $this->grantIdentity($user);

            $user[UnifiedBalanceRuntimeState::FIELD] = $after;
            $grants[$gameType] = [
                'amount' => $amount,
                'granted_at' => $createdAt,
                'source_game_id' => $sourceGameId,
            ];
            $latestGrantAt = $this->latestTimestampText($latestGrantAt, $createdAt);

            $this->appendTransaction($db, [
                'id' => make_id('tx'),
                'type' => 'balance_change',
                'category' => 'first_game_bonus',
                'event_key' => 'first_game_bonus:' . $grantIdentity . ':' . $gameType,
                'grant_identity' => $grantIdentity,
                'user_id' => $userId,
                'mgw_id' => $this->mgwId($user),
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
        if ($latestGrantAt !== '') {
            $user['weekly_match_first_game_last_at'] = $latestGrantAt;
        }

        if (count($grants) === count(self::GAME_TYPES)
            && empty($user['weekly_match_all_games_reward_triggered_at'])) {
            $user['weekly_match_all_games_reward_triggered_at'] = now_iso();
            $user['weekly_match_all_games_reward_pending'] = true;
        }

        $reason = 'already_awarded';
        if ($awardedGames !== []) $reason = 'awarded';
        elseif ($recoveredGames !== []) $reason = 'recovered';
        elseif ($completed === []) $reason = 'no_completed_games';

        return [
            'processed' => true,
            'awarded' => $awardedGames !== [],
            'reason' => $reason,
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

        $existingWeekly = $this->findBonusTransaction($db, $user, 'weekly_bonus', $cycleKey);
        if ($existingWeekly !== null) {
            $user['weekly_match_bonus_last_key'] = $cycleKey;
            $user['weekly_match_bonus_last_at'] = (string)($existingWeekly['created_at'] ?? now_iso());
            $user['weekly_match_bonus_last_amount'] = max(0, (int)($existingWeekly['amount'] ?? 0));
            $user['weekly_match_bonus_last_qualification'] = 'activity';
            $user['weekly_bonus_last'] = $cycleKey;
            return [
                'processed' => true,
                'awarded' => $otherAwarded,
                'reason' => $otherAwarded ? 'bonus_awarded' : 'recovered_existing_transaction',
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
        $grantIdentity = $this->grantIdentity($user);

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
            'event_key' => 'weekly_bonus:' . $grantIdentity . ':' . $cycleKey,
            'grant_identity' => $grantIdentity,
            'user_id' => $userId,
            'mgw_id' => $this->mgwId($user),
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
        $firstGameGrants = $this->normalizedFirstGameGrants($user);

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

        // Preparation cancellation is a terminal storage record, not a played
        // match. Tutorial sessions are also never eligible for economy rewards.
        if (trim((string)($game['preparation_cancelled_at'] ?? '')) !== '') return false;
        if (strtolower(trim((string)($game['launch_phase'] ?? ''))) === 'cancelled') return false;
        if (in_array(strtolower(trim((string)($game['finish_reason'] ?? ''))), [
            'preparation_timeout',
            'search_cancelled',
        ], true)) return false;
        if (array_key_exists('match_started', $game) && $game['match_started'] === false) return false;
        if (!empty($game['is_tutorial']) || !empty($game['tutorial'])) return false;
        if (strtolower(trim((string)($game['mode'] ?? ''))) === 'tutorial') return false;
        if (strtolower(trim((string)($game['match_type'] ?? ''))) === 'tutorial') return false;

        return true;
    }

    private function finishedTimestamp(array $game): int
    {
        $finishedAt = trim((string)($game['finished_at'] ?? ''));
        return $finishedAt === '' ? 0 : (strtotime($finishedAt) ?: 0);
    }

    private function normalizedFirstGameGrants(array $user): array
    {
        $source = is_array($user['weekly_match_first_game_grants'] ?? null)
            ? $user['weekly_match_first_game_grants']
            : [];
        $normalized = [];
        foreach (self::GAME_TYPES as $gameType) {
            if (isset($source[$gameType]) && is_array($source[$gameType])) {
                $normalized[$gameType] = $source[$gameType];
            }
        }
        return $normalized;
    }

    private function existingFirstGameTransaction(array $db, array $user, string $gameType): ?array
    {
        foreach (($db['transactions'] ?? []) as $transaction) {
            if (!is_array($transaction) || (string)($transaction['category'] ?? '') !== 'first_game_bonus') continue;
            if ((string)($transaction['game_type'] ?? '') !== $gameType) continue;
            if ($this->transactionMatchesOwner($transaction, $user)) return $transaction;
        }
        return null;
    }

    private function findBonusTransaction(
        array $db,
        array $user,
        string $category,
        ?string $cycleKey = null
    ): ?array {
        foreach (($db['transactions'] ?? []) as $transaction) {
            if (!is_array($transaction) || (string)($transaction['category'] ?? '') !== $category) continue;
            if ($cycleKey !== null && (string)($transaction['cycle_key'] ?? '') !== $cycleKey) continue;
            if ($this->transactionMatchesOwner($transaction, $user)) return $transaction;
        }
        return null;
    }

    private function transactionMatchesOwner(array $transaction, array $user): bool
    {
        $identity = $this->grantIdentity($user);
        $txIdentity = trim((string)($transaction['grant_identity'] ?? ''));
        if ($txIdentity !== '' && $txIdentity === $identity) return true;

        $mgwId = $this->mgwId($user);
        $txMgwId = trim((string)($transaction['mgw_id'] ?? ''));
        if ($mgwId !== '' && $txMgwId !== '' && $txMgwId === $mgwId) return true;

        $accountRef = $this->accountRef($user);
        $txAccount = trim((string)($transaction['account_ref'] ?? ''));
        if ($accountRef !== '' && $txAccount !== '' && $txAccount === $accountRef) return true;

        $userId = trim((string)($user['id'] ?? ''));
        $txUser = trim((string)($transaction['user_id'] ?? ''));
        return $userId !== '' && $txUser !== '' && $txUser === $userId;
    }

    private function appendTransaction(array &$db, array $transaction): void
    {
        if (!isset($db['transactions']) || !is_array($db['transactions'])) $db['transactions'] = [];
        $db['transactions'][] = $transaction;
    }

    private function mgwId(array $user): string
    {
        return trim((string)($user['mgw_id'] ?? ''));
    }

    private function accountRef(array $user): string
    {
        foreach (['mgw_account_ref', 'account_ref'] as $field) {
            $value = trim((string)($user[$field] ?? ''));
            if ($value !== '') return $value;
        }
        return '';
    }

    private function grantIdentity(array $user): string
    {
        $mgwId = $this->mgwId($user);
        if ($mgwId !== '') return 'mgw:' . $mgwId;
        $accountRef = $this->accountRef($user);
        if ($accountRef !== '') return $accountRef;
        return 'legacy:' . trim((string)($user['id'] ?? 'unknown'));
    }

    private function latestTimestampText(string $left, string $right): string
    {
        if ($left === '') return $right;
        if ($right === '') return $left;
        $leftTs = strtotime($left) ?: 0;
        $rightTs = strtotime($right) ?: 0;
        return $rightTs > $leftTs ? $right : $left;
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
        return $this->resolvedBonuses['starter'];
    }

    private function weeklyAmount(): int
    {
        return $this->resolvedBonuses['weekly'];
    }

    private function minGames(): int
    {
        return $this->resolvedBonuses['weekly_match_threshold'];
    }

    private function firstGameAmount(): int
    {
        return $this->resolvedBonuses['first_game'];
    }

    private function resolveBonusConfig(?array $injected): array
    {
        if (is_array($injected)) {
            return $this->normalizeBonusConfig($injected);
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
            return $this->normalizeBonusConfig($candidate);
        } catch (Throwable $error) {
            $environment = strtolower(trim((string)($this->config['environment'] ?? 'production')));
            if ($environment === 'local') {
                return $this->normalizeBonusConfig(EconomyConfigDefinition::defaults()['bonuses']);
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
