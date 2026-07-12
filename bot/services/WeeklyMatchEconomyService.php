<?php
declare(strict_types=1);

final class WeeklyMatchEconomyService
{
    private const DEFAULT_TIMEZONE = 'Europe/Warsaw';
    private const DEFAULT_START_AT = '2026-07-13 12:00:00';
    private const DEFAULT_BONUS_AMOUNT = 50;
    private const DEFAULT_MIN_GAMES = 3;

    public function __construct(
        private array $config,
        private ?NotificationService $notifications = null
    ) {}

    public function ensureWelcomeGrant(array &$db, array &$user): array
    {
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

        // Compatibility with the short-lived v45 implementation: if a player
        // already received its first special grant there, never pay it again.
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

        $amount = $this->bonusAmount();
        $before = (int)($user['balance_match'] ?? 0);
        $after = $before + $amount;
        $createdAt = now_iso();

        $user['balance_match'] = $after;
        $user['weekly_match_welcome_grant_done'] = true;
        $user['weekly_match_welcome_grant_at'] = $createdAt;
        $user['weekly_match_welcome_grant_amount'] = $amount;
        $user['weekly_match_first_grant_done'] = true;

        if (!isset($db['transactions']) || !is_array($db['transactions'])) {
            $db['transactions'] = [];
        }

        $db['transactions'][] = [
            'id' => make_id('tx'),
            'type' => 'balance_change',
            'category' => 'welcome_bonus',
            'user_id' => $userId,
            'username' => (string)($user['username'] ?? ''),
            'room' => 'match',
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
            'description' => 'Первые коины в Матч-комнате',
            'created_at' => $createdAt,
        ];

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

    public function applyDueForUser(
        array &$db,
        array &$user,
        ?DateTimeImmutable $now = null,
        bool $allowWelcomeGrant = true
    ): array {
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

        $now = $this->localNow($now);
        $cycleAt = $this->latestDueCycle($now);
        if ($cycleAt === null) {
            return [
                'processed' => !empty($welcomeResult['processed']),
                'awarded' => !empty($welcomeResult['awarded']),
                'reason' => !empty($welcomeResult['awarded']) ? 'welcome_awarded' : 'not_started',
                'welcome' => $welcomeResult,
            ];
        }

        $cycleKey = $this->cycleKey($cycleAt);
        $checkedKey = (string)($user['weekly_match_bonus_checked_key'] ?? '');
        if ($checkedKey === $cycleKey) {
            return [
                'processed' => !empty($welcomeResult['processed']),
                'awarded' => !empty($welcomeResult['awarded'])
                    || (string)($user['weekly_match_bonus_last_key'] ?? '') === $cycleKey,
                'reason' => !empty($welcomeResult['awarded']) ? 'welcome_awarded' : 'already_checked',
                'cycle_key' => $cycleKey,
                'qualifying_games' => (int)($user['weekly_match_bonus_checked_games'] ?? 0),
                'welcome' => $welcomeResult,
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
                'awarded' => !empty($welcomeResult['awarded']),
                'reason' => !empty($welcomeResult['awarded']) ? 'welcome_awarded' : 'not_eligible',
                'cycle_key' => $cycleKey,
                'qualifying_games' => $games,
                'welcome' => $welcomeResult,
            ];
        }

        if ((string)($user['weekly_match_bonus_last_key'] ?? '') === $cycleKey) {
            return [
                'processed' => true,
                'awarded' => !empty($welcomeResult['awarded']),
                'reason' => !empty($welcomeResult['awarded']) ? 'welcome_awarded' : 'already_awarded',
                'cycle_key' => $cycleKey,
                'qualifying_games' => $games,
                'welcome' => $welcomeResult,
            ];
        }

        $amount = $this->bonusAmount();
        $before = (int)($user['balance_match'] ?? 0);
        $after = $before + $amount;
        $awardedAt = now_iso();

        $user['balance_match'] = $after;
        $user['weekly_match_bonus_last_key'] = $cycleKey;
        $user['weekly_match_bonus_last_at'] = $awardedAt;
        $user['weekly_match_bonus_last_amount'] = $amount;
        $user['weekly_match_bonus_last_qualification'] = 'activity';
        $user['weekly_bonus_last'] = $cycleKey;

        if (!isset($db['transactions']) || !is_array($db['transactions'])) {
            $db['transactions'] = [];
        }

        $db['transactions'][] = [
            'id' => make_id('tx'),
            'type' => 'balance_change',
            'category' => 'weekly_bonus',
            'user_id' => $userId,
            'username' => (string)($user['username'] ?? ''),
            'room' => 'match',
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
        ];

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
        ];
    }

