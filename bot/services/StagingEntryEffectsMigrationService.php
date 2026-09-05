<?php
declare(strict_types=1);

final class StagingEntryEffectsMigrationService
{
    private const STAGING_HOST = 'seashell-okapi-889488.hostingersite.com';
    private const VERSION = '20260905_0025_add_profile_entry_effects';

    public function __construct(private array $config) {}

    public function applyIfExactlyPending(array $server): array
    {
        $this->assertExactStaging($server);
        $this->assertPaymentsDisabled();

        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Staging Entry Effects migration requires the configured database.');
        }

        $database = PdoConnectionFactory::create($databaseConfig);
        $runner = new MigrationRunner($database, dirname(__DIR__) . '/database/migrations');
        $before = $runner->status();
        $pending = array_values(array_map(
            static fn(array $item): string => (string)($item['version'] ?? ''),
            (array)($before['pending'] ?? [])
        ));

        if ($pending === []) {
            return [
                'ok' => true,
                'status' => 'already_applied',
                'migration' => self::VERSION,
                'pending_before' => [],
                'pending_after' => [],
            ];
        }

        if ($pending !== [self::VERSION]) {
            throw new RuntimeException('Staging Entry Effects migration refuses an unexpected pending migration set.');
        }

        $result = $runner->migrate(false);
        $executed = array_values(array_map(
            static fn(array $item): string => (string)($item['version'] ?? ''),
            (array)($result['executed'] ?? [])
        ));
        if ($executed !== [self::VERSION]) {
            throw new RuntimeException('Staging Entry Effects migration executed an unexpected migration set.');
        }

        $after = $runner->status();
        $pendingAfter = array_values(array_map(
            static fn(array $item): string => (string)($item['version'] ?? ''),
            (array)($after['pending'] ?? [])
        ));
        if ($pendingAfter !== []) {
            throw new RuntimeException('Staging Entry Effects migration did not converge to a clean schema.');
        }

        return [
            'ok' => true,
            'status' => 'applied',
            'migration' => self::VERSION,
            'pending_before' => $pending,
            'pending_after' => $pendingAfter,
        ];
    }

    private function assertExactStaging(array $server): void
    {
        if (strtolower(trim((string)($this->config['environment'] ?? ''))) !== 'staging') {
            throw new RuntimeException('Staging Entry Effects migration is unavailable outside staging.');
        }

        $baseUrl = rtrim(trim((string)($this->config['base_url'] ?? '')), '/');
        $baseScheme = strtolower((string)(parse_url($baseUrl, PHP_URL_SCHEME) ?: ''));
        $baseHost = strtolower((string)(parse_url($baseUrl, PHP_URL_HOST) ?: ''));
        $requestHost = strtolower(trim((string)($server['HTTP_HOST'] ?? '')));
        if (str_contains($requestHost, ':')) {
            $requestHost = explode(':', $requestHost, 2)[0];
        }

        if ($baseScheme !== 'https'
            || $baseHost !== self::STAGING_HOST
            || $requestHost !== self::STAGING_HOST) {
            throw new RuntimeException('Staging Entry Effects migration host mismatch.');
        }
    }

    private function assertPaymentsDisabled(): void
    {
        if (!empty($this->config['external_payments_enabled'])) {
            throw new RuntimeException('Staging Entry Effects migration refuses live payments.');
        }
        foreach (['payment_mode', 'telegram_stars_mode', 'google_play_billing_mode'] as $key) {
            if (strtolower(trim((string)($this->config[$key] ?? ''))) === 'live') {
                throw new RuntimeException('Staging Entry Effects migration refuses live payments.');
            }
        }
    }
}
