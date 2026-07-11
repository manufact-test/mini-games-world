<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/games/battleship/BattleshipBotService.php';
require_once dirname(__DIR__) . '/games/battleship/BattleshipService.php';
require_once dirname(__DIR__) . '/games/checkers/CheckersBotService.php';
require_once dirname(__DIR__) . '/games/checkers/CheckersService.php';

final class GameRuntimeService
{
    private FourInARowService $fourInARow;
    private BattleshipService $battleship;
    private CheckersService $checkers;

    public function __construct(
        private array $config,
        private GameCatalogService $catalog,
        private GameService $legacyGame
    ) {
        $settlement = new GameSettlementService($this->config);
        $this->fourInARow = new FourInARowService($this->config, $settlement);
        $this->battleship = new BattleshipService($this->config, $settlement);
        $this->checkers = new CheckersService($this->config, $settlement);
    }

    public function cleanup(array &$db): void
    {
        $this->normalizeDatabaseGameTypes($db);

        $nonLegacyGames = [];
        foreach ($db['games'] ?? [] as $gameId => $game) {
            if (!is_array($game)) continue;
            $gameType = $this->gameTypeFromRecord($game);
            if ($gameType === 'tictactoe') continue;
            $nonLegacyGames[(string)$gameId] = $game;
            unset($db['games'][$gameId]);
        }

        $this->legacyGame->cleanup($db);

        foreach ($nonLegacyGames as $gameId => $game) {
            $db['games'][$gameId] = $game;
        }

        $this->fourInARow->cleanup($db);
        $this->battleship->cleanup($db);
        $this->checkers->cleanup($db);
    }

    public function cleanupQueue(array &$db): void
    {
        $this->normalizeDatabaseGameTypes($db);
        $this->legacyGame->cleanupQueue($db);
    }

    public function refreshSearch(array &$db, array &$user): void
    {
        $this->normalizeDatabaseGameTypes($db);
        $this->legacyGame->refreshSearch($db, $user);
    }

    public function maybeCreateBotGameForSearchingUser(array &$db, array &$user): ?array
    {
        $this->normalizeDatabaseGameTypes($db);
        $queueItem = $this->queueItemForUser($db, (string)($user['id'] ?? ''));
        if (!$queueItem) return null;

        $gameType = $this->gameTypeFromRecord($queueItem);
        if (!$this->catalog->supportsBot($gameType)) return null;

        $requestedBoardSize = $this->requestedBoardSizeFromQueue($gameType, $queueItem);
        $game = $this->withIsolatedQueue(
            $db,
            $gameType,
            function () use (&$db, &$user): ?array {
                return $this->legacyGame->maybeCreateBotGameForSearchingUser($db, $user);
            }
        );

        if (!is_array($game)) return null;
        $gameId = (string)($game['id'] ?? '');
        if ($gameId === '' || !isset($db['games'][$gameId]) || !is_array($db['games'][$gameId])) {
            return $game;
        }

        $db['games'][$gameId]['game_type'] = $gameType;
        $this->applyRequestedBoardMetadata($db['games'][$gameId], $gameType, $requestedBoardSize);
        $this->initializeEngineGame($db['games'][$gameId]);
        $this->syncGameMetadataTransactions($db, $gameId);
        $this->rebalanceNewBotDifficulty($db, $user, $gameId);

        return $db['games'][$gameId];
    }

