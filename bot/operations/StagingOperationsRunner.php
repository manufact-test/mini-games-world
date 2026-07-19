<?php
declare(strict_types=1);

final class StagingOperationsRunner
{
    private const REDACTED_KEYS = [
        'user', 'users', 'user_id', 'user_ids', 'legacy_user_id', 'legacy_user_ids',
        'account', 'accounts', 'account_id', 'account_ids', 'account_ref', 'account_refs',
        'mgw_id', 'mgw_ids', 'email', 'emails', 'phone', 'phones', 'token', 'tokens',
        'password', 'passwords', 'secret', 'secrets', 'payload_json', 'metadata_json',
    ];

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
        $state = $this->normalizeState($this->readState());
        $eligible = $this->eligibleOperations();
        $pending = null;

        foreach ($eligible as $operation) {
            $operationState = $this->operationState($state, $operation);
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
        $previous = is_array($state['operations'][$id] ?? null) ? $state['operations'][$id] : [];
        $recoveredInterrupted = ($previous['state'] ?? null) === 'running'
            && (string)($previous['build'] ?? '') === $pending->build();
        $attempts = (int)($previous['attempts'] ?? 0) + 1;
        $state['operations'][$id] = [
            'id' => $id,
            'build' => $pending->build(),
            'state' => 'running',
            'attempts' => $attempts,
            'recovered_interrupted' => $recoveredInterrupted,
            'started_at_utc' => $this->nowUtc(),
        ];
        $state['manifest_fingerprint'] = $this->manifestFingerprint();
        $state['updated_at_utc'] = $this->nowUtc();
        $this->writeState($state);

        $result = null;
        try {
            $result = $pending->execute();
            $blockers = array_values(array_filter(array_map(
                static fn(mixed $value): string => trim((string)$value),
                is_array($result['blockers'] ?? null) ? $result['blockers'] : []
            ), static fn(string $value): bool => $value !== ''));
            $ok = ($result['ok'] ?? false) === true && $blockers === [];

            if (!$ok) {
                $rollback = $this->attemptRollback($pending, $result, null);
                $safeResult = $this->safeResult($result);
                $state['operations'][$id] = [
                    'id' => $id,
                    'build' => $pending->build(),
                    'state' => 'failed',
                    'attempts' => $attempts,
                    'recovered_interrupted' => $recoveredInterrupted,
                    'failed_at_utc' => $this->nowUtc(),
                    'failure_type' => 'operation_report',
                    'result' => $safeResult,
                    'rollback' => $rollback,
                ];
                $state['updated_at_utc'] = $this->nowUtc();
                $this->writeState($state);

                $report = $this->statusFromState($state, 'failed');
                $report += [
                    'ok' => false,
                    'action' => 'run',
                    'idempotent' => false,
                    'operation_id' => $id,
                    'operation_result' => $safeResult,
                    'rollback' => $rollback,
                ];
                return $this->withFingerprint($report);
            }

            $safeResult = $this->safeResult($result);
            $state['operations'][$id] = [
                'id' => $id,
                'build' => $pending->build(),
                'state' => 'completed',
                'attempts' => $attempts,
                'recovered_interrupted' => $recoveredInterrupted,
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
                'rollback' => ['attempted' => false, 'ok' => true],
                'completed_at_utc' => $this->nowUtc(),
            ];
            return $this->withFingerprint($report);
        } catch (Throwable $error) {
            $rollback = $this->attemptRollback($pending, $result, $error);
            $message = $this->safeErrorMessage($error->getMessage());
            $state['operations'][$id] = [
                'id' => $id,
                'build' => $pending->build(),
                'state' => 'failed',
                'attempts' => $attempts,
                'recovered_interrupted' => $recoveredInterrupted,
                'failed_at_utc' => $this->nowUtc(),
                'failure_type' => 'exception',
                'error_class' => get_class($error),
                'error_message' => $message,
                'rollback' => $rollback,
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
                'error_message' => $message,
                'rollback' => $rollback,
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

    private function operationState(array $state, StagingOperationDefinition $operation): string
    {
        $item = is_array($state['operations'][$operation->id()] ?? null)
            ? $state['operations'][$operation->id()]
            : [];
        if ((string)($item['build'] ?? '') !== $operation->build()) return 'pending';
        $value = (string)($item['state'] ?? 'pending');
        return in_array($value, ['pending', 'running', 'completed', 'failed'], true) ? $value : 'pending';
    }

    private function normalizeState(array $state): array
    {
        $state['schema_version'] = 1;
        $state['operations'] = is_array($state['operations'] ?? null) ? $state['operations'] : [];
        $state['manifest_fingerprint'] = (string)($state['manifest_fingerprint'] ?? '');
        return $state;
    }

    private function statusFromState(array $state, string $runnerState): array
    {
        $eligible = $this->eligibleOperations();
        $counts = ['pending' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0];
        $operations = [];
        foreach ($eligible as $id => $operation) {
            $item = is_array($state['operations'][$id] ?? null) ? $state['operations'][$id] : [];
            $operationState = $this->operationState($state, $operation);
            $counts[$operationState]++;
            $operations[] = [
                'id' => $id,
                'build' => $operation->build(),
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
            'manifest_fingerprint' => $this->manifestFingerprint(),
            'eligible_operation_count' => count($eligible),
            'counts' => $counts,
            'operations' => $operations,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => $this->nowUtc(),
        ];
    }

    private function attemptRollback(
        StagingOperationDefinition $operation,
        ?array $result,
        ?Throwable $error
    ): array {
        try {
            return $this->safeResult($operation->rollback($result, $error));
        } catch (Throwable $rollbackError) {
            return [
                'attempted' => $operation->hasRollback(),
                'ok' => false,
                'error_class' => get_class($rollbackError),
                'error_message' => $this->safeErrorMessage($rollbackError->getMessage()),
                'production_changed' => false,
                'sensitive_identifiers_exposed' => false,
            ];
        }
    }

    private function safeResult(array $result): array
    {
        $safe = $this->scrub($result, 0);
        if (!is_array($safe)) $safe = [];
        $safe['production_changed'] = false;
        $safe['sensitive_identifiers_exposed'] = false;
        return $safe;
    }

    private function scrub(mixed $value, int $depth): mixed
    {
        if ($depth > 12) return '[truncated]';
        if (is_string($value)) return $this->safeText($value, 2000);
        if (!is_array($value)) return $value;

        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->scrub($item, $depth + 1), $value);
        }

        $safe = [];
        foreach ($value as $key => $item) {
            $normalized = strtolower(trim((string)$key));
            if (in_array($normalized, self::REDACTED_KEYS, true)) continue;
            $safe[$key] = $this->scrub($item, $depth + 1);
        }
        return $safe;
    }

    private function safeText(string $value, int $limit): string
    {
        $value = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted-email]', $value) ?? $value;
        $value = preg_replace('~/(?:home|var|tmp|srv)/[^\s\'\"]+~', '[private-path]', $value) ?? $value;
        $value = preg_replace('/\b(?:user|account|mgw|game|tx)_[A-Za-z0-9-]{4,}\b/i', '[redacted-id]', $value) ?? $value;
        return mb_substr($value, 0, $limit);
    }

    private function safeErrorMessage(string $message): string
    {
        return $this->safeText(trim($message), 500);
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

    private function manifestFingerprint(): string
    {
        $manifest = [];
        foreach ($this->eligibleOperations() as $operation) {
            $manifest[] = $operation->id() . ':' . $operation->build();
        }
        sort($manifest, SORT_STRING);
        return hash('sha256', implode("\n", $manifest));
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
