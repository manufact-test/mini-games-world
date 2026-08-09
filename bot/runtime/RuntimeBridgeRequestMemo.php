<?php
declare(strict_types=1);

final class RuntimeBridgeRequestMemo
{
    private static bool $active = false;
    /** @var array<string,array<string,mixed>> */
    private static array $values = [];

    public static function begin(): void
    {
        self::$active = true;
        self::$values = [];
    }

    public static function remember(string $key, callable $callback): mixed
    {
        if (!self::$active) {
            return $callback();
        }

        $key = trim($key);
        if ($key === '') {
            throw new InvalidArgumentException('Runtime bridge request memo key is empty.');
        }
        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }

        $value = $callback();
        self::$values[$key] = $value;
        return $value;
    }

    public static function scope(array $config): string
    {
        $database = is_array($config['database'] ?? null) ? $config['database'] : [];
        $runtime = is_array(($config['feature_flags']['database_runtime'] ?? null))
            ? $config['feature_flags']['database_runtime']
            : [];

        $identity = [
            'environment' => (string)($config['environment'] ?? ''),
            'driver' => (string)($database['driver'] ?? ''),
            'host' => (string)($database['host'] ?? ''),
            'port' => (string)($database['port'] ?? ''),
            'name' => (string)($database['name'] ?? ''),
            'runtime' => $runtime,
        ];

        return hash('sha256', self::canonicalJson($identity));
    }

    public static function sourceFingerprint(array $sections): string
    {
        return hash('sha256', self::canonicalJson($sections));
    }

    private static function canonicalJson(mixed $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) return $item;
            if (array_is_list($item)) {
                return array_map($normalize, $item);
            }
            ksort($item, SORT_STRING);
            foreach ($item as $key => $nested) {
                $item[$key] = $normalize($nested);
            }
            return $item;
        };

        $json = json_encode(
            $normalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($json === false) {
            throw new RuntimeException('Runtime bridge request memo could not fingerprint source data.');
        }
        return $json;
    }
}
