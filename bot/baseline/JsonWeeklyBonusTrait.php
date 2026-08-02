<?php
declare(strict_types=1);

trait JsonWeeklyBonusTrait
{
    private function runWeekly(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        array $step,
        DateTimeImmutable $fallbackNow,
        array $config
    ): array {
        $now = $this->weeklyLocalNow($step['at'] ?? null, $fallbackNow, $config);
        $cycleAt = $this->latestWeeklyCycle($now, $config);
        $summary = [
            'started' => $cycleAt !== null,
            'cycle_key' => $cycleAt?->format('Y-m-d'),
            'cycle_at' => $cycleAt?->format(DATE_ATOM),
            'checked' => 0,
            'awarded' => 0,
            'ineligible' => 0,
            'already_checked' => 0,
            'skipped_dev' => 0,
            'bonus_amount' => max(1, (int)($config['weekly_match_bonus_amount'] ?? 50)),
            'min_completed_games' => max(1, (int)($config['weekly_match_min_completed'] ?? 3)),
            'timezone' => $this->weeklyTimezone($config)->getName(),
            'run_at' => $now->format(DATE_ATOM),
        ];
        $ledger = [];
        $notifications = [];
        if ($cycleAt !== null) {
            foreach (array_keys($state['users']) as $userId) {
                if (!isset($state['users'][$userId]) || !is_array($state['users'][$userId])) continue;
                $user =& $state['users'][$userId];
                if (!empty($user['is_dev_user'])) {
                    $summary['skipped_dev']++;
                    unset($user);
                    continue;
                }
                $result = $this->applyWeeklyForUser($fixture, $state, $user, $cycleAt, $now, $config);
                if (($result['reason'] ?? '') === 'already_checked') {
                    $summary['already_checked']++;
                    unset($user);
                    continue;
                }
                if (!empty($result['processed'])) $summary['checked']++;
                if (!empty($result['awarded'])) $summary['awarded']++;
                elseif (($result['reason'] ?? '') === 'not_eligible') $summary['ineligible']++;
                foreach ($result['ledger'] ?? [] as $tx) $ledger[] = $tx;
                foreach ($result['notifications'] ?? [] as $notification) $notifications[] = $notification;
                unset($user);
            }
        }
        $state['system']['weekly_match_economy'] = array_merge(
            is_array($state['system']['weekly_match_economy'] ?? null) ? $state['system']['weekly_match_economy'] : [],
            [
                'enabled' => true,
                'start_at' => $this->weeklyStartAt($config)->format(DATE_ATOM),
                'timezone' => $this->weeklyTimezone($config)->getName(),
                'bonus_amount' => $summary['bonus_amount'],
                'min_completed_games' => $summary['min_completed_games'],
                'last_run_at' => $now->format(DATE_ATOM),
                'last_cycle_key' => $summary['cycle_key'],
                'last_result' => $summary,
            ]
        );
        return [
            'public' => $summary,
            'ledger' => $ledger,
            'notifications' => $notifications,
            'event_type' => 'weekly_bonus_run',
            'cycle_key' => $summary['cycle_key'],
        ];
    }

