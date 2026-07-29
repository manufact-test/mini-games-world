<?php
declare(strict_types=1);

require_once __DIR__ . '/JsonGamesSettlementTrait.php';
require_once __DIR__ . '/JsonGamesClassicTrait.php';
require_once __DIR__ . '/JsonGamesStrategyTrait.php';

final class JsonGamesBaselineScenario
{
    use JsonGamesSettlementTrait;
    use JsonGamesClassicTrait;
    use JsonGamesStrategyTrait;

    public const CONTRACT_VERSION = 'mvp14r2-games-v1';

    public function run(JsonBehaviorBaselineFixture $fixture): array
    {
        $scenario = $fixture->scenario();
        $initial = $fixture->state();
        $input = $scenario['input'] ?? null;
        if (!is_array($input) || array_is_list($input)) {
            throw new RuntimeException('Games baseline input must be an object.');
        }
        $gameId = trim((string)($input['game_id'] ?? ''));
        if ($gameId === '' || !isset($initial['games'][$gameId]) || !is_array($initial['games'][$gameId])) {
            throw new RuntimeException('Games baseline game is unavailable.');
        }
        $workflow = $input['workflow'] ?? null;
        if (!is_array($workflow) || !array_is_list($workflow) || $workflow === []) {
            throw new RuntimeException('Games baseline workflow must be a non-empty list.');
        }
        $config = array_replace([
            'commission_rate' => 0.10,
            'move_timeout_sec' => 60,
        ], is_array($input['config'] ?? null) ? $input['config'] : []);
        $viewerId = trim((string)($input['viewer_id'] ?? ($initial['games'][$gameId]['player_ids'][0] ?? '')));

        $before = $this->snapshot($initial);
        [$payload, $afterState, $effects, $conflicts] = $this->execute($fixture, $initial, $gameId, $viewerId, $workflow, $config);
        $after = $this->snapshot($afterState);

        $fixture->resetIdSequences();
        [$retryPayload, $retryState, $retryEffects, $retryConflicts] = $this->execute(
            $fixture,
            $initial,
            $gameId,
            $viewerId,
            $workflow,
            $config
        );
        if ($payload !== $retryPayload
            || $after !== $this->snapshot($retryState)
            || $effects !== $retryEffects
            || $conflicts !== $retryConflicts) {
            throw new RuntimeException('Games baseline retry is not deterministic.');
        }

        $result = new JsonBehaviorBaselineResult($fixture->normalizer());
        return $result->build([
            'scenario_id' => (string)($scenario['id'] ?? ''),
            'input' => [
                'game_id' => $gameId,
                'viewer_id' => $viewerId,
                'workflow' => $workflow,
                'config' => $config,
            ],
            'public_result' => ['status' => 200, 'payload' => $payload],
            'domains' => ['before' => $before, 'after' => $after],
            'side_effects' => $effects,
            'retry' => ['attempted' => true, 'result' => ['stable' => true]],
            'conflict' => [
                'attempted' => $conflicts !== [],
                'result' => $conflicts !== [] ? ['errors' => $conflicts] : ['applicable' => false],
            ],
            'latency' => [
                'measured' => false,
                'samples' => 0,
                'cold_ms' => null,
                'warm_ms' => null,
            ],
        ]);
    }

    private function execute(
        JsonBehaviorBaselineFixture $fixture,
        array $state,
        string $gameId,
        string $viewerId,
        array $workflow,
        array $config
    ): array {
        foreach (['users','games','transactions','invites','notifications','system'] as $field) {
            if (!isset($state[$field]) || !is_array($state[$field])) $state[$field] = [];
        }
        $trace = [];
        $effects = ['notifications' => [], 'events' => [], 'ledger' => []];
        $conflicts = [];
        $base = $fixture->now();

        foreach ($workflow as $index => $step) {
            if (!is_array($step) || array_is_list($step)) {
                throw new RuntimeException('Games baseline workflow step must be an object.');
            }
            $action = strtolower(trim((string)($step['action'] ?? '')));
            $actorId = trim((string)($step['actor_id'] ?? $viewerId));
            $offset = (int)($step['offset_sec'] ?? $index);
            $now = $base->modify(($offset >= 0 ? '+' : '') . $offset . ' seconds');
            $expectedError = array_key_exists('expect_error', $step) ? (string)$step['expect_error'] : null;
            $beforeStep = $this->snapshot($state);

            try {
                $ledger = $this->applyStep($fixture, $state, $gameId, $actorId, $action, $step, $now, $config);
                if ($expectedError !== null) {
                    throw new RuntimeException('Expected game action error was not raised: ' . $expectedError);
                }
                foreach ($ledger as $transaction) $effects['ledger'][] = $transaction;
                $game = $state['games'][$gameId];
                $eventType = $action === 'rematch_projection'
                    ? 'rematch_projected'
                    : ((string)($game['status'] ?? '') === 'finished' ? 'game_finished' : 'game_action');
                $effects['events'][] = [
                    'type' => $eventType,
                    'game_id' => $gameId,
                    'game_type' => (string)($game['game_type'] ?? ''),
                    'action' => $action,
                    'actor_id' => $actorId,
                ];
                $trace[] = [
                    'action' => $action,
                    'actor_id' => $actorId,
                    'status' => 'ok',
                    'game' => $this->publicGame($game, $actorId, $now, (int)$config['move_timeout_sec']),
                    'rematch' => $action === 'rematch_projection'
                        ? $this->rematchProjection($state, $game, $actorId)
                        : null,
                ];
            } catch (RuntimeException $error) {
                if ($expectedError === null || !hash_equals($expectedError, $error->getMessage())) {
                    throw $error;
                }
                if ($beforeStep !== $this->snapshot($state)) {
                    throw new RuntimeException('Rejected game action mutated state.');
                }
                $conflict = ['action' => $action, 'actor_id' => $actorId, 'message' => $error->getMessage()];
                $conflicts[] = $conflict;
                $trace[] = $conflict + ['status' => 'error'];
            }
        }

        $finalGame = $state['games'][$gameId];
        return [[
            'trace' => $trace,
            'final_game' => $this->publicGame($finalGame, $viewerId, $base->modify('+300 seconds'), (int)$config['move_timeout_sec']),
            'rematch' => $this->rematchProjection($state, $finalGame, $viewerId),
        ], $state, $effects, $conflicts];
    }

