<?php
declare(strict_types=1);

final class ChessService
{
    private const SIZE = 8;
    private const PROMOTIONS = ['q', 'r', 'b', 'n'];
    private const KNIGHT_STEPS = [
        [-2,-1],[-2,1],[-1,-2],[-1,2],[1,-2],[1,2],[2,-1],[2,1],
    ];
    private const KING_STEPS = [
        [-1,-1],[-1,0],[-1,1],[0,-1],[0,1],[1,-1],[1,0],[1,1],
    ];
    private const BISHOP_DIRS = [[-1,-1],[-1,1],[1,-1],[1,1]];
    private const ROOK_DIRS = [[-1,0],[1,0],[0,-1],[0,1]];

    public function __construct(
        private array $config,
        private GameSettlementService $settlement,
        private ?ChessBotService $bot = null
    ) {
        $this->bot ??= new ChessBotService();
    }

    public function initializeGame(array &$game): void
    {
        $validBoard = is_array($game['board'] ?? null)
            && count($game['board']) === 64
            && $this->validBoard($game['board']);
        $validSides = isset($game['chess_sides']) && is_array($game['chess_sides']);

        $game['game_type'] = 'chess';
        $game['board_size'] = self::SIZE;
        $game['board_columns'] = self::SIZE;
        $game['board_rows'] = self::SIZE;

        if (!empty($game['chess_initialized']) && $validBoard && $validSides) {
            return;
        }

        $playerIds = array_values(array_map('strval', $game['player_ids'] ?? []));
        if (count($playerIds) < 2) {
            throw new RuntimeException('Для шахмат нужны два игрока.');
        }

        if (random_int(0, 1) === 1) {
            [$playerIds[0], $playerIds[1]] = [$playerIds[1], $playerIds[0]];
        }

        $whiteId = $playerIds[0];
        $blackId = $playerIds[1];
        $now = now_iso();
        $board = $this->initialBoard();

        $game['board'] = $board;
        $game['chess_sides'] = [$whiteId => 'white', $blackId => 'black'];
        $game['symbols'] = [$whiteId => 'W', $blackId => 'B'];
        $game['turn'] = $whiteId;
        $game['chess_castling'] = [
            'white_king' => true,
            'white_queen' => true,
            'black_king' => true,
            'black_queen' => true,
        ];
        $game['chess_en_passant'] = null;
        $game['chess_halfmove_clock'] = 0;
        $game['chess_position_counts'] = [];
        $game['chess_last_move'] = null;
        $game['chess_move_count'] = 0;
        $game['chess_end_reason'] = null;
        $game['turn_started_at'] = $now;
        $game['last_move_at'] = $now;
        $game['updated_at'] = $now;
        $game['chess_initialized'] = true;
        $game['engine_version'] = 1;

        $initialKey = $this->positionKey($board, 'white', $game['chess_castling'], null);
        $game['chess_position_counts'][$initialKey] = 1;
        $this->scheduleBotIfNeeded($game);
    }

    public function cleanup(array &$db): void
    {
        if (!isset($db['games']) || !is_array($db['games'])) return;

        foreach ($db['games'] as &$game) {
            if (!is_array($game) || (string)($game['game_type'] ?? '') !== 'chess') continue;
            $this->initializeGame($game);
            if (($game['status'] ?? '') !== 'active') continue;

            if ($this->isTurnExpired($game)) {
                $loserId = (string)($game['turn'] ?? '');
                $winnerId = $this->otherPlayerId($game, $loserId);
                $game['chess_end_reason'] = 'timeout';
                $this->settlement->finish($db, $game, $winnerId, 'timeout', $loserId);
                continue;
            }

            if (empty($game['is_bot_game'])) continue;
            $botId = (string)($game['bot_id'] ?? '');
            if ($botId === '' || (string)($game['turn'] ?? '') !== $botId) continue;

            $after = strtotime((string)($game['bot_move_after_at'] ?? '')) ?: 0;
            if ($after > time()) continue;
            $this->makeBotMove($db, $game);
        }
        unset($game);
    }

    public function applyAction(array &$db, array &$user, string $gameId, array $action): array
    {
        if (!isset($db['games'][$gameId]) || !is_array($db['games'][$gameId])) {
            throw new RuntimeException('Игра не найдена.');
        }

        $game =& $db['games'][$gameId];
        $this->initializeGame($game);
        $userId = (string)($user['id'] ?? '');
        if (!in_array($userId, array_map('strval', $game['player_ids'] ?? []), true)) {
            throw new RuntimeException('Вы не участник этой игры.');
        }
        if (($game['status'] ?? '') !== 'active') return $game;

        $type = trim((string)($action['type'] ?? 'chess_move'));
        if ($type !== 'chess_move') {
            throw new RuntimeException('Некорректное действие для шахмат.');
        }

        $from = filter_var($action['from'] ?? null, FILTER_VALIDATE_INT);
        $to = filter_var($action['to'] ?? null, FILTER_VALIDATE_INT);
        if ($from === false || $to === false) {
            throw new RuntimeException('Выберите фигуру и клетку для хода.');
        }

        $promotion = strtolower(trim((string)($action['promotion'] ?? '')));
        if ($promotion !== '' && !in_array($promotion, self::PROMOTIONS, true)) {
            throw new RuntimeException('Выберите фигуру для превращения пешки.');
        }

        return $this->performMove($db, $game, $userId, (int)$from, (int)$to, $promotion);
    }

