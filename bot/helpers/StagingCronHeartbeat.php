<?php
declare(strict_types=1);

final class StagingCronHeartbeat
{
    private const STAGING_HOST = 'seashell-okapi-889488.hostingersite.com';
    private const FILE_NAME = '.staging-weekly-match-cron-heartbeat.json';
    private const MAX_AGE_SECONDS = 691200; // Eight days: one weekly cycle plus margin.

    public static function recordSuccessfulRun(array $config, bool $isCli): bool
    {
        if (!self::isIsolatedStaging($config)) {
            return false;
        }

        $path = self::filePath($config);
        $previous = self::readPayload($path);
        $previousAt = trim((string)($previous['executed_at_utc'] ?? ''));
        $runCount = max(0, (int)($previous['run_count'] ?? 0)) + 1;

        $payload = [
            'schema_version' => 1,
            'service' => 'weekly-match',
            'environment' => 'staging',
            'host' => self::STAGING_HOST,
            'success' => true,
            'transport' => $isCli ? 'cli' : 'http',
            'run_count' => $runCount,
            'previous_executed_at_utc' => $previousAt !== '' ? $previousAt : null,
            'executed_at_utc' => gmdate('c'),
        ];

        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            return false;
        }

        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
            return false;
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            return false;
        }
        @chmod($path, 0600);

        return true;
    }

    public static function status(array $config): array
    {
        if (!self::isIsolatedStaging($config)) {
            return self::emptyStatus();
        }

        $payload = self::readPayload(self::filePath($config));
        $executedAt = trim((string)($payload['executed_at_utc'] ?? ''));
        $timestamp = $executedAt !== '' ? strtotime($executedAt) : false;
        $ageSeconds = $timestamp !== false ? max(0, time() - $timestamp) : null;

        $valid = ($payload['schema_version'] ?? null) === 1
            && ($payload['service'] ?? null) === 'weekly-match'
            && ($payload['environment'] ?? null) === 'staging'
            && ($payload['host'] ?? null) === self::STAGING_HOST
            && ($payload['success'] ?? null) === true
            && in_array(($payload['transport'] ?? null), ['cli', 'http'], true)
            && (int)($payload['run_count'] ?? 0) >= 1
            && $timestamp !== false;

        return [
            'observed_successful_run' => $valid,
            'fresh_within_eight_days' => $valid && $ageSeconds !== null && $ageSeconds <= self::MAX_AGE_SECONDS,
            'recurring_run_observed' => $valid
                && (int)($payload['run_count'] ?? 0) >= 2
                && trim((string)($payload['previous_executed_at_utc'] ?? '')) !== '',
            'transport' => $valid ? (string)$payload['transport'] : null,
            'run_count' => $valid ? (int)$payload['run_count'] : 0,
            'executed_at_utc' => $valid ? $executedAt : null,
            'previous_executed_at_utc' => $valid ? ($payload['previous_executed_at_utc'] ?? null) : null,
            'age_seconds' => $valid ? $ageSeconds : null,
        ];
    }

    private static function isIsolatedStaging(array $config): bool
    {
        $environment = strtolower(trim((string)($config['environment'] ?? '')));
        $baseUrl = rtrim(trim((string)($config['base_url'] ?? '')), '/');
        $scheme = strtolower((string)(parse_url($baseUrl, PHP_URL_SCHEME) ?: ''));
        $host = strtolower((string)(parse_url($baseUrl, PHP_URL_HOST) ?: ''));

        return $environment === 'staging'
            && $scheme === 'https'
            && $host === self::STAGING_HOST;
    }

    private static function filePath(array $config): string
    {
        $dataDir = trim((string)($config['data_dir'] ?? ''));
        if ($dataDir === '') {
            $dataDir = dirname(__DIR__) . '/data';
        }

        return rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR . self::FILE_NAME;
    }

    private static function readPayload(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function emptyStatus(): array
    {
        return [
            'observed_successful_run' => false,
            'fresh_within_eight_days' => false,
            'recurring_run_observed' => false,
            'transport' => null,
            'run_count' => 0,
            'executed_at_utc' => null,
            'previous_executed_at_utc' => null,
            'age_seconds' => null,
        ];
    }
}
