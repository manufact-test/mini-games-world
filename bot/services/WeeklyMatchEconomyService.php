<?php
declare(strict_types=1);

final class WeeklyMatchEconomyService
{
    private const DEFAULT_TIMEZONE = 'Europe/Warsaw';
    private const DEFAULT_START_AT = '2026-07-13 12:00:00';
    private const DEFAULT_BONUS_AMOUNT = 50;
    private const DEFAULT_MIN_MATCHES = 3;

    public function __construct(
        private array $config,
        private ?NotificationService $notifications = null
    ) {}

    public function applyDueForUser(array &$db, array &$user, ?DateTimeImmutable $now = null): array
    {
        $userId = trim((string)($user['id'] ?? ''));
        if ($userId === '' || !empty($user['is_dev_user'])) {
            return [
                'processed' => false,
                'awarded' => false,
                'reason' => $userId === '' ? 'missing_user' : 'dev_user',
            ];
        }

        $now = $this->localNow($now);
        $cycleAt = $this->latestDueCycle($now);
        if ($cycleAt === null) {
            return [
                'processed' => false,
                'awarded' => false,
                'reason' => 'not_started',
            ];
        }

        $cycleKey = $this->cycleKey($cycleAt);
        $checkedKey = (string)($user['weekly_match_bonus_checked_key'] ?? '');
        if ($checkedKey === $cycleKey) {
            return [
                'processed' => false,
                'awarded' => (string)($user['weekly_match_bonus_last_key'] ?? '') === $cycleKey,
                'reason' => 'already_checked',
                'cycle_key' => $cycleKey,
                'qualifying_games' => (int)($user['weekly_match_bonus_checked_games'] ?? 0),
            ];
        }

        $from = $cycleAt->modify('-7 days');
        $games = $this->countCompletedMatchGames($db, $userId, $from, $cycleAt);

        $user['weekly_match_bonus_checked_key'] = $cycleKey;
        $user['weekly_match_bonus_checked_at'] = now_iso();
        $user['weekly_match_bonus_checked_games'] = $games;

        if ($games < $this->minMatches()) {
            return [
                'processed' => true,
                'awarded' => false,
                'reason' => 'not_eligible',
                'cycle_key' => $cycleKey,
                'qualifying_games' => $games,
            ];
        }

        if ((string)($user['weekly_match_bonus_last_key'] ?? '') === $cycleKey) {
            return [
                'processed' => true,
                'awarded' => false,
                'reason' => 'already_awarded',
                'cycle_key' => $cycleKey,
                'qualifying_games' => $games,
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
            'qualifying_from' => $from->format(DATE_ATOM),
            'qualifying_to' => $cycleAt->format(DATE_ATOM),
            'qualifying_games' => $games,
            'description' => 'Еженедельный бонус за активность в Match-комнате',
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
            'min_completed_matches' => $this->minMatches(),
            'timezone' => $this->timezone()->getName(),
            'run_at' => $now->format(DATE_ATOM),
        ];

        if ($cycleAt !== null) {
            foreach (($db['users'] ?? []) as $userId => &$user) {
                if (!is_array($user)) {
                    continue;
                }

                if (!empty($user['is_dev_user'])) {
                    $summary['skipped_dev']++;
                    continue;
                }

                $result = $this->applyDueForUser($db, $user, $now);
                $reason = (string)($result['reason'] ?? '');

                if ($reason === 'already_checked') {
                    $summary['already_checked']++;
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

                $db['users'][(string)$userId] = $user;
            }
            unset($user);
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
                'min_completed_matches' => $this->minMatches(),
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
        $games = $this->countCompletedMatchGames(
            $db,
            (string)($user['id'] ?? ''),
            $from,
            $countTo
        );

        $min = $this->minMatches();
        $lastKey = (string)($user['weekly_match_bonus_last_key'] ?? '');

        return [
            'enabled' => true,
            'bonus_amount' => $this->bonusAmount(),
            'min_completed_matches' => $min,
            'completed_match_games' => $games,
            'remaining_match_games' => max(0, $min - $games),
            'eligible_for_next' => $games >= $min,
            'next_bonus_at' => $nextCycle->format(DATE_ATOM),
            'qualifying_from' => $from->format(DATE_ATOM),
            'qualifying_to' => $to->format(DATE_ATOM),
            'timezone' => $this->timezone()->getName(),
            'last_bonus_key' => $lastKey !== '' ? $lastKey : null,
            'last_bonus_at' => $user['weekly_match_bonus_last_at'] ?? null,
            'last_bonus_amount' => (int)($user['weekly_match_bonus_last_amount'] ?? 0),
        ];
    }

    private function countCompletedMatchGames(
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
            if (!is_array($game)
                || (string)($game['status'] ?? '') !== 'finished'
                || (string)($game['room'] ?? '') !== 'match') {
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

    private function minMatches(): int
    {
        return max(1, (int)($this->config['weekly_match_min_completed'] ?? self::DEFAULT_MIN_MATCHES));
    }
}
