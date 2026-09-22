<?php
declare(strict_types=1);

/**
 * MVP-21.10 prize-path integrity owner.
 *
 * This service owns only serious-signal review state and audit. It never writes
 * ledger entries, tournament results, reward entitlements or Golden Tickets.
 * TournamentSettlementService remains the only reward/settlement writer.
 */
final class TournamentPrizeReviewService
{
    public const STATE_PENDING = 'pending';
    public const STATE_RELEASED = 'released';
    public const STATE_DISQUALIFIED = 'disqualified';

    public const DECISION_RELEASE = 'release';
    public const DECISION_DISQUALIFY = 'disqualify';

    private const SIGNAL_CODES = [
        'fraud',
        'automation',
        'duplicate_identity',
        'match_manipulation',
        'cheating_report',
        'manual_review',
    ];

    public function __construct(private DatabaseConnectionInterface $database) {}

    public function flagSeriousSignal(
        string $tournamentId,
        string $mgwId,
        string $signalCode,
        string $note,
        string $actorRef,
        ?string $relatedGameId = null,
        ?DateTimeImmutable $now = null
    ): array {
        $tournamentId = $this->required($tournamentId, 64, 'tournament id');
        $mgwId = $this->required($mgwId, 24, 'MGW-ID');
        $signalCode = strtolower($this->required($signalCode, 64, 'signal code'));
        if (!in_array($signalCode, self::SIGNAL_CODES, true)) {
            throw new InvalidArgumentException('Unsupported tournament review signal.');
        }
        $note = $this->text($note, 800, 'signal note');
        $actorRef = $this->text($actorRef, 191, 'actor');
        $relatedGameId = $this->nullableText($relatedGameId, 96);
        $at = $this->utc($now);

        return $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $tournamentId,$mgwId,$signalCode,$note,$actorRef,$relatedGameId,$at
        ): array {
            $this->tournament($db, $tournamentId, true);
            $this->assertRegisteredParticipant($db, $tournamentId, $mgwId);

            $settledCount = (int)$db->fetchValue(
                'SELECT COUNT(*) FROM mgw_tournament_results WHERE tournament_id=:tournament_id',
                ['tournament_id'=>$tournamentId]
            );
            if ($settledCount > 0) {
                throw new RuntimeException('Serious prize-path signal must be registered before tournament settlement starts.');
            }

            if ($relatedGameId !== null) {
                $this->assertFlaggedTournamentGame($db, $tournamentId, $mgwId, $relatedGameId);
            } else {
                $placements = $this->terminalPlacements($db, $tournamentId);
                $placement = $placements[$mgwId] ?? null;
                if ($placement === null || $placement > 3) {
                    throw new RuntimeException('Heavy tournament review is limited to top-3 or a participant from a flagged tournament match.');
                }
            }

            $existing = $this->reviewRow($db, $tournamentId, $mgwId, true);
            $previousState = $existing === null ? null : (string)$existing['review_state'];
            if ($previousState === self::STATE_DISQUALIFIED) {
                return $this->publicReview($existing);
            }

            $params = [
                'tournament_id'=>$tournamentId,
                'mgw_id'=>$mgwId,
                'review_state'=>self::STATE_PENDING,
                'signal_code'=>$signalCode,
                'related_game_id'=>$relatedGameId,
                'signal_note'=>$note,
                'signal_actor_ref'=>$actorRef,
                'signaled_at_utc'=>$at,
                'updated_at_utc'=>$at,
            ];
            if ($db->driver() === 'sqlite') {
                $db->execute(
                    'INSERT INTO mgw_tournament_prize_reviews (
                        tournament_id,mgw_id,review_state,signal_code,related_game_id,
                        signal_note,signal_actor_ref,signaled_at_utc,
                        resolution_note,resolved_by_ref,resolved_at_utc,updated_at_utc
                     ) VALUES (
                        :tournament_id,:mgw_id,:review_state,:signal_code,:related_game_id,
                        :signal_note,:signal_actor_ref,:signaled_at_utc,
                        NULL,NULL,NULL,:updated_at_utc
                     )
                     ON CONFLICT(tournament_id,mgw_id) DO UPDATE SET
                        review_state=excluded.review_state,
                        signal_code=excluded.signal_code,
                        related_game_id=excluded.related_game_id,
                        signal_note=excluded.signal_note,
                        signal_actor_ref=excluded.signal_actor_ref,
                        signaled_at_utc=excluded.signaled_at_utc,
                        resolution_note=NULL,
                        resolved_by_ref=NULL,
                        resolved_at_utc=NULL,
                        updated_at_utc=excluded.updated_at_utc',
                    $params
                );
            } else {
                $db->execute(
                    'INSERT INTO mgw_tournament_prize_reviews (
                        tournament_id,mgw_id,review_state,signal_code,related_game_id,
                        signal_note,signal_actor_ref,signaled_at_utc,
                        resolution_note,resolved_by_ref,resolved_at_utc,updated_at_utc
                     ) VALUES (
                        :tournament_id,:mgw_id,:review_state,:signal_code,:related_game_id,
                        :signal_note,:signal_actor_ref,:signaled_at_utc,
                        NULL,NULL,NULL,:updated_at_utc
                     )
                     ON DUPLICATE KEY UPDATE
                        review_state=VALUES(review_state),
                        signal_code=VALUES(signal_code),
                        related_game_id=VALUES(related_game_id),
                        signal_note=VALUES(signal_note),
                        signal_actor_ref=VALUES(signal_actor_ref),
                        signaled_at_utc=VALUES(signaled_at_utc),
                        resolution_note=NULL,
                        resolved_by_ref=NULL,
                        resolved_at_utc=NULL,
                        updated_at_utc=VALUES(updated_at_utc)',
                    $params
                );
            }

            $this->audit(
                $db,$tournamentId,$mgwId,'serious_signal',$previousState,self::STATE_PENDING,
                $signalCode,$relatedGameId,$note,$actorRef,$at
            );
            $row = $this->reviewRow($db, $tournamentId, $mgwId, false);
            if ($row === null) throw new RuntimeException('Tournament prize review signal was not persisted.');
            return $this->publicReview($row);
        });
    }

    public function resolve(
        string $tournamentId,
        string $mgwId,
        string $decision,
        string $note,
        string $actorRef,
        ?DateTimeImmutable $now = null
    ): array {
        $tournamentId = $this->required($tournamentId, 64, 'tournament id');
        $mgwId = $this->required($mgwId, 24, 'MGW-ID');
        $decision = strtolower(trim($decision));
        if (!in_array($decision, [self::DECISION_RELEASE,self::DECISION_DISQUALIFY], true)) {
            throw new InvalidArgumentException('Unsupported tournament review decision.');
        }
        $note = $this->text($note, 800, 'review note');
        $actorRef = $this->text($actorRef, 191, 'actor');
        $at = $this->utc($now);
        $nextState = $decision === self::DECISION_DISQUALIFY
            ? self::STATE_DISQUALIFIED
            : self::STATE_RELEASED;

        return $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $tournamentId,$mgwId,$decision,$note,$actorRef,$at,$nextState
        ): array {
            $this->tournament($db, $tournamentId, true);
            $row = $this->reviewRow($db, $tournamentId, $mgwId, true);
            if ($row === null) throw new RuntimeException('Tournament prize review is not pending for this player.');

            $current = (string)$row['review_state'];
            if ($current === $nextState) return $this->publicReview($row);
            if ($current !== self::STATE_PENDING) {
                throw new RuntimeException('Resolved tournament prize review must receive a new serious signal before another decision.');
            }

            if ($decision === self::DECISION_DISQUALIFY) {
                $placements = $this->terminalPlacements($db, $tournamentId);
                if ($placements === []) {
                    throw new RuntimeException('Disqualification decision requires a completed final and third-place match.');
                }
            }

            $updated = $db->execute(
                'UPDATE mgw_tournament_prize_reviews
                 SET review_state=:review_state,
                     resolution_note=:resolution_note,
                     resolved_by_ref=:resolved_by_ref,
                     resolved_at_utc=:resolved_at_utc,
                     updated_at_utc=:updated_at_utc
                 WHERE tournament_id=:tournament_id
                   AND mgw_id=:mgw_id
                   AND review_state=:expected_state',
                [
                    'review_state'=>$nextState,
                    'resolution_note'=>$note,
                    'resolved_by_ref'=>$actorRef,
                    'resolved_at_utc'=>$at,
                    'updated_at_utc'=>$at,
                    'tournament_id'=>$tournamentId,
                    'mgw_id'=>$mgwId,
                    'expected_state'=>self::STATE_PENDING,
                ]
            );
            if ($updated !== 1) throw new RuntimeException('Tournament prize review changed concurrently.');

            $this->audit(
                $db,$tournamentId,$mgwId,$decision,self::STATE_PENDING,$nextState,
                (string)$row['signal_code'],$this->nullableText($row['related_game_id'] ?? null,96),
                $note,$actorRef,$at
            );
            $resolved = $this->reviewRow($db, $tournamentId, $mgwId, false);
            if ($resolved === null) throw new RuntimeException('Tournament prize review resolution was not persisted.');
            return $this->publicReview($resolved);
        });
    }

    /**
     * Read-only settlement decision. Canonical bracket placements stay immutable;
     * disqualification only changes the effective prize/result order.
     *
     * @param array<string,int> $canonicalPlacements
     */
    public function settlementDecision(string $tournamentId, array $canonicalPlacements): array
    {
        $tournamentId = $this->required($tournamentId, 64, 'tournament id');
        $reviews = $this->database->fetchAll(
            'SELECT * FROM mgw_tournament_prize_reviews
             WHERE tournament_id=:tournament_id
             ORDER BY signaled_at_utc ASC,mgw_id ASC',
            ['tournament_id'=>$tournamentId]
        );

        $states = [];
        $publicReviews = [];
        foreach ($reviews as $row) {
            if (!is_array($row)) continue;
            $mgwId = trim((string)($row['mgw_id'] ?? ''));
            if ($mgwId === '') continue;
            $states[$mgwId] = (string)$row['review_state'];
            $publicReviews[] = $this->publicReview($row);
        }

        $top = [];
        foreach ($canonicalPlacements as $mgwId=>$placement) {
            $place = (int)$placement;
            if ($place < 1 || $place > 4) continue;
            $top[] = ['mgw_id'=>(string)$mgwId,'canonical_placement'=>$place];
        }
        usort($top, static fn(array $a,array $b):int => $a['canonical_placement'] <=> $b['canonical_placement']);

        $disqualified = [];
        $effective = [];
        $nextPlacement = 1;
        foreach ($top as $candidate) {
            $mgwId = $candidate['mgw_id'];
            if (($states[$mgwId] ?? '') === self::STATE_DISQUALIFIED) {
                $disqualified[] = $mgwId;
                $effective[$mgwId] = null;
                continue;
            }
            $effective[$mgwId] = $nextPlacement++;
        }

        $holdThreshold = null;
        foreach ($effective as $mgwId=>$placement) {
            if (!is_int($placement) || $placement > 3) continue;
            if (($states[$mgwId] ?? '') !== self::STATE_PENDING) continue;
            $holdThreshold = $holdThreshold === null ? $placement : min($holdThreshold, $placement);
        }

        $held = [];
        if ($holdThreshold !== null) {
            foreach ($effective as $mgwId=>$placement) {
                if (!is_int($placement)) continue;
                if ($placement >= $holdThreshold && $placement <= 4) $held[] = $mgwId;
            }
        }

        return [
            'hold'=>$held !== [],
            'hold_threshold_placement'=>$holdThreshold,
            'held_mgw_ids'=>$held,
            'disqualified_mgw_ids'=>$disqualified,
            'effective_placements'=>$effective,
            'reviews'=>$publicReviews,
        ];
    }

    public function snapshot(?string $tournamentId = null): array
    {
        $tournamentId = $this->resolveTournamentId($tournamentId);
        if ($tournamentId === null) {
            return [
                'available'=>false,
                'tournament_id'=>null,
                'top3'=>[],
                'reviews'=>[],
                'audit'=>[],
                'settlement'=>[
                    'hold'=>false,
                    'held_mgw_ids'=>[],
                    'disqualified_mgw_ids'=>[],
                ],
                'signal_codes'=>self::SIGNAL_CODES,
            ];
        }

        $placements = $this->terminalPlacements($this->database, $tournamentId);
        $decision = $this->settlementDecision($tournamentId, $placements);
        $users = $this->userMap(array_keys($placements));

        $top3 = [];
        foreach ($placements as $mgwId=>$placement) {
            if ($placement > 3) continue;
            $top3[] = [
                'mgw_id'=>$mgwId,
                'public_mgw_id'=>MgwIdGenerator::toPublic($mgwId),
                'nickname'=>$users[$mgwId] ?? 'Игрок',
                'canonical_placement'=>$placement,
                'effective_placement'=>$decision['effective_placements'][$mgwId] ?? $placement,
                'review_state'=>$this->reviewStateFor($decision['reviews'], $mgwId),
            ];
        }
        usort($top3, static fn(array $a,array $b):int => $a['canonical_placement'] <=> $b['canonical_placement']);

        return [
            'available'=>true,
            'tournament_id'=>$tournamentId,
            'top3'=>$top3,
            'reviews'=>$decision['reviews'],
            'audit'=>$this->auditRows($tournamentId, 50),
            'settlement'=>[
                'hold'=>$decision['hold'],
                'hold_threshold_placement'=>$decision['hold_threshold_placement'],
                'held_mgw_ids'=>$decision['held_mgw_ids'],
                'disqualified_mgw_ids'=>$decision['disqualified_mgw_ids'],
            ],
            'signal_codes'=>self::SIGNAL_CODES,
        ];
    }

    public function participantReview(string $tournamentId, string $mgwId): ?array
    {
        $row = $this->reviewRow(
            $this->database,
            $this->required($tournamentId,64,'tournament id'),
            $this->required($mgwId,24,'MGW-ID'),
            false
        );
        return $row === null ? null : $this->publicReview($row);
    }

    private function resolveTournamentId(?string $tournamentId): ?string
    {
        $tournamentId = trim((string)($tournamentId ?? ''));
        if ($tournamentId !== '') return $this->required($tournamentId,64,'tournament id');
        $rows = $this->database->fetchAll(
            'SELECT tournament_id FROM mgw_tournaments
             WHERE active_slot=:active_slot
             ORDER BY created_at_utc DESC
             LIMIT 2',
            ['active_slot'=>TournamentRegistrationService::ACTIVE_SLOT]
        );
        if ($rows === []) return null;
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Active tournament prize review target is ambiguous.');
        }
        return (string)$rows[0]['tournament_id'];
    }

    private function terminalPlacements(DatabaseConnectionInterface $db, string $tournamentId): array
    {
        $rows = $db->fetchAll(
            'SELECT match_kind,winner_mgw_id,loser_mgw_id,completed_at_utc
             FROM mgw_tournament_round_matches
             WHERE tournament_id=:tournament_id
               AND match_kind IN (:final_kind,:third_kind)
             ORDER BY round_no DESC,pair_no ASC',
            [
                'tournament_id'=>$tournamentId,
                'final_kind'=>TournamentRoundProgressionService::MATCH_FINAL,
                'third_kind'=>TournamentRoundProgressionService::MATCH_THIRD_PLACE,
            ]
        );
        $final = null;
        $third = null;
        foreach ($rows as $row) {
            if (!is_array($row) || trim((string)($row['completed_at_utc'] ?? '')) === '') continue;
            if ((string)$row['match_kind'] === TournamentRoundProgressionService::MATCH_FINAL) $final = $row;
            if ((string)$row['match_kind'] === TournamentRoundProgressionService::MATCH_THIRD_PLACE) $third = $row;
        }
        if (!is_array($final) || !is_array($third)) return [];

        $placements = [];
        foreach ([
            [(string)($final['winner_mgw_id'] ?? ''),1],
            [(string)($final['loser_mgw_id'] ?? ''),2],
            [(string)($third['winner_mgw_id'] ?? ''),3],
            [(string)($third['loser_mgw_id'] ?? ''),4],
        ] as [$mgwId,$placement]) {
            $mgwId = trim($mgwId);
            if ($mgwId === '') continue;
            $placements[$mgwId] = $placement;
        }
        return $placements;
    }

    private function assertRegisteredParticipant(
        DatabaseConnectionInterface $db,
        string $tournamentId,
        string $mgwId
    ): void {
        $count = (int)$db->fetchValue(
            'SELECT COUNT(*) FROM mgw_tournament_registrations
             WHERE tournament_id=:tournament_id
               AND mgw_id=:mgw_id
               AND registration_state=:registration_state',
            [
                'tournament_id'=>$tournamentId,
                'mgw_id'=>$mgwId,
                'registration_state'=>TournamentRegistrationService::REGISTRATION_REGISTERED,
            ]
        );
        if ($count !== 1) throw new RuntimeException('Tournament review target is not a registered participant.');
    }

    private function assertFlaggedTournamentGame(
        DatabaseConnectionInterface $db,
        string $tournamentId,
        string $mgwId,
        string $gameId
    ): void {
        $round = (int)$db->fetchValue(
            'SELECT COUNT(*) FROM mgw_tournament_round_matches
             WHERE tournament_id=:tournament_id
               AND game_id=:game_id
               AND (player_a_mgw_id=:player_a OR player_b_mgw_id=:player_b)',
            [
                'tournament_id'=>$tournamentId,
                'game_id'=>$gameId,
                'player_a'=>$mgwId,
                'player_b'=>$mgwId,
            ]
        );
        if ($round === 1) return;

        $attempt = (int)$db->fetchValue(
            'SELECT COUNT(*) FROM mgw_tournament_match_attempts
             WHERE tournament_id=:tournament_id
               AND game_id=:game_id
               AND (player_a_mgw_id=:player_a OR player_b_mgw_id=:player_b)',
            [
                'tournament_id'=>$tournamentId,
                'game_id'=>$gameId,
                'player_a'=>$mgwId,
                'player_b'=>$mgwId,
            ]
        );
        if ($attempt !== 1) {
            throw new RuntimeException('Serious signal must reference the reviewed player\'s tournament match.');
        }
    }

    private function reviewRow(
        DatabaseConnectionInterface $db,
        string $tournamentId,
        string $mgwId,
        bool $lock
    ): ?array {
        $rows = $db->fetchAll(
            'SELECT * FROM mgw_tournament_prize_reviews
             WHERE tournament_id=:tournament_id AND mgw_id=:mgw_id
             LIMIT 2' . ($lock ? $this->forUpdate($db) : ''),
            ['tournament_id'=>$tournamentId,'mgw_id'=>$mgwId]
        );
        if ($rows === []) return null;
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Tournament prize review row is ambiguous.');
        }
        return $rows[0];
    }

    private function publicReview(array $row): array
    {
        $mgwId = (string)$row['mgw_id'];
        $nickname = $this->nickname($mgwId);
        return [
            'tournament_id'=>(string)$row['tournament_id'],
            'mgw_id'=>$mgwId,
            'public_mgw_id'=>MgwIdGenerator::toPublic($mgwId),
            'nickname'=>$nickname,
            'review_state'=>(string)$row['review_state'],
            'serious_signal'=>true,
            'signal_code'=>(string)$row['signal_code'],
            'related_game_id'=>$this->nullableText($row['related_game_id'] ?? null,96),
            'signal_note'=>(string)$row['signal_note'],
            'signal_actor_ref'=>(string)$row['signal_actor_ref'],
            'signaled_at_utc'=>(string)$row['signaled_at_utc'],
            'resolution_note'=>$this->nullableText($row['resolution_note'] ?? null,800),
            'resolved_by_ref'=>$this->nullableText($row['resolved_by_ref'] ?? null,191),
            'resolved_at_utc'=>$this->nullableText($row['resolved_at_utc'] ?? null,32),
            'updated_at_utc'=>(string)$row['updated_at_utc'],
        ];
    }

    private function reviewStateFor(array $reviews, string $mgwId): ?string
    {
        foreach ($reviews as $review) {
            if (is_array($review) && (string)($review['mgw_id'] ?? '') === $mgwId) {
                return (string)($review['review_state'] ?? '');
            }
        }
        return null;
    }

    private function userMap(array $mgwIds): array
    {
        $map = [];
        foreach ($mgwIds as $mgwId) {
            $map[$mgwId] = $this->nickname((string)$mgwId);
        }
        return $map;
    }

    private function nickname(string $mgwId): string
    {
        $rows = $this->database->fetchAll(
            'SELECT nickname,display_name FROM mgw_users WHERE mgw_id=:mgw_id LIMIT 2',
            ['mgw_id'=>$mgwId]
        );
        if ($rows === [] || !is_array($rows[0])) return 'Игрок';
        $nickname = trim((string)($rows[0]['nickname'] ?? ''));
        if ($nickname === '') $nickname = trim((string)($rows[0]['display_name'] ?? ''));
        return $nickname !== '' ? $nickname : 'Игрок';
    }

    private function auditRows(string $tournamentId, int $limit): array
    {
        $limit = max(1,min(100,$limit));
        return $this->database->fetchAll(
            'SELECT event_key,mgw_id,action_code,previous_state,next_state,signal_code,
                    related_game_id,note,actor_ref,created_at_utc
             FROM mgw_tournament_prize_review_audit
             WHERE tournament_id=:tournament_id
             ORDER BY created_at_utc DESC,event_key DESC
             LIMIT ' . $limit,
            ['tournament_id'=>$tournamentId]
        );
    }

    private function audit(
        DatabaseConnectionInterface $db,
        string $tournamentId,
        string $mgwId,
        string $action,
        ?string $previousState,
        string $nextState,
        string $signalCode,
        ?string $relatedGameId,
        string $note,
        string $actorRef,
        string $at
    ): void {
        $eventKey = hash('sha256', implode('|', [
            $tournamentId,$mgwId,$action,(string)$previousState,$nextState,
            $signalCode,(string)$relatedGameId,$note,$actorRef,
        ]));
        $sql = $db->driver() === 'sqlite'
            ? 'INSERT OR IGNORE INTO mgw_tournament_prize_review_audit (
                event_key,tournament_id,mgw_id,action_code,previous_state,next_state,
                signal_code,related_game_id,note,actor_ref,created_at_utc
               ) VALUES (
                :event_key,:tournament_id,:mgw_id,:action_code,:previous_state,:next_state,
                :signal_code,:related_game_id,:note,:actor_ref,:created_at_utc
               )'
            : 'INSERT IGNORE INTO mgw_tournament_prize_review_audit (
                event_key,tournament_id,mgw_id,action_code,previous_state,next_state,
                signal_code,related_game_id,note,actor_ref,created_at_utc
               ) VALUES (
                :event_key,:tournament_id,:mgw_id,:action_code,:previous_state,:next_state,
                :signal_code,:related_game_id,:note,:actor_ref,:created_at_utc
               )';
        $db->execute($sql, [
            'event_key'=>$eventKey,
            'tournament_id'=>$tournamentId,
            'mgw_id'=>$mgwId,
            'action_code'=>$action,
            'previous_state'=>$previousState,
            'next_state'=>$nextState,
            'signal_code'=>$signalCode,
            'related_game_id'=>$relatedGameId,
            'note'=>$note,
            'actor_ref'=>$actorRef,
            'created_at_utc'=>$at,
        ]);
    }

    private function tournament(DatabaseConnectionInterface $db, string $tournamentId, bool $lock): array
    {
        $rows = $db->fetchAll(
            'SELECT tournament_id,tournament_state FROM mgw_tournaments
             WHERE tournament_id=:tournament_id LIMIT 2' . ($lock ? $this->forUpdate($db) : ''),
            ['tournament_id'=>$tournamentId]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Tournament prize review target is unavailable.');
        }
        if (in_array((string)$rows[0]['tournament_state'], [
            TournamentRegistrationService::STATE_CANCELLED,
            TournamentRegistrationService::STATE_EMERGENCY_STOPPED,
        ], true)) {
            throw new RuntimeException('Cancelled tournament cannot enter prize review.');
        }
        return $rows[0];
    }

    private function required(string $value, int $max, string $field): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $max) throw new InvalidArgumentException('Invalid '.$field.'.');
        return $value;
    }

    private function text(string $value, int $max, string $field): string
    {
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$value) ?? '');
        if ($value === '') throw new InvalidArgumentException('Tournament '.$field.' is required.');
        if (function_exists('mb_strlen') ? mb_strlen($value) > $max : strlen($value) > $max) {
            throw new InvalidArgumentException('Tournament '.$field.' is too long.');
        }
        return $value;
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') return null;
        if (strlen($value) > $max) throw new InvalidArgumentException('Tournament review value is too long.');
        return $value;
    }

    private function utc(?DateTimeImmutable $now): string
    {
        return ($now ?? new DateTimeImmutable('now',new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }

    private function forUpdate(DatabaseConnectionInterface $db): string
    {
        return $db->driver() === 'sqlite' ? '' : ' FOR UPDATE';
    }
}