    private function applyStep(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        string $gameId,
        string $actorId,
        string $action,
        array $step,
        DateTimeImmutable $now,
        array $config
    ): array {
        if (!isset($state['games'][$gameId]) || !is_array($state['games'][$gameId])) {
            throw new RuntimeException('Игра не найдена.');
        }
        $game =& $state['games'][$gameId];
        $players = array_values(array_map('strval', $game['player_ids'] ?? []));
        if ($actorId === '' || !in_array($actorId, $players, true)) {
            throw new RuntimeException('Вы не участвуете в этой игре.');
        }
        if (in_array($action, ['observe','rematch_projection'], true)) return [];
        if ((string)($game['status'] ?? '') === 'finished') return [];

        if ($action === 'timeout') {
            $started = new DateTimeImmutable((string)($game['turn_started_at'] ?? $now->format('c')));
            if ($now->getTimestamp() - $started->getTimestamp() < (int)$config['move_timeout_sec']) {
                throw new RuntimeException('Время хода ещё не истекло.');
            }
            $loserId = (string)($game['turn'] ?? '');
            return $this->finishGame($fixture, $state, $game, $this->otherPlayerId($game, $loserId), 'timeout', $now, $config);
        }
        if ($action === 'surrender') {
            return $this->finishGame($fixture, $state, $game, $this->otherPlayerId($game, $actorId), 'player_left', $now, $config);
        }

        return match ((string)($game['game_type'] ?? '')) {
            'tictactoe' => $action === 'cell'
                ? $this->applyTicTacToe($fixture, $state, $game, $actorId, $step, $now, $config)
                : throw new RuntimeException('Некорректное действие для этой игры.'),
            'four_in_a_row' => $action === 'column'
                ? $this->applyFourInARow($fixture, $state, $game, $actorId, $step, $now, $config)
                : throw new RuntimeException('Выберите столбец для хода.'),
            'battleship' => $action === 'fire'
                ? $this->applyBattleship($fixture, $state, $game, $actorId, $step, $now, $config)
                : throw new RuntimeException('Некорректное действие для Морского боя.'),
            'checkers' => $action === 'move'
                ? $this->applyCheckers($fixture, $state, $game, $actorId, $step, $now, $config)
                : throw new RuntimeException('Некорректное действие для шашек.'),
            'reversi' => $action === 'cell'
                ? $this->applyReversi($fixture, $state, $game, $actorId, $step, $now, $config)
                : throw new RuntimeException('Некорректное действие для Реверси.'),
            'chess' => $action === 'chess_move'
                ? $this->applyChess($fixture, $state, $game, $actorId, $step, $now, $config)
                : throw new RuntimeException('Некорректное действие для шахмат.'),
            'go' => in_array($action, ['place','pass'], true)
                ? $this->applyGo($fixture, $state, $game, $actorId, $step + ['type' => $action], $now, $config)
                : throw new RuntimeException('Некорректное действие для Го.'),
            'domino' => in_array($action, ['play','draw'], true)
                ? $this->applyDomino($fixture, $state, $game, $actorId, $step + ['type' => $action], $now, $config)
                : throw new RuntimeException('Некорректное действие для домино.'),
            default => throw new RuntimeException('Движок этой игры пока не подключён.'),
        };
    }
}
