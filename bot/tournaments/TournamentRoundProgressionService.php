<?php
declare(strict_types=1);

final class TournamentRoundProgressionService
{
    public const ROUND_BREAK_SECONDS = 300;
    public const DRAW_REPLAY_WAIT_SECONDS = 60;
    public const STATE_COMPLETED = 'completed';
    public const WAIT_INITIAL_READY = 'initial_ready';
    public const WAIT_ROUND_BREAK = 'round_break';
    public const WAIT_DRAW_REPLAY = 'draw_replay';
    public const MATCH_ELIMINATION = 'elimination';
    public const MATCH_FINAL = 'final';
    public const MATCH_THIRD_PLACE = 'third_place';

    public function __construct(private DatabaseConnectionInterface $database) {}

    public function observeFinishedGame(array $game, ?DateTimeImmutable $now = null): array
    {
        if ((string)($game['match_source'] ?? '') !== 'tournament'
            || (string)($game['status'] ?? '') !== 'finished') {
            throw new InvalidArgumentException('Only finished tournament games may advance tournament rounds.');
        }

        $tournamentId = trim((string)($game['tournament_id'] ?? ''));
        $roundNo = max(1, (int)($game['tournament_round_no'] ?? 0));
        $pairNo = max(1, (int)($game['tournament_pair_no'] ?? 0));
        $gameId = trim((string)($game['id'] ?? ''));
        if ($tournamentId === '' || $gameId === '') {
            throw new InvalidArgumentException('Tournament result metadata is incomplete.');
        }

        $moment = $this->moment($now);
        $this->ensureFirstRoundRows($tournamentId, $moment);

        $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $game,
            $tournamentId,
            $roundNo,
            $pairNo,
            $gameId,
            $moment
        ): void {
            $rows = $db->fetchAll(
                'SELECT * FROM mgw_tournament_round_matches
                 WHERE tournament_id=:tournament_id AND round_no=:round_no AND pair_no=:pair_no'
                 . $this->forUpdate($db),
                ['tournament_id'=>$tournamentId,'round_no'=>$roundNo,'pair_no'=>$pairNo]
            );
            if (count($rows) !== 1 || !is_array($rows[0])) {
                throw new RuntimeException('Tournament progression pair is unavailable.');
            }
            $row = $rows[0];
            $attached = trim((string)($row['game_id'] ?? ''));
            if ($attached !== '' && $attached !== $gameId) {
                throw new RuntimeException('Finished game does not own the tournament pair.');
            }

            $existingAttempt = $db->fetchValue(
                'SELECT COUNT(*) FROM mgw_tournament_match_attempts WHERE game_id=:game_id',
                ['game_id'=>$gameId]
            );
            if ((int)$existingAttempt > 0) return;

            $attemptNo = max(1, (int)($row['attempt_no'] ?? 1));
            $runtimePlayers = array_values(array_filter(
                array_map('strval', $game['player_ids'] ?? []),
                static fn(string $id): bool => $id !== ''
            ));
            if (count($runtimePlayers) !== 2) {
                throw new RuntimeException('Tournament game result requires exactly two runtime players.');
            }

            $legacyByMgw = $this->legacyIdsForMgwIds([
                (string)$row['player_a_mgw_id'],
                (string)$row['player_b_mgw_id'],
            ]);
            $aMgw = (string)$row['player_a_mgw_id'];
            $bMgw = (string)$row['player_b_mgw_id'];
            if (!isset($legacyByMgw[$aMgw], $legacyByMgw[$bMgw])) {
                throw new RuntimeException('Tournament progression identities are incomplete.');
            }
            $expectedRuntime = [$legacyByMgw[$aMgw], $legacyByMgw[$bMgw]];
            sort($expectedRuntime);
            $actualRuntime = $runtimePlayers;
            sort($actualRuntime);
            if ($expectedRuntime !== $actualRuntime) {
                throw new RuntimeException('Tournament game players do not match the durable pair.');
            }

            $finishedAt = $this->parseOptionalMoment((string)($game['finished_at'] ?? ''), $moment);
            $finishedAtUtc = $this->utc($finishedAt);
            $winnerLegacy = trim((string)($game['winner_id'] ?? ''));
            $winnerMgw = null;
            $loserMgw = null;
            $resultType = 'draw';

            if ($winnerLegacy !== '') {
                if ($winnerLegacy === $legacyByMgw[$aMgw]) {
                    $winnerMgw = $aMgw;
                    $loserMgw = $bMgw;
                } elseif ($winnerLegacy === $legacyByMgw[$bMgw]) {
                    $winnerMgw = $bMgw;
                    $loserMgw = $aMgw;
                } else {
                    throw new RuntimeException('Tournament winner does not belong to the durable pair.');
                }
                $resultType = 'win';
            }

            $db->execute(
                'INSERT INTO mgw_tournament_match_attempts (
                    tournament_id,round_no,pair_no,attempt_no,game_id,
                    player_a_mgw_id,player_b_mgw_id,result_type,
                    winner_mgw_id,loser_mgw_id,finish_reason,
                    finished_at_utc,created_at_utc
                 ) VALUES (
                    :tournament_id,:round_no,:pair_no,:attempt_no,:game_id,
                    :player_a_mgw_id,:player_b_mgw_id,:result_type,
                    :winner_mgw_id,:loser_mgw_id,:finish_reason,
                    :finished_at_utc,:created_at_utc
                 )',
                [
                    'tournament_id'=>$tournamentId,
                    'round_no'=>$roundNo,
                    'pair_no'=>$pairNo,
                    'attempt_no'=>$attemptNo,
                    'game_id'=>$gameId,
                    'player_a_mgw_id'=>$aMgw,
                    'player_b_mgw_id'=>$bMgw,
                    'result_type'=>$resultType,
                    'winner_mgw_id'=>$winnerMgw,
                    'loser_mgw_id'=>$loserMgw,
                    'finish_reason'=>$this->nullable((string)($game['finish_reason'] ?? '')),
                    'finished_at_utc'=>$finishedAtUtc,
                    'created_at_utc'=>$this->utc($moment),
                ]
            );

