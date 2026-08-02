<?php
declare(strict_types=1);

final class JsonBehaviorBaselineResult
{
    public const CONTRACT_VERSION = 'mvp14r2-scenario-result-v1';

    public function __construct(private JsonBehaviorBaselineNormalizer $normalizer)
    {
    }

    public function build(array $record): array
    {
        if (array_key_exists('fingerprint_sha256', $record)) {
            throw new InvalidArgumentException('Baseline result fingerprint is generated, not supplied.');
        }
        $this->validateRecord($record);

        $core = $record;
        $core['contract_version'] = self::CONTRACT_VERSION;
        $normalized = $this->normalizer->normalize($core);
        if (!is_array($normalized) || array_is_list($normalized)) {
            throw new RuntimeException('Baseline normalized scenario result must be an object.');
        }
        $normalized['fingerprint_sha256'] = $this->normalizer->fingerprint($normalized);
        return $normalized;
    }

    public function verify(array $result): bool
    {
        $fingerprint = strtolower(trim((string)($result['fingerprint_sha256'] ?? '')));
        if (preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1
            || ($result['contract_version'] ?? null) !== self::CONTRACT_VERSION) {
            return false;
        }
        unset($result['fingerprint_sha256']);
        return hash_equals($fingerprint, $this->normalizer->fingerprint($result));
    }

    public function canonicalJson(array $result): string
    {
        return $this->normalizer->canonicalJson($result);
    }

    private function validateRecord(array $record): void
    {
        $scenarioId = trim((string)($record['scenario_id'] ?? ''));
        if (preg_match('/\A[a-z0-9][a-z0-9_.-]{0,95}\z/', $scenarioId) !== 1) {
            throw new InvalidArgumentException('Baseline scenario ID is invalid.');
        }

        foreach (['input', 'public_result', 'domains', 'side_effects', 'retry', 'conflict', 'latency'] as $field) {
            if (!is_array($record[$field] ?? null) || array_is_list($record[$field])) {
                throw new InvalidArgumentException('Baseline result field must be an object: ' . $field . '.');
            }
        }

        $status = $record['public_result']['status'] ?? null;
        if (!(is_int($status) && $status >= 100 && $status <= 599)
            && !(is_string($status) && trim($status) !== '')) {
            throw new InvalidArgumentException('Baseline public result status is invalid.');
        }
        if (!array_key_exists('payload', $record['public_result'])) {
            throw new InvalidArgumentException('Baseline public result payload is required.');
        }

        foreach (['before', 'after'] as $field) {
            if (!is_array($record['domains'][$field] ?? null)) {
                throw new InvalidArgumentException('Baseline domain snapshot is invalid: ' . $field . '.');
            }
        }
        foreach (['notifications', 'events', 'ledger'] as $field) {
            if (!is_array($record['side_effects'][$field] ?? null)) {
                throw new InvalidArgumentException('Baseline side-effect collection is invalid: ' . $field . '.');
            }
        }
        foreach (['retry', 'conflict'] as $field) {
            if (!is_bool($record[$field]['attempted'] ?? null)
                || !array_key_exists('result', $record[$field])) {
                throw new InvalidArgumentException('Baseline retry/conflict contract is invalid: ' . $field . '.');
            }
        }
        if (!is_bool($record['latency']['measured'] ?? null)) {
            throw new InvalidArgumentException('Baseline latency measurement marker is invalid.');
        }
    }
}
