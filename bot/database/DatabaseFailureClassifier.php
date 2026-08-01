<?php
declare(strict_types=1);

final class DatabaseFailureClassifier
{
    public static function classify(Throwable $error): array
    {
        $current = $error;
        $sqlState = '';
        $driverCode = null;
        $message = '';

        while ($current instanceof Throwable) {
            $message .= ' ' . strtolower($current->getMessage());
            if ($current instanceof PDOException) {
                $errorInfo = is_array($current->errorInfo ?? null) ? $current->errorInfo : [];
                $sqlState = strtoupper(trim((string)($errorInfo[0] ?? $current->getCode() ?? '')));
                $rawDriverCode = $errorInfo[1] ?? null;
                $driverCode = is_numeric($rawDriverCode) ? (int)$rawDriverCode : null;
                break;
            }
            $current = $current->getPrevious();
        }

        return [
            'category' => self::category($sqlState, $driverCode, $message),
            'sqlstate' => $sqlState !== '' ? $sqlState : null,
            'driver_code' => $driverCode,
        ];
    }

    private static function category(string $sqlState, ?int $driverCode, string $message): string
    {
        if (str_contains($message, 'pdo mysql extension is not available')) {
            return 'pdo_mysql_unavailable';
        }
        if ($sqlState === '28000' || $driverCode === 1045 || str_contains($message, 'access denied')) {
            return 'credentials_rejected';
        }
        if ($driverCode === 1049 || str_contains($message, 'unknown database')) {
            return 'database_not_found';
        }
        if (in_array($driverCode, [2002, 2003, 2005, 2006], true)
            || str_contains($message, 'connection refused')
            || str_contains($message, 'timed out')
            || str_contains($message, 'getaddrinfo')) {
            return 'server_unreachable';
        }
        if ($sqlState === 'HY000') {
            return 'mysql_runtime_error';
        }
        return 'unknown_connection_failure';
    }
}