    private function applyWeeklyForUser(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        array &$user,
        DateTimeImmutable $cycleAt,
        DateTimeImmutable $runAt,
        array $config
    ): array {
        $userId = trim((string)($user['id'] ?? ''));
        if ($userId === '' || !empty($user['is_dev_user'])) {
            return ['processed' => false, 'awarded' => false, 'reason' => $userId === '' ? 'missing_user' : 'dev_user', 'ledger' => [], 'notifications' => []];
        }
        $cycleKey = $cycleAt->format('Y-m-d');
        if ((string)($user['weekly_match_bonus_checked_key'] ?? '') === $cycleKey) {
            return [
                'processed' => false,
                'awarded' => (string)($user['weekly_match_bonus_last_key'] ?? '') === $cycleKey,
                'reason' => 'already_checked',
                'cycle_key' => $cycleKey,
                'qualifying_games' => (int)($user['weekly_match_bonus_checked_games'] ?? 0),
                'ledger' => [],
                'notifications' => [],
            ];
        }
        $from = $cycleAt->modify('-7 days');
        $games = $this->countWeeklyGames($state, $userId, $from, $cycleAt);
        $user['weekly_match_bonus_checked_key'] = $cycleKey;
        $user['weekly_match_bonus_checked_at'] = $runAt->format(DATE_ATOM);
        $user['weekly_match_bonus_checked_games'] = $games;
        $min = max(1, (int)($config['weekly_match_min_completed'] ?? 3));
        if ($games < $min) {
            return [
                'processed' => true,
                'awarded' => false,
                'reason' => 'not_eligible',
                'cycle_key' => $cycleKey,
                'qualifying_games' => $games,
                'ledger' => [],
                'notifications' => [],
            ];
        }
        if ((string)($user['weekly_match_bonus_last_key'] ?? '') === $cycleKey) {
            return [
                'processed' => true,
                'awarded' => false,
                'reason' => 'already_awarded',
                'cycle_key' => $cycleKey,
                'qualifying_games' => $games,
                'ledger' => [],
                'notifications' => [],
            ];
        }
        $amount = max(1, (int)($config['weekly_match_bonus_amount'] ?? 50));
        $before = (int)($user['balance_match'] ?? 0);
        $after = $before + $amount;
        $user['balance_match'] = $after;
        $user['weekly_match_bonus_last_key'] = $cycleKey;
        $user['weekly_match_bonus_last_at'] = $runAt->format(DATE_ATOM);
        $user['weekly_match_bonus_last_amount'] = $amount;
        $user['weekly_match_bonus_last_qualification'] = 'activity';
        $user['weekly_bonus_last'] = $cycleKey;
        $tx = [
            'id' => $fixture->nextId('tx'),
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
            'created_at' => $runAt->format(DATE_ATOM),
        ];
        $state['transactions'][] = $tx;
        $notification = [
            'id' => $fixture->nextId('notification'),
            'user_id' => $userId,
            'type' => 'weekly_match_bonus',
            'title' => 'Еженедельный бонус',
            'message' => '+' . $amount . ' Match коинов за игровую активность',
            'amount' => $amount,
            'cycle_key' => $cycleKey,
            'qualifying_games' => $games,
            'read' => false,
            'hidden' => false,
            'created_at' => $runAt->format(DATE_ATOM),
        ];
        $state['notifications'][] = $notification;
        return [
            'processed' => true,
            'awarded' => true,
            'reason' => 'awarded',
            'cycle_key' => $cycleKey,
            'qualifying_games' => $games,
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
            'ledger' => [$tx],
            'notifications' => [$notification],
        ];
    }

    private function weeklyStatusStep(array $state, array $step, DateTimeImmutable $fallbackNow, array $config): array
    {
        $userId = trim((string)($step['actor_id'] ?? ''));
        if ($userId === '' || !isset($state['users'][$userId])) throw new RuntimeException('Пользователь не найден.');
        $now = $this->weeklyLocalNow($step['at'] ?? null, $fallbackNow, $config);
        return ['public' => $this->weeklyStatus($state, $userId, $now, $config), 'ledger' => [], 'event_type' => 'weekly_status_read'];
    }

    private function weeklyProjection(array $state, array $config, DateTimeImmutable $base): array
    {
        $users = [];
        foreach ($state['users'] as $userId => $user) {
            if (!is_array($user) || !empty($user['is_dev_user'])) continue;
            $users[(string)$userId] = [
                'balance_match' => (int)($user['balance_match'] ?? 0),
                'checked_key' => $user['weekly_match_bonus_checked_key'] ?? null,
                'checked_games' => (int)($user['weekly_match_bonus_checked_games'] ?? 0),
                'last_key' => $user['weekly_match_bonus_last_key'] ?? null,
                'last_amount' => (int)($user['weekly_match_bonus_last_amount'] ?? 0),
            ];
        }
        ksort($users, SORT_STRING);
        return [
            'timezone' => $this->weeklyTimezone($config)->getName(),
            'bonus_amount' => max(1, (int)($config['weekly_match_bonus_amount'] ?? 50)),
            'min_completed_games' => max(1, (int)($config['weekly_match_min_completed'] ?? 3)),
            'system' => $state['system']['weekly_match_economy'] ?? null,
            'users' => $users,
            'base_at' => $base->setTimezone($this->weeklyTimezone($config))->format(DATE_ATOM),
        ];
    }

