<?php
declare(strict_types=1);

final class CheckersService
{
    private const BOARD_SIZE = 8;
    private const NO_PROGRESS_DRAW_PLIES = 80;

    public function __construct(
        private array $config,
        private GameSettlementService $settlement,
        private ?CheckersBotService $bot = null
    ) {
        $this->bot ??= new CheckersBotService();
    }

    public function initializeGame(array &$game): void
    {
        if (!empty($game['checkers_initialized']) && $this->hasValidStateShape($game)) {
            return;
        }

        $playerIds = array_values(array_map('strval', $game['player_ids'] ?? []));
        if (count($playerIds) < 2) {
            throw new RuntimeException('Для шашек нужны два игрока.');
        }

        if (random_int(0, 1) === 1) {
            [$playerIds[0], $playerIds[1]] = [$playerIds[1], $playerIds[0]];
        }

        $whiteId = $playerIds[0];
        $blackId = $playerIds[1];
        $now = now_iso();

        $game['game_type'] = 'checkers';
        $game['board_size'] = self::BOARD_SIZE;
        $game['board_columns'] = self::BOARD_SIZE;
        $game['board_rows'] = self::BOARD_SIZE;
        $game['board'] = $this->initialBoard();
        $game['checkers_sides'] = [
            $whiteId => 'white',
            $blackId => 'black',
        ];
        $game['turn'] = $whiteId;
        $game['checkers_chain_piece'] = null;
        $game['checkers_pending_captures'] = [];
        $game['checkers_last_move'] = null;
        $game['checkers_last_captured_cells'] = [];
        $game['checkers_last_promotion'] = null;
        $game['checkers_no_progress_plies'] = 0;
        $game['checkers_position_counts'] = [];
        $game['turn_started_at'] = $now;
        $game['last_move_at'] = $now;
        $game['updated_at'] = $now;
        $game['checkers_initialized'] = true;
        $game['engine_version'] = 1;

        $this->recordPosition($game);
        $this->scheduleBotIfNeeded($game);
    }

