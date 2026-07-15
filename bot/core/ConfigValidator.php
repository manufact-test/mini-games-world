<?php
declare(strict_types=1);

final class ConfigValidator
{
    public static function validate(array $config, array $server = []): array
    {
        [$environment, $environmentSource] = self::resolveEnvironment($config);

        $baseUrl = trim((string)($config['base_url'] ?? ''));
        $baseHost = self::hostFromUrl($baseUrl);
        $requestHost = self::requestHost($server);
        $allowedHosts = self::resolveAllowedHosts($config, $environment, $baseHost);

        self::validateBaseUrl($environment, $baseUrl, $baseHost);
        self::validateHostAllowlist($baseHost, $requestHost, $allowedHosts);
        self::validateRuntimeFlags($config, $environment);

        if ($environment === Environment::Production) {
            self::validateProductionConfig($config);
        } else {
            self::validateNonProductionIsolation($config, $environment, $baseHost, $allowedHosts);
        }

        $config['environment'] = $environment->value;
        $config['allowed_hosts'] = $allowedHosts;
        $config['environment_context'] = [
            'name' => $environment->value,
            'source' => $environmentSource,
            'is_production' => $environment === Environment::Production,
        ];

        return $config;
    }

    private static function resolveEnvironment(array $config): array
    {
        $envValue = trim((string)(getenv('MGW_ENV') ?: ''));
        $configValue = trim((string)($config['environment'] ?? ''));

        if ($envValue !== '' && $configValue !== '') {
            $fromEnv = Environment::parse($envValue);
            $fromConfig = Environment::parse($configValue);
            if ($fromEnv !== $fromConfig) {
                throw new RuntimeException('Mini Games World environment settings conflict.');
            }

            return [$fromEnv, 'env+config'];
        }

        if ($envValue !== '') {
            return [Environment::parse($envValue), 'env'];
        }

        if ($configValue !== '') {
            return [Environment::parse($configValue), 'config'];
        }

        // Backward-compatible bridge for the current production config. Staging
        // is never inferred: it must always declare itself explicitly.
        $baseHost = self::hostFromUrl(trim((string)($config['base_url'] ?? '')));
        if (self::isLocalHost($baseHost)) {
            return [Environment::Local, 'legacy-inference'];
        }

        return [Environment::Production, 'legacy-inference'];
    }

    private static function resolveAllowedHosts(array $config, Environment $environment, string $baseHost): array
    {
        $raw = $config['allowed_hosts'] ?? [];
        if (is_string($raw)) {
            $raw = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!is_array($raw)) {
            throw new RuntimeException('Mini Games World allowed_hosts must be an array or comma-separated string.');
        }

        $hosts = [];
        foreach ($raw as $host) {
            $normalized = self::normalizeHost((string)$host);
            if ($normalized === '') {
                continue;
            }
            if (str_contains($normalized, '*') || str_contains($normalized, '/')) {
                throw new RuntimeException('Mini Games World allowed_hosts contains an unsupported host value.');
            }
            $hosts[$normalized] = true;
        }

        if (!$hosts) {
            if ($environment === Environment::Staging) {
                throw new RuntimeException('Staging requires an explicit allowed_hosts list.');
            }

            if ($environment === Environment::Local) {
                foreach (['localhost', '127.0.0.1', '::1'] as $host) {
                    $hosts[$host] = true;
                }
            } elseif ($baseHost !== '') {
                // Keeps the current production deployment compatible while its
                // private config is upgraded with an explicit allowlist.
                $hosts[$baseHost] = true;
            }
        }

        if (!$hosts) {
            throw new RuntimeException('Mini Games World host allowlist is empty.');
        }

        return array_keys($hosts);
    }

    private static function validateBaseUrl(Environment $environment, string $baseUrl, string $baseHost): void
    {
        if ($baseUrl === '' || $baseHost === '') {
            throw new RuntimeException('Mini Games World base_url is missing or invalid.');
        }

        $scheme = strtolower((string)parse_url($baseUrl, PHP_URL_SCHEME));
        if ($environment->isPublic() && $scheme !== 'https') {
            throw new RuntimeException('Public Mini Games World environments require HTTPS.');
        }

        if ($environment === Environment::Local && !self::isLocalHost($baseHost)) {
            throw new RuntimeException('Local Mini Games World environment must use a local base_url host.');
        }
    }

    private static function validateHostAllowlist(string $baseHost, string $requestHost, array $allowedHosts): void
    {
        if (!in_array($baseHost, $allowedHosts, true)) {
            throw new RuntimeException('Mini Games World base_url host is not allowlisted.');
        }

        if ($requestHost !== '' && !in_array($requestHost, $allowedHosts, true)) {
            throw new RuntimeException('Mini Games World request host is not allowlisted.');
        }
    }

    private static function validateRuntimeFlags(array $config, Environment $environment): void
    {
        if ($environment === Environment::Production && !empty($config['force_browser_dev_user'])) {
            throw new RuntimeException('Forced browser development users are forbidden in production.');
        }

        if ($environment !== Environment::Production && self::liveExternalServiceEnabled($config)) {
            throw new RuntimeException('Live payment services are forbidden outside production.');
        }
    }

    private static function validateProductionConfig(array $config): void
    {
        $token = trim((string)($config['bot_token'] ?? ''));
        if (self::isPlaceholderToken($token)) {
            throw new RuntimeException('Production Telegram bot token is not configured.');
        }
    }

