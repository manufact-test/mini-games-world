<?php
declare(strict_types=1);

/**
 * MVP-21.9 read-only product projector.
 *
 * TournamentSettlementService is the only tournament reward writer. This class
 * never mutates reward, ledger, inventory, profile or rating state; it projects
 * the already-settled 21.9 tables into Profile, tournament archive and the
 * tournament Hall of Fame.
 */
final class TournamentRewardProjectionService
{
    private const MAX_PROFILE_HISTORY = 20;
    private const MAX_PUBLIC_TOURNAMENTS = 40;
    private const MAX_HALL_OF_FAME = 100;

    public function __construct(private DatabaseConnectionInterface $database) {}

    public function profileSnapshot(
        string $mgwId,
        ?DateTimeImmutable $now = null
    ): array {
        $mgwId = $this->required($mgwId, 24, 'MGW-ID');
        $moment = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $nowUtc = $moment->format('Y-m-d H:i:s.u');

        $historyRows = $this->database->fetchAll(
            'SELECT tr.tournament_id,tr.placement,tr.result_code,tr.entry_amount,
                    tr.entry_return_amount,tr.prize_amount,tr.payout_amount,tr.settled_at_utc,
                    t.title,t.game_type,t.capacity,t.scheduled_start_at_utc
             FROM mgw_tournament_results tr
             INNER JOIN mgw_tournaments t ON t.tournament_id=tr.tournament_id
             WHERE tr.mgw_id=:mgw_id AND tr.reward_eligible=1
             ORDER BY tr.settled_at_utc DESC,tr.tournament_id DESC
             LIMIT ' . self::MAX_PROFILE_HISTORY,
            ['mgw_id'=>$mgwId]
        );

        $entitlementRows = $this->database->fetchAll(
            'SELECT e.tournament_id,e.reward_code,e.reward_kind,
                    e.valid_from_at_utc,e.valid_until_at_utc,e.granted_at_utc,
                    t.title,t.game_type,t.scheduled_start_at_utc
             FROM mgw_tournament_reward_entitlements e
             INNER JOIN mgw_tournaments t ON t.tournament_id=e.tournament_id
             WHERE e.mgw_id=:mgw_id
             ORDER BY e.granted_at_utc DESC,e.reward_code ASC',
            ['mgw_id'=>$mgwId]
        );

        $byTournament = [];
        $permanent = [];
        $activeTemporary = [];
        foreach ($entitlementRows as $row) {
            if (!is_array($row)) continue;
            $reward = $this->publicEntitlement($row, $nowUtc);
            $tournamentId = (string)$row['tournament_id'];
            $byTournament[$tournamentId] ??= [];
            $byTournament[$tournamentId][] = $reward;

            if ((string)$row['reward_kind'] === 'permanent_achievement') {
                $permanent[] = $reward;
            } elseif ((string)$row['reward_kind'] === 'temporary_style'
                && $reward['active'] === true) {
                $activeTemporary[] = $reward;
            }
        }

        $history = [];
        $podiums = 0;
        $championshipsFromResults = 0;
        foreach ($historyRows as $row) {
            if (!is_array($row)) continue;
            $placement = $this->nullableInt($row['placement'] ?? null);
            if ($placement !== null && $placement <= 3) $podiums++;
            if ($placement === 1) $championshipsFromResults++;
            $tournamentId = (string)$row['tournament_id'];
            $history[] = [
                'tournament_id'=>$tournamentId,
                'title'=>(string)($row['title'] ?? 'Официальный турнир'),
                'game_type'=>(string)($row['game_type'] ?? ''),
                'capacity'=>(int)($row['capacity'] ?? 0),
                'scheduled_start_at_utc'=>$this->nullableText($row['scheduled_start_at_utc'] ?? null),
                'settled_at_utc'=>(string)$row['settled_at_utc'],
                'placement'=>$placement,
                'result_code'=>(string)$row['result_code'],
                'entry_amount'=>(int)$row['entry_amount'],
                'entry_return_amount'=>(int)$row['entry_return_amount'],
                'prize_amount'=>(int)$row['prize_amount'],
                'payout_amount'=>(int)$row['payout_amount'],
                'rewards'=>$byTournament[$tournamentId] ?? [],
            ];
        }

        $ticket = $this->goldenTicket($mgwId);
        $summaryRows = $this->database->fetchAll(
            'SELECT COUNT(*) AS tournaments,
                    SUM(CASE WHEN placement BETWEEN 1 AND 3 THEN 1 ELSE 0 END) AS podiums,
                    SUM(CASE WHEN placement=1 THEN 1 ELSE 0 END) AS championships
             FROM mgw_tournament_results
             WHERE mgw_id=:mgw_id AND reward_eligible=1',
            ['mgw_id'=>$mgwId]
        );
        $summaryRow = count($summaryRows) === 1 && is_array($summaryRows[0])
            ? $summaryRows[0]
            : [];

        return [
            'available'=>$history !== [] || $permanent !== [] || $activeTemporary !== [] || $ticket !== null,
            'golden_ticket'=>$ticket,
            'summary'=>[
                'tournaments'=>max(0,(int)($summaryRow['tournaments'] ?? count($history))),
                'podiums'=>max(0,(int)($summaryRow['podiums'] ?? $podiums)),
                'championships'=>$ticket !== null
                    ? (int)$ticket['championship_count']
                    : max(0,(int)($summaryRow['championships'] ?? $championshipsFromResults)),
            ],
            'active_temporary'=>$activeTemporary,
            'permanent_achievements'=>$permanent,
            'history'=>$history,
            'generated_at_utc'=>$nowUtc,
        ];
    }

