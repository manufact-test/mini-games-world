<?php
declare(strict_types=1);

require_once __DIR__ . '/JsonEconomyHistoryTrait.php';
require_once __DIR__ . '/JsonShopPaymentsTrait.php';
require_once __DIR__ . '/JsonWeeklyBonusTrait.php';

final class JsonEconomySupportingBaselineScenario
{
    use JsonEconomyHistoryTrait;
    use JsonShopPaymentsTrait;
    use JsonWeeklyBonusTrait;

    public const CONTRACT_VERSION = 'mvp14r2-economy-supporting-v1';

    public function run(JsonBehaviorBaselineFixture $fixture): array
    {
        $scenario = $fixture->scenario();
        $input = $scenario['input'] ?? null;
        if (!is_array($input) || array_is_list($input)) {
            throw new RuntimeException('Economy supporting baseline input must be an object.');
        }
        $workflow = $input['workflow'] ?? null;
        if (!is_array($workflow) || !array_is_list($workflow) || $workflow === []) {
            throw new RuntimeException('Economy supporting workflow must be a non-empty list.');
        }

        $initial = $this->normalizeState($fixture->state());
        $before = $this->snapshot($initial);
        [$payload, $afterState, $effects, $conflicts] = $this->execute($fixture, $initial, $workflow, $input);
        $after = $this->snapshot($afterState);

        $fixture->resetIdSequences();
        [$retryPayload, $retryState, $retryEffects, $retryConflicts] = $this->execute(
            $fixture,
            $initial,
            $workflow,
            $input
        );
        if ($payload !== $retryPayload
            || $after !== $this->snapshot($retryState)
            || $effects !== $retryEffects
            || $conflicts !== $retryConflicts) {
            throw new RuntimeException('Economy supporting baseline retry is not deterministic.');
        }

        return (new JsonBehaviorBaselineResult($fixture->normalizer()))->build([
            'scenario_id' => (string)($scenario['id'] ?? ''),
            'input' => $input,
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
        array $workflow,
        array $input
    ): array {
        $trace = [];
        $effects = ['notifications' => [], 'events' => [], 'ledger' => []];
        $conflicts = [];
        $base = $fixture->now();
        $config = array_replace([
            'commission_rate' => 0.10,
            'match_bet' => 10,
            'weekly_match_timezone' => 'Europe/Warsaw',
            'weekly_match_start_at' => '2026-07-13 12:00:00',
            'weekly_match_bonus_amount' => 50,
            'weekly_match_min_completed' => 3,
            'payment_rates' => ['match' => 10, 'gold' => 1],
        ], is_array($input['config'] ?? null) ? $input['config'] : []);

        foreach ($workflow as $index => $step) {
            if (!is_array($step) || array_is_list($step)) {
                throw new RuntimeException('Economy supporting workflow step must be an object.');
            }
            $action = strtolower(trim((string)($step['action'] ?? '')));
            $offset = (int)($step['offset_sec'] ?? $index);
            $now = $base->modify(($offset >= 0 ? '+' : '') . $offset . ' seconds');
            $expectedError = array_key_exists('expect_error', $step) ? (string)$step['expect_error'] : null;
            $beforeStep = $this->snapshot($state);

            try {
                $result = $this->applyStep($fixture, $state, $action, $step, $now, $config);
                if ($expectedError !== null) {
                    throw new RuntimeException('Expected economy supporting error was not raised: ' . $expectedError);
                }
                foreach ($result['ledger'] ?? [] as $row) $effects['ledger'][] = $row;
                foreach ($result['notifications'] ?? [] as $row) $effects['notifications'][] = $row;
                $event = [
                    'type' => (string)($result['event_type'] ?? $action),
                    'action' => $action,
                    'actor_id' => (string)($step['actor_id'] ?? ''),
                ];
                foreach (['game_id','order_id','payment_id','cycle_key'] as $field) {
                    if (array_key_exists($field, $result)) $event[$field] = $result[$field];
                }
                $effects['events'][] = $event;
                $trace[] = [
                    'action' => $action,
                    'status' => 'ok',
                    'result' => $result['public'] ?? null,
                ];
            } catch (RuntimeException $error) {
                if ($expectedError === null || !hash_equals($expectedError, $error->getMessage())) {
                    throw $error;
                }
                if ($beforeStep !== $this->snapshot($state)) {
                    throw new RuntimeException('Rejected economy supporting action mutated state.');
                }
                $conflict = [
                    'action' => $action,
                    'actor_id' => (string)($step['actor_id'] ?? ''),
                    'message' => $error->getMessage(),
                ];
                $conflicts[] = $conflict;
                $trace[] = $conflict + ['status' => 'error'];
            }
        }

        return [[
            'trace' => $trace,
            'history' => $this->allRequestedHistory($state, $input),
            'shop' => $this->shopProjection($state),
            'payments' => $this->paymentProjection($state),
            'weekly_bonus' => $this->weeklyProjection($state, $config, $base),
        ], $state, $effects, $conflicts];
    }

    private function applyStep(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        string $action,
        array $step,
        DateTimeImmutable $now,
        array $config
    ): array {
        return match ($action) {
            'reserve_game' => $this->reserveGame($fixture, $state, $step, $now, $config),
            'finish_game' => $this->finishEconomyGame($fixture, $state, $step, $now, $config),
            'settle_again' => $this->settleAgain($fixture, $state, $step, $now, $config),
            'read_history' => $this->readHistoryStep($state, $step),
            'read_catalog' => $this->readCatalogStep($step),
            'create_shop_order' => $this->createShopOrder($fixture, $state, $step, $now),
            'complete_shop_order' => $this->completeShopOrder($fixture, $state, $step, $now),
            'reject_shop_order' => $this->rejectShopOrder($fixture, $state, $step, $now),
            'create_payment' => $this->createPayment($fixture, $state, $step, $now, $config),
            'apply_payment' => $this->applyPayment($fixture, $state, $step, $now),
            'reject_payment' => $this->rejectPayment($fixture, $state, $step, $now),
            'cancel_payment' => $this->cancelPayment($fixture, $state, $step, $now),
            'run_weekly' => $this->runWeekly($fixture, $state, $step, $now, $config),
            'weekly_status' => $this->weeklyStatusStep($state, $step, $now, $config),
            default => throw new RuntimeException('Unknown economy supporting baseline action: ' . $action . '.'),
        };
    }

    private function normalizeState(array $state): array
    {
        foreach (['users','games','transactions','shop_orders','payments','notifications','system'] as $field) {
            if (!isset($state[$field]) || !is_array($state[$field])) $state[$field] = [];
        }
        return $state;
    }

    private function snapshot(array $state): array
    {
        foreach (['users','games'] as $field) {
            if (isset($state[$field]) && is_array($state[$field])) ksort($state[$field], SORT_STRING);
        }
        foreach (['transactions','shop_orders','payments','notifications'] as $field) {
            $state[$field] = array_values(is_array($state[$field] ?? null) ? $state[$field] : []);
        }
        $state['system'] = is_array($state['system'] ?? null) ? $state['system'] : [];
        ksort($state['system'], SORT_STRING);
        return [
            'users' => $state['users'],
            'games' => $state['games'],
            'transactions' => $state['transactions'],
            'shop_orders' => $state['shop_orders'],
            'payments' => $state['payments'],
            'notifications' => $state['notifications'],
            'system' => $state['system'],
        ];
    }
}