    public function runDue(array &$db, ?DateTimeImmutable $now = null): array
    {
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
            'bonus_amount' => $this->bonusAmount(),
            'min_completed_games' => $this->minGames(),
            'timezone' => $this->timezone()->getName(),
            'run_at' => $now->format(DATE_ATOM),
        ];

        if ($cycleAt !== null) {
            foreach (array_keys($db['users'] ?? []) as $userId) {
                if (!isset($db['users'][$userId]) || !is_array($db['users'][$userId])) {
                    continue;
                }

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

                if (!empty($result['processed'])) {
                    $summary['checked']++;
                }
                if (!empty($result['awarded'])) {
                    $summary['awarded']++;
                } elseif ($reason === 'not_eligible') {
                    $summary['ineligible']++;
                }

                unset($user);
            }
        }

        if (!isset($db['system']) || !is_array($db['system'])) {
            $db['system'] = [];
        }
        $db['system']['weekly_match_economy'] = array_merge(
            is_array($db['system']['weekly_match_economy'] ?? null) ? $db['system']['weekly_match_economy'] : [],
            [
                'enabled' => true,
                'start_at' => $this->startAt()->format(DATE_ATOM),
                'timezone' => $this->timezone()->getName(),
                'bonus_amount' => $this->bonusAmount(),
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
        if ($nextCycle < $this->startAt()) {
            $nextCycle = $this->startAt();
        }

        $from = $nextCycle->modify('-7 days');
        $to = $nextCycle;
        $countTo = $now < $to ? $now : $to;
        $games = $this->countCompletedGames(
            $db,
            (string)($user['id'] ?? ''),
            $from,
            $countTo
        );

        $min = $this->minGames();
        $lastKey = (string)($user['weekly_match_bonus_last_key'] ?? '');

        return [
            'enabled' => true,
            'bonus_amount' => $this->bonusAmount(),
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

            // Backward-compatible aliases for any cached v44-v45 client.
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
        if ($userId === '' || $to <= $from) {
            return 0;
        }

        $fromTs = $from->getTimestamp();
        $toTs = $to->getTimestamp();
        $count = 0;

        foreach (($db['games'] ?? []) as $game) {
            if (!is_array($game) || (string)($game['status'] ?? '') !== 'finished') {
                continue;
            }

            // Weekly Match rewards must never be unlocked by Gold-room games.
            // Old records without an explicit room are treated as Match for compatibility.
            if ((string)($game['room'] ?? 'match') !== 'match') {
                continue;
            }

            $players = array_map('strval', $game['player_ids'] ?? []);
            if (!in_array($userId, $players, true)) {
                continue;
            }

            $finishedAt = trim((string)($game['finished_at'] ?? ''));
            if ($finishedAt === '') {
                continue;
            }

            $finishedTs = strtotime($finishedAt) ?: 0;
            if ($finishedTs >= $fromTs && $finishedTs < $toTs) {
                $count++;
            }
        }

        return $count;
    }

    private function latestDueCycle(DateTimeImmutable $now): ?DateTimeImmutable
    {
        $candidate = $this->mondayAtNoon($now);
        if ($now < $candidate) {
            $candidate = $candidate->modify('-7 days');
        }

        return $candidate < $this->startAt() ? null : $candidate;
    }

    private function nextScheduledCycle(DateTimeImmutable $now): DateTimeImmutable
    {
        $candidate = $this->mondayAtNoon($now);
        if ($now >= $candidate) {
            $candidate = $candidate->modify('+7 days');
        }
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
        if ($now === null) {
            return new DateTimeImmutable('now', $this->timezone());
        }
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
        $name = trim((string)($this->config['weekly_match_timezone'] ?? self::DEFAULT_TIMEZONE));
        try {
            return new DateTimeZone($name !== '' ? $name : self::DEFAULT_TIMEZONE);
        } catch (Throwable) {
            return new DateTimeZone(self::DEFAULT_TIMEZONE);
        }
    }

    private function bonusAmount(): int
    {
        return max(1, (int)($this->config['weekly_match_bonus_amount'] ?? self::DEFAULT_BONUS_AMOUNT));
    }

    private function minGames(): int
    {
        return max(1, (int)($this->config['weekly_match_min_completed'] ?? self::DEFAULT_MIN_GAMES));
    }
}