    public function prestigeSnapshot(
        string $mgwId,
        ?DateTimeImmutable $now = null
    ): array {
        $mgwId = $this->required($mgwId, 24, 'MGW-ID');
        $moment = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $nowUtc = $moment->format('Y-m-d H:i:s.u');

        $summaryRows = $this->database->fetchAll(
            'SELECT COUNT(*) AS tournaments,
                    SUM(CASE WHEN placement BETWEEN 1 AND 3 THEN 1 ELSE 0 END) AS podiums,
                    SUM(CASE WHEN placement=1 THEN 1 ELSE 0 END) AS championships
             FROM mgw_tournament_results
             WHERE mgw_id=:mgw_id AND reward_eligible=1',
            ['mgw_id'=>$mgwId]
        );
        $summaryRow = count($summaryRows) === 1 && is_array($summaryRows[0])
            ? $summaryRows[0]
            : [];
        $ticket = $this->goldenTicket($mgwId);

        $crownRows = $this->database->fetchAll(
            "SELECT e.tournament_id,e.reward_code,e.reward_kind,
                    e.valid_from_at_utc,e.valid_until_at_utc,e.granted_at_utc,
                    t.title,t.game_type,t.scheduled_start_at_utc
             FROM mgw_tournament_reward_entitlements e
             INNER JOIN mgw_tournaments t ON t.tournament_id=e.tournament_id
             WHERE e.mgw_id=:mgw_id
               AND e.reward_code='champion_crown'
             ORDER BY e.granted_at_utc DESC
             LIMIT 8",
            ['mgw_id'=>$mgwId]
        );
        $activeTemporary = [];
        foreach ($crownRows as $row) {
            if (!is_array($row)) continue;
            $reward = $this->publicEntitlement($row, $nowUtc);
            if (($reward['active'] ?? false) === true) $activeTemporary[] = $reward;
        }

        $tournaments = max(0,(int)($summaryRow['tournaments'] ?? 0));
        $podiums = max(0,(int)($summaryRow['podiums'] ?? 0));
        $championships = $ticket !== null
            ? max(0,(int)($ticket['championship_count'] ?? 0))
            : max(0,(int)($summaryRow['championships'] ?? 0));

        return [
            'available'=>$tournaments > 0 || $ticket !== null || $activeTemporary !== [],
            'golden_ticket'=>$ticket,
            'summary'=>[
                'tournaments'=>$tournaments,
                'podiums'=>$podiums,
                'championships'=>$championships,
            ],
            'active_temporary'=>$activeTemporary,
            'permanent_achievements'=>[],
            'history'=>[],
            'partial'=>true,
            'generated_at_utc'=>$nowUtc,
        ];
    }

