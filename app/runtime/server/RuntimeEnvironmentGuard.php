<?php
declare(strict_types=1);

namespace Mgw\CleanRuntime\Server;

final class RuntimeEnvironmentGuard
{
    public static function assertAvailable(RuntimeConfig $config, array $server): void
    {
        if ($config->environment !== 'staging') {
            throw new \RuntimeException('Clean runtime is disabled outside staging.');
        }

        $requestHost = self::requestHost($server);
        if ($requestHost === '') {
            if (PHP_SAPI === 'cli') return;
            throw new \RuntimeException('Clean runtime request host is missing.');
        }

        if (!in_array($requestHost, $config->allowedHosts, true)) {
            throw new \RuntimeException('Clean runtime request host is not allowlisted.');
        }
    }

    public static function requestHost(array $server): string
    {
        $host = trim((string)($server['HTTP_HOST'] ?? ''));
        if ($host === '') $host = trim((string)($server['SERVER_NAME'] ?? ''));
        return self::normalizeHost($host);
    }

    private static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') return '';

        if (str_contains($host, '://')) {
            $host = (string)(parse_url($host, PHP_URL_HOST) ?: '');
        } elseif ($host[0] === '[') {
            $end = strpos($host, ']');
            $host = $end === false ? $host : substr($host, 1, $end - 1);
        } elseif (substr_count($host, ':') === 1) {
            $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        }

        return rtrim(strtolower(trim($host)), '.');
    }
}