    public function surrender(array &$db, array &$user, string $gameId): array
    {
        if (!isset($db['games'][$gameId]) || !is_array($db['games'][$gameId])) {
            throw new RuntimeException('Игра не найдена.');
        }

        $game =& $db['games'][$gameId];
        $this->initializeGame($game);
        $userId = (string)($user['id'] ?? '');
        if (!in_array($userId, array_map('strval', $game['player_ids'] ?? []), true)) {
            throw new RuntimeException('Вы не участник этой игры.');
        }
        if (($game['status'] ?? '') === 'finished') return $game;

        $winnerId = $this->otherPlayerId($game, $userId);
        $game['chess_end_reason'] = 'player_left';
        $this->settlement->finish($db, $game, $winnerId, 'player_left', $userId);
        return $game;
    }

    public function publicGame(array $game, string $viewerId): array
    {
        $this->initializeGame($game);
        $board = $this->normalizeBoard($game['board'] ?? []);
        $viewerSide = $this->sideForPlayer($game, $viewerId);
        $turnSide = $this->sideForPlayer($game, (string)($game['turn'] ?? ''));
        $isViewerTurn = ($game['status'] ?? '') === 'active' && (string)($game['turn'] ?? '') === $viewerId;
        $state = $this->stateFromGame($game);
        $legalMoves = $isViewerTurn ? $this->legalMoves($state, $viewerSide) : [];

        $players = [];
        foreach (array_values(array_map('strval', $game['player_ids'] ?? [])) as $playerId) {
            $side = $this->sideForPlayer($game, $playerId);
            $players[] = [
                'id' => $playerId,
                'name' => (string)($game['player_names'][$playerId] ?? 'Игрок'),
                'side' => $side,
                'symbol' => $side === 'white' ? 'W' : 'B',
            ];
        }

        $whiteKing = $this->kingSquare($board, 'white');
        $blackKing = $this->kingSquare($board, 'black');
        $checkedSide = $this->isInCheck($board, $turnSide) ? $turnSide : null;
        $checkedPlayerId = $checkedSide ? $this->playerIdForSide($game, $checkedSide) : null;

        return [
            'id' => (string)($game['id'] ?? ''),
            'room' => (string)($game['room'] ?? 'match'),
            'room_name' => ($game['room'] ?? 'match') === 'gold' ? 'Gold-комната' : 'Матч-комната',
            'bet' => (int)($game['bet'] ?? 0),
            'board_size' => self::SIZE,
            'board_columns' => self::SIZE,
            'board_rows' => self::SIZE,
            'board' => $board,
            'turn' => (string)($game['turn'] ?? ''),
            'players' => $players,
            'status' => (string)($game['status'] ?? 'active'),
            'winner_id' => $game['winner_id'] ?? null,
            'loser_id' => $game['loser_id'] ?? null,
            'finish_reason' => $game['finish_reason'] ?? null,
            'payout' => $game['payout'] ?? null,
            'commission' => (int)($game['commission'] ?? 0),
            'time_left' => $this->timeLeft($game),
            'move_timeout_sec' => $this->moveTimeoutSec(),
            'is_bot_game' => !empty($game['is_bot_game']),
            'viewer_side' => $viewerSide,
            'legal_moves' => array_values(array_map([$this, 'publicMove'], $legalMoves)),
            'last_move' => is_array($game['chess_last_move'] ?? null) ? $game['chess_last_move'] : null,
            'move_count' => (int)($game['chess_move_count'] ?? 0),
            'halfmove_clock' => (int)($game['chess_halfmove_clock'] ?? 0),
            'in_check' => $checkedSide !== null,
            'checked_side' => $checkedSide,
            'checked_player_id' => $checkedPlayerId,
            'king_cells' => ['white' => $whiteKing, 'black' => $blackKing],
            'chess_end_reason' => $game['chess_end_reason'] ?? null,
        ];
    }