    public function cleanup(array &$db): void
    {
        if (!isset($db['games']) || !is_array($db['games'])) return;

        foreach ($db['games'] as &$game) {
            if (!is_array($game) || (string)($game['game_type'] ?? '') !== 'checkers') continue;

            $this->initializeGame($game);
            if (($game['status'] ?? '') !== 'active') continue;

            if ($this->isTurnExpired($game)) {
                $loserId = (string)($game['turn'] ?? '');
                $winnerId = $this->otherPlayerId($game, $loserId);
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

        $type = trim((string)($action['type'] ?? ''));
        if ($type !== 'move') {
            throw new RuntimeException('Некорректное действие для шашек.');
        }

        $from = filter_var($action['from'] ?? null, FILTER_VALIDATE_INT);
        $to = filter_var($action['to'] ?? null, FILTER_VALIDATE_INT);
        if ($from === false || $to === false) {
            throw new RuntimeException('Выберите шашку и клетку для хода.');
        }

        return $this->performMove($db, $game, $userId, (int)$from, (int)$to);
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
        $this->settlement->finish($db, $game, $winnerId, 'player_left', $userId);
        return $game;
    }

    public function publicGame(array $game, string $viewerId): array
    {
        $this->initializeGame($game);
        $board = $this->normalizeBoard($game['board'] ?? []);
        $viewerSide = $this->sideForPlayer($game, $viewerId);
        $isViewerTurn = ($game['status'] ?? '') === 'active' && (string)($game['turn'] ?? '') === $viewerId;
        $legalMoves = $isViewerTurn ? $this->legalMovesForPlayer($game, $viewerId) : [];
        $captureRequired = $legalMoves !== [] && !empty($legalMoves[0]['capture']);
        $forcedPiece = isset($game['checkers_chain_piece']) && $game['checkers_chain_piece'] !== null
            ? (int)$game['checkers_chain_piece']
            : null;

        $players = [];
        foreach (array_values(array_map('strval', $game['player_ids'] ?? [])) as $playerId) {
            $side = $this->sideForPlayer($game, $playerId);
            $players[] = [
                'id' => $playerId,
                'name' => (string)($game['player_names'][$playerId] ?? 'Игрок'),
                'side' => $side,
                'symbol' => $side === 'white' ? '○' : '●',
            ];
        }

        return [
            'id' => (string)($game['id'] ?? ''),
            'room' => (string)($game['room'] ?? 'match'),
            'room_name' => ($game['room'] ?? 'match') === 'gold' ? 'Gold-комната' : 'Матч-комната',
            'bet' => (int)($game['bet'] ?? 0),
            'board_size' => self::BOARD_SIZE,
            'board_columns' => self::BOARD_SIZE,
            'board_rows' => self::BOARD_SIZE,
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
            'legal_moves' => array_values($legalMoves),
            'capture_required' => $captureRequired,
            'forced_piece' => $forcedPiece,
            'pending_captures' => array_values(array_map('intval', $game['checkers_pending_captures'] ?? [])),
            'last_move' => is_array($game['checkers_last_move'] ?? null) ? $game['checkers_last_move'] : null,
            'last_captured_cells' => array_values(array_map('intval', $game['checkers_last_captured_cells'] ?? [])),
            'last_promotion' => $game['checkers_last_promotion'] ?? null,
            'white_pieces' => $this->pieceCount($board, 'white'),
            'black_pieces' => $this->pieceCount($board, 'black'),
            'white_kings' => $this->kingCount($board, 'white'),
            'black_kings' => $this->kingCount($board, 'black'),
        ];
    }

    private function performMove(array &$db, array &$game, string $playerId, int $from, int $to): array
    {
        if ((string)($game['turn'] ?? '') !== $playerId) {
            throw new RuntimeException('Сейчас ход соперника.');
        }
        if ($from < 0 || $from >= 64 || $to < 0 || $to >= 64) {
            throw new RuntimeException('Выберите клетку на доске.');
        }

        $legalMoves = $this->legalMovesForPlayer($game, $playerId);
        $move = null;
        foreach ($legalMoves as $candidate) {
            if ((int)$candidate['from'] === $from && (int)$candidate['to'] === $to) {
                $move = $candidate;
                break;
            }
        }
        if ($move === null) {
            if ($legalMoves !== [] && !empty($legalMoves[0]['capture'])) {
                throw new RuntimeException('Есть обязательное взятие. Выберите подсвеченный ход.');
            }
            throw new RuntimeException('Сюда ходить нельзя.');
        }

        $board = $this->normalizeBoard($game['board'] ?? []);
        $piece = (string)($board[$from] ?? '');
        $side = $this->sideForPlayer($game, $playerId);
        $pendingCaptures = array_values(array_unique(array_map('intval', $game['checkers_pending_captures'] ?? [])));
        $capturedCell = !empty($move['capture']) ? (int)($move['captured'] ?? -1) : -1;

        $board[$from] = '';
        $board[$to] = $piece;

        $promoted = false;
        if ($piece === 'w' && intdiv($to, 8) === 0) {
            $board[$to] = 'W';
            $promoted = true;
        } elseif ($piece === 'b' && intdiv($to, 8) === 7) {
            $board[$to] = 'B';
            $promoted = true;
        }

        if ($capturedCell >= 0 && !in_array($capturedCell, $pendingCaptures, true)) {
            $pendingCaptures[] = $capturedCell;
        }

        $game['board'] = $board;
        $game['checkers_last_move'] = [
            'from' => $from,
            'to' => $to,
            'capture' => !empty($move['capture']),
            'captured' => $capturedCell >= 0 ? $capturedCell : null,
            'promoted' => $promoted,
            'player_id' => $playerId,
            'side' => $side,
        ];
        $game['checkers_last_promotion'] = $promoted ? $to : null;
        $game['last_move_at'] = now_iso();
        $game['updated_at'] = now_iso();
        $game['turn_started_at'] = now_iso();

        if (!empty($move['capture'])) {
            $continuations = $this->captureMovesForPiece($board, $to, $side, $pendingCaptures);
            if ($continuations !== []) {
                $game['checkers_chain_piece'] = $to;
                $game['checkers_pending_captures'] = $pendingCaptures;
                $game['checkers_last_move']['chain_continues'] = true;
                $game['checkers_last_captured_cells'] = [];
                $this->scheduleBotIfNeeded($game);
                return $game;
            }
        }

        foreach ($pendingCaptures as $cell) {
            if ($cell >= 0 && $cell < 64) $board[$cell] = '';
        }
        $game['board'] = $board;
        $game['checkers_chain_piece'] = null;
        $game['checkers_pending_captures'] = [];
        $game['checkers_last_captured_cells'] = $pendingCaptures;
        $game['checkers_last_move']['chain_continues'] = false;

        $madeCapture = $pendingCaptures !== [];
        $game['checkers_no_progress_plies'] = ($madeCapture || $promoted)
            ? 0
            : (int)($game['checkers_no_progress_plies'] ?? 0) + 1;

        $opponentId = $this->otherPlayerId($game, $playerId);
        $game['turn'] = $opponentId;
        $game['turn_started_at'] = now_iso();
        $game['updated_at'] = now_iso();

        $opponentSide = $this->sideForPlayer($game, $opponentId);
        if ($this->pieceCount($board, $opponentSide) === 0 || $this->legalMovesForSide($board, $opponentSide, null, []) === []) {
            $this->settlement->finish($db, $game, $playerId, 'normal_win', $opponentId);
            return $game;
        }

        if ($this->recordPosition($game) >= 3) {
            $this->settlement->finish($db, $game, null, 'draw_repetition', null);
            return $game;
        }

        if ((int)($game['checkers_no_progress_plies'] ?? 0) >= self::NO_PROGRESS_DRAW_PLIES) {
            $this->settlement->finish($db, $game, null, 'draw_no_progress', null);
            return $game;
        }

        $this->scheduleBotIfNeeded($game);
        return $game;
    }

    private function makeBotMove(array &$db, array &$game): void
    {
        $botId = (string)($game['bot_id'] ?? '');
        if ($botId === '' || ($game['status'] ?? '') !== 'active' || (string)($game['turn'] ?? '') !== $botId) return;

        $moves = $this->legalMovesForPlayer($game, $botId);
        if ($moves === []) {
            $winnerId = $this->otherPlayerId($game, $botId);
            $this->settlement->finish($db, $game, $winnerId, 'normal_win', $botId);
            return;
        }

        $side = $this->sideForPlayer($game, $botId);
        $difficulty = (string)($game['bot_difficulty'] ?? 'medium');
        $move = $this->bot->chooseMove($this->normalizeBoard($game['board'] ?? []), $moves, $side, $difficulty);
        $this->performMove($db, $game, $botId, (int)$move['from'], (int)$move['to']);
    }

    private function legalMovesForPlayer(array $game, string $playerId): array
    {
        $side = $this->sideForPlayer($game, $playerId);
        $board = $this->normalizeBoard($game['board'] ?? []);
        $chainPiece = isset($game['checkers_chain_piece']) && $game['checkers_chain_piece'] !== null
            ? (int)$game['checkers_chain_piece']
            : null;
        $pending = array_values(array_unique(array_map('intval', $game['checkers_pending_captures'] ?? [])));
        return $this->legalMovesForSide($board, $side, $chainPiece, $pending);
    }

    private function legalMovesForSide(array $board, string $side, ?int $chainPiece, array $pendingCaptures): array
    {
        if ($chainPiece !== null) {
            return $this->captureMovesForPiece($board, $chainPiece, $side, $pendingCaptures);
        }

        $captures = [];
        for ($cell = 0; $cell < 64; $cell++) {
            if (!$this->belongsTo((string)($board[$cell] ?? ''), $side)) continue;
            foreach ($this->captureMovesForPiece($board, $cell, $side, $pendingCaptures) as $move) {
                $captures[] = $move;
            }
        }
        if ($captures !== []) return $captures;

        $moves = [];
        for ($cell = 0; $cell < 64; $cell++) {
            $piece = (string)($board[$cell] ?? '');
            if (!$this->belongsTo($piece, $side)) continue;
            foreach ($this->simpleMovesForPiece($board, $cell, $side) as $move) {
                $moves[] = $move;
            }
        }
        return $moves;
    }

    private function simpleMovesForPiece(array $board, int $cell, string $side): array
    {
        $piece = (string)($board[$cell] ?? '');
        if ($piece === '') return [];

        $row = intdiv($cell, 8);
        $col = $cell % 8;
        $moves = [];

        if (ctype_upper($piece)) {
            foreach ([[-1,-1],[-1,1],[1,-1],[1,1]] as [$dr, $dc]) {
                $r = $row + $dr;
                $c = $col + $dc;
                while ($this->inside($r, $c)) {
                    $to = $r * 8 + $c;
                    if ((string)($board[$to] ?? '') !== '') break;
                    $moves[] = $this->movePayload($cell, $to, false, null, false);
                    $r += $dr;
                    $c += $dc;
                }
            }
            return $moves;
        }

        $forward = $side === 'white' ? -1 : 1;
        foreach ([-1, 1] as $dc) {
            $r = $row + $forward;
            $c = $col + $dc;
            if (!$this->inside($r, $c)) continue;
            $to = $r * 8 + $c;
            if ((string)($board[$to] ?? '') !== '') continue;
            $promotes = ($side === 'white' && $r === 0) || ($side === 'black' && $r === 7);
            $moves[] = $this->movePayload($cell, $to, false, null, $promotes);
        }
        return $moves;
    }

    private function captureMovesForPiece(array $board, int $cell, string $side, array $pendingCaptures): array
    {
        $piece = (string)($board[$cell] ?? '');
        if (!$this->belongsTo($piece, $side)) return [];

        $row = intdiv($cell, 8);
        $col = $cell % 8;
        $moves = [];

        if (ctype_upper($piece)) {
            foreach ([[-1,-1],[-1,1],[1,-1],[1,1]] as [$dr, $dc]) {
                $r = $row + $dr;
                $c = $col + $dc;
                $enemyCell = null;

                while ($this->inside($r, $c)) {
                    $index = $r * 8 + $c;
                    $occupant = (string)($board[$index] ?? '');

                    if ($occupant === '') {
                        if ($enemyCell !== null) {
                            $moves[] = $this->movePayload($cell, $index, true, $enemyCell, false);
                        }
                        $r += $dr;
                        $c += $dc;
                        continue;
                    }

                    if ($enemyCell !== null) break;
                    if ($this->belongsTo($occupant, $side)) break;
                    if (in_array($index, $pendingCaptures, true)) break;

                    $enemyCell = $index;
                    $r += $dr;
                    $c += $dc;
                }
            }
            return $moves;
        }

        foreach ([[-1,-1],[-1,1],[1,-1],[1,1]] as [$dr, $dc]) {
            $enemyRow = $row + $dr;
            $enemyCol = $col + $dc;
            $landRow = $row + $dr * 2;
            $landCol = $col + $dc * 2;
            if (!$this->inside($enemyRow, $enemyCol) || !$this->inside($landRow, $landCol)) continue;

            $enemyCell = $enemyRow * 8 + $enemyCol;
            $landingCell = $landRow * 8 + $landCol;
            $enemyPiece = (string)($board[$enemyCell] ?? '');
            if ($enemyPiece === '' || $this->belongsTo($enemyPiece, $side)) continue;
            if (in_array($enemyCell, $pendingCaptures, true)) continue;
            if ((string)($board[$landingCell] ?? '') !== '') continue;

            $promotes = ($side === 'white' && $landRow === 0) || ($side === 'black' && $landRow === 7);
            $moves[] = $this->movePayload($cell, $landingCell, true, $enemyCell, $promotes);
        }
        return $moves;
    }

    private function movePayload(int $from, int $to, bool $capture, ?int $captured, bool $promotes): array
    {
        return [
            'from' => $from,
            'to' => $to,
            'capture' => $capture,
            'captured' => $captured,
            'promotes' => $promotes,
        ];
    }

    private function initialBoard(): array
    {
        $board = array_fill(0, 64, '');
        for ($row = 0; $row < 3; $row++) {
            for ($col = 0; $col < 8; $col++) {
                if (($row + $col) % 2 === 1) $board[$row * 8 + $col] = 'b';
            }
        }
        for ($row = 5; $row < 8; $row++) {
            for ($col = 0; $col < 8; $col++) {
                if (($row + $col) % 2 === 1) $board[$row * 8 + $col] = 'w';
            }
        }
        return $board;
    }

    private function normalizeBoard(array $board): array
    {
        $clean = array_fill(0, 64, '');
        for ($i = 0; $i < 64; $i++) {
            $piece = (string)($board[$i] ?? '');
            $clean[$i] = in_array($piece, ['w', 'W', 'b', 'B'], true) ? $piece : '';
        }
        return $clean;
    }

    private function sideForPlayer(array $game, string $playerId): string
    {
        $side = (string)($game['checkers_sides'][$playerId] ?? '');
        if (in_array($side, ['white', 'black'], true)) return $side;
        $playerIds = array_values(array_map('strval', $game['player_ids'] ?? []));
        return ($playerIds[0] ?? '') === $playerId ? 'white' : 'black';
    }

    private function belongsTo(string $piece, string $side): bool
    {
        if ($piece === '') return false;
        return $side === 'white' ? strtolower($piece) === 'w' : strtolower($piece) === 'b';
    }

    private function pieceCount(array $board, string $side): int
    {
        $count = 0;
        foreach ($board as $piece) if ($this->belongsTo((string)$piece, $side)) $count++;
        return $count;
    }

    private function kingCount(array $board, string $side): int
    {
        $king = $side === 'white' ? 'W' : 'B';
        return count(array_filter($board, static fn($piece): bool => $piece === $king));
    }

    private function recordPosition(array &$game): int
    {
        $board = $this->normalizeBoard($game['board'] ?? []);
        $hash = md5(implode('', array_map(static fn(string $piece): string => $piece === '' ? '.' : $piece, $board)) . '#' . (string)($game['turn'] ?? ''));
        if (!isset($game['checkers_position_counts']) || !is_array($game['checkers_position_counts'])) {
            $game['checkers_position_counts'] = [];
        }
        $game['checkers_position_counts'][$hash] = (int)($game['checkers_position_counts'][$hash] ?? 0) + 1;
        return (int)$game['checkers_position_counts'][$hash];
    }

    private function scheduleBotIfNeeded(array &$game): void
    {
        if (empty($game['is_bot_game'])) {
            unset($game['bot_move_after_at']);
            return;
        }
        $botId = (string)($game['bot_id'] ?? '');
        if ($botId !== '' && (string)($game['turn'] ?? '') === $botId && ($game['status'] ?? '') === 'active') {
            $game['bot_move_after_at'] = gmdate('c', time() + $this->botMoveDelaySec());
        } else {
            unset($game['bot_move_after_at']);
        }
    }

    private function otherPlayerId(array $game, string $userId): string
    {
        foreach ($game['player_ids'] ?? [] as $playerId) {
            if ((string)$playerId !== $userId) return (string)$playerId;
        }
        return $userId;
    }

    private function hasValidStateShape(array $game): bool
    {
        return isset($game['board'], $game['checkers_sides'])
            && is_array($game['board'])
            && is_array($game['checkers_sides'])
            && count($game['board']) === 64;
    }

    private function timeLeft(array $game): int
    {
        if (($game['status'] ?? '') !== 'active') return 0;
        $started = strtotime((string)($game['turn_started_at'] ?? $game['last_move_at'] ?? '')) ?: time();
        return max(0, $this->moveTimeoutSec() - (time() - $started));
    }

    private function isTurnExpired(array $game): bool
    {
        return ($game['status'] ?? '') === 'active' && $this->timeLeft($game) <= 0;
    }

    private function moveTimeoutSec(): int
    {
        $value = (int)($this->config['move_timeout_sec'] ?? 60);
        if ($value <= 0 || $value > 60) return 60;
        return max(20, $value);
    }

    private function botMoveDelaySec(): int
    {
        return random_int(1, 3);
    }

    private function inside(int $row, int $col): bool
    {
        return $row >= 0 && $row < 8 && $col >= 0 && $col < 8;
    }
}