    public function startSearch(
        array &$db,
        array &$user,
        string $room,
        int $bet,
        int $boardSize,
        ?string $gameType = null
    ): array {
        $this->normalizeDatabaseGameTypes($db);
        $gameType = $this->catalog->normalizeGameType($gameType);
        $room = $room === 'gold' ? 'gold' : 'match';

        if (!$this->catalog->supportsRoom($gameType, $room)) {
            throw new RuntimeException('Эта игра недоступна в выбранной комнате.');
        }

        $userId = (string)($user['id'] ?? '');
        $active = $this->findActiveGameForUser($db, $userId);
        if ($active) return ['game' => $this->publicGame($active, $userId)];

        $boardSize = $this->catalog->normalizeBoardSize($gameType, $boardSize);
        $definition = $this->catalog->get($gameType);
        $engine = (string)($definition['engine'] ?? '');

        if (!in_array($engine, ['tictactoe', 'four_in_a_row', 'battleship', 'checkers'], true)) {
            throw new RuntimeException('Движок этой игры пока не подключён.');
        }

        $legacyBoardSize = $engine === 'tictactoe'
            ? $boardSize
            : $this->legacyProxyBoardSize($boardSize);

        $result = $this->withIsolatedQueue(
            $db,
            $gameType,
            function () use (&$db, &$user, $room, $bet, $legacyBoardSize): array {
                return $this->legacyGame->startSearch($db, $user, $room, $bet, $legacyBoardSize);
            },
            $userId
        );

        if (isset($result['game']) && is_array($result['game'])) {
            $gameId = (string)($result['game']['id'] ?? '');
            if ($gameId !== '' && isset($db['games'][$gameId]) && is_array($db['games'][$gameId])) {
                $db['games'][$gameId]['game_type'] = $gameType;
                $this->applyRequestedBoardMetadata($db['games'][$gameId], $gameType, $boardSize);
                $this->initializeEngineGame($db['games'][$gameId]);
                $this->syncGameMetadataTransactions($db, $gameId);
                $result['game'] = $this->publicGame($db['games'][$gameId], $userId);
            }
        } else {
            $this->setQueuedGameType($db, $userId, $gameType, $boardSize);
        }

        return $result;
    }

    public function leaveSearch(array &$db, array &$user): void
    {
        $this->legacyGame->leaveSearch($db, $user);
    }

    public function surrenderGame(array &$db, array &$user, string $gameId): array
    {
        $this->normalizeDatabaseGameTypes($db);
        $game = $db['games'][$gameId] ?? null;

        if (is_array($game)) {
            return match ($this->gameTypeFromRecord($game)) {
                'four_in_a_row' => $this->fourInARow->surrender($db, $user, $gameId),
                'battleship' => $this->battleship->surrender($db, $user, $gameId),
                'checkers' => $this->checkers->surrender($db, $user, $gameId),
                default => $this->surrenderLegacyGame($db, $user, $gameId),
            };
        }

        return $this->legacyGame->surrenderGame($db, $user, $gameId);
    }

    public function findActiveGameForUser(array $db, string $userId): ?array
    {
        $game = $this->legacyGame->findActiveGameForUser($db, $userId);
        if (!$game) return null;
        $this->ensureGameType($game);
        return $game;
    }

    public function makeMove(array &$db, array &$user, string $gameId, int $cell): array
    {
        $this->normalizeDatabaseGameTypes($db);
        $game = $db['games'][$gameId] ?? null;

        if (is_array($game)) {
            $definition = $this->catalog->get($this->gameTypeFromRecord($game));
            if ((string)($definition['engine'] ?? '') !== 'tictactoe') {
                throw new RuntimeException('Это действие не поддерживается выбранной игрой.');
            }
        }

        $result = $this->legacyGame->makeMove($db, $user, $gameId, $cell);
        if (isset($db['games'][$gameId]) && is_array($db['games'][$gameId])) {
            $this->ensureGameType($db['games'][$gameId]);
            return $db['games'][$gameId];
        }
        $this->ensureGameType($result);
        return $result;
    }

    public function dropFourInARowDisc(array &$db, array &$user, string $gameId, int $column): array
    {
        return $this->fourInARow->dropDisc($db, $user, $gameId, $column);
    }

    public function applyBattleshipAction(array &$db, array &$user, string $gameId, array $action): array
    {
        return $this->battleship->applyAction($db, $user, $gameId, $action);
    }

    public function applyCheckersAction(array &$db, array &$user, string $gameId, array $action): array
    {
        return $this->checkers->applyAction($db, $user, $gameId, $action);
    }