    private function performMove(array &$db, array &$game, string $playerId, int $from, int $to, string $promotion): array
    {
        if ((string)($game['turn'] ?? '') !== $playerId) {
            throw new RuntimeException('Сейчас ход соперника.');
        }
        if ($from < 0 || $from >= 64 || $to < 0 || $to >= 64 || $from === $to) {
            throw new RuntimeException('Выберите допустимый ход.');
        }

        $side = $this->sideForPlayer($game, $playerId);
        $state = $this->stateFromGame($game);
        $legalMoves = $this->legalMoves($state, $side);
        $matching = array_values(array_filter($legalMoves, static function(array $move) use ($from, $to): bool {
            return (int)$move['from'] === $from && (int)$move['to'] === $to;
        }));

        if ($matching === []) {
            $piece = (string)($state['board'][$from] ?? '');
            if ($piece === '' || $this->pieceSide($piece) !== $side) {
                throw new RuntimeException('Выберите свою фигуру.');
            }
            throw new RuntimeException('Этот ход недоступен. Король не должен оставаться под шахом.');
        }

        $requiresPromotion = count(array_filter($matching, static fn(array $move): bool => !empty($move['promotion']))) > 0;
        if ($requiresPromotion && $promotion === '') {
            throw new RuntimeException('Выберите фигуру для превращения пешки.');
        }

        $move = null;
        foreach ($matching as $candidate) {
            $candidatePromotion = (string)($candidate['promotion'] ?? '');
            if (($requiresPromotion && $candidatePromotion === $promotion) || (!$requiresPromotion && $candidatePromotion === '')) {
                $move = $candidate;
                break;
            }
        }
        if (!$move) {
            throw new RuntimeException('Выберите доступную фигуру для превращения пешки.');
        }

        $this->applyChosenMove($db, $game, $playerId, $side, $state, $move);
        return $game;
    }

    private function applyChosenMove(array &$db, array &$game, string $playerId, string $side, array $state, array $move): void
    {
        $nextState = $this->applyMoveState($state, $move, $side);
        $nextSide = $this->opposite($side);
        $nextPlayerId = $this->playerIdForSide($game, $nextSide);
        $now = now_iso();

        $game['board'] = $nextState['board'];
        $game['chess_castling'] = $nextState['castling'];
        $game['chess_en_passant'] = $nextState['en_passant'];
        $game['chess_halfmove_clock'] = $nextState['halfmove'];
        $game['chess_last_move'] = [
            'from' => (int)$move['from'],
            'to' => (int)$move['to'],
            'player_id' => $playerId,
            'side' => $side,
            'piece' => (string)$move['piece'],
            'capture' => !empty($move['capture']),
            'promotion' => (string)($move['promotion'] ?? ''),
            'castle' => (string)($move['castle'] ?? ''),
            'en_passant' => !empty($move['en_passant']),
        ];
        $game['chess_move_count'] = (int)($game['chess_move_count'] ?? 0) + 1;
        $game['turn'] = $nextPlayerId;
        $game['turn_started_at'] = $now;
        $game['last_move_at'] = $now;
        $game['updated_at'] = $now;
        unset($game['bot_move_after_at']);

        $key = $this->positionKey($nextState['board'], $nextSide, $nextState['castling'], $nextState['en_passant']);
        if (!isset($game['chess_position_counts']) || !is_array($game['chess_position_counts'])) {
            $game['chess_position_counts'] = [];
        }
        $game['chess_position_counts'][$key] = (int)($game['chess_position_counts'][$key] ?? 0) + 1;

        $nextMoves = $this->legalMoves($nextState, $nextSide);
        if ($nextMoves === []) {
            if ($this->isInCheck($nextState['board'], $nextSide)) {
                $game['chess_end_reason'] = 'checkmate';
                $this->settlement->finish($db, $game, $playerId, 'normal_win', $nextPlayerId);
            } else {
                $game['chess_end_reason'] = 'stalemate';
                $this->settlement->finish($db, $game, null, 'draw');
            }
            return;
        }

        if ($this->insufficientMaterial($nextState['board'])) {
            $game['chess_end_reason'] = 'insufficient_material';
            $this->settlement->finish($db, $game, null, 'draw');
            return;
        }

        if ((int)$nextState['halfmove'] >= 100) {
            $game['chess_end_reason'] = 'fifty_move';
            $this->settlement->finish($db, $game, null, 'draw');
            return;
        }

        if ((int)$game['chess_position_counts'][$key] >= 3) {
            $game['chess_end_reason'] = 'threefold_repetition';
            $this->settlement->finish($db, $game, null, 'draw');
            return;
        }

        $this->scheduleBotIfNeeded($game);
    }

