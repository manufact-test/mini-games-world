<?php
declare(strict_types=1);

require_once __DIR__ . '/JsonInviteMatchmakingInviteTrait.php';
require_once __DIR__ . '/JsonInviteMatchmakingQueueTrait.php';
require_once __DIR__ . '/JsonInviteMatchmakingProjectionTrait.php';

final class JsonInviteMatchmakingBaselineScenario
{
    use JsonInviteMatchmakingInviteTrait;
    use JsonInviteMatchmakingQueueTrait;
    use JsonInviteMatchmakingProjectionTrait;

    public const CONTRACT_VERSION = 'mvp14r2-invites-matchmaking-v1';
    private const INVITE_TTL_SEC = 900;
    private const READY_TTL_SEC = 90;

    public function run(JsonBehaviorBaselineFixture $fixture): array
    {
        $scenario = $fixture->scenario();
        $initial = $fixture->state();
        $input = $scenario['input'] ?? null;
        if (!is_array($input) || array_is_list($input)) {
            throw new RuntimeException('Invite/matchmaking baseline input must be an object.');
        }
        $workflow = $input['workflow'] ?? null;
        if (!is_array($workflow) || !array_is_list($workflow) || $workflow === []) {
            throw new RuntimeException('Invite/matchmaking baseline workflow must be a non-empty list.');
        }
        $config = array_replace([
            'match_bet' => 10,
            'bot_after_sec' => 15,
            'move_timeout_sec' => 60,
        ], is_array($input['config'] ?? null) ? $input['config'] : []);

        $before = $this->domainSnapshot($initial);
        [$payload, $afterState, $effects] = $this->executeWorkflow($fixture, $initial, $workflow, $config);
        $after = $this->domainSnapshot($afterState);

        $fixture->resetIdSequences();
        [$retryPayload, $retryState, $retryEffects] = $this->executeWorkflow($fixture, $initial, $workflow, $config);
        $retryStable = $payload === $retryPayload
            && $after === $this->domainSnapshot($retryState)
            && $effects === $retryEffects;
        if (!$retryStable) {
            throw new RuntimeException('Invite/matchmaking baseline retry is not deterministic.');
        }

        $result = new JsonBehaviorBaselineResult($fixture->normalizer());
        return $result->build([
            'scenario_id' => (string)($scenario['id'] ?? ''),
            'input' => [
                'workflow' => $workflow,
                'config' => $config,
            ],
            'public_result' => [
                'status' => 200,
                'payload' => $payload,
            ],
            'domains' => [
                'before' => $before,
                'after' => $after,
            ],
            'side_effects' => $effects,
            'retry' => [
                'attempted' => true,
                'result' => ['stable' => true],
            ],
            'conflict' => [
                'attempted' => false,
                'result' => ['applicable' => false],
            ],
            'latency' => [
                'measured' => false,
                'samples' => 0,
                'cold_ms' => null,
                'warm_ms' => null,
            ],
        ]);
    }

    private function executeWorkflow(
        JsonBehaviorBaselineFixture $fixture,
        array $state,
        array $workflow,
        array $config
    ): array {
        $trace = [];
        $effects = ['notifications' => [], 'events' => [], 'ledger' => []];
        $context = ['last_token' => '', 'last_game_id' => ''];
        $base = $fixture->now();

        foreach ($workflow as $index => $step) {
            if (!is_array($step) || array_is_list($step)) {
                throw new RuntimeException('Invite/matchmaking workflow step must be an object.');
            }
            $action = strtolower(trim((string)($step['action'] ?? '')));
            $actorId = trim((string)($step['actor_id'] ?? ''));
            if ($actorId === '' || !isset($state['users'][$actorId]) || !is_array($state['users'][$actorId])) {
                throw new RuntimeException('Invite/matchmaking workflow actor is unavailable.');
            }
            $offset = (int)($step['offset_sec'] ?? $index);
            $now = $base->modify(($offset >= 0 ? '+' : '') . $offset . ' seconds');
            [$stepResult, $stepEffects] = $this->applyStep(
                $fixture,
                $state,
                $action,
                $actorId,
                $step,
                $config,
                $context,
                $now
            );
            foreach (['notifications', 'events', 'ledger'] as $field) {
                foreach ($stepEffects[$field] ?? [] as $item) $effects[$field][] = $item;
            }
            $trace[] = [
                'action' => $action,
                'actor_id' => $actorId,
                'result' => $stepResult,
            ];
        }

        return [[
            'trace' => $trace,
            'last' => $trace[array_key_last($trace)]['result'],
        ], $state, $effects];
    }

    private function applyStep(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        string $action,
        string $actorId,
        array $step,
        array $config,
        array &$context,
        DateTimeImmutable $now
    ): array {
        return match ($action) {
            'create_direct' => $this->createDirect($fixture, $state, $actorId, $step, $context, $now, $config),
            'create_link_draft' => $this->createLinkDraft($fixture, $state, $actorId, $step, $context, $now, $config),
            'confirm_shared' => $this->confirmShared($state, $actorId, $step, $context, $now),
            'open_link' => $this->openLink($fixture, $state, $actorId, $step, $context, $now),
            'accept' => $this->acceptInvite($fixture, $state, $actorId, $step, $context, $now, $config),
            'start' => $this->startInvite($fixture, $state, $actorId, $step, $context, $now, $config),
            'cancel' => $this->cancelInvite($fixture, $state, $actorId, $step, $context, $now),
            'rematch' => $this->rematch($fixture, $state, $actorId, $step, $context, $now, $config),
            'start_search' => $this->startSearch($fixture, $state, $actorId, $step, $context, $now, $config),
            'leave_search' => $this->leaveSearch($state, $actorId),
            'bot_fallback' => $this->botFallback($fixture, $state, $actorId, $step, $context, $now, $config),
            default => throw new RuntimeException('Invite/matchmaking baseline action is unsupported: ' . $action . '.'),
        };
    }

}