    private static function validateNonProductionIsolation(
        array $config,
        Environment $environment,
        string $baseHost,
        array $allowedHosts
    ): void {
        $guard = $config['environment_guard'] ?? [];
        if (!is_array($guard)) {
            throw new RuntimeException('Mini Games World environment_guard must be an array.');
        }

        $productionHosts = self::normalizeHostList($guard['production_hosts'] ?? []);
        if ($environment === Environment::Staging && !$productionHosts) {
            throw new RuntimeException('Staging requires protected production host metadata.');
        }

        foreach ($allowedHosts as $host) {
            if (in_array($host, $productionHosts, true)) {
                throw new RuntimeException('Non-production host overlaps production.');
            }
        }
        if ($baseHost !== '' && in_array($baseHost, $productionHosts, true)) {
            throw new RuntimeException('Non-production base_url points to production.');
        }

        self::validateBotTokenIsolation($config, $guard, $environment);
        self::validateDataDirectoryIsolation($config, $guard, $environment);
        self::validateDatabaseIsolation($config, $guard, $environment);
    }

    private static function validateBotTokenIsolation(array $config, array $guard, Environment $environment): void
    {
        $token = trim((string)($config['bot_token'] ?? ''));
        if ($environment === Environment::Staging) {
            if (self::isPlaceholderToken($token)) {
                throw new RuntimeException('Staging Telegram bot token is not configured.');
            }

            $expectedBot = ltrim(strtolower(trim((string)($config['staging_bot_username'] ?? ''))), '@');
            if ($expectedBot === '') {
                throw new RuntimeException('Staging requires an expected Telegram bot username.');
            }
        }

        $productionHash = self::normalizeSha256((string)($guard['production_bot_token_sha256'] ?? ''));
        if ($productionHash !== '' && !self::isPlaceholderToken($token)) {
            if (hash_equals($productionHash, hash('sha256', $token))) {
                throw new RuntimeException('Non-production Telegram bot token matches production.');
            }
        }
    }

    private static function validateDataDirectoryIsolation(array $config, array $guard, Environment $environment): void
    {
        $dataDir = self::normalizePath((string)($config['data_dir'] ?? ''));
        $productionDataDir = self::normalizePath((string)($guard['production_data_dir'] ?? ''));

        if ($environment === Environment::Staging && $productionDataDir === '') {
            throw new RuntimeException('Staging requires the protected production data directory.');
        }

        if ($dataDir !== '' && $productionDataDir !== '' && self::pathsEqual($dataDir, $productionDataDir)) {
            throw new RuntimeException('Non-production data directory matches production.');
        }
    }

    private static function validateDatabaseIsolation(array $config, array $guard, Environment $environment): void
    {
        $databaseFingerprint = self::databaseFingerprint($config);
        if ($databaseFingerprint === '') {
            return;
        }

        $productionFingerprint = self::normalizeSha256((string)($guard['production_database_sha256'] ?? ''));
        if ($environment === Environment::Staging && $productionFingerprint === '') {
            throw new RuntimeException('Staging database requires a protected production database fingerprint.');
        }

        if ($productionFingerprint !== '' && hash_equals($productionFingerprint, $databaseFingerprint)) {
            throw new RuntimeException('Non-production database matches production.');
        }
    }

    private static function liveExternalServiceEnabled(array $config): bool
    {
        if (!empty($config['external_payments_enabled'])) {
            return true;
        }

        foreach (['payment_mode', 'telegram_stars_mode', 'google_play_billing_mode'] as $key) {
            if (strtolower(trim((string)($config[$key] ?? ''))) === 'live') {
                return true;
            }
        }

        return false;
    }

    private static function databaseFingerprint(array $config): string
    {
        $database = is_array($config['database'] ?? null) ? $config['database'] : [];
        $identity = [
            'dsn' => trim((string)($database['dsn'] ?? $config['db_dsn'] ?? '')),
            'host' => strtolower(trim((string)($database['host'] ?? $config['db_host'] ?? ''))),
            'port' => (string)($database['port'] ?? $config['db_port'] ?? ''),
            'name' => trim((string)($database['name'] ?? $config['db_name'] ?? '')),
        ];

        if (implode('', $identity) === '') {
            return '';
        }

        return hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function normalizeHostList(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $hosts = [];
        foreach ($raw as $host) {
            $normalized = self::normalizeHost((string)$host);
            if ($normalized !== '') {
                $hosts[$normalized] = true;
            }
        }

        return array_keys($hosts);
    }

    private static function requestHost(array $server): string
    {
        $httpHost = trim((string)($server['HTTP_HOST'] ?? ''));
        if ($httpHost !== '') {
            return self::normalizeHost($httpHost);
        }

        // CLI cron/scripts have no request host and must not be rejected because
        // the host operating system happens to define SERVER_NAME.
        if (trim((string)($server['REQUEST_METHOD'] ?? '')) === '') {
            return '';
        }

        return self::normalizeHost((string)($server['SERVER_NAME'] ?? ''));
    }

    private static function hostFromUrl(string $url): string
    {
        return self::normalizeHost((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    }

    private static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return '';
        }

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

    private static function isLocalHost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private static function isPlaceholderToken(string $token): bool
    {
        return $token === ''
            || in_array($token, [
                'PASTE_BOT_TOKEN_HERE',
                'PASTE_STAGING_BOT_TOKEN_HERE',
                'PUT_TELEGRAM_BOT_TOKEN_HERE',
            ], true);
    }

    private static function normalizeSha256(string $value): string
    {
        $value = strtolower(trim($value));
        if (str_starts_with($value, 'sha256:')) {
            $value = substr($value, 7);
        }

        return preg_match('/^[a-f0-9]{64}$/', $value) ? $value : '';
    }

    private static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $real = realpath($path);
        $path = $real !== false ? $real : $path;
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        return rtrim($path, '/');
    }

    private static function pathsEqual(string $left, string $right): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return strtolower($left) === strtolower($right);
        }

        return $left === $right;
    }
}
