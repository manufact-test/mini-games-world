<?php
declare(strict_types=1);

final class JsonBaselineLatencyRunner
{
    public const CONTRACT_VERSION = 'mvp14r2-latency-report-v1';

    private const GUARDRAILS = [
        'per_scenario_cold_max_ms' => 2000.0,
        'per_scenario_warm_p95_ms' => 500.0,
        'aggregate_warm_p95_ms' => 500.0,
    ];

    public function __construct(
        private string $fixtureRoot,
        private JsonBaselineScenarioCatalog $catalog
    ) {
        if ($fixtureRoot === '' || is_link($fixtureRoot) || !is_dir($fixtureRoot)) {
            throw new RuntimeException('Latency fixture root is unavailable or unsafe.');
        }
        $canonical = realpath($fixtureRoot);
        if (!is_string($canonical) || !hash_equals($fixtureRoot, $canonical)) {
            throw new RuntimeException('Latency fixture root must use its exact canonical path.');
        }
    }

    public function run(int $coldSamples = 3, int $warmSamples = 15): array
    {
        if ($coldSamples < 1 || $coldSamples > 20 || $warmSamples < 3 || $warmSamples > 200) {
            throw new InvalidArgumentException('Latency sample counts are outside the safe bounded range.');
        }

        $scenarioReports = [];
        $groupSamples = [];
        $allCold = [];
        $allWarm = [];

        foreach ($this->catalog->timedEntries() as $entry) {
            $fixtureId = (string)$entry['fixture_id'];
            $cold = [];
            $warm = [];
            $fingerprint = '';

            for ($sample = 0; $sample < $coldSamples; $sample++) {
                gc_collect_cycles();
                $started = hrtime(true);
                $fixture = JsonBehaviorBaselineFixture::load($this->fixtureRoot, $fixtureId);
                $runner = $this->catalog->createRunner($entry);
                $result = $runner->run($fixture);
                $elapsed = self::millisecondsSince($started);
                $fingerprint = $this->verifyResult($entry, $fixture, $result, $fingerprint);
                $cold[] = $elapsed;
            }

            $fixture = JsonBehaviorBaselineFixture::load($this->fixtureRoot, $fixtureId);
            $runner = $this->catalog->createRunner($entry);
            $fixture->resetIdSequences();
            $warmup = $runner->run($fixture);
            $fingerprint = $this->verifyResult($entry, $fixture, $warmup, $fingerprint);

            for ($sample = 0; $sample < $warmSamples; $sample++) {
                $fixture->resetIdSequences();
                $started = hrtime(true);
                $result = $runner->run($fixture);
                $elapsed = self::millisecondsSince($started);
                $fingerprint = $this->verifyResult($entry, $fixture, $result, $fingerprint);
                $warm[] = $elapsed;
            }

            $coldStats = self::summarizeSamples($cold);
            $warmStats = self::summarizeSamples($warm);
            $guardrailPassed = $coldStats['max_ms'] <= self::GUARDRAILS['per_scenario_cold_max_ms']
                && $warmStats['p95_ms'] <= self::GUARDRAILS['per_scenario_warm_p95_ms'];

            $scenarioReports[] = [
                'fixture_id' => $fixtureId,
                'scenario_id' => (string)$entry['scenario_id'],
                'group' => (string)$entry['group'],
                'runner_class' => (string)$entry['runner_class'],
                'fingerprint_sha256' => $fingerprint,
                'cold' => ['samples_ms' => $cold, 'stats' => $coldStats],
                'warm' => ['samples_ms' => $warm, 'stats' => $warmStats],
                'guardrail_passed' => $guardrailPassed,
            ];

            $group = (string)$entry['group'];
            $groupSamples[$group]['cold'] = [...($groupSamples[$group]['cold'] ?? []), ...$cold];
            $groupSamples[$group]['warm'] = [...($groupSamples[$group]['warm'] ?? []), ...$warm];
            $allCold = [...$allCold, ...$cold];
            $allWarm = [...$allWarm, ...$warm];
        }

        $groups = [];
        ksort($groupSamples, SORT_STRING);
        foreach ($groupSamples as $group => $samples) {
            $groups[$group] = [
                'scenario_count' => $this->catalog->groupCounts()[$group],
                'cold' => self::summarizeSamples($samples['cold']),
                'warm' => self::summarizeSamples($samples['warm']),
            ];
        }

        $aggregateCold = self::summarizeSamples($allCold);
        $aggregateWarm = self::summarizeSamples($allWarm);
        $allScenarioGuardrails = array_reduce(
            $scenarioReports,
            static fn(bool $carry, array $scenario): bool => $carry && $scenario['guardrail_passed'],
            true
        );
        $guardrailPassed = $allScenarioGuardrails
            && $aggregateWarm['p95_ms'] <= self::GUARDRAILS['aggregate_warm_p95_ms'];

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'measurement_scope' => 'local_or_ci_isolated_fixture_runner',
            'production_evidence' => false,
            'generated_at_utc' => gmdate(DATE_ATOM),
            'definitions' => [
                'cold' => 'fixture load + runner construction + one deterministic scenario run',
                'warm' => 'same loaded fixture and runner after one unmeasured warmup; ID sequences reset before each run',
                'percentile' => 'nearest-rank',
            ],
            'environment' => [
                'php_version' => PHP_VERSION,
                'php_sapi' => PHP_SAPI,
                'os_family' => PHP_OS_FAMILY,
                'architecture' => PHP_INT_SIZE * 8,
                'opcache_cli' => (string)ini_get('opcache.enable_cli'),
                'memory_limit' => (string)ini_get('memory_limit'),
                'peak_memory_bytes' => memory_get_peak_usage(true),
            ],
            'index' => [
                'contract_version' => JsonBaselineScenarioCatalog::CONTRACT_VERSION,
                'foundation_fixture' => $this->catalog->foundation()['fixture_id'],
                'foundation_timed' => false,
                'timed_scenario_count' => count($scenarioReports),
                'group_counts' => $this->catalog->groupCounts(),
            ],
            'samples' => [
                'cold_per_scenario' => $coldSamples,
                'warm_per_scenario' => $warmSamples,
                'cold_total' => count($allCold),
                'warm_total' => count($allWarm),
            ],
            'scenarios' => $scenarioReports,
            'groups' => $groups,
            'aggregate' => [
                'cold' => $aggregateCold,
                'warm' => $aggregateWarm,
            ],
            'guardrails' => [
                'classification' => 'catastrophic_regression_guard_only_not_product_slo',
                'limits_ms' => self::GUARDRAILS,
                'passed' => $guardrailPassed,
            ],
            'safety' => [
                'network_contacted' => false,
                'production_contacted' => false,
                'database_contacted' => false,
                'database_write_executed' => false,
                'live_json_changed' => false,
                'persistent_config_changed' => false,
                'webhook_changed' => false,
                'cron_changed' => false,
                'production_changed' => false,
            ],
        ];
    }

    public static function summarizeSamples(array $samples): array
    {
        if ($samples === []) {
            throw new InvalidArgumentException('Latency samples cannot be empty.');
        }
        $values = [];
        foreach ($samples as $sample) {
            if (!is_int($sample) && !is_float($sample)) {
                throw new InvalidArgumentException('Latency samples must be numeric.');
            }
            $value = (float)$sample;
            if (!is_finite($value) || $value < 0.0) {
                throw new InvalidArgumentException('Latency samples must be finite and non-negative.');
            }
            $values[] = $value;
        }
        sort($values, SORT_NUMERIC);
        return [
            'count' => count($values),
            'min_ms' => self::rounded($values[0]),
            'median_ms' => self::rounded(self::percentile($values, 0.50)),
            'p95_ms' => self::rounded(self::percentile($values, 0.95)),
            'max_ms' => self::rounded($values[array_key_last($values)]),
            'mean_ms' => self::rounded(array_sum($values) / count($values)),
        ];
    }

    private function verifyResult(
        array $entry,
        JsonBehaviorBaselineFixture $fixture,
        array $result,
        string $previousFingerprint
    ): string {
        if (($result['scenario_id'] ?? null) !== $entry['scenario_id']) {
            throw new RuntimeException('Latency scenario identity changed: ' . $entry['fixture_id'] . '.');
        }
        $fingerprint = (string)($result['fingerprint_sha256'] ?? '');
        if (!hash_equals((string)$entry['fingerprint_sha256'], $fingerprint)) {
            throw new RuntimeException('Latency scenario frozen fingerprint changed: ' . $entry['fixture_id'] . '.');
        }
        if ($previousFingerprint !== '' && !hash_equals($previousFingerprint, $fingerprint)) {
            throw new RuntimeException('Latency scenario fingerprint changed between samples: ' . $entry['fixture_id'] . '.');
        }
        if (!(new JsonBehaviorBaselineResult($fixture->normalizer()))->verify($result)) {
            throw new RuntimeException('Latency scenario result fingerprint does not verify: ' . $entry['fixture_id'] . '.');
        }
        return $fingerprint;
    }

    private static function percentile(array $sortedValues, float $percentile): float
    {
        $rank = max(1, (int)ceil($percentile * count($sortedValues)));
        return $sortedValues[$rank - 1];
    }

    private static function millisecondsSince(int $started): float
    {
        return self::rounded((hrtime(true) - $started) / 1_000_000);
    }

    private static function rounded(float $value): float
    {
        return round($value, 6);
    }
}
