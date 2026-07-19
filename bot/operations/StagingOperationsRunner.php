<?php
declare(strict_types=1);

final class StagingOperationsRunner
{
    /** @var array<string, StagingOperationDefinition> */
    private array $operations = [];

    public function __construct(
        private string $build,
        private string $stateFile,
        array $operations,
        private ?Closure $clock = null
    ) {
        $this->build = trim($this->build);
        $this->stateFile = trim($this->stateFile);
        if ($this->build === '') throw new InvalidArgumentException('Runner build is required.');
        if ($this->stateFile === '') throw new InvalidArgumentException('Runner state file is required.');

        foreach ($operations as $operation) {
            if (!$operation instanceof StagingOperationDefinition) {
                throw new InvalidArgumentException('Runner operations must be StagingOperationDefinition instances.');
            }
            if (isset($this->operations[$operation->id()])) {
                throw new InvalidArgumentException('Duplicate staging operation ID: ' . $operation->id());
            }
            $this->operations[$operation->id()] = $operation;
        }
    }

    public function run(): array
    {
        $state = $this->readState();
        $state = $this->normalizeState($state);
        $eligible = $this->eligibleOperations();
        $pending = null;

        foreach ($eligible as $id => $operation) {
            $operationState = (string)($state['operations'][$id]['state'] ?? 'pending');
            if (!in_array($operationState, ['completed', 'failed'], true)) {
                $pending = $operation;
                break;
            }
        }

        if ($pending === null) {
            $report = $this->statusFromState($state, 'idle');
            $report['action'] = 'run_noop';
            $report['idempotent'] = true;
            return $this->withFingerprint($report);
        }

        $id = $pending->id();
        $attempts = (int)($state['operations'][$id]['attempts'] ?? 0) + 1;
        $state['operations'][$id] = [
            'id' => $id,
            'build' => $pending->build(),
            'state' => 'running',
            'attempts' => $attempts,
            'started_at_utc' => $this->nowUtc(),
        ];
        $state['updated_at_utc'] = $this->nowUtc();
        $this->writeState($state);

        try {
            $result = $pending->execute();
            $blockers = array_values(array_filter(array_map(
                static fn(mixed $value): string => trim((string)$value),
                is_array($result['blockers'] ?? null) ? $result['blockers'] : []
            ), static fn(string $value): bool => $value !== ''));
            $ok = ($result['ok'] ?? false) === true && $blockers === [];
            if (!$ok) {
                $state['operations'][$id] = [
                    'id' => $id,
                    'build' => $pending->build(),
                    'state' => 'failed',
                    'attempts' => $attempts,
                    'failed_at_utc' => $this->nowUtc(),
                    'failure_type' => 'operation_report',
                    'result' => $this->safeResult($result),
                ];
                $state['updated_at_utc'] = $this->nowUtc();
                $this->writeState($state);

                $report = $this->statusFromState($state, 'failed');
                $report += [
                    'ok' => false,
                    'action' => 'run',
                    'idempotent' => false,
                    'operation_id' => $id,
                    'operation_result' => $this->safeResult($result),
                ];
                return $this->withFingerprint($report);
            }

            $safeResult = $this->safeResult($result);
            $state['operations'][$id] = [
                'id' => $id,
                'build' => $pending->build(),
                'state' => 'completed',
                'attempts' => $attempts,
                'completed_at_utc' => $this->nowUtc(),
                'result_fingerprint' => $this->fingerprint($safeResult),
            ];
            $state['updated_at_utc'] = $this->nowUtc();
            $this->writeState($state);

            $report = $this->statusFromState($state, 'completed');
            $report += [
                'ok' => true,
                'action' => 'run',
                'idempotent' => false,
                'operation_id' => $id,
                'operation_result' => $safeResult,
                'completed_at_utc' => $this->nowUtc(),
            ];
            return $this->withFingerprint($report);
        } catch (Throwable $error) {
            $state['operations'][$id] = [
                'id' => $id,
                'build' => $pending->build(),
                'state' => 'failed',
                'attempts' => $attempts,
                'failed_at_utc' => $this->nowUtc(),
                'failure_type' => 'exception',
                'error_class' => get_class($error),
                'error_message' => mb_substr($error->getMessage(), 0, 500),
            ];
            $state['updated_at_utc'] = $this->nowUtc();
            $this->writeState($state);

            $report = $this->statusFromState($state, 'failed');
            $report += [
                'ok' => false,
                'action' => 'run',
                'idempotent' => false,
                'operation_id' => $id,
                'error_class' => get_class($error),
                'error_message' => mb_substr($error->getMessage(), 0, 500),
            ];
            return $this->withFingerprint($report);
        }
    }