    private function makeBotMove(array &$db, array &$game): void
    {
        $botId = (string)($game['bot_id'] ?? '');
        if ($botId === '' || (string)($game['turn'] ?? '') !== $botId) return;

        $side = $this->sideForPlayer($game, $botId);
        $state = $this->stateFromGame($game);
        $moves = $this->legalMoves($state, $side);
        if ($moves === []) {
            $this->finishNoMoves($db, $game, $side);
            return;
        }

        $difficulty = (string)($game['bot_difficulty'] ?? 'medium');
        $move = $this->bot->chooseMove(
            $moves,
            $difficulty,
            fn(array $candidate, int $depth, int $deadline): float => $this->scoreBotMove($state, $candidate, $side, $depth, $deadline),
            165
        );

        $this->applyChosenMove($db, $game, $botId, $side, $state, $move);
    }

    private function finishNoMoves(array &$db, array &$game, string $side): void
    {
        $playerId = $this->playerIdForSide($game, $side);
        if ($this->isInCheck($this->normalizeBoard($game['board'] ?? []), $side)) {
            $winnerId = $this->otherPlayerId($game, $playerId);
            $game['chess_end_reason'] = 'checkmate';
            $this->settlement->finish($db, $game, $winnerId, 'normal_win', $playerId);
        } else {
            $game['chess_end_reason'] = 'stalemate';
            $this->settlement->finish($db, $game, null, 'draw');
        }
    }

    private function scoreBotMove(array $state, array $move, string $rootSide, int $depth, int $deadline): float
    {
        $next = $this->applyMoveState($state, $move, $rootSide);
        $score = $this->evaluateBoard($next['board'], $rootSide) + $this->moveBonus($move, $next['board'], $rootSide);
        if ($depth <= 0 || hrtime(true) >= $deadline) return $score;

        $opponent = $this->opposite($rootSide);
        $opponentMoves = $this->legalMoves($next, $opponent);
        if ($opponentMoves === []) {
            return $this->isInCheck($next['board'], $opponent) ? 100000.0 : 0.0;
        }

        usort($opponentMoves, fn(array $a, array $b): int => $this->moveOrderingScore($b) <=> $this->moveOrderingScore($a));
        $opponentMoves = array_slice($opponentMoves, 0, $depth >= 2 ? 14 : 8);
        $worst = INF;

        foreach ($opponentMoves as $reply) {
            if (hrtime(true) >= $deadline) break;
            $afterReply = $this->applyMoveState($next, $reply, $opponent);
            $replyScore = $this->evaluateBoard($afterReply['board'], $rootSide) - $this->moveBonus($reply, $afterReply['board'], $opponent);

            if ($depth >= 2 && hrtime(true) < $deadline) {
                $followUps = $this->legalMoves($afterReply, $rootSide);
                usort($followUps, fn(array $a, array $b): int => $this->moveOrderingScore($b) <=> $this->moveOrderingScore($a));
                $bestFollow = -INF;
                foreach (array_slice($followUps, 0, 8) as $follow) {
                    if (hrtime(true) >= $deadline) break;
                    $afterFollow = $this->applyMoveState($afterReply, $follow, $rootSide);
                    $bestFollow = max($bestFollow, $this->evaluateBoard($afterFollow['board'], $rootSide) + $this->moveBonus($follow, $afterFollow['board'], $rootSide));
                }
                if ($bestFollow > -INF) $replyScore = max($replyScore, $bestFollow);
            }

            $worst = min($worst, $replyScore);
        }

        return $worst === INF ? $score : $worst;
    }

    private function moveBonus(array $move, array $boardAfter, string $side): float
    {
        $captured = (string)($move['captured_piece'] ?? '');
        $bonus = $captured !== '' ? $this->pieceValue($captured) * 0.9 : 0.0;
        if (!empty($move['promotion'])) $bonus += 700;
        if (!empty($move['castle'])) $bonus += 55;
        if ($this->isInCheck($boardAfter, $this->opposite($side))) $bonus += 38;
        $row = intdiv((int)$move['to'], 8);
        $col = (int)$move['to'] % 8;
        $bonus += max(0, 4 - (abs(3.5 - $row) + abs(3.5 - $col))) * 4;
        return $bonus;
    }

    private function moveOrderingScore(array $move): int
    {
        $score = 0;
        if (!empty($move['capture'])) $score += 1000 + $this->pieceValue((string)($move['captured_piece'] ?? ''));
        if (!empty($move['promotion'])) $score += 900;
        if (!empty($move['castle'])) $score += 80;
        return $score;
    }

    private function evaluateBoard(array $board, string $rootSide): float
    {
        $score = 0.0;
        foreach ($board as $index => $piece) {
            if ($piece === '') continue;
            $value = $this->pieceValue($piece);
            $row = intdiv((int)$index, 8);
            $col = (int)$index % 8;
            $center = max(0, 4 - (abs(3.5 - $row) + abs(3.5 - $col))) * 2.5;
            $pieceScore = $value + $center;
            $score += $this->pieceSide($piece) === $rootSide ? $pieceScore : -$pieceScore;
        }
        return $score;
    }