    public function publicArchive(int $limit = self::MAX_PUBLIC_TOURNAMENTS): array
    {
        $limit = max(1, min(self::MAX_PUBLIC_TOURNAMENTS, $limit));
        $rows = $this->database->fetchAll(
            'SELECT t.tournament_id,t.title,t.game_type,t.capacity,t.scheduled_start_at_utc,
                    MAX(tr.settled_at_utc) AS completed_at_utc
             FROM mgw_tournament_results tr
             INNER JOIN mgw_tournaments t ON t.tournament_id=tr.tournament_id
             WHERE tr.reward_eligible=1
             GROUP BY t.tournament_id,t.title,t.game_type,t.capacity,t.scheduled_start_at_utc
             ORDER BY completed_at_utc DESC,t.tournament_id DESC
             LIMIT ' . $limit
        );

        if ($rows === []) {
            return [
                'available'=>false,
                'entries'=>[],
                'hall_of_fame'=>[],
            ];
        }

        $ids = [];
        $meta = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $id = trim((string)($row['tournament_id'] ?? ''));
            if ($id === '') continue;
            $ids[] = $id;
            $meta[$id] = $row;
        }
        if ($ids === []) {
            return [
                'available'=>false,
                'entries'=>[],
                'hall_of_fame'=>[],
            ];
        }

        [$inSql,$params] = $this->inParams('tournament', $ids);
        $podiumRows = $this->database->fetchAll(
            "SELECT tr.tournament_id,tr.placement,tr.result_code,tr.settled_at_utc,
                    u.mgw_id,u.nickname,u.display_name,u.equipped_avatar_item_id
             FROM mgw_tournament_results tr
             INNER JOIN mgw_users u ON u.mgw_id=tr.mgw_id
             WHERE tr.tournament_id IN ($inSql)
               AND tr.reward_eligible=1
               AND tr.placement BETWEEN 1 AND 3
             ORDER BY tr.settled_at_utc DESC,tr.tournament_id DESC,tr.placement ASC",
            $params
        );

        $podiums = [];
        foreach ($podiumRows as $row) {
            if (!is_array($row)) continue;
            $tournamentId = (string)$row['tournament_id'];
            $podiums[$tournamentId] ??= [];
            $podiums[$tournamentId][] = $this->publicPodiumResult($row);
        }

        $entries = [];
        foreach ($ids as $id) {
            $row = $meta[$id];
            $podium = $podiums[$id] ?? [];
            $entries[] = [
                'tournament_id'=>$id,
                'title'=>(string)($row['title'] ?? 'Официальный турнир'),
                'game_type'=>(string)($row['game_type'] ?? ''),
                'capacity'=>(int)($row['capacity'] ?? 0),
                'scheduled_start_at_utc'=>$this->nullableText($row['scheduled_start_at_utc'] ?? null),
                'completed_at_utc'=>(string)($row['completed_at_utc'] ?? ''),
                'top3'=>$podium,
            ];
        }

