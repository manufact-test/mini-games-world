<?php
declare(strict_types=1);

final class DominoService
{
    private const HAND_SIZE = 7;

    public function __construct(
        private array $config,
        private GameSettlementService $settlement,
        private ?DominoBotService $bot = null
    ) {
        $this->bot ??= new DominoBotService();
    }

    public function initializeGame(array &$game): void
    {
        $playerIds = array_values(array_map('strval', $game['player_ids'] ?? []));
        if (count($playerIds) < 2) {
            throw new RuntimeException('Для домино нужны два игрока.');
        }

        if (!empty($game['domino_initialized'])
            && is_array($game['domino_hands'] ?? null)
            && is_array($game['domino_chain'] ?? null)
            && is_array($game['domino_stock'] ?? null)) {
            return;
        }

        $tiles = $this->allTiles();
        shuffle($tiles);

        $firstId = $playerIds[0];
        $secondId = $playerIds[1];
        $hands = [
            $firstId => array_slice($tiles, 0, self::HAND_SIZE),
            $secondId => array_slice($tiles, self::HAND_SIZE, self::HAND_SIZE),
        ];
        $stock = array_slice($tiles, self::HAND_SIZE * 2);

        foreach ($hands as &$hand) $this->sortHand($hand);
        unset($hand);

        [$starterId, $starterTile] = $this->chooseStarter($hands, $playerIds);
        $hands[$starterId] = array_values(array_filter(
            $hands[$starterId],
            static fn(string $tile): bool => $tile !== $starterTile
        ));
        [$left, $right] = $this->parseTile($starterTile);
        $now = now_iso();

        $game['game_type'] = 'domino';
        $game['board_size'] = 7;
        $game['board_columns'] = 7;
        $game['board_rows'] = 1;
        $game['board'] = [];
        $game['domino_hands'] = $hands;
        $game['domino_stock'] = array_values($stock);
        $game['domino_chain'] = [[
            'tile' => $starterTile,
            'left' => $left,
            'right' => $right,
            'player_id' => $starterId,
            'side' => 'start',
            'move_number' => 1,
            'is_start' => true,
        ]];
        $game['domino_starting_player_id'] = $starterId;
        $game['domino_start_tile'] = $starterTile;
        $game['domino_move_count'] = 1;
        $game['domino_consecutive_passes'] = 0;
        $game['domino_last_action'] = [
            'type' => 'start',
            'player_id' => $starterId,
            'tile' => $starterTile,
            'side' => 'start',
        ];
        $game['domino_final_points'] = null;
        $game['domino_end_reason'] = null;
        $game['domino_void_values'] = [];
        $game['turn'] = $this->otherPlayerId($game, $starterId);
        $game['turn_started_at'] = $now;
        $game['last_move_at'] = $now;
        $game['updated_at'] = $now;
        $game['domino_initialized'] = true;
        $game['engine_version'] = 1;
        unset($game['bot_move_after_at']);

        $this->scheduleBotIfNeeded($game);
    }

