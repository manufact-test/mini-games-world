<?php
declare(strict_types=1);

trait RuntimeRealtimeValueTrait
{
    private function assertDatabaseRoute(): void
    {
        if (!$this->router->enabled()
            || $this->router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE
            || $this->router->routeFor('realtime') !== RuntimeStorageRouter::DRIVER_DATABASE) {
            throw new RuntimeException('Realtime DB runtime requires accounts and realtime routing.');
        }
    }

    private function database(): DatabaseConnectionInterface
    {
        if ($this->connection !== null) return $this->connection;
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Realtime DB runtime requires an enabled database.');
        }
        return $this->connection = PdoConnectionFactory::create($databaseConfig);
    }

    private function room(mixed $value): string
    {
        return trim((string)$value) === 'gold' ? 'gold' : 'match';
    }

    private function requiredText(mixed $value, int $maxLength, string $label): string
    {
        $text = trim((string)$value);
        if ($text === '') throw new RuntimeException('Missing ' . $label . '.');
        if (strlen($text) > $maxLength) throw new RuntimeException(ucfirst($label) . ' is too long.');
        return $text;
    }

    private function nullableText(mixed $value, int $maxLength): ?string
    {
        $text = trim((string)($value ?? ''));
        if ($text === '') return null;
        if (strlen($text) > $maxLength) throw new RuntimeException('Realtime text value is too long.');
        return $text;
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        $number = is_numeric($value) ? (int)$value : $fallback;
        return $number > 0 ? $number : $fallback;
    }

    private function nonNegativeInt(mixed $value, string $label): int
    {
        if (is_int($value)) $number = $value;
        elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) $number = (int)$value;
        elseif (is_float($value) && floor($value) === $value) $number = (int)$value;
        else throw new RuntimeException('Invalid ' . $label . '.');
        if ($number < 0) throw new RuntimeException('Negative ' . $label . ' is not allowed.');
        return $number;
    }

    private function nullableTimestamp(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));
        return $text === '' ? null : $this->timestamp($text);
    }

    private function timestamp(mixed ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $text = trim((string)($candidate ?? ''));
            if ($text === '') continue;
            try {
                return (new DateTimeImmutable($text))
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s.u');
            } catch (Throwable) {
                continue;
            }
        }
        return '1970-01-01 00:00:00.000000';
    }

    private function timestampSortValue(mixed $value): float
    {
        $text = trim((string)($value ?? ''));
        if ($text === '') return 0.0;
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $text, new DateTimeZone('UTC'));
        if (!$parsed) {
            try {
                $parsed = new DateTimeImmutable($text, new DateTimeZone('UTC'));
            } catch (Throwable) {
                return 0.0;
            }
        }
        return (float)$parsed->format('U.u');
    }

    private function decodeJson(mixed $value, string $label): mixed
    {
        if ($value === null || trim((string)$value) === '') return null;
        try {
            return json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Invalid ' . $label . '.');
        }
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }

    private function isTerminalStatus(string $status): bool
    {
        return in_array($status, ['finished', 'cancelled', 'expired', 'abandoned', 'failed'], true);
    }
}