        return [
            'available'=>$entries !== [],
            'entries'=>$entries,
            'hall_of_fame'=>$this->hallOfFame(self::MAX_HALL_OF_FAME),
        ];
    }

    public function hallOfFame(int $limit = self::MAX_HALL_OF_FAME): array
    {
        $limit = max(1, min(self::MAX_HALL_OF_FAME, $limit));
        $rows = $this->database->fetchAll(
            'SELECT tr.tournament_id,tr.settled_at_utc,
                    t.title,t.game_type,t.capacity,t.scheduled_start_at_utc,
                    u.mgw_id,u.nickname,u.display_name,u.equipped_avatar_item_id,
                    gt.championship_count
             FROM mgw_tournament_results tr
             INNER JOIN mgw_tournaments t ON t.tournament_id=tr.tournament_id
             INNER JOIN mgw_users u ON u.mgw_id=tr.mgw_id
             INNER JOIN mgw_tournament_reward_entitlements h
               ON h.tournament_id=tr.tournament_id
              AND h.mgw_id=tr.mgw_id
              AND h.reward_code=:hall_reward_code
             LEFT JOIN mgw_tournament_golden_tickets gt ON gt.mgw_id=tr.mgw_id
             WHERE tr.placement=1
               AND tr.reward_eligible=1
             ORDER BY tr.settled_at_utc DESC,tr.tournament_id DESC
             LIMIT ' . $limit,
            ['hall_reward_code'=>'hall_of_fame']
        );

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $nickname = trim((string)($row['nickname'] ?? ''));
            if ($nickname === '') $nickname = trim((string)($row['display_name'] ?? ''));
            if ($nickname === '') $nickname = 'Игрок';
            $mgwId = trim((string)($row['mgw_id'] ?? ''));
            $result[] = [
                'tournament_id'=>(string)$row['tournament_id'],
                'title'=>(string)($row['title'] ?? 'Официальный турнир'),
                'game_type'=>(string)($row['game_type'] ?? ''),
                'capacity'=>(int)($row['capacity'] ?? 0),
                'scheduled_start_at_utc'=>$this->nullableText($row['scheduled_start_at_utc'] ?? null),
                'settled_at_utc'=>(string)$row['settled_at_utc'],
                'public_mgw_id'=>$mgwId !== '' ? MgwIdGenerator::toPublic($mgwId) : '',
                'nickname'=>$nickname,
                'avatar_item_id'=>$this->avatar((string)($row['equipped_avatar_item_id'] ?? '')),
                'championship_count'=>max(1,(int)($row['championship_count'] ?? 1)),
            ];
        }
        return $result;
    }

    private function goldenTicket(string $mgwId): ?array
    {
        $rows = $this->database->fetchAll(
            'SELECT ticket_state,championship_count,first_tournament_id,last_tournament_id,
                    first_awarded_at_utc,last_awarded_at_utc
             FROM mgw_tournament_golden_tickets
             WHERE mgw_id=:mgw_id
             LIMIT 2',
            ['mgw_id'=>$mgwId]
        );
        if ($rows === []) return null;
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Golden Ticket projection is invalid.');
        }
        $row = $rows[0];
        return [
            'state'=>(string)$row['ticket_state'],
            'valid'=>(string)$row['ticket_state'] === 'active',
            'championship_count'=>(int)$row['championship_count'],
            'first_tournament_id'=>(string)$row['first_tournament_id'],
            'last_tournament_id'=>(string)$row['last_tournament_id'],
            'first_awarded_at_utc'=>(string)$row['first_awarded_at_utc'],
            'last_awarded_at_utc'=>(string)$row['last_awarded_at_utc'],
            'sellable'=>false,
            'transferable'=>false,
        ];
    }

    private function publicEntitlement(array $row, string $nowUtc): array
    {
        $validUntil = $this->nullableText($row['valid_until_at_utc'] ?? null);
        $kind = (string)$row['reward_kind'];
        $active = $kind === 'permanent_achievement'
            || ($kind === 'temporary_style' && ($validUntil === null || strcmp($validUntil, $nowUtc) > 0));
        return [
            'tournament_id'=>(string)$row['tournament_id'],
            'tournament_title'=>(string)($row['title'] ?? 'Официальный турнир'),
            'game_type'=>(string)($row['game_type'] ?? ''),
            'scheduled_start_at_utc'=>$this->nullableText($row['scheduled_start_at_utc'] ?? null),
            'reward_code'=>(string)$row['reward_code'],
            'reward_kind'=>$kind,
            'valid_from_at_utc'=>$this->nullableText($row['valid_from_at_utc'] ?? null),
            'valid_until_at_utc'=>$validUntil,
            'granted_at_utc'=>(string)$row['granted_at_utc'],
            'active'=>$active,
        ];
    }

    private function publicPodiumResult(array $row): array
    {
        $nickname = trim((string)($row['nickname'] ?? ''));
        if ($nickname === '') $nickname = trim((string)($row['display_name'] ?? ''));
        if ($nickname === '') $nickname = 'Игрок';
        $mgwId = trim((string)($row['mgw_id'] ?? ''));
        return [
            'placement'=>(int)$row['placement'],
            'result_code'=>(string)$row['result_code'],
            'public_mgw_id'=>$mgwId !== '' ? MgwIdGenerator::toPublic($mgwId) : '',
            'nickname'=>$nickname,
            'avatar_item_id'=>$this->avatar((string)($row['equipped_avatar_item_id'] ?? '')),
        ];
    }

    private function avatar(string $itemId): string
    {
        $itemId = trim($itemId);
        return $itemId !== '' ? $itemId : 'starter-default-01';
    }

    private function inParams(string $prefix, array $values): array
    {
        $params = [];
        $placeholders = [];
        foreach (array_values($values) as $index=>$value) {
            $key = $prefix . '_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = (string)$value;
        }
        if ($placeholders === []) throw new InvalidArgumentException('Tournament archive id list is empty.');
        return [implode(', ', $placeholders), $params];
    }

    private function required(string $value, int $max, string $field): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $max) {
            throw new InvalidArgumentException('Invalid ' . $field . '.');
        }
        return $value;
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int)$value;
    }
}