            if ($resultType === 'draw') {
                $opens = $finishedAt->modify('+' . self::DRAW_REPLAY_WAIT_SECONDS . ' seconds');
                $opensUtc = $this->utc($opens);
                $deadlineUtc = $this->utc($opens->modify('+' . TournamentMatchReadinessService::READY_WINDOW_SECONDS . ' seconds'));
                $db->execute(
                    'UPDATE mgw_tournament_round_matches
                     SET player_a_mgw_id=:player_a_mgw_id,
                         player_b_mgw_id=:player_b_mgw_id,
                         readiness_opened_at_utc=:readiness_opened_at_utc,
                         readiness_deadline_at_utc=:readiness_deadline_at_utc,
                         player_a_ready_at_utc=:player_a_ready_at_utc,
                         player_b_ready_at_utc=:player_b_ready_at_utc,
                         launch_state=:launch_state,
                         game_id=NULL,
                         attempt_no=:attempt_no,
                         wait_kind=:wait_kind,
                         winner_mgw_id=NULL,
                         loser_mgw_id=NULL,
                         result_reason=NULL,
                         completed_at_utc=NULL,
                         updated_at_utc=:updated_at_utc
                     WHERE tournament_id=:tournament_id AND round_no=:round_no AND pair_no=:pair_no',
                    [
                        'player_a_mgw_id'=>$bMgw,
                        'player_b_mgw_id'=>$aMgw,
                        'readiness_opened_at_utc'=>$opensUtc,
                        'readiness_deadline_at_utc'=>$deadlineUtc,
                        'player_a_ready_at_utc'=>$opensUtc,
                        'player_b_ready_at_utc'=>$opensUtc,
                        'launch_state'=>TournamentMatchReadinessService::STATE_READY,
                        'attempt_no'=>$attemptNo + 1,
                        'wait_kind'=>self::WAIT_DRAW_REPLAY,
                        'updated_at_utc'=>$finishedAtUtc,
                        'tournament_id'=>$tournamentId,
                        'round_no'=>$roundNo,
                        'pair_no'=>$pairNo,
                    ]
                );
                return;
            }

