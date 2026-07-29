<?php
declare(strict_types=1);

trait JsonGamesStrategyTrait
{
    private function applyCheckers(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        array &$game,
        string $actorId,
        array $step,
        DateTimeImmutable $now,
        array $config
    ): array {
        if ((string)($game['turn'] ?? '') !== $actorId) throw new RuntimeException('Сейчас ход соперника.');
        $from = filter_var($step['from'] ?? null, FILTER_VALIDATE_INT);
        $to = filter_var($step['to'] ?? null, FILTER_VALIDATE_INT);
        if ($from === false || $to === false) throw new RuntimeException('Выберите шашку и клетку для хода.');
        $legalMoves = array_values($game['checkers_legal_moves'] ?? []);
        $move = null;
        foreach ($legalMoves as $candidate) {
            if ((int)($candidate['from'] ?? -1) === (int)$from && (int)($candidate['to'] ?? -1) === (int)$to) {
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
        $board = array_values($game['board'] ?? array_fill(0, 64, ''));
        $piece = (string)($board[(int)$from] ?? '');
        $board[(int)$from] = '';
        $promoted = !empty($move['promoted']);
        $board[(int)$to] = $promoted ? (ctype_lower($piece) ? strtoupper($piece) : $piece) : $piece;
        $captured = isset($move['captured']) ? (int)$move['captured'] : -1;
        if ($captured >= 0 && $captured < 64) $board[$captured] = '';
        $game['board'] = $board;
        $game['checkers_last_move'] = [
            'from' => (int)$from,
            'to' => (int)$to,
            'capture' => !empty($move['capture']),
            'captured' => $captured >= 0 ? $captured : null,
            'promoted' => $promoted,
            'player_id' => $actorId,
            'side' => (string)($game['checkers_sides'][$actorId] ?? 'white'),
            'chain_continues' => false,
        ];
        $game['checkers_last_captured_cells'] = $captured >= 0 ? [$captured] : [];
        $game['last_move_at'] = $now->format('c');
        $game['updated_at'] = $now->format('c');
        if (!empty($move['finish_winner'])) {
            return $this->finishGame($fixture, $state, $game, (string)$move['finish_winner'], 'normal_win', $now, $config);
        }
        $game['turn'] = $this->otherPlayerId($game, $actorId);
        $game['turn_started_at'] = $now->format('c');
        $game['checkers_legal_moves'] = $game['checkers_next_legal_moves'] ?? [];
        return [];
    }

    private function applyChess(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        array &$game,
        string $actorId,
        array $step,
        DateTimeImmutable $now,
        array $config
    ): array {
        if ((string)($game['turn'] ?? '') !== $actorId) throw new RuntimeException('Сейчас ход соперника.');
        $from = filter_var($step['from'] ?? null, FILTER_VALIDATE_INT);
        $to = filter_var($step['to'] ?? null, FILTER_VALIDATE_INT);
        if ($from === false || $to === false) throw new RuntimeException('Выберите фигуру и клетку для хода.');
        if ($from < 0 || $from >= 64 || $to < 0 || $to >= 64 || $from === $to) {
            throw new RuntimeException('Выберите допустимый ход.');
        }
        $promotion = strtolower(trim((string)($step['promotion'] ?? '')));
        if ($promotion !== '' && !in_array($promotion, ['q','r','b','n'], true)) {
            throw new RuntimeException('Выберите фигуру для превращения пешки.');
        }
        $move = null;
        foreach (array_values($game['chess_legal_moves'] ?? []) as $candidate) {
            if ((int)($candidate['from'] ?? -1) === (int)$from
                && (int)($candidate['to'] ?? -1) === (int)$to
                && (string)($candidate['promotion'] ?? '') === $promotion) {
                $move = $candidate;
                break;
            }
        }
        if ($move === null) {
            $board = array_values($game['board'] ?? array_fill(0, 64, ''));
            $piece = (string)($board[(int)$from] ?? '');
            $side = (string)($game['chess_sides'][$actorId] ?? 'white');
            $belongs = $piece !== '' && (($side === 'white' && ctype_upper($piece)) || ($side === 'black' && ctype_lower($piece)));
            if (!$belongs) throw new RuntimeException('Выберите свою фигуру.');
            throw new RuntimeException('Этот ход недоступен. Король не должен оставаться под шахом.');
        }
        $board = array_values($game['board'] ?? array_fill(0, 64, ''));
        $piece = (string)($board[(int)$from] ?? '');
        $captured = (string)($board[(int)$to] ?? '');
        $board[(int)$from] = '';
        $board[(int)$to] = $promotion !== ''
            ? ((string)($game['chess_sides'][$actorId] ?? 'white') === 'white' ? strtoupper($promotion) : strtolower($promotion))
            : $piece;
        $game['board'] = $board;
        $game['chess_last_move'] = [
            'from' => (int)$from,
            'to' => (int)$to,
            'piece' => $piece,
            'captured' => $captured !== '' ? $captured : null,
            'promotion' => $promotion !== '' ? $promotion : null,
            'player_id' => $actorId,
        ];
        $game['chess_move_count'] = (int)($game['chess_move_count'] ?? 0) + 1;
        $game['last_move_at'] = $now->format('c');
        $game['updated_at'] = $now->format('c');
        if (!empty($move['checkmate'])) {
            $game['chess_end_reason'] = 'checkmate';
            return $this->finishGame($fixture, $state, $game, $actorId, 'normal_win', $now, $config);
        }
        if (!empty($move['stalemate'])) {
            $game['chess_end_reason'] = 'stalemate';
            return $this->finishGame($fixture, $state, $game, null, 'draw', $now, $config);
        }
        $game['turn'] = $this->otherPlayerId($game, $actorId);
        $game['turn_started_at'] = $now->format('c');
        $game['chess_legal_moves'] = $game['chess_next_legal_moves'] ?? [];
        return [];
    }

    private function applyGo(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        array &$game,
        string $actorId,
        array $step,
        DateTimeImmutable $now,
        array $config
    ): array {
        if ((string)($game['turn'] ?? '') !== $actorId) throw new RuntimeException('Сейчас ход соперника.');
        $type = strtolower(trim((string)($step['type'] ?? $step['action'] ?? 'place')));
        $side = (string)($game['go_sides'][$actorId] ?? 'black');
        if ($type === 'pass') {
            $passes = (int)($game['go_consecutive_passes'] ?? 0) + 1;
            $game['go_last_move'] = ['type' => 'pass', 'cell' => null, 'player_id' => $actorId, 'side' => $side, 'captured' => 0];
            $game['go_last_captured_cells'] = [];
            $game['go_last_passed_player_id'] = $actorId;
            $game['go_consecutive_passes'] = $passes;
            $game['go_move_count'] = (int)($game['go_move_count'] ?? 0) + 1;
            $game['last_move_at'] = $now->format('c');
            $game['updated_at'] = $now->format('c');
            if ($passes >= 2) {
                $score = is_array($game['go_score'] ?? null) ? $game['go_score'] : ['black' => 0.0, 'white' => 6.5];
                $game['go_final_score'] = $score;
                if ((float)$score['black'] === (float)$score['white']) {
                    return $this->finishGame($fixture, $state, $game, null, 'draw', $now, $config);
                }
                $winnerSide = (float)$score['black'] > (float)$score['white'] ? 'black' : 'white';
                $winnerId = array_search($winnerSide, $game['go_sides'] ?? [], true);
                return $this->finishGame($fixture, $state, $game, (string)$winnerId, 'normal_win', $now, $config);
            }
            $game['turn'] = $this->otherPlayerId($game, $actorId);
            $game['turn_started_at'] = $now->format('c');
            return [];
        }
        if (!in_array($type, ['place','cell','go_action'], true)) throw new RuntimeException('Некорректное действие для Го.');
        $cell = filter_var($step['cell'] ?? null, FILTER_VALIDATE_INT);
        $size = (int)($game['board_size'] ?? 9);
        if ($cell === false || $cell < 0 || $cell >= $size * $size) throw new RuntimeException('Выберите точку на поле.');
        $board = (string)($game['board'] ?? str_repeat('-', $size * $size));
        if (($board[$cell] ?? '-') !== '-') throw new RuntimeException('Эта точка уже занята.');
        $legal = $game['go_legal_moves'] ?? [];
        $captured = $legal[(string)$cell] ?? $legal[$cell] ?? null;
        if (!is_array($captured)) throw new RuntimeException('У этой группы не останется свобод.');
        $symbol = $side === 'black' ? 'B' : 'W';
        $board[$cell] = $symbol;
        foreach ($captured as $capturedCell) $board[(int)$capturedCell] = '-';
        $game['board'] = $board;
        $game['go_last_move'] = ['type' => 'place', 'cell' => (int)$cell, 'player_id' => $actorId, 'side' => $side, 'captured' => count($captured)];
        $game['go_last_captured_cells'] = array_values(array_map('intval', $captured));
        $game['go_last_passed_player_id'] = null;
        $game['go_consecutive_passes'] = 0;
        $game['go_move_count'] = (int)($game['go_move_count'] ?? 0) + 1;
        if (!isset($game['go_captures']) || !is_array($game['go_captures'])) $game['go_captures'] = ['black' => 0, 'white' => 0];
        $game['go_captures'][$side] = (int)($game['go_captures'][$side] ?? 0) + count($captured);
        $game['last_move_at'] = $now->format('c');
        $game['updated_at'] = $now->format('c');
        $game['turn'] = $this->otherPlayerId($game, $actorId);
        $game['turn_started_at'] = $now->format('c');
        return [];
    }

    private function applyDomino(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        array &$game,
        string $actorId,
        array $step,
        DateTimeImmutable $now,
        array $config
    ): array {
        if ((string)($game['turn'] ?? '') !== $actorId) throw new RuntimeException('Сейчас ход соперника.');
        $type = strtolower(trim((string)($step['type'] ?? $step['action'] ?? 'play')));
        if ($type === 'draw') {
            $stock = array_values($game['domino_stock'] ?? []);
            if ($stock === []) throw new RuntimeException('В базаре больше нет костяшек.');
            $tile = array_shift($stock);
            $game['domino_stock'] = $stock;
            $game['domino_hands'][$actorId][] = $tile;
            sort($game['domino_hands'][$actorId], SORT_STRING);
            $game['domino_last_action'] = ['type' => 'draw', 'player_id' => $actorId, 'drawn_tiles' => [$tile]];
            $game['last_move_at'] = $now->format('c');
            $game['updated_at'] = $now->format('c');
            return [];
        }
        if ($type !== 'play') throw new RuntimeException('Некорректное действие для домино.');
        $tile = trim((string)($step['tile'] ?? ''));
        $side = trim((string)($step['side'] ?? ''));
        $hand = array_values(array_map('strval', $game['domino_hands'][$actorId] ?? []));
        if (!in_array($tile, $hand, true)) throw new RuntimeException('Этой костяшки нет в вашей руке.');
        $legalMap = $game['domino_legal_sides'] ?? [];
        $legalSides = $legalMap[$tile] ?? [];
        if (!is_array($legalSides) || $legalSides === []) throw new RuntimeException('Эта костяшка не подходит к открытым концам.');
        if (count($legalSides) === 1) $side = (string)$legalSides[0];
        elseif (!in_array($side, $legalSides, true)) throw new RuntimeException('Выберите левый или правый конец цепочки.');
        [$a,$b] = array_map('intval', explode('-', $tile));
        $chain = array_values($game['domino_chain'] ?? []);
        $openLeft = (int)($chain[0]['left'] ?? $a);
        $openRight = (int)($chain[array_key_last($chain)]['right'] ?? $b);
        if ($side === 'left') {
            $left = $a === $openLeft ? $b : $a;
            $right = $openLeft;
            array_unshift($chain, ['tile' => $tile, 'left' => $left, 'right' => $right, 'player_id' => $actorId, 'side' => 'left', 'move_number' => (int)($game['domino_move_count'] ?? 0) + 1]);
        } else {
            $left = $openRight;
            $right = $a === $openRight ? $b : $a;
            $chain[] = ['tile' => $tile, 'left' => $left, 'right' => $right, 'player_id' => $actorId, 'side' => 'right', 'move_number' => (int)($game['domino_move_count'] ?? 0) + 1];
        }
        $game['domino_chain'] = $chain;
        $game['domino_hands'][$actorId] = array_values(array_filter($hand, static fn(string $candidate): bool => $candidate !== $tile));
        $game['domino_move_count'] = (int)($game['domino_move_count'] ?? 0) + 1;
        $game['domino_consecutive_passes'] = 0;
        $game['domino_last_action'] = ['type' => 'play', 'player_id' => $actorId, 'tile' => $tile, 'side' => $side];
        $game['last_move_at'] = $now->format('c');
        $game['updated_at'] = $now->format('c');
        if (($game['domino_hands'][$actorId] ?? []) === []) {
            $game['domino_end_reason'] = 'empty_hand';
            $game['domino_final_points'] = $this->dominoPoints($game);
            return $this->finishGame($fixture, $state, $game, $actorId, 'normal_win', $now, $config);
        }
        $game['turn'] = $this->otherPlayerId($game, $actorId);
        $game['turn_started_at'] = $now->format('c');
        return [];
    }

    private function dominoPoints(array $game): array
    {
        $points = [];
        foreach ($game['domino_hands'] ?? [] as $playerId => $tiles) {
            $total = 0;
            foreach ($tiles as $tile) {
                [$a,$b] = array_map('intval', explode('-', (string)$tile));
                $total += $a + $b;
            }
            $points[(string)$playerId] = $total;
        }
        ksort($points, SORT_STRING);
        return $points;
    }
}
