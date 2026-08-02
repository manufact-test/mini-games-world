<?php
declare(strict_types=1);

final class JsonBaselineScenarioCatalog
{
    public const CONTRACT_VERSION = 'mvp14r2-scenario-index-v1';

    private const ALLOWED_RUNNERS = [
        'JsonAccountPassiveBaselineScenario',
        'JsonInviteMatchmakingBaselineScenario',
        'JsonGamesBaselineScenario',
        'JsonEconomySupportingBaselineScenario',
    ];

    private function __construct(private array $data)
    {
    }

    public static function load(string $indexPath): self
    {
        if ($indexPath === '' || is_link($indexPath) || !is_file($indexPath) || !is_readable($indexPath)) {
            throw new RuntimeException('Baseline scenario index is unavailable or unsafe.');
        }
        $canonical = realpath($indexPath);
        if (!is_string($canonical) || !hash_equals($indexPath, $canonical)) {
            throw new RuntimeException('Baseline scenario index must use its exact canonical path.');
        }
        $raw = file_get_contents($canonical);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('Baseline scenario index is empty.');
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Baseline scenario index JSON is invalid.', 0, $error);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Baseline scenario index must be a JSON object.');
        }
        self::validate($data);
        return new self($data);
    }

    public function foundation(): array
    {
        return $this->data['foundation'];
    }

    public function timedEntries(): array
    {
        return $this->data['scenarios'];
    }

    public function allEntries(): array
    {
        return [$this->foundation(), ...$this->timedEntries()];
    }

    public function createRunner(array $entry): object
    {
        $class = (string)($entry['runner_class'] ?? '');
        if (!in_array($class, self::ALLOWED_RUNNERS, true) || !class_exists($class)) {
            throw new RuntimeException('Baseline scenario runner is unavailable: ' . $class . '.');
        }
        return match ($class) {
            'JsonAccountPassiveBaselineScenario' => new JsonAccountPassiveBaselineScenario(),
            'JsonInviteMatchmakingBaselineScenario' => new JsonInviteMatchmakingBaselineScenario(),
            'JsonGamesBaselineScenario' => new JsonGamesBaselineScenario(),
            'JsonEconomySupportingBaselineScenario' => new JsonEconomySupportingBaselineScenario(),
        };
    }

    public function groupCounts(): array
    {
        $counts = [];
        foreach ($this->timedEntries() as $entry) {
            $group = (string)$entry['group'];
            $counts[$group] = ($counts[$group] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);
        return $counts;
    }

    private static function validate(array $data): void
    {
        if (($data['contract_version'] ?? null) !== self::CONTRACT_VERSION) {
            throw new RuntimeException('Baseline scenario index contract version is invalid.');
        }
        $foundation = $data['foundation'] ?? null;
        $scenarios = $data['scenarios'] ?? null;
        if (!is_array($foundation) || array_is_list($foundation)
            || !is_array($scenarios) || !array_is_list($scenarios)) {
            throw new RuntimeException('Baseline scenario index structure is invalid.');
        }
        if (($foundation['fixture_id'] ?? null) !== 'foundation'
            || ($foundation['scenario_id'] ?? null) !== 'foundation_contract'
            || ($foundation['group'] ?? null) !== 'foundation'
            || ($foundation['timed'] ?? null) !== false
            || array_key_exists('runner_class', $foundation) && $foundation['runner_class'] !== null) {
            throw new RuntimeException('Baseline foundation index entry is invalid.');
        }
        if (count($scenarios) !== 26) {
            throw new RuntimeException('Baseline scenario index must contain exactly 26 timed scenarios.');
        }

        $fixtureIds = ['foundation' => true];
        $scenarioIds = ['foundation_contract' => true];
        $expectedGroups = [
            'account_passive' => 4,
            'economy_supporting' => 8,
            'games' => 8,
            'invites_matchmaking' => 6,
        ];
        $groupCounts = [];

        foreach ($scenarios as $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new RuntimeException('Baseline scenario index entry must be an object.');
            }
            $fixtureId = (string)($entry['fixture_id'] ?? '');
            $scenarioId = (string)($entry['scenario_id'] ?? '');
            $group = (string)($entry['group'] ?? '');
            $runner = (string)($entry['runner_class'] ?? '');
            $fingerprint = (string)($entry['fingerprint_sha256'] ?? '');
            if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $fixtureId) !== 1
                || preg_match('/\A[a-z0-9][a-z0-9_.-]{0,95}\z/', $scenarioId) !== 1
                || !array_key_exists($group, $expectedGroups)
                || !in_array($runner, self::ALLOWED_RUNNERS, true)
                || ($entry['timed'] ?? null) !== true
                || preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1) {
                throw new RuntimeException('Baseline scenario index entry contract is invalid.');
            }
            if (isset($fixtureIds[$fixtureId]) || isset($scenarioIds[$scenarioId])) {
                throw new RuntimeException('Baseline scenario index identities must be unique.');
            }
            $fixtureIds[$fixtureId] = true;
            $scenarioIds[$scenarioId] = true;
            $groupCounts[$group] = ($groupCounts[$group] ?? 0) + 1;
        }
        ksort($groupCounts, SORT_STRING);
        ksort($expectedGroups, SORT_STRING);
        if ($groupCounts !== $expectedGroups) {
            throw new RuntimeException('Baseline scenario index group coverage is incomplete.');
        }
    }
}