    private function legalMoves(array $state, string $side): array
    {
        $moves = [];
        foreach ($this->pseudoMoves($state, $side) as $move) {
            $next = $this->applyMoveState($state, $move, $side);
            if (!$this->isInCheck($next['board'], $side)) $moves[] = $move;
        }
        return $moves;
    }

    private function pseudoMoves(array $state, string $side): array
    {
        $board = $state['board'];
        $moves = [];
        foreach ($board as $from => $piece) {
            if ($piece === '' || $this->pieceSide($piece) !== $side) continue;
            $type = $this->pieceType($piece);
            if ($type === 'P') $moves = array_merge($moves, $this->pawnMoves($state, $side, (int)$from, $piece));
            elseif ($type === 'N') $moves = array_merge($moves, $this->jumpMoves($board, $side, (int)$from, $piece, self::KNIGHT_STEPS));
            elseif ($type === 'B') $moves = array_merge($moves, $this->slidingMoves($board, $side, (int)$from, $piece, self::BISHOP_DIRS));
            elseif ($type === 'R') $moves = array_merge($moves, $this->slidingMoves($board, $side, (int)$from, $piece, self::ROOK_DIRS));
            elseif ($type === 'Q') $moves = array_merge($moves, $this->slidingMoves($board, $side, (int)$from, $piece, array_merge(self::BISHOP_DIRS, self::ROOK_DIRS)));
            elseif ($type === 'K') $moves = array_merge($moves, $this->kingMoves($state, $side, (int)$from, $piece));
        }
        return $moves;
    }

    private function pawnMoves(array $state, string $side, int $from, string $piece): array
    {
        $board = $state['board'];
        $moves = [];
        $row = intdiv($from, 8);
        $col = $from % 8;
        $dir = $side === 'white' ? -1 : 1;
        $startRow = $side === 'white' ? 6 : 1;
        $promotionRow = $side === 'white' ? 0 : 7;
        $oneRow = $row + $dir;

        if ($this->inside($oneRow, $col)) {
            $one = $oneRow * 8 + $col;
            if (($board[$one] ?? '') === '') {
                $moves = array_merge($moves, $this->promotionMoves($from, $one, $piece, '', false, $oneRow === $promotionRow));
                $twoRow = $row + 2 * $dir;
                if ($row === $startRow && $this->inside($twoRow, $col)) {
                    $two = $twoRow * 8 + $col;
                    if (($board[$two] ?? '') === '') {
                        $moves[] = $this->move($from, $two, $piece, '', ['double_pawn' => true]);
                    }
                }
            }
        }

        foreach ([-1, 1] as $dc) {
            $nr = $row + $dir;
            $nc = $col + $dc;
            if (!$this->inside($nr, $nc)) continue;
            $to = $nr * 8 + $nc;
            $target = (string)($board[$to] ?? '');
            if ($target !== '' && $this->pieceSide($target) !== $side && $this->pieceType($target) !== 'K') {
                $moves = array_merge($moves, $this->promotionMoves($from, $to, $piece, $target, true, $nr === $promotionRow));
                continue;
            }

            $enPassant = $state['en_passant'];
            if (is_array($enPassant)
                && (int)($enPassant['target'] ?? -1) === $to
                && (string)($enPassant['capturable_by'] ?? '') === $side) {
                $capturedCell = (int)($enPassant['pawn_cell'] ?? -1);
                $captured = (string)($board[$capturedCell] ?? '');
                if ($captured !== '' && $this->pieceType($captured) === 'P' && $this->pieceSide($captured) !== $side) {
                    $moves[] = $this->move($from, $to, $piece, $captured, [
                        'capture' => true,
                        'en_passant' => true,
                        'captured_cell' => $capturedCell,
                    ]);
                }
            }
        }

        return $moves;
    }

    private function promotionMoves(int $from, int $to, string $piece, string $captured, bool $capture, bool $promotion): array
    {
        if (!$promotion) return [$this->move($from, $to, $piece, $captured, ['capture' => $capture])];
        $moves = [];
        foreach (self::PROMOTIONS as $promoteTo) {
            $moves[] = $this->move($from, $to, $piece, $captured, [
                'capture' => $capture,
                'promotion' => $promoteTo,
            ]);
        }
        return $moves;
    }

    private function jumpMoves(array $board, string $side, int $from, string $piece, array $steps): array
    {
        $moves = [];
        $row = intdiv($from, 8);
        $col = $from % 8;
        foreach ($steps as [$dr, $dc]) {
            $nr = $row + $dr;
            $nc = $col + $dc;
            if (!$this->inside($nr, $nc)) continue;
            $to = $nr * 8 + $nc;
            $target = (string)($board[$to] ?? '');
            if ($target !== '' && ($this->pieceSide($target) === $side || $this->pieceType($target) === 'K')) continue;
            $moves[] = $this->move($from, $to, $piece, $target, ['capture' => $target !== '']);
        }
        return $moves;
    }