    public function publicGame(array $game, string $viewerId): array
    {
        $this->ensureGameType($game);
        $gameType = $this->gameTypeFromRecord($game);
        $definition = $this->catalog->publicGameDefinition($gameType);

        $public = match ($gameType) {
            'four_in_a_row' => $this->fourInARow->publicGame($game, $viewerId),
            'battleship' => $this->battleship->publicGame($game, $viewerId),
            'checkers' => $this->checkers->publicGame($game, $viewerId),
            default => $this->legacyGame->publicGame($game, $viewerId),
        };

        return [
            'game_type' => $gameType,
            'game_title' => (string)$definition['title'],
            'renderer' => (string)$definition['renderer'],
            'action_type' => (string)$definition['action_type'],
        ] + $public;
    }

    public function catalog(): array
    {
        return $this->catalog->publicCatalog();
    }

    private function surrenderLegacyGame(array &$db, array &$user, string $gameId): array
    {
        $result = $this->legacyGame->surrenderGame($db, $user, $gameId);
        $this->ensureGameType($result);
        if (isset($db['games'][$gameId]) && is_array($db['games'][$gameId])) {
            $this->ensureGameType($db['games'][$gameId]);
            return $db['games'][$gameId];
        }
        return $result;
    }

    private function initializeEngineGame(array &$game): void
    {
        match ($this->gameTypeFromRecord($game)) {
            'four_in_a_row' => $this->fourInARow->initializeGame($game),
            'battleship' => $this->battleship->initializeGame($game),
            'checkers' => $this->checkers->initializeGame($game),
            default => null,
        };
    }

    private function applyRequestedBoardMetadata(array &$game, string $gameType, int $boardSize): void
    {
        if ($gameType === 'four_in_a_row') {
            $game['game_variant_size'] = $boardSize;
            $game['board_size'] = $boardSize;
            return;
        }

        if ($gameType === 'battleship') {
            $game['board_size'] = 10;
            $game['board_columns'] = 10;
            $game['board_rows'] = 10;
            return;
        }

        if ($gameType === 'checkers') {
            $game['board_size'] = 8;
            $game['board_columns'] = 8;
            $game['board_rows'] = 8;
        }
    }

    /**
     * Runs the legacy matcher against only one queue type and only legacy game records.
     * Non-legacy games are temporarily hidden because GameService performs its own cleanup.
     */
    private function withIsolatedQueue(
        array &$db,
        string $gameType,
        callable $callback,
        ?string $dropUserId = null
    ): mixed {
        $originalQueue = is_array($db['queue'] ?? null) ? array_values($db['queue']) : [];
        $originalGames = is_array($db['games'] ?? null) ? $db['games'] : [];
        $workingQueue = [];
        $legacyGames = [];

        foreach ($originalQueue as $item) {
            if (!is_array($item)) continue;
            if ($dropUserId !== null && (string)($item['user_id'] ?? '') === $dropUserId) continue;
            if ($this->gameTypeFromRecord($item) === $gameType) $workingQueue[] = $item;
        }

        foreach ($originalGames as $id => $game) {
            if (!is_array($game)) continue;
            if ($this->gameTypeFromRecord($game) === 'tictactoe') $legacyGames[$id] = $game;
        }

        $db['queue'] = $workingQueue;
        $db['games'] = $legacyGames;

        try {
            return $callback();
        } finally {
            $updatedWorkingQueue = is_array($db['queue'] ?? null) ? array_values($db['queue']) : [];
            $updatedLegacyGames = is_array($db['games'] ?? null) ? $db['games'] : [];
            $db['queue'] = $this->mergeIsolatedQueue($originalQueue, $updatedWorkingQueue, $gameType, $dropUserId);
            $db['games'] = $this->mergeLegacyGameIsolation($originalGames, $updatedLegacyGames);
        }
    }

    private function mergeLegacyGameIsolation(array $originalGames, array $updatedLegacyGames): array
    {
        $merged = [];
        foreach ($originalGames as $id => $game) {
            if (!is_array($game)) {
                $merged[$id] = $game;
                continue;
            }
            if ($this->gameTypeFromRecord($game) !== 'tictactoe') {
                $merged[$id] = $game;
                continue;
            }
            if (array_key_exists($id, $updatedLegacyGames)) {
                $merged[$id] = $updatedLegacyGames[$id];
                unset($updatedLegacyGames[$id]);
            }
        }
        foreach ($updatedLegacyGames as $id => $game) $merged[$id] = $game;
        return $merged;
    }

