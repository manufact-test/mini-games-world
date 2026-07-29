<?php
declare(strict_types=1);

final class JsonBehaviorBaselineFixture
{
    public const CONTRACT_VERSION = 'mvp14r2-fixture-v1';

    /** @var array<string, int> */
    private array $idCursors = [];

    private function __construct(private array $data)
    {
    }

    public static function load(string $fixtureRoot, string $fixtureId): self
    {
        if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $fixtureId) !== 1) {
            throw new InvalidArgumentException('Baseline fixture ID is invalid.');
        }
        if ($fixtureRoot === '' || is_link($fixtureRoot) || !is_dir($fixtureRoot)) {
            throw new RuntimeException('Baseline fixture root is unavailable or unsafe.');
        }
        $canonicalRoot = realpath($fixtureRoot);
        if (!is_string($canonicalRoot) || !hash_equals($fixtureRoot, $canonicalRoot)) {
            throw new RuntimeException('Baseline fixture root must use its exact canonical path.');
        }

        $path = $canonicalRoot . '/' . $fixtureId . '.json';
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Baseline fixture file is unavailable or unsafe.');
        }
        $canonicalPath = realpath($path);
        if (!is_string($canonicalPath)
            || !str_starts_with($canonicalPath, $canonicalRoot . '/')
            || !hash_equals($path, $canonicalPath)) {
            throw new RuntimeException('Baseline fixture path escaped its root.');
        }

        $raw = file_get_contents($canonicalPath);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('Baseline fixture file is empty.');
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Baseline fixture JSON is invalid.', 0, $error);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Baseline fixture must be a JSON object.');
        }

        self::validate($data, $fixtureId);
        return new self($data);
    }

    public function fixtureId(): string
    {
        return (string)$this->data['fixture_id'];
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            (string)$this->data['clock']['now'],
            new DateTimeZone('UTC')
        );
    }

    public function randomSeed(): int
    {
        return (int)$this->data['random_seed'];
    }

    public function state(): array
    {
        return $this->data['state'];
    }

    public function scenario(): array
    {
        return $this->data['scenario'];
    }

    public function normalizer(): JsonBehaviorBaselineNormalizer
    {
        return new JsonBehaviorBaselineNormalizer(
            $this->data['normalization']['path_categories'],
            $this->data['normalization']['aliases']
        );
    }

    public function nextId(string $category): string
    {
        $category = strtolower(trim($category));
        if (preg_match('/\A[a-z][a-z0-9_]{0,31}\z/', $category) !== 1) {
            throw new InvalidArgumentException('Baseline ID sequence category is invalid.');
        }
        $sequence = $this->data['id_sequence'][$category] ?? null;
        if (!is_array($sequence)) {
            throw new RuntimeException('Baseline ID sequence category is unavailable.');
        }
        $cursor = $this->idCursors[$category] ?? 0;
        if (!array_key_exists($cursor, $sequence)) {
            throw new RuntimeException('Baseline ID sequence is exhausted: ' . $category . '.');
        }
        $this->idCursors[$category] = $cursor + 1;
        return (string)$sequence[$cursor];
    }

    public function resetIdSequences(): void
    {
        $this->idCursors = [];
    }

    private static function validate(array $data, string $fixtureId): void
    {
        if (($data['contract_version'] ?? null) !== self::CONTRACT_VERSION
            || ($data['fixture_id'] ?? null) !== $fixtureId) {
            throw new RuntimeException('Baseline fixture identity is invalid.');
        }

        $clock = $data['clock'] ?? null;
        if (!is_array($clock)
            || ($clock['timezone']'] ?? null) !== 'UTC'
            || !is_string($clock['now'] ?? null)) {
            throw new RuntimeException('Baseline fixture clock is invalid.');
        }
        try {
            $now = new DateTimeImmutable((string)$clock['now']);
        } catch (Throwable $error) {
            throw new RuntimeException('Baseline fixture clock timestamp is invalid.', 0, $error);
        }
        if ($now->getOffset() !== 0) {
            throw new RuntimeException('Baseline fixture clock must use UTC.');
        }

        $seed = $data['random_seed'] ?? null;
        if (!is_int($seed) || $seed < 0) {
            throw new RuntimeException('Baseline fixture random seed is invalid.');
        }

        $sequences = $data['id_sequence'] ?? null;
        if (!is_array($sequences) || array_is_list($sequences) || $sequences === []) {
            throw new RuntimeException('Baseline fixture ID sequences are unavailable.');
        }
        foreach ($sequences as $category => $sequence) {
            if (!is_string($category)
                || preg_match('/\A[a-z][a-z0-9_]{0,31}\z/', $category) !== 1
                || !is_array($sequence)
                || !array_is_list($sequence)
                || $sequence === []) {
                throw new RuntimeException('Baseline fixture ID sequence is invalid.');
            }
            $seen = [];
            foreach ($sequence as $value) {
                if (!is_string($value) || trim($value) === '' || isset($seen[$value])) {
                    throw new RuntimeException('Baseline fixture IDs must be non-empty and unique per category.');
                }
                $seen[$value] = true;
            }
        }

        $normalization = $data['normalization'] ?? null;
        if (!is_array($normalization)
            || !is_array($normalization['path_categories'] ?? null)
            || !is_array($normalization['aliases'] ?? null)) {
            throw new RuntimeException('Baseline fixture normalization contract is invalid.');
        }
        new JsonBehaviorBaselineNormalizer(
            $normalization['path_categories'],
            $normalization['aliases']
        );

        foreach (['state', 'scenario'] as $field) {
            if (!is_array($data[$field] ?? null) || array_is_list($data[$field])) {
                throw new RuntimeException('Baseline fixture field must be an object: ' . $field . '.');
            }
        }
        $scenarioId = trim((string)($data['scenario']['id'] ?? ''));
        if (preg_match('/\A[a-z0-9][a-z0-9_.-]{0,95}\z/', $scenarioId) !== 1) {
            throw new RuntimeException('Baseline fixture scenario ID is invalid.');
        }
    }
}