    private function slidingMoves(array $board, string $side, int $from, string $piece, array $dirs): array
    {
        $moves = [];
        $row = intdiv($from, 8);
        $col = $from % 8;
        foreach ($dirs as [$dr, $dc]) {
            $step = 1;
            while (true) {
                $nr = $row + $dr * $step;
                $nc = $col + $dc * $step;
                if (!$this->inside($nr, $nc)) break;
                $to = $nr * 8 + $nc;
                $target = (string)($board[$to] ?? '');
                if ($target === '') {
                    $moves[] = $this->move($from, $to, $piece);
                    $step++;
                    continue;
                }
                if ($this->pieceSide($target) !== $side && $this->pieceType($target) !== 'K') {
                    $moves[] = $this->move($from, $to, $piece, $target, ['capture' => true]);
                }
                break;
            }
        }
        return $moves;
    }

    private function kingMoves(array $state, string $side, int $from, string $piece): array
    {
        $moves = $this->jumpMoves($state['board'], $side, $from, $piece, self::KING_STEPS);
        $board = $state['board'];
        $rights = $state['castling'];
        $opponent = $this->opposite($side);
        $homeKing = $side === 'white' ? 60 : 4;
        if ($from !== $homeKing || $this->isInCheck($board, $side)) return $moves;

        $kingKey = $side . '_king';
        $queenKey = $side . '_queen';
        $rookPiece = ($side === 'white' ? 'w' : 'b') . 'R';

        $kingRook = $side === 'white' ? 63 : 7;
        $kingTransit = $side === 'white' ? 61 : 5;
        $kingDestination = $side === 'white' ? 62 : 6;
        if (!empty($rights[$kingKey])
            && ($board[$kingRook] ?? '') === $rookPiece
            && ($board[$kingTransit] ?? '') === ''
            && ($board[$kingDestination] ?? '') === ''
            && !$this->isSquareAttacked($board, $kingTransit, $opponent)
            && !$this->isSquareAttacked($board, $kingDestination, $opponent)) {
            $moves[] = $this->move($from, $kingDestination, $piece, '', ['castle' => 'king']);
        }

        $queenRook = $side === 'white' ? 56 : 0;
        $queenBetween = $side === 'white' ? [59, 58, 57] : [3, 2, 1];
        $queenTransit = $side === 'white' ? 59 : 3;
        $queenDestination = $side === 'white' ? 58 : 2;
        if (!empty($rights[$queenKey])
            && ($board[$queenRook] ?? '') === $rookPiece
            && ($board[$queenBetween[0]] ?? '') === ''
            && ($board[$queenBetween[1]] ?? '') === ''
            && ($board[$queenBetween[2]] ?? '') === ''
            && !$this->isSquareAttacked($board, $queenTransit, $opponent)
            && !$this->isSquareAttacked($board, $queenDestination, $opponent)) {
            $moves[] = $this->move($from, $queenDestination, $piece, '', ['castle' => 'queen']);
        }

        return $moves;
    }

    private function applyMoveState(array $state, array $move, string $side): array
    {
        $board = $state['board'];
        $castling = $state['castling'];
        $from = (int)$move['from'];
        $to = (int)$move['to'];
        $piece = (string)$move['piece'];
        $capturedPiece = (string)($move['captured_piece'] ?? ($board[$to] ?? ''));
        $capturedCell = !empty($move['en_passant']) ? (int)($move['captured_cell'] ?? $to) : $to;

        $board[$from] = '';
        if (!empty($move['en_passant'])) $board[$capturedCell] = '';
        $placedPiece = $piece;
        if (!empty($move['promotion'])) {
            $placedPiece = ($side === 'white' ? 'w' : 'b') . strtoupper((string)$move['promotion']);
        }
        $board[$to] = $placedPiece;

        if (!empty($move['castle'])) {
            if ((string)$move['castle'] === 'king') {
                $rookFrom = $side === 'white' ? 63 : 7;
                $rookTo = $side === 'white' ? 61 : 5;
            } else {
                $rookFrom = $side === 'white' ? 56 : 0;
                $rookTo = $side === 'white' ? 59 : 3;
            }
            $board[$rookTo] = $board[$rookFrom];
            $board[$rookFrom] = '';
        }

        $type = $this->pieceType($piece);
        if ($type === 'K') {
            $castling[$side . '_king'] = false;
            $castling[$side . '_queen'] = false;
        }
        if ($type === 'R') {
            $this->disableRookRight($castling, $side, $from);
        }
        if ($capturedPiece !== '' && $this->pieceType($capturedPiece) === 'R') {
            $this->disableRookRight($castling, $this->opposite($side), $capturedCell);
        }

        $enPassant = null;
        if (!empty($move['double_pawn'])) {
            $enPassant = [
                'target' => intdiv($from + $to, 2),
                'pawn_cell' => $to,
                'capturable_by' => $this->opposite($side),
            ];
        }

        $halfmove = ($type === 'P' || !empty($move['capture'])) ? 0 : (int)($state['halfmove'] ?? 0) + 1;

        return [
            'board' => array_values($board),
            'castling' => $castling,
            'en_passant' => $enPassant,
            'halfmove' => $halfmove,
        ];
    }