    public function status(): array
    {
        return $this->withFingerprint($this->statusFromState(
            $this->normalizeState($this->readState()),
            'status'
        ));
    }

    private function eligibleOperations(): array
    {
        return array_filter(
            $this->operations,
            fn(StagingOperationDefinition $operation): bool => hash_equals($operation->build(), $this->build)
        );
    }

    private function normalizeState(array $state): array
    {
        $state['schema_version'] = 1;
        $state['operations'] = is_array($state['operations'] ?? null) ? $state['operations'] : [];
        return $state;
    }

    private function statusFromState(array $state, string $runnerState): array
    {
        $eligible = $this->eligibleOperations();
        $counts = ['pending' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0];
        $operations = [];
        foreach ($eligible as $id => $operation) {
            $item = is_array($state['operations'][$id] ?? null) ? $state['operations'][$id] : [];
            $operationState = (string)($item['state'] ?? 'pending');
            if (!array_key_exists($operationState, $counts)) $operationState = 'pending';
            $counts[$operationState]++;
            $operations[] = [
                'id' => $id,
                'state' => $operationState,
                'attempts' => (int)($item['attempts'] ?? 0),
            ];
        }

        $ok = $counts['failed'] === 0;
        return [
            'ok' => $ok,
            'report_type' => 'mvp-14.8.4f-staging-operations-runner',
            'runner_state' => $runnerState,
            'build' => $this->build,
            'eligible_operation_count' => count($eligible),
            'counts' => $counts,
            'operations' => $operations,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => $this->nowUtc(),
        ];
    }

    private function safeResult(array $result): array
    {
        unset($result['users'], $result['user_ids'], $result['legacy_user_ids'], $result['accounts']);
        $result['production_changed'] = false;
        $result['sensitive_identifiers_exposed'] = false;
        return $result;
    }

    private function readState(): array
    {
        if (!is_file($this->stateFile)) return [];
        $raw = file_get_contents($this->stateFile);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('Staging operations runner state is unreadable.');
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Staging operations runner state is invalid JSON.', 0, $error);
        }
        if (!is_array($decoded)) throw new RuntimeException('Staging operations runner state root is invalid.');
        return $decoded;
    }

    private function writeState(array $state): void
    {
        $directory = dirname($this->stateFile);
        if (!is_dir($directory)) throw new RuntimeException('Staging operations private directory is unavailable.');
        $temporary = $this->stateFile . '.tmp-' . bin2hex(random_bytes(6));
        $json = json_encode(
            $state,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new RuntimeException('Could not write staging operations runner state.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $this->stateFile)) {
            @unlink($temporary);
            throw new RuntimeException('Could not publish staging operations runner state.');
        }
        @chmod($this->stateFile, 0600);
    }

    private function withFingerprint(array $report): array
    {
        unset($report['report_fingerprint']);
        $report['report_fingerprint'] = $this->fingerprint($report);
        return $report;
    }

    private function fingerprint(array $value): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }

    private function nowUtc(): string
    {
        return $this->clock !== null
            ? (string)($this->clock)()
            : gmdate(DATE_ATOM);
    }
}
