<?php
declare(strict_types=1);

final class BattleshipService
{
    private const BOARD_SIZE = 10;
    private const FLEET_COUNTS = [4 => 1, 3 => 2, 2 => 3, 1 => 4];

    public function __construct(
        private array $config,
        private GameSettlementService $settlement,
        private ?BattleshipBotService $bot = null
    ) {
        $this->bot ??= new BattleshipBotService();
    }

    public function initializeGame(array &$game): void
    {
        if (!empty($game['battleship_initialized']) && $this->hasValidStateShape($game)) {
            return;
        }

        $playerIds = array_values(array_map('strval', $game['player_ids'] ?? []));
        if (count($playerIds) < 2) {
            throw new RuntimeException('Для Морского боя нужны два игрока.');
        }

        $now = now_iso();
        $game['game_type'] = 'battleship';
        $game['board_size'] = self::BOARD_SIZE;
        $game['board_columns'] = self::BOARD_SIZE;
        $game['board_rows'] = self::BOARD_SIZE;
        $game['phase'] = 'setup';
        $game['turn'] = '';
        $game['battleship_fleets'] = [];
        $game['battleship_shots'] = [];
        $game['battleship_last_shot'] = null;
        $game['battleship_last_result'] = null;
        $game['setup_started_at'] = $now;
        $game['setup_deadline_at'] = gmdate('c', time() + $this->setupTimeoutSec());
        $game['turn_started_at'] = $now;
        $game['last_move_at'] = $now;
        $game['updated_at'] = $now;
        $game['battleship_initialized'] = true;
        $game['engine_version'] = 1;

        foreach ($playerIds as $playerId) {
            $game['battleship_fleets'][$playerId] = [
                'ships' => [],
                'ready' => false,
                'ready_at' => null,
            ];
            $game['battleship_shots'][$playerId] = [];
        }

        if (!empty($game['is_bot_game'])) {
            $botId = (string)($game['bot_id'] ?? '');
            if ($botId !== '' && isset($game['battleship_fleets'][$botId])) {
                $game['battleship_fleets'][$botId]['ships'] = $this->generateFullFleet();
                $game['battleship_fleets'][$botId]['ready'] = true;
                $game['battleship_fleets'][$botId]['ready_at'] = $now;
            }
        }
    }