    private function disableRookRight(array &$castling, string $side, int $cell): void
    {
        if ($side === 'white' && $cell === 63) $castling['white_king'] = false;
        if ($side === 'white' && $cell === 56) $castling['white_queen'] = false;
        if ($side === 'black' && $cell === 7) $castling['black_king'] = false;
        if ($side === 'black' && $cell === 0) $castling['black_queen'] = false;
    }

    private function isInCheck(array $board, string $side): bool
    {
        $king = $this->kingSquare($board, $side);
        if ($king < 0) return true;
        return $this->isSquareAttacked($board, $king, $this->opposite($side));
    }

    private function isSquareAttacked(array $board, int $square, string $bySide): bool
    {
        $row = intdiv($square, 8);
        $col = $square % 8;
        $prefix = $bySide === 'white' ? 'w' : 'b';

        $pawnSourceRow = $row + ($bySide === 'white' ? 1 : -1);
        foreach ([-1, 1] as $dc) {
            $pc = $col + $dc;
            if ($this->inside($pawnSourceRow, $pc) && ($board[$pawnSourceRow * 8 + $pc] ?? '') === $prefix . 'P') return true;
        }

        foreach (self::KNIGHT_STEPS as [$dr, $dc]) {
            $nr = $row + $dr; $nc = $col + $dc;
            if ($this->inside($nr, $nc) && ($board[$nr * 8 + $nc] ?? '') === $prefix . 'N') return true;
        }

        foreach (self::KING_STEPS as [$dr, $dc]) {
            $nr = $row + $dr; $nc = $col + $dc;
            if ($this->inside($nr, $nc) && ($board[$nr * 8 + $nc] ?? '') === $prefix . 'K') return true;
        }

        if ($this->rayAttacked($board, $row, $col, $prefix, self::BISHOP_DIRS, ['B','Q'])) return true;
        return $this->rayAttacked($board, $row, $col, $prefix, self::ROOK_DIRS, ['R','Q']);
    }

    private function rayAttacked(array $board, int $row, int $col, string $prefix, array $dirs, array $types): bool
    {
        foreach ($dirs as [$dr, $dc]) {
            for ($step = 1; $step < 8; $step++) {
                $nr = $row + $dr * $step;
                $nc = $col + $dc * $step;
                if (!$this->inside($nr, $nc)) break;
                $piece = (string)($board[$nr * 8 + $nc] ?? '');
                if ($piece === '') continue;
                if ($piece[0] === $prefix[0] && in_array($this->pieceType($piece), $types, true)) return true;
                break;
            }
        }
        return false;
    }

    private function insufficientMaterial(array $board): bool
    {
        $pieces = array_values(array_filter($board, static fn(string $piece): bool => $piece !== ''));
        $nonKings = array_values(array_filter($pieces, fn(string $piece): bool => $this->pieceType($piece) !== 'K'));
        if ($nonKings === []) return true;
        if (count($nonKings) !== 1) return false;
        return in_array($this->pieceType($nonKings[0]), ['B','N'], true);
    }

    private function positionKey(array $board, string $turnSide, array $castling, ?array $enPassant): string
    {
        $rights = '';
        foreach (['white_king','white_queen','black_king','black_queen'] as $key) {
            $rights .= !empty($castling[$key]) ? '1' : '0';
        }
        $ep = is_array($enPassant) ? (string)($enPassant['target'] ?? '-') : '-';
        return implode(',', $board) . '|' . $turnSide . '|' . $rights . '|' . $ep;
    }

    private function stateFromGame(array $game): array
    {
        return [
            'board' => $this->normalizeBoard($game['board'] ?? []),
            'castling' => array_merge([
                'white_king' => false,
                'white_queen' => false,
                'black_king' => false,
                'black_queen' => false,
            ], is_array($game['chess_castling'] ?? null) ? $game['chess_castling'] : []),
            'en_passant' => is_array($game['chess_en_passant'] ?? null) ? $game['chess_en_passant'] : null,
            'halfmove' => (int)($game['chess_halfmove_clock'] ?? 0),
        ];
    }