    private function mergeIsolatedQueue(
        array $originalQueue,
        array $updatedWorkingQueue,
        string $gameType,
        ?string $dropUserId
    ): array {
        $updatedById = [];
        $updatedWithoutId = [];
        foreach ($updatedWorkingQueue as $item) {
            if (!is_array($item)) continue;
            $id = trim((string)($item['id'] ?? ''));
            if ($id !== '') $updatedById[$id] = $item;
            else $updatedWithoutId[] = $item;
        }

        $merged = [];
        foreach ($originalQueue as $item) {
            if (!is_array($item)) continue;
            if ($dropUserId !== null && (string)($item['user_id'] ?? '') === $dropUserId) continue;
            if ($this->gameTypeFromRecord($item) !== $gameType) {
                $merged[] = $item;
                continue;
            }
            $id = trim((string)($item['id'] ?? ''));
            if ($id !== '' && isset($updatedById[$id])) {
                $merged[] = $updatedById[$id];
                unset($updatedById[$id]);
            }
        }

        foreach ($updatedById as $item) $merged[] = $item;
        foreach ($updatedWithoutId as $item) $merged[] = $item;
        return array_values($merged);
    }

    private function rebalanceNewBotDifficulty(array &$db, array $user, string $gameId): void
    {
        $game = $db['games'][$gameId] ?? null;
        if (!is_array($game) || empty($game['is_bot_game'])) return;

        $difficulty = $this->chooseBotDifficulty($user);
        $db['games'][$gameId]['bot_difficulty'] = $difficulty;
        if (!isset($db['transactions']) || !is_array($db['transactions'])) return;

        foreach ($db['transactions'] as &$transaction) {
            if (
                !is_array($transaction)
                || (string)($transaction['game_id'] ?? '') !== $gameId
                || !array_key_exists('bot_difficulty', $transaction)
            ) continue;

            $transaction['bot_difficulty'] = $difficulty;
            $transaction['game_type'] = (string)($db['games'][$gameId]['game_type'] ?? 'tictactoe');
        }
        unset($transaction);
    }

    private function chooseBotDifficulty(array $user): string
    {
        $stats = is_array($user['stats'] ?? null) ? $user['stats'] : [];
        $games = (int)($stats['games_played'] ?? 0);
        $wins = (int)($stats['wins'] ?? 0);
        $botGames = (int)($stats['bot_games_played'] ?? 0);
        $botWins = (int)($stats['bot_wins'] ?? 0);
        $botStreak = (int)($stats['bot_win_streak'] ?? 0);
        $winRate = $games > 0 ? $wins / max(1, $games) : 0.0;
        $botWinRate = $botGames > 0 ? $botWins / max(1, $botGames) : 0.0;

        if ($botStreak >= 5 || ($botGames >= 8 && $botWinRate >= 0.70) || ($games >= 30 && $winRate >= 0.65)) {
            return $this->weightedDifficulty(['medium' => 20, 'hard' => 80]);
        }
        if ($botStreak >= 3 || ($games >= 20 && $winRate >= 0.55)) {
            return $this->weightedDifficulty(['medium' => 45, 'hard' => 55]);
        }
        if ($games < 5 && $botGames < 3) {
            return $this->weightedDifficulty(['easy' => 8, 'medium' => 62, 'hard' => 30]);
        }
        return $this->weightedDifficulty(['easy' => 5, 'medium' => 65, 'hard' => 30]);
    }

    private function weightedDifficulty(array $weights): string
    {
        $total = array_sum($weights);
        $roll = random_int(1, max(1, $total));
        $acc = 0;
        foreach ($weights as $difficulty => $weight) {
            $acc += (int)$weight;
            if ($roll <= $acc) return (string)$difficulty;
        }
        return 'medium';
    }