    private function weeklyStatus(array $state, string $userId, DateTimeImmutable $now, array $config): array
    {
        $next = $this->mondayNoon($now);
        if ($now >= $next) $next = $next->modify('+7 days');
        $start = $this->weeklyStartAt($config);
        if ($next < $start) $next = $start;
        $from = $next->modify('-7 days');
        $to = $next;
        $countTo = $now < $to ? $now : $to;
        $games = $this->countWeeklyGames($state, $userId, $from, $countTo);
        $min = max(1, (int)($config['weekly_match_min_completed'] ?? 3));
        $user = $state['users'][$userId];
        return [
            'enabled' => true,
            'bonus_amount' => max(1, (int)($config['weekly_match_bonus_amount'] ?? 50)),
            'min_completed_games' => $min,
            'completed_games' => $games,
            'remaining_games' => max(0, $min - $games),
            'eligible_for_next' => $games >= $min,
            'next_bonus_at' => $next->format(DATE_ATOM),
            'qualifying_from' => $from->format(DATE_ATOM),
            'qualifying_to' => $to->format(DATE_ATOM),
            'timezone' => $this->weeklyTimezone($config)->getName(),
            'last_bonus_key' => ($user['weekly_match_bonus_last_key'] ?? '') !== '' ? $user['weekly_match_bonus_last_key'] : null,
            'last_bonus_at' => $user['weekly_match_bonus_last_at'] ?? null,
            'last_bonus_amount' => (int)($user['weekly_match_bonus_last_amount'] ?? 0),
            'min_completed_matches' => $min,
            'completed_match_games' => $games,
            'remaining_match_games' => max(0, $min - $games),
            'first_grant_pending' => false,
        ];
    }

    private function countWeeklyGames(array $state, string $userId, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        if ($userId === '' || $to <= $from) return 0;
        $count = 0;
        $fromTs = $from->getTimestamp();
        $toTs = $to->getTimestamp();
        foreach ($state['games'] ?? [] as $game) {
            if (!is_array($game) || (string)($game['status'] ?? '') !== 'finished') continue;
            if ((string)($game['room'] ?? 'match') !== 'match') continue;
            if (!in_array($userId, array_map('strval', $game['player_ids'] ?? []), true)) continue;
            $finishedAt = trim((string)($game['finished_at'] ?? ''));
            if ($finishedAt === '') continue;
            $ts = strtotime($finishedAt) ?: 0;
            if ($ts >= $fromTs && $ts < $toTs) $count++;
        }
        return $count;
    }

    private function latestWeeklyCycle(DateTimeImmutable $now, array $config): ?DateTimeImmutable
    {
        $candidate = $this->mondayNoon($now);
        if ($now < $candidate) $candidate = $candidate->modify('-7 days');
        return $candidate < $this->weeklyStartAt($config) ? null : $candidate;
    }

    private function mondayNoon(DateTimeImmutable $value): DateTimeImmutable
    {
        return $value->modify('monday this week')->setTime(12, 0, 0);
    }

    private function weeklyLocalNow(mixed $raw, DateTimeImmutable $fallback, array $config): DateTimeImmutable
    {
        $timezone = $this->weeklyTimezone($config);
        if (is_string($raw) && trim($raw) !== '') return (new DateTimeImmutable($raw))->setTimezone($timezone);
        return $fallback->setTimezone($timezone);
    }

    private function weeklyStartAt(array $config): DateTimeImmutable
    {
        $timezone = $this->weeklyTimezone($config);
        $raw = trim((string)($config['weekly_match_start_at'] ?? '2026-07-13 12:00:00'));
        try { return new DateTimeImmutable($raw, $timezone); }
        catch (Throwable) { return new DateTimeImmutable('2026-07-13 12:00:00', $timezone); }
    }

    private function weeklyTimezone(array $config): DateTimeZone
    {
        $name = trim((string)($config['weekly_match_timezone'] ?? 'Europe/Warsaw'));
        try { return new DateTimeZone($name !== '' ? $name : 'Europe/Warsaw'); }
        catch (Throwable) { return new DateTimeZone('Europe/Warsaw'); }
    }
}
