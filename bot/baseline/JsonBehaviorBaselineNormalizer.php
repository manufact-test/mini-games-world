<?php
declare(strict_types=1);

final class JsonBehaviorBaselineNormalizer
{
    public const CONTRACT_VERSION = 'mvp14r2-normalizer-v1';

    /** @var list<array{pattern:string,segments:list<string>,category:string,specificity:int}> */
    private array $rules = [];
    /** @var array<string, array<string, string>> */
    private array $explicitAliases = [];
    /** @var array<string, array<string, string>> */
    private array $generatedAliases = [];
    /** @var array<string, int> */
    private array $generatedCounters = [];

    /**
     * @param array<string, string> $pathCategories JSON-pointer-like path => alias category.
     * @param array<string, array<string, string>> $explicitAliases category => source identity/value => alias.
     */
    public function __construct(array $pathCategories = [], array $explicitAliases = [])
    {
        foreach ($pathCategories as $pattern => $category) {
            if (!is_string($pattern) || !is_string($category)) {
                throw new InvalidArgumentException('Baseline normalization rules must be string mappings.');
            }
            $pattern = trim($pattern);
            $category = strtoupper(trim($category));
            if ($pattern === '' || !str_starts_with($pattern, '/')) {
                throw new InvalidArgumentException('Baseline normalization paths must be absolute JSON pointers.');
            }
            if (preg_match('/\A[A-Z][A-Z0-9_]{0,31}\z/', $category) !== 1) {
                throw new InvalidArgumentException('Baseline normalization category is invalid.');
            }
            $segments = array_map(
                static fn(string $segment): string => self::unescapePointerSegment($segment),
                explode('/', substr($pattern, 1))
            );
            if ($segments === [] || in_array('', $segments, true)) {
                throw new InvalidArgumentException('Baseline normalization path contains an empty segment.');
            }
            foreach ($segments as $segment) {
                if ($segment === '**') {
                    throw new InvalidArgumentException('Recursive wildcard normalization is not supported.');
                }
            }
            $this->rules[] = [
                'pattern' => $pattern,
                'segments' => array_values($segments),
                'category' => $category,
                'specificity' => count(array_filter(
                    $segments,
                    static fn(string $segment): bool => $segment !== '*'
                )),
            ];
        }

        usort($this->rules, static function (array $left, array $right): int {
            $specificity = $right['specificity'] <=> $left['specificity'];
            return $specificity !== 0 ? $specificity : strcmp($left['pattern'], $right['pattern']);
        });

        foreach ($explicitAliases as $category => $aliases) {
            if (!is_string($category) || !is_array($aliases)) {
                throw new InvalidArgumentException('Baseline explicit aliases must be grouped by category.');
            }
            $category = strtoupper(trim($category));
            if (preg_match('/\A[A-Z][A-Z0-9_]{0,31}\z/', $category) !== 1) {
                throw new InvalidArgumentException('Baseline explicit alias category is invalid.');
            }
            $seenAliases = [];
            foreach ($aliases as $source => $alias) {
                if ((!is_string($source) && !is_int($source)) || !is_string($alias)) {
                    throw new InvalidArgumentException('Baseline explicit aliases must be string mappings.');
                }
                $source = (string)$source;
                $alias = trim($alias);
                if (preg_match('/\A<[A-Z][A-Z0-9_:-]{0,63}>\z/', $alias) !== 1) {
                    throw new InvalidArgumentException('Baseline explicit alias format is invalid.');
                }
                if (isset($seenAliases[$alias])) {
                    throw new InvalidArgumentException('Baseline explicit aliases must be unique per category.');
                }
                $seenAliases[$alias] = true;
                $this->explicitAliases[$category][$source] = $alias;
            }
        }
    }

    public function normalize(mixed $value): mixed
    {
        $this->generatedAliases = [];
        $this->generatedCounters = [];
        return $this->normalizeValue($value, []);
    }

    public function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    public function fingerprint(mixed $value): string
    {
        return hash('sha256', $this->canonicalJson($value));
    }

    /** @param list<string> $path */
    private function normalizeValue(mixed $value, array $path): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                $normalized = [];
                foreach ($value as $index => $item) {
                    $normalized[] = $this->normalizeValue($item, [...$path, (string)$index]);
                }
                return $normalized;
            }

            ksort($value, SORT_STRING);
            $normalized = [];
            foreach ($value as $key => $item) {
                if (!is_int($key) && !is_string($key)) {
                    throw new InvalidArgumentException('Baseline objects require string or integer keys.');
                }
                $normalized[(string)$key] = $this->normalizeValue($item, [...$path, (string)$key]);
            }
            return $normalized;
        }

        if (is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException('Baseline values must be JSON-compatible.');
        }
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException('Baseline floating-point values must be finite.');
        }

        $category = $this->categoryForPath($path);
        return $category === null ? $value : $this->alias($category, $value);
    }

    /** @param list<string> $path */
    private function categoryForPath(array $path): ?string
    {
        foreach ($this->rules as $rule) {
            if (count($rule['segments']) !== count($path)) continue;
            $matches = true;
            foreach ($rule['segments'] as $index => $segment) {
                if ($segment !== '*' && $segment !== $path[$index]) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) return $rule['category'];
        }
        return null;
    }

    private function alias(string $category, mixed $value): string
    {
        if (is_array($value) || is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException('Baseline aliases may replace only scalar or null values.');
        }
        $identity = self::scalarIdentity($value);
        $fallbackIdentity = is_bool($value)
            ? ($value ? 'true' : 'false')
            : ($value === null ? 'null' : (string)$value);

        $explicit = $this->explicitAliases[$category][$identity]
            ?? $this->explicitAliases[$category][$fallbackIdentity]
            ?? null;
        if (is_string($explicit)) return $explicit;

        if (isset($this->generatedAliases[$category][$identity])) {
            return $this->generatedAliases[$category][$identity];
        }
        $number = $this->generatedCounters[$category] ?? 0;
        do {
            $number++;
            $alias = '<' . $category . '_' . $number . '>';
        } while (in_array($alias, $this->explicitAliases[$category] ?? [], true));
        $this->generatedCounters[$category] = $number;
        $this->generatedAliases[$category][$identity] = $alias;
        return $alias;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
            return $value;
        }
        if (is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException('Baseline values must be JSON-compatible.');
        }
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException('Baseline floating-point values must be finite.');
        }
        return $value;
    }

    private static function scalarIdentity(mixed $value): string
    {
        return match (true) {
            $value === null => 'null:null',
            is_bool($value) => 'bool:' . ($value ? 'true' : 'false'),
            is_int($value) => 'int:' . $value,
            is_float($value) => 'float:' . json_encode(
                $value,
                JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            ),
            is_string($value) => 'string:' . $value,
            default => throw new InvalidArgumentException('Baseline alias value is not scalar.'),
        };
    }

    private static function unescapePointerSegment(string $segment): string
    {
        if (preg_match('/~(?![01])/', $segment) === 1) {
            throw new InvalidArgumentException('Baseline normalization path contains invalid JSON-pointer escaping.');
        }
        return str_replace(['~1', '~0'], ['/', '~'], $segment);
    }
}