    private function normalizeDatabaseGameTypes(array &$db): void
    {
        if (isset($db['games']) && is_array($db['games'])) {
            foreach ($db['games'] as &$game) if (is_array($game)) $this->ensureGameType($game);
            unset($game);
        }
        if (isset($db['queue']) && is_array($db['queue'])) {
            foreach ($db['queue'] as &$item) if (is_array($item)) $this->ensureGameType($item);
            unset($item);
        }
    }

    private function ensureGameType(array &$record): void
    {
        $record['game_type'] = $this->gameTypeFromRecord($record);
    }

    private function gameTypeFromRecord(array $record): string
    {
        if (
            (string)($record['game_type'] ?? '') === 'checkers'
            || !empty($record['checkers_initialized'])
            || isset($record['checkers_sides'])
        ) return 'checkers';

        if (
            (string)($record['game_type'] ?? '') === 'battleship'
            || !empty($record['battleship_initialized'])
            || isset($record['battleship_fleets'])
        ) return 'battleship';

        if (
            (string)($record['game_type'] ?? '') === 'four_in_a_row'
            || !empty($record['four_in_a_row_initialized'])
            || ((int)($record['connect_length'] ?? 0) === 4 && (int)($record['board_rows'] ?? 0) >= 5)
        ) return 'four_in_a_row';

        return $this->catalog->normalizeGameType((string)($record['game_type'] ?? ''));
    }

    private function setQueuedGameType(array &$db, string $userId, string $gameType, int $requestedBoardSize): void
    {
        if ($userId === '' || !isset($db['queue']) || !is_array($db['queue'])) return;
        foreach ($db['queue'] as &$item) {
            if (!is_array($item) || (string)($item['user_id'] ?? '') !== $userId) continue;
            $item['game_type'] = $gameType;
            $item['requested_board_size'] = $requestedBoardSize;
            if ($gameType === 'four_in_a_row') $item['game_variant_size'] = $requestedBoardSize;
            unset($item);
            return;
        }
        unset($item);
    }

    private function queueItemForUser(array $db, string $userId): ?array
    {
        if ($userId === '') return null;
        foreach ($db['queue'] ?? [] as $item) {
            if (is_array($item) && (string)($item['user_id'] ?? '') === $userId) return $item;
        }
        return null;
    }

    private function legacyProxyBoardSize(int $boardSize): int
    {
        return match ($boardSize) {
            6 => 3,
            8 => 9,
            default => 5,
        };
    }

    private function requestedBoardSizeFromQueue(string $gameType, array $queueItem): int
    {
        if ($gameType === 'battleship') return 10;
        if ($gameType === 'checkers') return 8;
        if ($gameType !== 'four_in_a_row') {
            return $this->catalog->normalizeBoardSize($gameType, (int)($queueItem['requested_board_size'] ?? $queueItem['board_size'] ?? 3));
        }

        $requested = (int)($queueItem['game_variant_size'] ?? $queueItem['requested_board_size'] ?? 0);
        if (in_array($requested, [6, 7, 8], true)) return $requested;
        return match ((int)($queueItem['board_size'] ?? 5)) {
            3 => 6,
            9 => 8,
            default => 7,
        };
    }

    private function syncGameMetadataTransactions(array &$db, string $gameId): void
    {
        if (!isset($db['games'][$gameId]) || !is_array($db['games'][$gameId])) return;
        if (!isset($db['transactions']) || !is_array($db['transactions'])) return;

        $game = $db['games'][$gameId];
        foreach ($db['transactions'] as &$transaction) {
            if (!is_array($transaction) || (string)($transaction['game_id'] ?? '') !== $gameId) continue;
            $transaction['game_type'] = (string)($game['game_type'] ?? 'tictactoe');
            $transaction['board_size'] = (int)($game['board_size'] ?? 0);
            if (isset($game['board_columns'])) $transaction['board_columns'] = (int)$game['board_columns'];
            if (isset($game['board_rows'])) $transaction['board_rows'] = (int)$game['board_rows'];
        }
        unset($transaction);
    }
}