    public function cleanup(array &$db): void
    {
        if (!isset($db['games']) || !is_array($db['games'])) return;

        foreach ($db['games'] as &$game) {
            if (!is_array($game) || (string)($game['game_type'] ?? '') !== 'domino') continue;
            $this->initializeGame($game);
            if (($game['status'] ?? '') !== 'active') continue;

            if ($this->isTurnExpired($game)) {
                $loserId = (string)($game['turn'] ?? '');
                $winnerId = $this->otherPlayerId($game, $loserId);
                $this->storeFinalPoints($game, 'timeout');
                $this->settlement->finish($db, $game, $winnerId, 'timeout', $loserId);
                continue;
            }

            $this->resolveAutomaticPasses($db, $game);
            if (($game['status'] ?? '') !== 'active') continue;

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

        if ($this->isTurnExpired($game)) {
            $loserId = (string)($game['turn'] ?? '');
            $winnerId = $this->otherPlayerId($game, $loserId);
            $this->storeFinalPoints($game, 'timeout');
            $this->settlement->finish($db, $game, $winnerId, 'timeout', $loserId);
            return $game;
        }

        $this->resolveAutomaticPasses($db, $game);
        if (($game['status'] ?? '') !== 'active') return $game;
        if ((string)($game['turn'] ?? '') !== $userId) {
            throw new RuntimeException('Сейчас ход соперника.');
        }

        $type = trim((string)($action['type'] ?? 'play'));
        if ($type === 'draw') return $this->performDraw($db, $game, $userId);
        if ($type !== 'play') throw new RuntimeException('Некорректное действие для домино.');

        $tile = trim((string)($action['tile'] ?? ''));
        $side = trim((string)($action['side'] ?? ''));
        return $this->performPlay($db, $game, $userId, $tile, $side);
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
        $this->storeFinalPoints($game, 'player_left');
        $this->settlement->finish($db, $game, $winnerId, 'player_left', $userId);
        return $game;
    }

    public function publicGame(array $game, string $viewerId): array
    {
        $this->initializeGame($game);
        $hands = is_array($game['domino_hands'] ?? null) ? $game['domino_hands'] : [];
        $viewerHand = array_values(array_map('strval', $hands[$viewerId] ?? []));
        $opponentId = $this->otherPlayerId($game, $viewerId);
        $opponentHand = array_values(array_map('strval', $hands[$opponentId] ?? []));
        $chain = array_values($game['domino_chain'] ?? []);
        [$openLeft, $openRight] = $this->openEnds($chain);
        $playable = $this->playableMap($viewerHand, $chain);
        $isViewerTurn = ($game['status'] ?? '') === 'active' && (string)($game['turn'] ?? '') === $viewerId;

        $players = [];
        foreach (array_values(array_map('strval', $game['player_ids'] ?? [])) as $playerId) {
            $players[] = [
                'id' => $playerId,
                'name' => (string)($game['player_names'][$playerId] ?? 'Игрок'),
                'tile_count' => count($hands[$playerId] ?? []),
            ];
        }

        $lastAction = is_array($game['domino_last_action'] ?? null)
            ? $game['domino_last_action']
            : null;
        if (is_array($lastAction)
            && (string)($lastAction['type'] ?? '') === 'draw'
            && (string)($lastAction['player_id'] ?? '') !== $viewerId) {
            unset($lastAction['drawn_tiles']);
        }

        $finished = ($game['status'] ?? '') === 'finished';
        $finalPoints = is_array($game['domino_final_points'] ?? null)
            ? $game['domino_final_points']
            : null;

        return [
            'id' => (string)($game['id'] ?? ''),
            'room' => (string)($game['room'] ?? 'match'),
            'room_name' => ($game['room'] ?? 'match') === 'gold' ? 'Gold-комната' : 'Матч-комната',
            'bet' => (int)($game['bet'] ?? 0),
            'board_size' => 7,
            'board_columns' => 7,
            'board_rows' => 1,
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
            'chain' => $chain,
            'open_left' => $openLeft,
            'open_right' => $openRight,
            'stock_count' => count($game['domino_stock'] ?? []),
            'viewer_hand' => $this->publicTiles($viewerHand),
            'opponent_tile_count' => count($opponentHand),
            'opponent_hand' => $finished ? $this->publicTiles($opponentHand) : [],
            'playable_sides' => $playable,
            'can_draw' => $isViewerTurn && $playable === [] && count($game['domino_stock'] ?? []) > 0,
            'last_action' => $lastAction,
            'move_count' => (int)($game['domino_move_count'] ?? 0),
            'consecutive_passes' => (int)($game['domino_consecutive_passes'] ?? 0),
            'end_reason' => $game['domino_end_reason'] ?? null,
            'final_points' => $finalPoints,
            'my_points' => $finalPoints !== null ? (int)($finalPoints[$viewerId] ?? 0) : null,
            'opponent_points' => $finalPoints !== null ? (int)($finalPoints[$opponentId] ?? 0) : null,
            'starting_player_id' => $game['domino_starting_player_id'] ?? null,
            'start_tile' => $game['domino_start_tile'] ?? null,
        ];
    }

    private function performPlay(
        array &$db,
        array &$game,
        string $playerId,
        string $tile,
        string $side
    ): array {
        $hand = array_values(array_map('strval', $game['domino_hands'][$playerId] ?? []));
        if (!in_array($tile, $hand, true)) {
            throw new RuntimeException('Этой костяшки нет в вашей руке.');
        }

        $legalSides = $this->legalSides($tile, $game['domino_chain'] ?? []);
        if ($legalSides === []) {
            throw new RuntimeException('Эта костяшка не подходит к открытым концам.');
        }
        if (count($legalSides) === 1) {
            $side = $legalSides[0];
        } elseif (!in_array($side, $legalSides, true)) {
            throw new RuntimeException('Выберите левый или правый конец цепочки.');
        }

        $oriented = $this->orientedTile($tile, $side, $game['domino_chain'] ?? []);
        $moveNumber = (int)($game['domino_move_count'] ?? 0) + 1;
        $item = [
            'tile' => $tile,
            'left' => $oriented[0],
            'right' => $oriented[1],
            'player_id' => $playerId,
            'side' => $side,
            'move_number' => $moveNumber,
            'is_start' => false,
        ];

        if ($side === 'left') array_unshift($game['domino_chain'], $item);
        else $game['domino_chain'][] = $item;

        $removed = false;
        $nextHand = [];
        foreach ($hand as $handTile) {
            if (!$removed && $handTile === $tile) {
                $removed = true;
                continue;
            }
            $nextHand[] = $handTile;
        }
        $game['domino_hands'][$playerId] = array_values($nextHand);
        $now = now_iso();
        $game['domino_move_count'] = $moveNumber;
        $game['domino_consecutive_passes'] = 0;
        $game['domino_last_action'] = [
            'type' => 'play',
            'player_id' => $playerId,
            'tile' => $tile,
            'side' => $side,
            'left' => $oriented[0],
            'right' => $oriented[1],
        ];
        $game['last_move_at'] = $now;
        $game['updated_at'] = $now;
        unset($game['bot_move_after_at']);

        if ($game['domino_hands'][$playerId] === []) {
            $this->storeFinalPoints($game, 'empty_hand');
            $loserId = $this->otherPlayerId($game, $playerId);
            $this->settlement->finish($db, $game, $playerId, 'normal_win', $loserId);
            return $game;
        }

        $game['turn'] = $this->otherPlayerId($game, $playerId);
        $game['turn_started_at'] = $now;
        $this->resolveAutomaticPasses($db, $game);
        if (($game['status'] ?? '') === 'active') $this->scheduleBotIfNeeded($game);
        return $game;
    }

    private function performDraw(array &$db, array &$game, string $playerId): array
    {
        $hand = array_values(array_map('strval', $game['domino_hands'][$playerId] ?? []));
        if ($this->hasLegalPlay($hand, $game['domino_chain'] ?? [])) {
            throw new RuntimeException('У вас уже есть подходящая костяшка.');
        }

        $stock =& $game['domino_stock'];
        if (!is_array($stock) || $stock === []) {
            $this->resolveAutomaticPasses($db, $game);
            return $game;
        }

        $drawn = [];
        while ($stock !== [] && !$this->hasLegalPlay($hand, $game['domino_chain'] ?? [])) {
            $tile = (string)array_pop($stock);
            $hand[] = $tile;
            $drawn[] = $tile;
        }
        $game['domino_hands'][$playerId] = array_values($hand);

        $now = now_iso();
        $game['domino_move_count'] = (int)($game['domino_move_count'] ?? 0) + 1;
        $game['last_move_at'] = $now;
        $game['updated_at'] = $now;
        $game['turn_started_at'] = $now;
        $game['domino_last_action'] = [
            'type' => 'draw',
            'player_id' => $playerId,
            'drawn_count' => count($drawn),
            'drawn_tiles' => array_values($drawn),
            'playable_found' => $this->hasLegalPlay($hand, $game['domino_chain'] ?? []),
        ];
        unset($game['bot_move_after_at']);

        if ($this->hasLegalPlay($hand, $game['domino_chain'] ?? [])) {
            $this->scheduleBotIfNeeded($game);
            return $game;
        }

        $this->resolveAutomaticPasses($db, $game, count($drawn));
        if (($game['status'] ?? '') === 'active') $this->scheduleBotIfNeeded($game);
        return $game;
    }

    private function resolveAutomaticPasses(array &$db, array &$game, int $afterDrawCount = 0): void
    {
        for ($guard = 0; $guard < 2; $guard++) {
            if (($game['status'] ?? '') !== 'active') return;
            if (($game['domino_stock'] ?? []) !== []) return;

            $playerId = (string)($game['turn'] ?? '');
            $hand = array_values(array_map('strval', $game['domino_hands'][$playerId] ?? []));
            if ($this->hasLegalPlay($hand, $game['domino_chain'] ?? [])) return;

            [$left, $right] = $this->openEnds($game['domino_chain'] ?? []);
            $known = array_values(array_unique(array_filter([$left, $right], static fn($value): bool => $value !== null)));
            $game['domino_void_values'][$playerId] = $known;
            $game['domino_consecutive_passes'] = (int)($game['domino_consecutive_passes'] ?? 0) + 1;
            $game['domino_move_count'] = (int)($game['domino_move_count'] ?? 0) + 1;
            $now = now_iso();
            $game['domino_last_action'] = [
                'type' => 'pass',
                'player_id' => $playerId,
                'drawn_count' => $afterDrawCount,
            ];
            $afterDrawCount = 0;
            $game['last_move_at'] = $now;
            $game['updated_at'] = $now;
            unset($game['bot_move_after_at']);

            if ((int)$game['domino_consecutive_passes'] >= 2) {
                $this->finishBlockedGame($db, $game);
                return;
            }

            $game['turn'] = $this->otherPlayerId($game, $playerId);
            $game['turn_started_at'] = $now;
        }
    }

    private function finishBlockedGame(array &$db, array &$game): void
    {
        $points = $this->storeFinalPoints($game, 'blocked');
        $playerIds = array_values(array_map('strval', $game['player_ids'] ?? []));
        $firstId = (string)($playerIds[0] ?? '');
        $secondId = (string)($playerIds[1] ?? '');
        $firstPoints = (int)($points[$firstId] ?? 0);
        $secondPoints = (int)($points[$secondId] ?? 0);

        if ($firstPoints === $secondPoints) {
            $this->settlement->finish($db, $game, null, 'draw');
            return;
        }

        $winnerId = $firstPoints < $secondPoints ? $firstId : $secondId;
        $loserId = $winnerId === $firstId ? $secondId : $firstId;
        $this->settlement->finish($db, $game, $winnerId, 'normal_win', $loserId);
    }

    private function storeFinalPoints(array &$game, string $reason): array
    {
        $points = [];
        foreach (array_values(array_map('strval', $game['player_ids'] ?? [])) as $playerId) {
            $points[$playerId] = $this->handPoints($game['domino_hands'][$playerId] ?? []);
        }
        $game['domino_final_points'] = $points;
        $game['domino_end_reason'] = $reason;
        unset($game['bot_move_after_at']);
        return $points;
    }

    private function makeBotMove(array &$db, array &$game): void
    {
        $botId = (string)($game['bot_id'] ?? '');
        if ($botId === '' || ($game['status'] ?? '') !== 'active' || (string)($game['turn'] ?? '') !== $botId) return;

        $opponentId = $this->otherPlayerId($game, $botId);
        $action = $this->bot->chooseAction(
            array_values(array_map('strval', $game['domino_hands'][$botId] ?? [])),
            array_values($game['domino_chain'] ?? []),
            count($game['domino_stock'] ?? []),
            count($game['domino_hands'][$opponentId] ?? []),
            (string)($game['bot_difficulty'] ?? 'medium'),
            array_values(array_map('intval', $game['domino_void_values'][$opponentId] ?? []))
        );

        $type = (string)($action['type'] ?? 'draw');
        if ($type === 'play') {
            $this->performPlay(
                $db,
                $game,
                $botId,
                (string)($action['tile'] ?? ''),
                (string)($action['side'] ?? '')
            );
            return;
        }
        if ($type === 'draw') {
            $this->performDraw($db, $game, $botId);
            return;
        }

        $this->resolveAutomaticPasses($db, $game);
        if (($game['status'] ?? '') === 'active') $this->scheduleBotIfNeeded($game);
    }

    private function chooseStarter(array $hands, array $playerIds): array
    {
        $best = null;
        foreach ($playerIds as $playerId) {
            foreach ($hands[$playerId] ?? [] as $tile) {
                [$a, $b] = $this->parseTile((string)$tile);
                $isDouble = $a === $b ? 1 : 0;
                $score = $isDouble === 1
                    ? [1, $a, 0, 0]
                    : [0, $a + $b, max($a, $b), min($a, $b)];
                if ($best === null || $this->compareScore($score, $best['score']) > 0) {
                    $best = ['player_id' => (string)$playerId, 'tile' => (string)$tile, 'score' => $score];
                }
            }
        }
        if ($best === null) throw new RuntimeException('Не удалось определить первый ход.');
        return [$best['player_id'], $best['tile']];
    }

    private function compareScore(array $a, array $b): int
    {
        $length = max(count($a), count($b));
        for ($i = 0; $i < $length; $i++) {
            $left = (int)($a[$i] ?? 0);
            $right = (int)($b[$i] ?? 0);
            if ($left === $right) continue;
            return $left <=> $right;
        }
        return 0;
    }

    private function playableMap(array $hand, array $chain): array
    {
        $result = [];
        foreach ($hand as $tile) {
            $sides = $this->legalSides((string)$tile, $chain);
            if ($sides !== []) $result[(string)$tile] = $sides;
        }
        return $result;
    }

    private function hasLegalPlay(array $hand, array $chain): bool
    {
        foreach ($hand as $tile) {
            if ($this->legalSides((string)$tile, $chain) !== []) return true;
        }
        return false;
    }

    private function legalSides(string $tile, array $chain): array
    {
        if ($chain === []) return ['right'];
        [$a, $b] = $this->parseTile($tile);
        [$left, $right] = $this->openEnds($chain);
        $sides = [];
        if ($a === $left || $b === $left) $sides[] = 'left';
        if ($a === $right || $b === $right) $sides[] = 'right';
        return array_values(array_unique($sides));
    }

    private function orientedTile(string $tile, string $side, array $chain): array
    {
        [$a, $b] = $this->parseTile($tile);
        [$openLeft, $openRight] = $this->openEnds($chain);

        if ($side === 'left') {
            if ($b === $openLeft) return [$a, $b];
            if ($a === $openLeft) return [$b, $a];
        } else {
            if ($a === $openRight) return [$a, $b];
            if ($b === $openRight) return [$b, $a];
        }

        throw new RuntimeException('Эта костяшка не подходит к выбранному концу.');
    }

    private function openEnds(array $chain): array
    {
        if ($chain === []) return [null, null];
        $first = $chain[0] ?? [];
        $last = $chain[count($chain) - 1] ?? [];
        return [(int)($first['left'] ?? 0), (int)($last['right'] ?? 0)];
    }

    private function allTiles(): array
    {
        $tiles = [];
        for ($a = 0; $a <= 6; $a++) {
            for ($b = $a; $b <= 6; $b++) $tiles[] = $a . '-' . $b;
        }
        return $tiles;
    }

    private function publicTiles(array $tiles): array
    {
        return array_map(function (string $tile): array {
            [$a, $b] = $this->parseTile($tile);
            return ['id' => $tile, 'a' => $a, 'b' => $b, 'sum' => $a + $b, 'double' => $a === $b];
        }, array_values(array_map('strval', $tiles)));
    }

    private function handPoints(array $hand): int
    {
        $points = 0;
        foreach ($hand as $tile) {
            [$a, $b] = $this->parseTile((string)$tile);
            $points += $a + $b;
        }
        return $points;
    }

    private function sortHand(array &$hand): void
    {
        usort($hand, function (string $left, string $right): int {
            [$a1, $b1] = $this->parseTile($left);
            [$a2, $b2] = $this->parseTile($right);
            return $this->compareScore(
                [$a1 + $b1, max($a1, $b1), min($a1, $b1)],
                [$a2 + $b2, max($a2, $b2), min($a2, $b2)]
            );
        });
    }

    private function parseTile(string $tile): array
    {
        if (!preg_match('/^([0-6])-([0-6])$/', $tile, $matches)) {
            throw new RuntimeException('Некорректная костяшка.');
        }
        return [(int)$matches[1], (int)$matches[2]];
    }

    private function scheduleBotIfNeeded(array &$game): void
    {
        if (empty($game['is_bot_game'])) {
            unset($game['bot_move_after_at']);
            return;
        }
        $botId = (string)($game['bot_id'] ?? '');
        if ($botId !== '' && (string)($game['turn'] ?? '') === $botId && ($game['status'] ?? '') === 'active') {
            $game['bot_move_after_at'] = gmdate('c', time() + random_int(1, 3));
        } else {
            unset($game['bot_move_after_at']);
        }
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

    private function otherPlayerId(array $game, string $userId): string
    {
        foreach ($game['player_ids'] ?? [] as $playerId) {
            if ((string)$playerId !== $userId) return (string)$playerId;
        }
        return $userId;
    }
}