            $db->execute(
                'UPDATE mgw_tournament_round_matches
                 SET launch_state=:launch_state,
                     winner_mgw_id=:winner_mgw_id,
                     loser_mgw_id=:loser_mgw_id,
                     result_reason=:result_reason,
                     completed_at_utc=:completed_at_utc,
                     updated_at_utc=:updated_at_utc
                 WHERE tournament_id=:tournament_id AND round_no=:round_no AND pair_no=:pair_no',
                [
                    'launch_state'=>self::STATE_COMPLETED,
                    'winner_mgw_id'=>$winnerMgw,
                    'loser_mgw_id'=>$loserMgw,
                    'result_reason'=>$this->nullable((string)($game['finish_reason'] ?? 'normal_win')),
                    'completed_at_utc'=>$finishedAtUtc,
                    'updated_at_utc'=>$finishedAtUtc,
                    'tournament_id'=>$tournamentId,
                    'round_no'=>$roundNo,
                    'pair_no'=>$pairNo,
                ]
            );
        });

        $this->createNextRoundIfComplete($tournamentId, $roundNo, $moment);
        return $this->tournamentSnapshot($tournamentId, $moment);
    }

    public function launchContextForParticipant(
        string $mgwId,
        string $accountRef,
        string $legacyUserId,
        ?DateTimeImmutable $now = null
    ): ?array {
        $participant = $this->participant($mgwId, $accountRef, $legacyUserId);
        $moment = $this->moment($now);
        $tournamentId = (string)$participant['tournament_id'];
        $this->ensureFirstRoundRows($tournamentId, $moment);

        $rows = $this->database->fetchAll(
            'SELECT m.*,t.game_type
             FROM mgw_tournament_round_matches m
             INNER JOIN mgw_tournaments t ON t.tournament_id=m.tournament_id
             WHERE m.tournament_id=:tournament_id
               AND (m.player_a_mgw_id=:mgw_id OR m.player_b_mgw_id=:mgw_id)
               AND m.completed_at_utc IS NULL
             ORDER BY m.round_no DESC,m.pair_no ASC
             LIMIT 2',
            ['tournament_id'=>$tournamentId,'mgw_id'=>$mgwId]
        );
        if ($rows === []) return null;
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Tournament participant has ambiguous active progression matches.');
        }
        $row = $rows[0];
        if ((string)($row['launch_state'] ?? '') !== TournamentMatchReadinessService::STATE_READY) return null;
        if (trim((string)($row['game_id'] ?? '')) !== '') return null;
        $opens = $this->parseUtc((string)$row['readiness_opened_at_utc']);
        if ($moment < $opens) return null;

        $players = $this->runtimePlayersForRow($row);
        $attemptNo = max(1, (int)($row['attempt_no'] ?? 1));
        return [
            'tournament_id'=>$tournamentId,
            'round_no'=>(int)$row['round_no'],
            'pair_no'=>(int)$row['pair_no'],
            'attempt_no'=>$attemptNo,
            'match_kind'=>(string)($row['match_kind'] ?? self::MATCH_ELIMINATION),
            'wait_kind'=>(string)($row['wait_kind'] ?? self::WAIT_INITIAL_READY),
            'game_type'=>(string)$row['game_type'],
            'game_id'=>$this->expectedGameId($tournamentId, (int)$row['round_no'], (int)$row['pair_no'], $attemptNo),
            'side_swap'=>$attemptNo > 1,
            'players'=>$players,
        ];
    }

    public function attachGame(
        string $tournamentId,
        int $roundNo,
        int $pairNo,
        int $attemptNo,
        string $gameId,
        ?DateTimeImmutable $now = null
    ): void {
        $timestamp = $this->utc($this->moment($now));
        $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $tournamentId,$roundNo,$pairNo,$attemptNo,$gameId,$timestamp
        ): void {
            $rows = $db->fetchAll(
                'SELECT * FROM mgw_tournament_round_matches
                 WHERE tournament_id=:tournament_id AND round_no=:round_no AND pair_no=:pair_no'
                 . $this->forUpdate($db),
                ['tournament_id'=>$tournamentId,'round_no'=>$roundNo,'pair_no'=>$pairNo]
            );
            if (count($rows) !== 1 || !is_array($rows[0])) {
                throw new RuntimeException('Tournament progression pair is unavailable.');
            }
            $row = $rows[0];
            if ((int)($row['attempt_no'] ?? 1) !== $attemptNo) {
                throw new RuntimeException('Tournament match attempt changed before game attachment.');
            }
            $existing = trim((string)($row['game_id'] ?? ''));
            if ($existing !== '' && $existing !== $gameId) {
                throw new RuntimeException('Tournament progression pair is already attached to another game.');
            }
            if ($existing === $gameId && (string)$row['launch_state'] === TournamentMatchReadinessService::STATE_LAUNCHED) return;
            $db->execute(
                'UPDATE mgw_tournament_round_matches
                 SET game_id=:game_id,launch_state=:launch_state,updated_at_utc=:updated_at_utc
                 WHERE tournament_id=:tournament_id AND round_no=:round_no AND pair_no=:pair_no',
                [
                    'game_id'=>$gameId,
                    'launch_state'=>TournamentMatchReadinessService::STATE_LAUNCHED,
                    'updated_at_utc'=>$timestamp,
                    'tournament_id'=>$tournamentId,
                    'round_no'=>$roundNo,
                    'pair_no'=>$pairNo,
                ]
            );
        });
    }

    public function expectedGameId(string $tournamentId, int $roundNo, int $pairNo, int $attemptNo = 1): string
    {
        $identity = trim($tournamentId) . '|' . $roundNo . '|' . $pairNo;
        if ($attemptNo > 1) $identity .= '|attempt:' . $attemptNo;
        return 'game_tour_' . substr(hash('sha256', $identity), 0, 48);
    }

    public function statusForParticipant(
        string $mgwId,
        string $accountRef,
        string $legacyUserId,
        ?DateTimeImmutable $now = null
    ): array {
        $participant = $this->participant($mgwId, $accountRef, $legacyUserId);
        $moment = $this->moment($now);
        $tournamentId = (string)$participant['tournament_id'];
        $this->ensureFirstRoundRows($tournamentId, $moment);
        $snapshot = $this->tournamentSnapshot($tournamentId, $moment);

        $current = null;
        foreach ($snapshot['matches'] as $match) {
            if (($match['player_a_mgw_id'] === $mgwId || $match['player_b_mgw_id'] === $mgwId)
                && $match['completed_at_utc'] === null) {
                if ($current === null || $match['round_no'] > $current['round_no']) $current = $match;
            }
        }
        $latest = null;
        foreach ($snapshot['matches'] as $match) {
            if ($match['player_a_mgw_id'] !== $mgwId && $match['player_b_mgw_id'] !== $mgwId) continue;
            if ($latest === null || $match['round_no'] > $latest['round_no']) $latest = $match;
        }

        return [
            'tournament_id'=>$tournamentId,
            'round_break_seconds'=>self::ROUND_BREAK_SECONDS,
            'draw_replay_wait_seconds'=>self::DRAW_REPLAY_WAIT_SECONDS,
            'current_match'=>$current,
            'latest_match'=>$latest,
            'tournament_complete'=>$this->isTournamentComplete($snapshot['matches']),
        ];
    }

    private function createNextRoundIfComplete(string $tournamentId, int $roundNo, DateTimeImmutable $moment): void
    {
        $this->database->transaction(function (DatabaseConnectionInterface $db) use ($tournamentId,$roundNo,$moment): void {
            $rows = $db->fetchAll(
                'SELECT * FROM mgw_tournament_round_matches
                 WHERE tournament_id=:tournament_id AND round_no=:round_no
                 ORDER BY pair_no ASC' . $this->forUpdate($db),
                ['tournament_id'=>$tournamentId,'round_no'=>$roundNo]
            );
            if ($rows === []) return;
            foreach ($rows as $row) {
                if (!is_array($row) || trim((string)($row['completed_at_utc'] ?? '')) === '') return;
            }
            foreach ($rows as $row) {
                if (in_array((string)($row['match_kind'] ?? ''), [self::MATCH_FINAL,self::MATCH_THIRD_PLACE], true)) return;
            }

            $nextRound = $roundNo + 1;
            $exists = (int)$db->fetchValue(
                'SELECT COUNT(*) FROM mgw_tournament_round_matches
                 WHERE tournament_id=:tournament_id AND round_no=:round_no',
                ['tournament_id'=>$tournamentId,'round_no'=>$nextRound]
            );
            if ($exists > 0) return;

            $completedAt = null;
            foreach ($rows as $row) {
                $candidate = $this->parseUtc((string)$row['completed_at_utc']);
                if ($completedAt === null || $candidate > $completedAt) $completedAt = $candidate;
            }
            if (!$completedAt instanceof DateTimeImmutable) return;
            $opens = $completedAt->modify('+' . self::ROUND_BREAK_SECONDS . ' seconds');

            if (count($rows) === 2) {
                $this->insertAutoReadyRow(
                    $db,$tournamentId,$nextRound,1,
                    (string)$rows[0]['winner_mgw_id'],(string)$rows[1]['winner_mgw_id'],
                    self::MATCH_FINAL,$opens
                );
                $this->insertAutoReadyRow(
                    $db,$tournamentId,$nextRound,2,
                    (string)$rows[0]['loser_mgw_id'],(string)$rows[1]['loser_mgw_id'],
                    self::MATCH_THIRD_PLACE,$opens
                );
                return;
            }

            if ((count($rows) % 2) !== 0) {
                throw new RuntimeException('Tournament elimination round must contain an even number of completed pairs.');
            }
            for ($index = 0; $index < count($rows); $index += 2) {
                $this->insertAutoReadyRow(
                    $db,$tournamentId,$nextRound,(int)(($index / 2) + 1),
                    (string)$rows[$index]['winner_mgw_id'],
                    (string)$rows[$index + 1]['winner_mgw_id'],
                    self::MATCH_ELIMINATION,$opens
                );
            }
        });
    }

    private function insertAutoReadyRow(
        DatabaseConnectionInterface $db,
        string $tournamentId,
        int $roundNo,
        int $pairNo,
        string $a,
        string $b,
        string $matchKind,
        DateTimeImmutable $opens
    ): void {
        if ($a === '' || $b === '' || $a === $b) {
            throw new RuntimeException('Tournament next-round pair is invalid.');
        }
        $opensUtc = $this->utc($opens);
        $deadlineUtc = $this->utc($opens->modify('+' . TournamentMatchReadinessService::READY_WINDOW_SECONDS . ' seconds'));
        $db->execute(
            'INSERT INTO mgw_tournament_round_matches (
                tournament_id,round_no,pair_no,player_a_mgw_id,player_b_mgw_id,
                readiness_opened_at_utc,readiness_deadline_at_utc,
                player_a_ready_at_utc,player_b_ready_at_utc,
                launch_state,game_id,created_at_utc,updated_at_utc,
                attempt_no,wait_kind,match_kind,winner_mgw_id,loser_mgw_id,result_reason,completed_at_utc
             ) VALUES (
                :tournament_id,:round_no,:pair_no,:player_a_mgw_id,:player_b_mgw_id,
                :readiness_opened_at_utc,:readiness_deadline_at_utc,
                :player_a_ready_at_utc,:player_b_ready_at_utc,
                :launch_state,NULL,:created_at_utc,:updated_at_utc,
                1,:wait_kind,:match_kind,NULL,NULL,NULL,NULL
             )',
            [
                'tournament_id'=>$tournamentId,'round_no'=>$roundNo,'pair_no'=>$pairNo,
                'player_a_mgw_id'=>$a,'player_b_mgw_id'=>$b,
                'readiness_opened_at_utc'=>$opensUtc,'readiness_deadline_at_utc'=>$deadlineUtc,
                'player_a_ready_at_utc'=>$opensUtc,'player_b_ready_at_utc'=>$opensUtc,
                'launch_state'=>TournamentMatchReadinessService::STATE_READY,
                'created_at_utc'=>$opensUtc,'updated_at_utc'=>$opensUtc,
                'wait_kind'=>self::WAIT_ROUND_BREAK,'match_kind'=>$matchKind,
            ]
        );
    }

    private function ensureFirstRoundRows(string $tournamentId, DateTimeImmutable $moment): void
    {
        $tournamentRows = $this->database->fetchAll(
            'SELECT * FROM mgw_tournaments WHERE tournament_id=:tournament_id LIMIT 2',
            ['tournament_id'=>$tournamentId]
        );
        if (count($tournamentRows) !== 1 || !is_array($tournamentRows[0])) {
            throw new RuntimeException('Tournament progression owner is unavailable.');
        }
        $tournament = $tournamentRows[0];
        $start = $this->parseUtc((string)$tournament['scheduled_start_at_utc']);
        if ($moment < $start) return;

        $seeds = $this->database->fetchAll(
            'SELECT seed_no,pair_no,mgw_id,present_at_start,technical_loss_at_start
             FROM mgw_tournament_bracket_seeds
             WHERE tournament_id=:tournament_id
             ORDER BY pair_no ASC,seed_no ASC',
            ['tournament_id'=>$tournamentId]
        );
        if ($seeds === []) return;
        $pairs = [];
        foreach ($seeds as $seed) {
            if (!is_array($seed)) continue;
            $pairs[(int)$seed['pair_no']][] = $seed;
        }

        foreach ($pairs as $pairNo=>$pair) {
            if (count($pair) !== 2) throw new RuntimeException('Tournament first-round bracket pair is incomplete.');
            $exists = (int)$this->database->fetchValue(
                'SELECT COUNT(*) FROM mgw_tournament_round_matches
                 WHERE tournament_id=:tournament_id AND round_no=1 AND pair_no=:pair_no',
                ['tournament_id'=>$tournamentId,'pair_no'=>$pairNo]
            );
            if ($exists > 0) continue;

            $a = (string)$pair[0]['mgw_id'];
            $b = (string)$pair[1]['mgw_id'];
            $presentA = (int)($pair[0]['present_at_start'] ?? 0) === 1
                && (int)($pair[0]['technical_loss_at_start'] ?? 0) === 0;
            $presentB = (int)($pair[1]['present_at_start'] ?? 0) === 1
                && (int)($pair[1]['technical_loss_at_start'] ?? 0) === 0;
            $opened = $this->utc($start);
            $deadline = $this->utc($start->modify('+' . TournamentMatchReadinessService::READY_WINDOW_SECONDS . ' seconds'));

            if ($presentA && $presentB) {
                $this->database->execute(
                    'INSERT INTO mgw_tournament_round_matches (
                        tournament_id,round_no,pair_no,player_a_mgw_id,player_b_mgw_id,
                        readiness_opened_at_utc,readiness_deadline_at_utc,
                        player_a_ready_at_utc,player_b_ready_at_utc,
                        launch_state,game_id,created_at_utc,updated_at_utc,
                        attempt_no,wait_kind,match_kind,winner_mgw_id,loser_mgw_id,result_reason,completed_at_utc
                     ) VALUES (
                        :tournament_id,1,:pair_no,:player_a_mgw_id,:player_b_mgw_id,
                        :opened,:deadline,NULL,NULL,:launch_state,NULL,:created,:updated,
                        1,:wait_kind,:match_kind,NULL,NULL,NULL,NULL
                     )',
                    [
                        'tournament_id'=>$tournamentId,'pair_no'=>$pairNo,
                        'player_a_mgw_id'=>$a,'player_b_mgw_id'=>$b,
                        'opened'=>$opened,'deadline'=>$deadline,
                        'launch_state'=>TournamentMatchReadinessService::STATE_WAITING_READY,
                        'created'=>$this->utc($moment),'updated'=>$this->utc($moment),
                        'wait_kind'=>self::WAIT_INITIAL_READY,'match_kind'=>self::MATCH_ELIMINATION,
                    ]
                );
                continue;
            }

            if ($presentA xor $presentB) {
                $winner = $presentA ? $a : $b;
                $loser = $presentA ? $b : $a;
                $this->database->execute(
                    'INSERT INTO mgw_tournament_round_matches (
                        tournament_id,round_no,pair_no,player_a_mgw_id,player_b_mgw_id,
                        readiness_opened_at_utc,readiness_deadline_at_utc,
                        player_a_ready_at_utc,player_b_ready_at_utc,
                        launch_state,game_id,created_at_utc,updated_at_utc,
                        attempt_no,wait_kind,match_kind,winner_mgw_id,loser_mgw_id,result_reason,completed_at_utc
                     ) VALUES (
                        :tournament_id,1,:pair_no,:player_a_mgw_id,:player_b_mgw_id,
                        :opened,:deadline,NULL,NULL,:launch_state,NULL,:created,:updated,
                        1,:wait_kind,:match_kind,:winner_mgw_id,:loser_mgw_id,:result_reason,:completed
                     )',
                    [
                        'tournament_id'=>$tournamentId,'pair_no'=>$pairNo,
                        'player_a_mgw_id'=>$a,'player_b_mgw_id'=>$b,
                        'opened'=>$opened,'deadline'=>$deadline,
                        'launch_state'=>self::STATE_COMPLETED,
                        'created'=>$opened,'updated'=>$opened,
                        'wait_kind'=>self::WAIT_INITIAL_READY,'match_kind'=>self::MATCH_ELIMINATION,
                        'winner_mgw_id'=>$winner,'loser_mgw_id'=>$loser,
                        'result_reason'=>'technical_loss_at_start','completed'=>$opened,
                    ]
                );
            }

            if (!$presentA && !$presentB) {
                // Keep every seeded pair represented in progression. When both
                // competitors missed T0 there is no automatic winner, but omitting
                // the row makes an 8-player round structurally odd and breaks the
                // entire bracket. The unresolved row cannot launch; staging may
                // finish synthetic fixture-only pairs through the explicit admin
                // acceptance helper, while production can keep its separate
                // both-absent resolution policy.
                $this->database->execute(
                    'INSERT INTO mgw_tournament_round_matches (
                        tournament_id,round_no,pair_no,player_a_mgw_id,player_b_mgw_id,
                        readiness_opened_at_utc,readiness_deadline_at_utc,
                        player_a_ready_at_utc,player_b_ready_at_utc,
                        launch_state,game_id,created_at_utc,updated_at_utc,
                        attempt_no,wait_kind,match_kind,winner_mgw_id,loser_mgw_id,result_reason,completed_at_utc
                     ) VALUES (
                        :tournament_id,1,:pair_no,:player_a_mgw_id,:player_b_mgw_id,
                        :opened,:deadline,NULL,NULL,:launch_state,NULL,:created,:updated,
                        1,:wait_kind,:match_kind,NULL,NULL,:result_reason,NULL
                     )',
                    [
                        'tournament_id'=>$tournamentId,'pair_no'=>$pairNo,
                        'player_a_mgw_id'=>$a,'player_b_mgw_id'=>$b,
                        'opened'=>$opened,'deadline'=>$deadline,
                        'launch_state'=>TournamentMatchReadinessService::STATE_READINESS_EXPIRED,
                        'created'=>$opened,'updated'=>$opened,
                        'wait_kind'=>self::WAIT_INITIAL_READY,'match_kind'=>self::MATCH_ELIMINATION,
                        'result_reason'=>'both_absent_at_start_pending',
                    ]
                );
            }
        }
    }

    private function tournamentSnapshot(string $tournamentId, DateTimeImmutable $moment): array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM mgw_tournament_round_matches
             WHERE tournament_id=:tournament_id
             ORDER BY round_no ASC,pair_no ASC',
            ['tournament_id'=>$tournamentId]
        );
        $matches = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $opens = $this->parseUtc((string)$row['readiness_opened_at_utc']);
            $matches[] = [
                'round_no'=>(int)$row['round_no'],
                'pair_no'=>(int)$row['pair_no'],
                'attempt_no'=>max(1,(int)($row['attempt_no'] ?? 1)),
                'wait_kind'=>(string)($row['wait_kind'] ?? self::WAIT_INITIAL_READY),
                'match_kind'=>(string)($row['match_kind'] ?? self::MATCH_ELIMINATION),
                'player_a_mgw_id'=>(string)$row['player_a_mgw_id'],
                'player_b_mgw_id'=>(string)$row['player_b_mgw_id'],
                'launch_state'=>(string)$row['launch_state'],
                'game_id'=>$this->nullable((string)($row['game_id'] ?? '')),
                'winner_mgw_id'=>$this->nullable((string)($row['winner_mgw_id'] ?? '')),
                'loser_mgw_id'=>$this->nullable((string)($row['loser_mgw_id'] ?? '')),
                'result_reason'=>$this->nullable((string)($row['result_reason'] ?? '')),
                'completed_at_utc'=>$this->nullable((string)($row['completed_at_utc'] ?? '')),
                'opens_at_utc'=>$this->utc($opens),
                'seconds_until_open'=>max(0,$opens->getTimestamp()-$moment->getTimestamp()),
            ];
        }
        return ['tournament_id'=>$tournamentId,'matches'=>$matches];
    }

    private function isTournamentComplete(array $matches): bool
    {
        $final = false;
        $third = false;
        foreach ($matches as $match) {
            if ($match['match_kind'] === self::MATCH_FINAL && $match['completed_at_utc'] !== null) $final = true;
            if ($match['match_kind'] === self::MATCH_THIRD_PLACE && $match['completed_at_utc'] !== null) $third = true;
        }
        return $final && $third;
    }

    private function participant(string $mgwId, string $accountRef, string $legacyUserId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT t.*,r.mgw_id,r.account_ref,o.legacy_user_id
             FROM mgw_tournaments t
             INNER JOIN mgw_tournament_registrations r
               ON r.tournament_id=t.tournament_id
              AND r.registration_state=:registration_state
             INNER JOIN mgw_account_ownership o
               ON o.mgw_id=r.mgw_id
              AND o.account_ref=r.account_ref
              AND o.ownership_status=:ownership_status
             WHERE t.active_slot=:active_slot
               AND r.mgw_id=:mgw_id
               AND r.account_ref=:account_ref
               AND o.legacy_user_id=:legacy_user_id
             LIMIT 2',
            [
                'registration_state'=>TournamentRegistrationService::REGISTRATION_REGISTERED,
                'ownership_status'=>'active',
                'active_slot'=>TournamentRegistrationService::ACTIVE_SLOT,
                'mgw_id'=>trim($mgwId),'account_ref'=>trim($accountRef),'legacy_user_id'=>trim($legacyUserId),
            ]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Tournament progression is available only to registered participants.');
        }
        return $rows[0];
    }

    private function runtimePlayersForRow(array $row): array
    {
        $legacy = $this->legacyIdsForMgwIds([(string)$row['player_a_mgw_id'],(string)$row['player_b_mgw_id']]);
        $a=(string)$row['player_a_mgw_id']; $b=(string)$row['player_b_mgw_id'];
        if (!isset($legacy[$a],$legacy[$b])) throw new RuntimeException('Tournament runtime identities are unavailable.');
        return [
            ['mgw_id'=>$a,'legacy_user_id'=>$legacy[$a]],
            ['mgw_id'=>$b,'legacy_user_id'=>$legacy[$b]],
        ];
    }

    private function legacyIdsForMgwIds(array $mgwIds): array
    {
        $rows = $this->database->fetchAll(
            'SELECT mgw_id,legacy_user_id FROM mgw_account_ownership
             WHERE ownership_status=:ownership_status AND mgw_id IN (:player_a,:player_b)',
            [
                'ownership_status'=>'active',
                'player_a'=>(string)$mgwIds[0],
                'player_b'=>(string)$mgwIds[1],
            ]
        );
        $result=[];
        foreach($rows as $row){
            if(!is_array($row)) continue;
            $mgw=trim((string)($row['mgw_id']??'')); $legacy=trim((string)($row['legacy_user_id']??''));
            if($mgw!==''&&$legacy!=='') $result[$mgw]=$legacy;
        }
        return $result;
    }

    private function parseOptionalMoment(string $value, DateTimeImmutable $fallback): DateTimeImmutable
    {
        $value=trim($value);
        return $value==='' ? $fallback : $this->parseUtc($value);
    }

    private function moment(?DateTimeImmutable $now): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    private function parseUtc(string $value): DateTimeImmutable
    {
        try {
            return (new DateTimeImmutable($value,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        } catch(Throwable) {
            throw new RuntimeException('Tournament progression timestamp is invalid.');
        }
    }

    private function utc(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function nullable(string $value): ?string
    {
        $value=trim($value);
        return $value==='' ? null : $value;
    }

    private function forUpdate(DatabaseConnectionInterface $database): string
    {
        return $database->driver()==='sqlite' ? '' : ' FOR UPDATE';
    }
}