    public function cleanup(array &$db): void
    {
        if (!isset($db['games']) || !is_array($db['games'])) return;

        foreach ($db['games'] as &$game) {
            if (!is_array($game) || (string)($game['game_type'] ?? '') !== 'battleship') continue;

            $this->initializeGame($game);
            if (($game['status'] ?? '') !== 'active') continue;

            if (($game['phase'] ?? 'setup') === 'setup') {
                if ($this->setupExpired($game)) {
                    foreach (array_values(array_map('strval', $game['player_ids'] ?? [])) as $playerId) {
                        if (!empty($game['battleship_fleets'][$playerId]['ready'])) continue;
                        $ships = $this->sanitizeShips($game['battleship_fleets'][$playerId]['ships'] ?? []);
                        $game['battleship_fleets'][$playerId]['ships'] = $this->completeFleetPreservingMaximum($ships);
                        $game['battleship_fleets'][$playerId]['ready'] = true;
                        $game['battleship_fleets'][$playerId]['ready_at'] = now_iso();
                        $game['battleship_fleets'][$playerId]['auto_completed'] = true;
                    }
                    $this->beginBattleIfReady($game);
                } else {
                    $this->beginBattleIfReady($game);
                }
                continue;
            }

            if (($game['phase'] ?? '') !== 'battle') continue;

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

            $this->makeBotShot($db, $game);
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
        return match ($type) {
            'randomize_fleet' => $this->randomizeFleet($game, $userId),
            'clear_fleet' => $this->clearFleet($game, $userId),
            'place_ship' => $this->placeShip(
                $game,
                $userId,
                (int)($action['size'] ?? 0),
                (int)($action['cell'] ?? -1),
                (string)($action['orientation'] ?? 'h')
            ),
            'remove_ship' => $this->removeShip($game, $userId, (string)($action['ship_id'] ?? ''), $action['cell'] ?? null),
            'ready' => $this->markReady($game, $userId),
            'fire' => $this->fire($db, $game, $userId, (int)($action['cell'] ?? -1)),
            default => throw new RuntimeException('Некорректное действие для Морского боя.'),
        };
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
        $playerIds = array_values(array_map('strval', $game['player_ids'] ?? []));
        $opponentId = $this->otherPlayerId($game, $viewerId);
        $myFleet = $game['battleship_fleets'][$viewerId] ?? ['ships' => [], 'ready' => false];
        $enemyFleet = $game['battleship_fleets'][$opponentId] ?? ['ships' => [], 'ready' => false];
        $myShots = $this->normalizeShotMap($game['battleship_shots'][$viewerId] ?? []);
        $enemyShots = $this->normalizeShotMap($game['battleship_shots'][$opponentId] ?? []);
        $phase = (string)($game['phase'] ?? 'setup');

        $players = [];
        foreach ($playerIds as $playerId) {
            $players[] = [
                'id' => $playerId,
                'name' => (string)($game['player_names'][$playerId] ?? 'Игрок'),
                'symbol' => $playerId === $viewerId ? 'YOU' : 'ENEMY',
                'ready' => !empty($game['battleship_fleets'][$playerId]['ready']),
            ];
        }

        $timeLeft = $phase === 'setup' ? $this->setupTimeLeft($game) : $this->battleTimeLeft($game);
        $myShips = $this->publicOwnShips($this->sanitizeShips($myFleet['ships'] ?? []));
        $myBoard = $this->buildOwnBoard($myShips, $enemyShots);
        $enemyBoard = $this->buildEnemyBoard($myShots);
        $required = [];
        foreach (self::FLEET_COUNTS as $size => $count) {
            $required[] = ['size' => $size, 'count' => $count];
        }

        return [
            'id' => (string)($game['id'] ?? ''),
            'room' => (string)($game['room'] ?? 'match'),
            'room_name' => ($game['room'] ?? 'match') === 'gold' ? 'Gold-комната' : 'Матч-комната',
            'bet' => (int)($game['bet'] ?? 0),
            'board_size' => self::BOARD_SIZE,
            'board_columns' => self::BOARD_SIZE,
            'board_rows' => self::BOARD_SIZE,
            'phase' => $phase,
            'turn' => (string)($game['turn'] ?? ''),
            'players' => $players,
            'status' => (string)($game['status'] ?? 'active'),
            'winner_id' => $game['winner_id'] ?? null,
            'loser_id' => $game['loser_id'] ?? null,
            'finish_reason' => $game['finish_reason'] ?? null,
            'payout' => $game['payout'] ?? null,
            'commission' => (int)($game['commission'] ?? 0),
            'time_left' => $timeLeft,
            'setup_time_left' => $this->setupTimeLeft($game),
            'move_timeout_sec' => $this->moveTimeoutSec(),
            'setup_timeout_sec' => $this->setupTimeoutSec(),
            'is_bot_game' => !empty($game['is_bot_game']),
            'my_ready' => !empty($myFleet['ready']),
            'opponent_ready' => !empty($enemyFleet['ready']),
            'fleet_required' => $required,
            'fleet_placed' => $this->fleetCountSummary($myShips),
            'remaining_to_place' => $this->remainingFleetSummary($myShips),
            'my_fleet' => $myShips,
            'my_board' => $myBoard,
            'enemy_board' => $enemyBoard,
            'my_ships_remaining' => $this->unsunkShipCount($myShips),
            'enemy_ships_remaining' => $this->unsunkShipCount($this->sanitizeShips($enemyFleet['ships'] ?? [])),
            'last_shot' => isset($game['battleship_last_shot']) ? (int)$game['battleship_last_shot'] : null,
            'last_result' => $game['battleship_last_result'] ?? null,
            'last_shooter_id' => $game['battleship_last_shooter_id'] ?? null,
        ];
    }

    private function randomizeFleet(array &$game, string $userId): array
    {
        $this->assertSetupEditable($game, $userId);
        $game['battleship_fleets'][$userId]['ships'] = $this->generateFullFleet();
        $game['updated_at'] = now_iso();
        return $game;
    }

    private function clearFleet(array &$game, string $userId): array
    {
        $this->assertSetupEditable($game, $userId);
        $game['battleship_fleets'][$userId]['ships'] = [];
        $game['updated_at'] = now_iso();
        return $game;
    }

    private function placeShip(array &$game, string $userId, int $size, int $cell, string $orientation): array
    {
        $this->assertSetupEditable($game, $userId);
        if (!isset(self::FLEET_COUNTS[$size])) throw new RuntimeException('Выберите корабль из своего флота.');
        if ($cell < 0 || $cell >= 100) throw new RuntimeException('Выберите клетку на поле.');
        $orientation = $orientation === 'v' ? 'v' : 'h';

        $ships = $this->sanitizeShips($game['battleship_fleets'][$userId]['ships'] ?? []);
        $counts = $this->countShipsBySize($ships);
        if (($counts[$size] ?? 0) >= self::FLEET_COUNTS[$size]) {
            throw new RuntimeException('Все корабли этого размера уже размещены.');
        }

        $cells = $this->shipCells($cell, $size, $orientation);
        if ($cells === null || !$this->canPlaceCells($cells, $ships)) {
            throw new RuntimeException('Здесь корабль разместить нельзя.');
        }

        $ships[] = $this->newShip($size, $cells);
        $game['battleship_fleets'][$userId]['ships'] = $ships;
        $game['updated_at'] = now_iso();
        return $game;
    }

    private function removeShip(array &$game, string $userId, string $shipId, mixed $cell): array
    {
        $this->assertSetupEditable($game, $userId);
        $ships = $this->sanitizeShips($game['battleship_fleets'][$userId]['ships'] ?? []);
        $cellInt = filter_var($cell, FILTER_VALIDATE_INT);
        $removed = false;

        $ships = array_values(array_filter($ships, function (array $ship) use ($shipId, $cellInt, &$removed): bool {
            $match = ($shipId !== '' && (string)($ship['id'] ?? '') === $shipId)
                || ($cellInt !== false && in_array((int)$cellInt, array_map('intval', $ship['cells'] ?? []), true));
            if ($match && !$removed) {
                $removed = true;
                return false;
            }
            return true;
        }));

        if (!$removed) throw new RuntimeException('Корабль не найден.');
        $game['battleship_fleets'][$userId]['ships'] = $ships;
        $game['updated_at'] = now_iso();
        return $game;
    }

    private function markReady(array &$game, string $userId): array
    {
        if (($game['phase'] ?? '') !== 'setup') throw new RuntimeException('Расстановка уже завершена.');
        if (!empty($game['battleship_fleets'][$userId]['ready'])) return $game;

        $ships = $this->sanitizeShips($game['battleship_fleets'][$userId]['ships'] ?? []);
        if (!$this->isCompleteFleet($ships)) {
            throw new RuntimeException('Сначала разместите все 10 кораблей.');
        }

        $game['battleship_fleets'][$userId]['ships'] = $ships;
        $game['battleship_fleets'][$userId]['ready'] = true;
        $game['battleship_fleets'][$userId]['ready_at'] = now_iso();
        $game['updated_at'] = now_iso();
        $this->beginBattleIfReady($game);
        return $game;
    }

    private function fire(array &$db, array &$game, string $shooterId, int $cell): array
    {
        if (($game['phase'] ?? '') !== 'battle') throw new RuntimeException('Сначала завершите расстановку кораблей.');
        if ($cell < 0 || $cell >= 100) throw new RuntimeException('Выберите клетку для выстрела.');
        if ((string)($game['turn'] ?? '') !== $shooterId) throw new RuntimeException('Сейчас не ваш ход.');

        $shots = $this->normalizeShotMap($game['battleship_shots'][$shooterId] ?? []);
        if (isset($shots[$cell])) throw new RuntimeException('Вы уже стреляли в эту клетку.');

        $targetId = $this->otherPlayerId($game, $shooterId);
        return $this->resolveShot($db, $game, $shooterId, $targetId, $cell);
    }

    private function resolveShot(array &$db, array &$game, string $shooterId, string $targetId, int $cell): array
    {
        $ships = $this->sanitizeShips($game['battleship_fleets'][$targetId]['ships'] ?? []);
        $hitIndex = null;
        foreach ($ships as $index => $ship) {
            if (in_array($cell, array_map('intval', $ship['cells'] ?? []), true)) {
                $hitIndex = $index;
                break;
            }
        }

        $result = 'miss';
        if ($hitIndex === null) {
            $game['battleship_shots'][$shooterId][(string)$cell] = 'miss';
            $game['turn'] = $targetId;
        } else {
            $hits = array_values(array_unique(array_map('intval', $ships[$hitIndex]['hits'] ?? [])));
            if (!in_array($cell, $hits, true)) $hits[] = $cell;
            sort($hits);
            $ships[$hitIndex]['hits'] = $hits;
            $isSunk = count($hits) >= (int)$ships[$hitIndex]['size'];
            $ships[$hitIndex]['sunk'] = $isSunk;
            $game['battleship_fleets'][$targetId]['ships'] = $ships;

            if ($isSunk) {
                $result = 'sunk';
                foreach ($ships[$hitIndex]['cells'] as $shipCell) {
                    $game['battleship_shots'][$shooterId][(string)(int)$shipCell] = 'sunk';
                }
            } else {
                $result = 'hit';
                $game['battleship_shots'][$shooterId][(string)$cell] = 'hit';
            }

            if ($this->allShipsSunk($ships)) {
                $game['battleship_last_shot'] = $cell;
                $game['battleship_last_result'] = $result;
                $game['battleship_last_shooter_id'] = $shooterId;
                $game['last_move_at'] = now_iso();
                $game['updated_at'] = now_iso();
                $this->settlement->finish($db, $game, $shooterId, 'normal_win', $targetId);
                return $game;
            }

            $game['turn'] = $shooterId;
        }

        $game['battleship_last_shot'] = $cell;
        $game['battleship_last_result'] = $result;
        $game['battleship_last_shooter_id'] = $shooterId;
        $game['turn_started_at'] = now_iso();
        $game['last_move_at'] = now_iso();
        $game['updated_at'] = now_iso();
        unset($game['bot_move_after_at']);

        $nextTurn = (string)($game['turn'] ?? '');
        if (!empty($game['is_bot_game']) && $nextTurn === (string)($game['bot_id'] ?? '')) {
            $game['bot_move_after_at'] = gmdate('c', time() + $this->botMoveDelaySec());
        }

        return $game;
    }

    private function makeBotShot(array &$db, array &$game): void
    {
        $botId = (string)($game['bot_id'] ?? '');
        if ($botId === '' || (string)($game['turn'] ?? '') !== $botId) return;
        $humanId = $this->otherPlayerId($game, $botId);
        $shots = $this->normalizeShotMap($game['battleship_shots'][$botId] ?? []);
        $remainingSizes = [];
        foreach ($this->sanitizeShips($game['battleship_fleets'][$humanId]['ships'] ?? []) as $ship) {
            if (empty($ship['sunk'])) $remainingSizes[] = (int)$ship['size'];
        }

        $cell = $this->bot->chooseTarget($shots, $remainingSizes, (string)($game['bot_difficulty'] ?? 'medium'));
        if ($cell === null) {
            $this->settlement->finish($db, $game, null, 'draw');
            return;
        }

        $this->resolveShot($db, $game, $botId, $humanId, $cell);
    }

    private function beginBattleIfReady(array &$game): void
    {
        if (($game['phase'] ?? '') !== 'setup') return;
        $playerIds = array_values(array_map('strval', $game['player_ids'] ?? []));
        if (count($playerIds) < 2) return;

        foreach ($playerIds as $playerId) {
            if (empty($game['battleship_fleets'][$playerId]['ready'])) return;
            $ships = $this->sanitizeShips($game['battleship_fleets'][$playerId]['ships'] ?? []);
            if (!$this->isCompleteFleet($ships)) {
                $game['battleship_fleets'][$playerId]['ships'] = $this->completeFleetPreservingMaximum($ships);
            }
        }

        $first = $playerIds[random_int(0, count($playerIds) - 1)];
        $game['phase'] = 'battle';
        $game['turn'] = $first;
        $game['battle_started_at'] = now_iso();
        $game['turn_started_at'] = now_iso();
        $game['last_move_at'] = now_iso();
        $game['updated_at'] = now_iso();
        unset($game['setup_deadline_at']);

        if (!empty($game['is_bot_game']) && $first === (string)($game['bot_id'] ?? '')) {
            $game['bot_move_after_at'] = gmdate('c', time() + $this->botMoveDelaySec());
        }
    }

    private function assertSetupEditable(array $game, string $userId): void
    {
        if (($game['phase'] ?? '') !== 'setup') throw new RuntimeException('Расстановка уже завершена.');
        if (!empty($game['battleship_fleets'][$userId]['ready'])) throw new RuntimeException('Флот уже подтверждён.');
    }

    private function generateFullFleet(): array
    {
        $result = $this->fillMissingFleet([]);
        if ($result === null) throw new RuntimeException('Не удалось автоматически расставить флот.');
        return $result;
    }

    private function completeFleetPreservingMaximum(array $ships): array
    {
        $ships = $this->sanitizeShips($ships);
        $direct = $this->fillMissingFleet($ships);
        if ($direct !== null) return $direct;

        $count = count($ships);
        for ($removeCount = 1; $removeCount <= $count; $removeCount++) {
            foreach ($this->indexCombinations($count, $removeCount) as $removeIndexes) {
                $removeSet = array_fill_keys($removeIndexes, true);
                $preserved = [];
                foreach ($ships as $index => $ship) {
                    if (!isset($removeSet[$index])) $preserved[] = $ship;
                }
                $completed = $this->fillMissingFleet($preserved);
                if ($completed !== null) return $completed;
            }
        }

        return $this->generateFullFleet();
    }

    private function fillMissingFleet(array $preserved): ?array
    {
        $preserved = $this->sanitizeShips($preserved);
        if (!$this->shipsRespectFleetLimits($preserved) || !$this->shipsDoNotTouch($preserved)) return null;

        $counts = $this->countShipsBySize($preserved);
        $missing = [];
        foreach (self::FLEET_COUNTS as $size => $required) {
            $need = $required - ($counts[$size] ?? 0);
            for ($i = 0; $i < $need; $i++) $missing[] = $size;
        }
        rsort($missing);

        $ships = $preserved;
        return $this->backtrackPlaceMissing($ships, $missing, 0) ? $ships : null;
    }

    private function backtrackPlaceMissing(array &$ships, array $missing, int $index): bool
    {
        if ($index >= count($missing)) return true;
        $size = (int)$missing[$index];
        $candidates = $this->candidatePlacements($size);
        shuffle($candidates);

        foreach ($candidates as $cells) {
            if (!$this->canPlaceCells($cells, $ships)) continue;
            $ships[] = $this->newShip($size, $cells);
            if ($this->backtrackPlaceMissing($ships, $missing, $index + 1)) return true;
            array_pop($ships);
        }

        return false;
    }

    private function candidatePlacements(int $size): array
    {
        $candidates = [];
        foreach (['h', 'v'] as $orientation) {
            for ($row = 0; $row < 10; $row++) {
                for ($col = 0; $col < 10; $col++) {
                    $cells = $this->shipCells($row * 10 + $col, $size, $orientation);
                    if ($cells !== null) $candidates[] = $cells;
                }
            }
        }
        return $candidates;
    }

    private function shipCells(int $startCell, int $size, string $orientation): ?array
    {
        $row = intdiv($startCell, 10);
        $col = $startCell % 10;
        $cells = [];
        for ($step = 0; $step < $size; $step++) {
            $r = $row + ($orientation === 'v' ? $step : 0);
            $c = $col + ($orientation === 'h' ? $step : 0);
            if ($r < 0 || $r >= 10 || $c < 0 || $c >= 10) return null;
            $cells[] = $r * 10 + $c;
        }
        return $cells;
    }

    private function canPlaceCells(array $cells, array $ships): bool
    {
        $occupied = [];
        foreach ($ships as $ship) {
            foreach ($ship['cells'] ?? [] as $cell) $occupied[(int)$cell] = true;
        }

        foreach ($cells as $cell) {
            $cell = (int)$cell;
            if ($cell < 0 || $cell >= 100 || isset($occupied[$cell])) return false;
            $row = intdiv($cell, 10);
            $col = $cell % 10;
            for ($dr = -1; $dr <= 1; $dr++) {
                for ($dc = -1; $dc <= 1; $dc++) {
                    $r = $row + $dr;
                    $c = $col + $dc;
                    if ($r < 0 || $r >= 10 || $c < 0 || $c >= 10) continue;
                    if (isset($occupied[$r * 10 + $c])) return false;
                }
            }
        }
        return true;
    }

    private function shipsDoNotTouch(array $ships): bool
    {
        $accepted = [];
        foreach ($ships as $ship) {
            $cells = array_values(array_unique(array_map('intval', $ship['cells'] ?? [])));
            if (count($cells) !== (int)($ship['size'] ?? 0) || !$this->canPlaceCells($cells, $accepted)) return false;
            $accepted[] = $ship;
        }
        return true;
    }

    private function shipsRespectFleetLimits(array $ships): bool
    {
        $counts = $this->countShipsBySize($ships);
        foreach ($counts as $size => $count) {
            if (!isset(self::FLEET_COUNTS[$size]) || $count > self::FLEET_COUNTS[$size]) return false;
        }
        return true;
    }

    private function isCompleteFleet(array $ships): bool
    {
        if (count($ships) !== 10 || !$this->shipsRespectFleetLimits($ships) || !$this->shipsDoNotTouch($ships)) return false;
        $counts = $this->countShipsBySize($ships);
        foreach (self::FLEET_COUNTS as $size => $required) {
            if (($counts[$size] ?? 0) !== $required) return false;
        }
        return true;
    }

    private function sanitizeShips(array $ships): array
    {
        $clean = [];
        foreach ($ships as $ship) {
            if (!is_array($ship)) continue;
            $size = (int)($ship['size'] ?? 0);
            $cells = array_values(array_unique(array_map('intval', $ship['cells'] ?? [])));
            sort($cells);
            if (!isset(self::FLEET_COUNTS[$size]) || count($cells) !== $size) continue;
            $hits = array_values(array_intersect($cells, array_unique(array_map('intval', $ship['hits'] ?? []))));
            sort($hits);
            $clean[] = [
                'id' => (string)($ship['id'] ?? make_id('ship')),
                'size' => $size,
                'cells' => $cells,
                'hits' => $hits,
                'sunk' => count($hits) >= $size,
            ];
        }
        return $clean;
    }

    private function newShip(int $size, array $cells): array
    {
        sort($cells);
        return [
            'id' => make_id('ship'),
            'size' => $size,
            'cells' => array_values(array_map('intval', $cells)),
            'hits' => [],
            'sunk' => false,
        ];
    }

    private function countShipsBySize(array $ships): array
    {
        $counts = [];
        foreach ($ships as $ship) {
            $size = (int)($ship['size'] ?? 0);
            $counts[$size] = ($counts[$size] ?? 0) + 1;
        }
        return $counts;
    }

    private function fleetCountSummary(array $ships): array
    {
        $counts = $this->countShipsBySize($ships);
        $summary = [];
        foreach (self::FLEET_COUNTS as $size => $required) {
            $summary[] = ['size' => $size, 'placed' => min($required, (int)($counts[$size] ?? 0)), 'required' => $required];
        }
        return $summary;
    }

    private function remainingFleetSummary(array $ships): array
    {
        $counts = $this->countShipsBySize($ships);
        $summary = [];
        foreach (self::FLEET_COUNTS as $size => $required) {
            $remaining = max(0, $required - (int)($counts[$size] ?? 0));
            if ($remaining > 0) $summary[] = ['size' => $size, 'count' => $remaining];
        }
        return $summary;
    }

    private function publicOwnShips(array $ships): array
    {
        return array_map(function (array $ship): array {
            return [
                'id' => (string)$ship['id'],
                'size' => (int)$ship['size'],
                'cells' => array_values(array_map('intval', $ship['cells'] ?? [])),
                'hits' => array_values(array_map('intval', $ship['hits'] ?? [])),
                'sunk' => !empty($ship['sunk']),
            ];
        }, $ships);
    }

    private function buildOwnBoard(array $ships, array $enemyShots): array
    {
        $board = array_fill(0, 100, 'water');
        foreach ($ships as $ship) {
            foreach ($ship['cells'] as $cell) $board[(int)$cell] = !empty($ship['sunk']) ? 'sunk' : 'ship';
        }
        foreach ($enemyShots as $cell => $result) {
            $board[(int)$cell] = (string)$result;
        }
        return $board;
    }

    private function buildEnemyBoard(array $myShots): array
    {
        $board = array_fill(0, 100, 'unknown');
        foreach ($myShots as $cell => $result) $board[(int)$cell] = (string)$result;
        return $board;
    }

    private function normalizeShotMap(array $shots): array
    {
        $clean = [];
        foreach ($shots as $cell => $result) {
            $index = (int)$cell;
            $result = (string)$result;
            if ($index < 0 || $index >= 100 || !in_array($result, ['miss', 'hit', 'sunk'], true)) continue;
            $clean[$index] = $result;
        }
        return $clean;
    }

    private function unsunkShipCount(array $ships): int
    {
        $count = 0;
        foreach ($ships as $ship) if (empty($ship['sunk'])) $count++;
        return $count;
    }

    private function allShipsSunk(array $ships): bool
    {
        return $ships !== [] && $this->unsunkShipCount($ships) === 0;
    }

    private function hasValidStateShape(array $game): bool
    {
        return isset($game['battleship_fleets'], $game['battleship_shots'])
            && is_array($game['battleship_fleets'])
            && is_array($game['battleship_shots']);
    }

    private function indexCombinations(int $count, int $choose): array
    {
        $result = [];
        $walk = function (int $start, array $current) use (&$walk, &$result, $count, $choose): void {
            if (count($current) === $choose) {
                $result[] = $current;
                return;
            }
            for ($i = $start; $i < $count; $i++) {
                $next = $current;
                $next[] = $i;
                $walk($i + 1, $next);
            }
        };
        $walk(0, []);
        return $result;
    }

    private function otherPlayerId(array $game, string $userId): string
    {
        foreach ($game['player_ids'] ?? [] as $playerId) {
            if ((string)$playerId !== $userId) return (string)$playerId;
        }
        return $userId;
    }

    private function setupExpired(array $game): bool
    {
        $deadline = strtotime((string)($game['setup_deadline_at'] ?? '')) ?: 0;
        return $deadline > 0 && time() >= $deadline;
    }

    private function setupTimeLeft(array $game): int
    {
        if (($game['phase'] ?? '') !== 'setup') return 0;
        $deadline = strtotime((string)($game['setup_deadline_at'] ?? '')) ?: 0;
        return $deadline > 0 ? max(0, $deadline - time()) : $this->setupTimeoutSec();
    }

    private function battleTimeLeft(array $game): int
    {
        if (($game['phase'] ?? '') !== 'battle' || ($game['status'] ?? '') !== 'active') return 0;
        $started = strtotime((string)($game['turn_started_at'] ?? $game['last_move_at'] ?? '')) ?: time();
        return max(0, $this->moveTimeoutSec() - (time() - $started));
    }

    private function isTurnExpired(array $game): bool
    {
        return ($game['phase'] ?? '') === 'battle' && $this->battleTimeLeft($game) <= 0;
    }

    private function setupTimeoutSec(): int
    {
        $value = (int)($this->config['battleship_setup_timeout_sec'] ?? 120);
        return max(60, min(300, $value));
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
}
