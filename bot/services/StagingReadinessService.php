<?php
declare(strict_types=1);

final class StagingReadinessService
{
    private const BUILD = 'mgw-staging-parity-r13.2-v1';
    private const SOURCE_FILES = [
        'app/v110.php',
        'app/assets/js/main-v110.js',
        'app/assets/js/main-v110-handoff-shell.js',
        'bot/api.php',
        'bot/invites.php',
        'bot/notifications.php',
        'bot/core/ConfigValidator.php',
    ];

    public function __construct(private array $config, private string $rootDirectory) {}

    public function report(): array
    {
        $environment = strtolower(trim((string)($this->config['environment'] ?? '')));
        if ($environment !== 'staging') {
            throw new RuntimeException('Staging readiness is unavailable outside staging.');
        }

        $baseUrl = trim((string)($this->config['base_url'] ?? ''));
        $baseHost = strtolower((string)(parse_url($baseUrl, PHP_URL_HOST) ?: ''));
        $allowedHosts = array_values(array_filter(array_map(
            static fn(mixed $host): string => strtolower(trim((string)$host)),
            is_array($this->config['allowed_hosts'] ?? null) ? $this->config['allowed_hosts'] : []
        )));
        sort($allowedHosts, SORT_STRING);

        $database = DatabaseConfig::fromApplicationConfig($this->config);
        $databaseFingerprint = $database->identityFingerprint();
        $dataDirectory = $this->normalizePath((string)($this->config['data_dir'] ?? ''));
        $guard = is_array($this->config['environment_guard'] ?? null)
            ? $this->config['environment_guard']
            : [];

        return [
            'ok' => true,
            'service' => 'mini-games-world-staging-readiness',
            'status' => 'ready_for_parity_validation',
            'environment' => 'staging',
            'build' => self::BUILD,
            'source_fingerprint_sha256' => $this->sourceFingerprint(),
            'base_host' => $baseHost,
            'allowed_hosts' => $allowedHosts,
            'storage' => [
                'driver' => strtolower(trim((string)($this->config['storage_driver'] ?? 'json'))) ?: 'json',
                'data_identity_sha256' => $dataDirectory !== '' ? hash('sha256', $dataDirectory) : null,
                'database' => $database->safeSummary(),
                'database_identity_sha256' => $databaseFingerprint !== '' ? $databaseFingerprint : null,
            ],
            'isolation' => [
                'production_hosts_protected' => $this->hasValues($guard['production_hosts'] ?? []),
                'production_data_identity_protected' => trim((string)($guard['production_data_dir'] ?? '')) !== '',
                'production_database_identity_protected' => $this->isSha256((string)($guard['production_database_sha256'] ?? '')),
                'production_bot_identity_protected' => $this->isSha256((string)($guard['production_bot_token_sha256'] ?? '')),
                'staging_bot_username_configured' => trim((string)($this->config['staging_bot_username'] ?? '')) !== '',
                'live_payments_disabled' => !$this->livePaymentsEnabled(),
            ],
            'server_time_utc' => gmdate('c'),
        ];
    }

    private function sourceFingerprint(): string
    {
        $parts = [];
        foreach (self::SOURCE_FILES as $relative) {
            $path = rtrim($this->rootDirectory, '/\\') . '/' . $relative;
            if (!is_file($path)) {
                throw new RuntimeException('Staging source file is missing: ' . $relative);
            }
            $hash = hash_file('sha256', $path);
            if (!is_string($hash) || $hash === '') {
                throw new RuntimeException('Cannot fingerprint staging source: ' . $relative);
            }
            $parts[] = $relative . ':' . $hash;
        }
        return hash('sha256', implode("\n", $parts));
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') return '';
        $real = realpath($path);
        $path = $real !== false ? $real : $path;
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        return rtrim($path, '/');
    }

    private function hasValues(mixed $value): bool
    {
        if (is_string($value)) return trim($value) !== '';
        if (!is_array($value)) return false;
        foreach ($value as $item) {
            if (trim((string)$item) !== '') return true;
        }
        return false;
    }

    private function isSha256(string $value): bool
    {
        $value = strtolower(trim($value));
        if (str_starts_with($value, 'sha256:')) $value = substr($value, 7);
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    private function livePaymentsEnabled(): bool
    {
        if (!empty($this->config['external_payments_enabled'])) return true;
        foreach (['payment_mode', 'telegram_stars_mode', 'google_play_billing_mode'] as $key) {
            if (strtolower(trim((string)($this->config[$key] ?? ''))) === 'live') return true;
        }
        return false;
    }
}