    private function initialBoard(): array
    {
        return [
            'bR','bN','bB','bQ','bK','bB','bN','bR',
            'bP','bP','bP','bP','bP','bP','bP','bP',
            '','','','','','','','',
            '','','','','','','','',
            '','','','','','','','',
            '','','','','','','','',
            'wP','wP','wP','wP','wP','wP','wP','wP',
            'wR','wN','wB','wQ','wK','wB','wN','wR',
        ];
    }

    private function normalizeBoard(mixed $board): array
    {
        if (!is_array($board)) return $this->initialBoard();
        $normalized = array_values(array_map(static fn(mixed $piece): string => is_string($piece) ? $piece : '', $board));
        if (count($normalized) !== 64 || !$this->validBoard($normalized)) return $this->initialBoard();
        return $normalized;
    }

    private function validBoard(array $board): bool
    {
        $allowed = ['', 'wP','wN','wB','wR','wQ','wK','bP','bN','bB','bR','bQ','bK'];
        foreach ($board as $piece) if (!is_string($piece) || !in_array($piece, $allowed, true)) return false;
        return true;
    }

    private function move(int $from, int $to, string $piece, string $captured = '', array $extra = []): array
    {
        return array_merge([
            'from' => $from,
            'to' => $to,
            'piece' => $piece,
            'capture' => $captured !== '',
            'captured_piece' => $captured,
            'promotion' => '',
            'castle' => '',
            'en_passant' => false,
            'double_pawn' => false,
        ], $extra);
    }

    private function publicMove(array $move): array
    {
        return [
            'from' => (int)$move['from'],
            'to' => (int)$move['to'],
            'capture' => !empty($move['capture']),
            'promotion' => (string)($move['promotion'] ?? ''),
            'promotion_required' => !empty($move['promotion']),
            'castle' => (string)($move['castle'] ?? ''),
            'en_passant' => !empty($move['en_passant']),
        ];
    }

    private function pieceSide(string $piece): string
    {
        return str_starts_with($piece, 'w') ? 'white' : 'black';
    }

    private function pieceType(string $piece): string
    {
        return strlen($piece) >= 2 ? strtoupper($piece[1]) : '';
    }

    private function pieceValue(string $piece): int
    {
        return match ($this->pieceType($piece)) {
            'P' => 100,
            'N' => 320,
            'B' => 330,
            'R' => 500,
            'Q' => 900,
            'K' => 20000,
            default => 0,
        };
    }

    private function sideForPlayer(array $game, string $playerId): string
    {
        $side = (string)($game['chess_sides'][$playerId] ?? '');
        return $side === 'black' ? 'black' : 'white';
    }

    private function playerIdForSide(array $game, string $side): string
    {
        foreach ($game['chess_sides'] ?? [] as $playerId => $playerSide) {
            if ((string)$playerSide === $side) return (string)$playerId;
        }
        return (string)($game['player_ids'][0] ?? '');
    }

    private function kingSquare(array $board, string $side): int
    {
        $needle = ($side === 'white' ? 'w' : 'b') . 'K';
        $index = array_search($needle, $board, true);
        return $index === false ? -1 : (int)$index;
    }

    private function opposite(string $side): string
    {
        return $side === 'white' ? 'black' : 'white';
    }

    private function inside(int $row, int $col): bool
    {
        return $row >= 0 && $row < 8 && $col >= 0 && $col < 8;
    }

    private function otherPlayerId(array $game, string $playerId): string
    {
        foreach ($game['player_ids'] ?? [] as $candidate) {
            if ((string)$candidate !== $playerId) return (string)$candidate;
        }
        return $playerId;
    }

    private function scheduleBotIfNeeded(array &$game): void
    {
        if (empty($game['is_bot_game'])) {
            unset($game['bot_move_after_at']);
            return;
        }
        $botId = (string)($game['bot_id'] ?? '');
        if ($botId !== '' && (string)($game['turn'] ?? '') === $botId) {
            $game['bot_move_after_at'] = gmdate('c', time() + random_int(1, 2));
        } else {
            unset($game['bot_move_after_at']);
        }
    }

    private function isTurnExpired(array $game): bool
    {
        if (($game['status'] ?? '') !== 'active') return false;
        $started = strtotime((string)($game['turn_started_at'] ?? $game['last_move_at'] ?? $game['created_at'] ?? '')) ?: 0;
        return $started > 0 && time() - $started >= $this->moveTimeoutSec();
    }

    private function timeLeft(array $game): int
    {
        if (($game['status'] ?? '') !== 'active') return 0;
        $started = strtotime((string)($game['turn_started_at'] ?? $game['last_move_at'] ?? $game['created_at'] ?? '')) ?: time();
        return max(0, $this->moveTimeoutSec() - (time() - $started));
    }

    private function moveTimeoutSec(): int
    {
        $value = (int)($this->config['move_timeout_sec'] ?? 60);
        return $value >= 20 && $value <= 60 ? $value : 60;
    }
}
