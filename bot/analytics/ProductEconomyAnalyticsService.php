<?php
declare(strict_types=1);

require_once __DIR__ . '/RealAccountScope.php';

final class ProductEconomyAnalyticsService
{
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

    public function __construct(private DatabaseConnectionInterface $database) {}

    public function snapshot(
        array $matchmakingTelemetry,
        array $economyReconciliation,
        ?DateTimeImmutable $now = null
    ): array {
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));

        return [
            'generated_at_utc' => $now->format(DATE_ATOM),
            'users' => $this->users($now),
            'retention' => $this->retention($now),
            'games' => $this->games($now),
            'matchmaking' => $this->matchmaking($matchmakingTelemetry),
            'purchases' => $this->purchases($now),
            'ads' => $this->ads(),
            'tournaments' => $this->tournaments($now),
            'coin_flow' => $this->coinFlow($now),
            'reconciliation' => $this->reconciliation($economyReconciliation),
            'coverage' => [
                'retention' => 'Показатель означает, что аккаунт был активен хотя бы раз после указанного срока с момента регистрации. Исторических событий по каждому календарному дню нет, поэтому это не классический D1/D7 retention.',
                'matchmaking' => 'Историческая телеметрия ожидания хранится агрегатами. Старый staging-трафик нельзя достоверно отделить задним числом; новые значения продолжают накапливаться в существующем владельце телеметрии.',
                'ads' => 'События показов, кликов и рекламного дохода до MVP-22.6 не собирались. Нули не подставляются и история не восстанавливается искусственно.',
                'real_accounts' => 'Из продуктовых показателей исключаются development-аккаунты и канонические staging tournament fixtures; их исторические записи не удаляются.',
            ],
        ];
    }

    private function users(DateTimeImmutable $now): array
    {
        $scope = RealAccountScope::userPredicate('u', 'users');
        $rows = $this->database->fetchAll(
            'SELECT u.created_at_utc,u.last_seen_at_utc
             FROM mgw_users u
             WHERE ' . $scope['sql'],
            $scope['params']
        );

        $counts = [
            'real_total' => count($rows),
            'new_24h' => 0,
            'new_7d' => 0,
            'new_30d' => 0,
            'active_24h' => 0,
            'active_7d' => 0,
            'active_30d' => 0,
        ];
        $limits = [
            '24h' => $now->modify('-1 day')->getTimestamp(),
            '7d' => $now->modify('-7 days')->getTimestamp(),
            '30d' => $now->modify('-30 days')->getTimestamp(),
        ];

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $created = $this->timestamp((string)($row['created_at_utc'] ?? ''));
            $seen = $this->timestamp((string)($row['last_seen_at_utc'] ?? ''));
            foreach ($limits as $key => $limit) {
                if ($created !== null && $created >= $limit) $counts['new_' . $key]++;
                if ($seen !== null && $seen >= $limit) $counts['active_' . $key]++;
            }
        }

        return $counts;
    }

    private function retention(DateTimeImmutable $now): array
    {
        $scope = RealAccountScope::userPredicate('u', 'retention');
        $rows = $this->database->fetchAll(
            'SELECT u.created_at_utc,u.last_seen_at_utc
             FROM mgw_users u
             WHERE ' . $scope['sql'],
            $scope['params']
        );

        $oneDay = $this->returnAfter($rows, $now, 1);
        $sevenDays = $this->returnAfter($rows, $now, 7);

        return [
            'method' => 'ever_active_after_threshold',
            'return_after_1d' => $oneDay,
            'return_after_7d' => $sevenDays,
        ];
    }

    private function returnAfter(array $rows, DateTimeImmutable $now, int $days): array
    {
        $thresholdSeconds = $days * 86400;
        $eligible = 0;
        $returned = 0;
        $nowTs = $now->getTimestamp();

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $created = $this->timestamp((string)($row['created_at_utc'] ?? ''));
            $seen = $this->timestamp((string)($row['last_seen_at_utc'] ?? ''));
            if ($created === null || $created + $thresholdSeconds > $nowTs) continue;
            $eligible++;
            if ($seen !== null && $seen >= $created + $thresholdSeconds) $returned++;
        }

        return [
            'eligible_accounts' => $eligible,
            'returned_accounts' => $returned,
            'percent' => $eligible > 0 ? round(($returned / $eligible) * 100, 1) : null,
        ];
    }

    private function games(DateTimeImmutable $now): array
    {
        $real = RealAccountScope::existsForMgwExpression('mp.mgw_id', 'game_real');
        $base = 'EXISTS (
            SELECT 1 FROM mgw_match_players mp
            WHERE mp.match_id = m.match_id
              AND mp.player_type = :game_human_type
              AND ' . $real['sql'] . '
        )';
        $baseParams = $real['params'] + [
            'game_human_type' => 'human',
            'game_finished' => 'finished',
        ];

        $finishedAll = (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_matches m
             WHERE m.status = :game_finished AND ' . $base,
            $baseParams
        );

        $finished7 = (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_matches m
             WHERE m.status = :game_finished
               AND m.finished_at_utc >= :game_since_7d
               AND ' . $base,
            $baseParams + ['game_since_7d' => $this->sqlTime($now->modify('-7 days'))]
        );
        $finished30 = (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_matches m
             WHERE m.status = :game_finished
               AND m.finished_at_utc >= :game_since_30d
               AND ' . $base,
            $baseParams + ['game_since_30d' => $this->sqlTime($now->modify('-30 days'))]
        );

        $pvpReal = RealAccountScope::existsForMgwExpression('pvp.mgw_id', 'pvp_real');
        $pvp30 = (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_matches m
             WHERE m.status = :pvp_finished
               AND m.finished_at_utc >= :pvp_since
               AND (
                   SELECT COUNT(*)
                   FROM mgw_match_players pvp
                   WHERE pvp.match_id = m.match_id
                     AND pvp.player_type = :pvp_human
                     AND ' . $pvpReal['sql'] . '
               ) >= 2',
            $pvpReal['params'] + [
                'pvp_finished' => 'finished',
                'pvp_since' => $this->sqlTime($now->modify('-30 days')),
                'pvp_human' => 'human',
            ]
        );

        $humanReal = RealAccountScope::existsForMgwExpression('vh.mgw_id', 'vsbot_real');
        $vsBot30 = (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_matches m
             WHERE m.status = :vsbot_finished
               AND m.finished_at_utc >= :vsbot_since
               AND EXISTS (
                   SELECT 1 FROM mgw_match_players vh
                   WHERE vh.match_id = m.match_id
                     AND vh.player_type = :vsbot_human
                     AND ' . $humanReal['sql'] . '
               )
               AND EXISTS (
                   SELECT 1 FROM mgw_match_players vb
                   WHERE vb.match_id = m.match_id
                     AND vb.player_type <> :vsbot_human_check
               )',
            $humanReal['params'] + [
                'vsbot_finished' => 'finished',
                'vsbot_since' => $this->sqlTime($now->modify('-30 days')),
                'vsbot_human' => 'human',
                'vsbot_human_check' => 'human',
            ]
        );

        $byGameReal = RealAccountScope::existsForMgwExpression('bgp.mgw_id', 'bygame_real');
        $byGameRows = $this->database->fetchAll(
            'SELECT m.game_type,COUNT(*) AS total
             FROM mgw_matches m
             WHERE m.status = :bygame_finished
               AND m.finished_at_utc >= :bygame_since
               AND EXISTS (
                   SELECT 1 FROM mgw_match_players bgp
                   WHERE bgp.match_id = m.match_id
                     AND bgp.player_type = :bygame_human
                     AND ' . $byGameReal['sql'] . '
               )
             GROUP BY m.game_type
             ORDER BY total DESC,m.game_type ASC',
            $byGameReal['params'] + [
                'bygame_finished' => 'finished',
                'bygame_since' => $this->sqlTime($now->modify('-30 days')),
                'bygame_human' => 'human',
            ]
        );

        $byGame = array_fill_keys(self::GAME_TYPES, 0);
        foreach ($byGameRows as $row) {
            if (!is_array($row)) continue;
            $type = strtolower(trim((string)($row['game_type'] ?? '')));
            if (isset($byGame[$type])) $byGame[$type] = max(0, (int)($row['total'] ?? 0));
        }

        return [
            'finished_all_time' => $finishedAll,
            'finished_7d' => $finished7,
            'finished_30d' => $finished30,
            'pvp_30d' => $pvp30,
            'vs_bot_30d' => $vsBot30,
            'by_game_30d' => $byGame,
        ];
    }

    private function matchmaking(array $telemetry): array
    {
        $skillMatches = max(0, (int)($telemetry['matchmaking_skill_match_total'] ?? 0));
        $waitSum = max(0, (int)($telemetry['matchmaking_skill_wait_ms_sum'] ?? 0));

        return [
            'queue_depth' => max(0, (int)($telemetry['matchmaking_queue_depth'] ?? 0)),
            'last_wait_ms' => max(0, (int)($telemetry['matchmaking_wait_ms'] ?? 0)),
            'human_match_total' => max(0, (int)($telemetry['matchmaking_human_match_total'] ?? 0)),
            'bot_match_total' => max(0, (int)($telemetry['matchmaking_bot_match_total'] ?? 0)),
            'skill_match_total' => $skillMatches,
            'skill_exact_band_total' => max(0, (int)($telemetry['matchmaking_skill_exact_band_total'] ?? 0)),
            'skill_widened_match_total' => max(0, (int)($telemetry['matchmaking_skill_widened_match_total'] ?? 0)),
            'skill_wait_avg_ms' => $skillMatches > 0 ? (int)round($waitSum / $skillMatches) : null,
            'skill_wait_max_ms' => max(0, (int)($telemetry['matchmaking_skill_wait_ms_max'] ?? 0)),
            'duplicate_prevented_total' => max(0, (int)($telemetry['matchmaking_duplicate_match_prevented_total'] ?? 0)),
            'historical_scope' => 'aggregate_existing_telemetry',
        ];
    }

    private function purchases(DateTimeImmutable $now): array
    {
        $real = RealAccountScope::existsForMgwExpression('p.mgw_id', 'purchase_real');
        $base = 'p.purchase_status = :purchase_completed AND ' . $real['sql'];
        $params = $real['params'] + ['purchase_completed' => 'completed'];

        $all = (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_cosmetic_purchases p WHERE ' . $base,
            $params
        );
        $week = (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_cosmetic_purchases p
             WHERE ' . $base . ' AND p.created_at_utc >= :purchase_since_7d',
            $params + ['purchase_since_7d' => $this->sqlTime($now->modify('-7 days'))]
        );
        $monthRows = $this->database->fetchAll(
            'SELECT COUNT(*) AS total,
                    COUNT(DISTINCT p.mgw_id) AS unique_buyers,
                    COALESCE(SUM(p.price_coins),0) AS coins_spent
             FROM mgw_cosmetic_purchases p
             WHERE ' . $base . ' AND p.created_at_utc >= :purchase_since_30d',
            $params + ['purchase_since_30d' => $this->sqlTime($now->modify('-30 days'))]
        );
        $month = is_array($monthRows[0] ?? null) ? $monthRows[0] : [];

        $topRows = $this->database->fetchAll(
            'SELECT p.offer_id,COUNT(*) AS purchases,COALESCE(SUM(p.price_coins),0) AS coins_spent
             FROM mgw_cosmetic_purchases p
             WHERE ' . $base . ' AND p.created_at_utc >= :purchase_top_since
             GROUP BY p.offer_id
             ORDER BY purchases DESC,coins_spent DESC,p.offer_id ASC
             LIMIT 8',
            $params + ['purchase_top_since' => $this->sqlTime($now->modify('-30 days'))]
        );

        $top = [];
        foreach ($topRows as $row) {
            if (!is_array($row)) continue;
            $top[] = [
                'offer_id' => (string)($row['offer_id'] ?? ''),
                'purchases' => max(0, (int)($row['purchases'] ?? 0)),
                'coins_spent' => max(0, (int)($row['coins_spent'] ?? 0)),
            ];
        }

        return [
            'completed_all_time' => $all,
            'completed_7d' => $week,
            'completed_30d' => max(0, (int)($month['total'] ?? 0)),
            'unique_buyers_30d' => max(0, (int)($month['unique_buyers'] ?? 0)),
            'coins_spent_30d' => max(0, (int)($month['coins_spent'] ?? 0)),
            'top_offers_30d' => $top,
        ];
    }

    private function ads(): array
    {
        return [
            'available' => false,
            'status' => 'not_collected',
            'impressions' => null,
            'clicks' => null,
            'revenue' => null,
            'history_reconstructable' => false,
        ];
    }

    private function tournaments(DateTimeImmutable $now): array
    {
        $realRegistration = RealAccountScope::existsForMgwExpression('r.mgw_id', 'treg_real');
        $registrationParams = $realRegistration['params'] + ['treg_registered' => 'registered'];

        $realRegistrations = (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_tournament_registrations r
             WHERE r.registration_state = :treg_registered
               AND ' . $realRegistration['sql'],
            $registrationParams
        );
        $realRegistrations30 = (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_tournament_registrations r
             WHERE r.registration_state = :treg_registered
               AND r.registered_at_utc >= :treg_since
               AND ' . $realRegistration['sql'],
            $registrationParams + ['treg_since' => $this->sqlTime($now->modify('-30 days'))]
        );
        $uniqueReal = (int)$this->database->fetchValue(
            'SELECT COUNT(DISTINCT r.mgw_id) FROM mgw_tournament_registrations r
             WHERE r.registration_state = :treg_registered
               AND ' . $realRegistration['sql'],
            $registrationParams
        );
        $withReal = (int)$this->database->fetchValue(
            'SELECT COUNT(DISTINCT r.tournament_id) FROM mgw_tournament_registrations r
             WHERE r.registration_state = :treg_registered
               AND ' . $realRegistration['sql'],
            $registrationParams
        );

        $realResult = RealAccountScope::existsForMgwExpression('tr.mgw_id', 'tresult_real');
        $completedReal = (int)$this->database->fetchValue(
            'SELECT COUNT(DISTINCT tr.tournament_id)
             FROM mgw_tournament_results tr
             WHERE ' . $realResult['sql'],
            $realResult['params']
        );

        $currentRows = $this->database->fetchAll(
            'SELECT tournament_id,title,game_type,capacity,tournament_state,created_at_utc
             FROM mgw_tournaments
             WHERE active_slot = :tournament_active_slot
             LIMIT 1',
            ['tournament_active_slot' => 'official']
        );
        $current = null;
        if (is_array($currentRows[0] ?? null)) {
            $row = $currentRows[0];
            $currentId = (string)($row['tournament_id'] ?? '');
            $currentRealScope = RealAccountScope::existsForMgwExpression('cr.mgw_id', 'current_treg_real');
            $currentRealCount = (int)$this->database->fetchValue(
                'SELECT COUNT(*) FROM mgw_tournament_registrations cr
                 WHERE cr.tournament_id = :current_tournament_id
                   AND cr.registration_state = :current_tournament_registered
                   AND ' . $currentRealScope['sql'],
                $currentRealScope['params'] + [
                    'current_tournament_id' => $currentId,
                    'current_tournament_registered' => 'registered',
                ]
            );
            $current = [
                'tournament_id' => $currentId,
                'title' => (string)($row['title'] ?? ''),
                'game_type' => (string)($row['game_type'] ?? ''),
                'capacity' => max(0, (int)($row['capacity'] ?? 0)),
                'state' => (string)($row['tournament_state'] ?? ''),
                'real_registered_count' => $currentRealCount,
                'created_at_utc' => (string)($row['created_at_utc'] ?? ''),
            ];
        }

        $stateRows = $this->database->fetchAll(
            'SELECT tournament_state,COUNT(*) AS total
             FROM mgw_tournaments
             GROUP BY tournament_state
             ORDER BY tournament_state ASC'
        );
        $states = [];
        foreach ($stateRows as $row) {
            if (!is_array($row)) continue;
            $states[(string)($row['tournament_state'] ?? '')] = max(0, (int)($row['total'] ?? 0));
        }

        return [
            'records_total' => array_sum($states),
            'with_real_participants' => $withReal,
            'completed_with_real_participants' => $completedReal,
            'real_registrations_all_time' => $realRegistrations,
            'real_registrations_30d' => $realRegistrations30,
            'unique_real_participants' => $uniqueReal,
            'states' => $states,
            'current' => $current,
        ];
    }

    private function coinFlow(DateTimeImmutable $now): array
    {
        $identity = RealAccountScope::existsForLedgerIdentity('l.mgw_id', 'l.account_ref', 'ledger_real');
        $params = $identity['params'] + ['ledger_asset_code' => 'mgw_coin'];
        $deltaExpression = '(l.available_delta + l.reserved_delta)';

        $weekRows = $this->database->fetchAll(
            'SELECT
                COALESCE(SUM(CASE WHEN ' . $deltaExpression . ' > 0 THEN ' . $deltaExpression . ' ELSE 0 END),0) AS sources,
                COALESCE(SUM(CASE WHEN ' . $deltaExpression . ' < 0 THEN -(' . $deltaExpression . ') ELSE 0 END),0) AS sinks,
                COALESCE(SUM(' . $deltaExpression . '),0) AS net
             FROM mgw_ledger_entries l
             WHERE l.asset_code = :ledger_asset_code
               AND l.created_at_utc >= :ledger_since_7d
               AND ' . $identity['sql'],
            $params + ['ledger_since_7d' => $this->sqlTime($now->modify('-7 days'))]
        );
        $monthRows = $this->database->fetchAll(
            'SELECT
                COALESCE(SUM(CASE WHEN ' . $deltaExpression . ' > 0 THEN ' . $deltaExpression . ' ELSE 0 END),0) AS sources,
                COALESCE(SUM(CASE WHEN ' . $deltaExpression . ' < 0 THEN -(' . $deltaExpression . ') ELSE 0 END),0) AS sinks,
                COALESCE(SUM(' . $deltaExpression . '),0) AS net
             FROM mgw_ledger_entries l
             WHERE l.asset_code = :ledger_asset_code
               AND l.created_at_utc >= :ledger_since_30d
               AND ' . $identity['sql'],
            $params + ['ledger_since_30d' => $this->sqlTime($now->modify('-30 days'))]
        );
        $week = is_array($weekRows[0] ?? null) ? $weekRows[0] : [];
        $month = is_array($monthRows[0] ?? null) ? $monthRows[0] : [];

        $categoryRows = $this->database->fetchAll(
            'SELECT l.category,
                    COALESCE(SUM(CASE WHEN ' . $deltaExpression . ' > 0 THEN ' . $deltaExpression . ' ELSE 0 END),0) AS sources,
                    COALESCE(SUM(CASE WHEN ' . $deltaExpression . ' < 0 THEN -(' . $deltaExpression . ') ELSE 0 END),0) AS sinks,
                    COALESCE(SUM(' . $deltaExpression . '),0) AS net,
                    COUNT(*) AS entries
             FROM mgw_ledger_entries l
             WHERE l.asset_code = :ledger_asset_code
               AND l.created_at_utc >= :ledger_category_since
               AND ' . $identity['sql'] . '
             GROUP BY l.category
             ORDER BY entries DESC,l.category ASC
             LIMIT 20',
            $params + ['ledger_category_since' => $this->sqlTime($now->modify('-30 days'))]
        );
        $categories = [];
        foreach ($categoryRows as $row) {
            if (!is_array($row)) continue;
            $categories[] = [
                'category' => (string)($row['category'] ?? ''),
                'sources' => max(0, (int)($row['sources'] ?? 0)),
                'sinks' => max(0, (int)($row['sinks'] ?? 0)),
                'net' => (int)($row['net'] ?? 0),
                'entries' => max(0, (int)($row['entries'] ?? 0)),
            ];
        }

        $balanceIdentity = RealAccountScope::existsForLedgerIdentity('b.mgw_id', 'b.account_ref', 'balance_real');
        $balanceRows = $this->database->fetchAll(
            'SELECT COALESCE(SUM(b.available_amount),0) AS available,
                    COALESCE(SUM(b.reserved_amount),0) AS reserved
             FROM mgw_balances b
             WHERE b.asset_code = :balance_asset_code
               AND ' . $balanceIdentity['sql'],
            $balanceIdentity['params'] + ['balance_asset_code' => 'mgw_coin']
        );
        $balances = is_array($balanceRows[0] ?? null) ? $balanceRows[0] : [];

        return [
            'sources_7d' => max(0, (int)($week['sources'] ?? 0)),
            'sinks_7d' => max(0, (int)($week['sinks'] ?? 0)),
            'net_7d' => (int)($week['net'] ?? 0),
            'sources_30d' => max(0, (int)($month['sources'] ?? 0)),
            'sinks_30d' => max(0, (int)($month['sinks'] ?? 0)),
            'net_30d' => (int)($month['net'] ?? 0),
            'available_now' => max(0, (int)($balances['available'] ?? 0)),
            'reserved_now' => max(0, (int)($balances['reserved'] ?? 0)),
            'by_category_30d' => $categories,
            'delta_definition' => 'available_delta_plus_reserved_delta',
        ];
    }

    private function reconciliation(array $report): array
    {
        $blockers = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            (array)($report['blockers'] ?? $report['blocking_reasons'] ?? [])
        ), static fn(string $value): bool => $value !== '')));

        $unified = is_array($report['unified'] ?? null) ? $report['unified'] : [];
        foreach ((array)($unified['blocking_reasons'] ?? []) as $reason) {
            $reason = trim((string)$reason);
            if ($reason !== '' && !in_array($reason, $blockers, true)) $blockers[] = $reason;
        }

        return [
            'ok' => ($report['ok'] ?? false) === true && $blockers === [],
            'phase' => (string)($report['phase'] ?? ''),
            'warning_count' => count($blockers),
            'planned_delta_count' => max(
                0,
                (int)($report['planned_delta_count'] ?? $unified['planned_delta_count'] ?? 0)
            ),
            'integrity_failure_count' => max(0, (int)($report['integrity_failure_count'] ?? 0)),
            'active_reservation_count' => max(0, (int)($report['active_reservation_count'] ?? 0)),
            'ledger_entry_count' => max(0, (int)($report['ledger_entry_count'] ?? 0)),
            'blockers' => $blockers,
        ];
    }

    private function timestamp(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') return null;
        $timestamp = strtotime($value . (preg_match('/(?:Z|[+-]\d\d:\d\d)$/', $value) ? '' : ' UTC'));
        return $timestamp === false ? null : $timestamp;
    }

    private function sqlTime(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
