<?php
declare(strict_types=1);

/**
 * Single database-error classifier used by transaction and concurrency owners.
 * Keep retry/recovery decisions tied to exact driver signals instead of message
 * guessing at call sites.
 */
final class DatabaseExceptionClassifier
{
    private function __construct() {}

    public static function isDeadlock(Throwable $error): bool
    {
        foreach (self::chain($error) as $candidate) {
            if (!$candidate instanceof PDOException) continue;

            $info = is_array($candidate->errorInfo ?? null) ? $candidate->errorInfo : [];
            $sqlState = strtoupper(trim((string)($info[0] ?? $candidate->getCode())));
            $driverCode = (int)($info[1] ?? 0);

            // MySQL/MariaDB InnoDB deadlock.
            if ($driverCode === 1213) return true;

            // Some PDO layers preserve only SQLSTATE 40001. Treat it as a
            // deadlock only when no contradictory vendor code is available.
            if ($sqlState === '40001' && $driverCode === 0) return true;
        }

        return false;
    }

    public static function isUniqueConstraintViolation(Throwable $error): bool
    {
        foreach (self::chain($error) as $candidate) {
            if (!$candidate instanceof PDOException) continue;

            $info = is_array($candidate->errorInfo ?? null) ? $candidate->errorInfo : [];
            $sqlState = strtoupper(trim((string)($info[0] ?? $candidate->getCode())));
            $driverCode = (int)($info[1] ?? 0);
            $detail = strtoupper((string)($info[2] ?? $candidate->getMessage()));

            if ($driverCode === 1062) return true; // MySQL duplicate key.

            // SQLite constraint codes can surface either the generic 19 or an
            // extended code. Confirm that the diagnostic is specifically a
            // UNIQUE/PRIMARY KEY collision before allowing recovery.
            if (in_array($driverCode, [19, 1555, 2067], true)
                && (str_contains($detail, 'UNIQUE') || str_contains($detail, 'PRIMARY KEY'))) {
                return true;
            }

            if ($sqlState === '23000'
                && (str_contains($detail, 'DUPLICATE') || str_contains($detail, 'UNIQUE'))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Throwable> */
    private static function chain(Throwable $error): array
    {
        $chain = [];
        $current = $error;
        while ($current instanceof Throwable) {
            $chain[] = $current;
            $previous = $current->getPrevious();
            if (!$previous instanceof Throwable) break;
            $current = $previous;
        }
        return $chain;
    }
}
