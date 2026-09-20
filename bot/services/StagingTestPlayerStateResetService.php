<?php
declare(strict_types=1);

final class StagingTestPlayerStateResetService
{
    private const STAGING_HOST = 'seashell-okapi-889488.hostingersite.com';
    private const TEST_PLAYER_IDS = ['stg_test_player_a', 'stg_test_player_b'];
    private const MATCH_BALANCE = 100;

    private RuntimeStorageRouter $router;

    public function __construct(private array $config, ?RuntimeStorageRouter $router = null)
    {
        $this->router = $router ?? new RuntimeStorageRouter($config);
    }

    public function reset(array $server): array
    {
        $this->assertAvailable($server);

        $storage = StorageFactory::createJson((string)($this->config['data_dir'] ?? (__DIR__ . '/../data')));
        $before = [];
        $snapshot = $storage->transaction(function (array &$data) use (&$before): array {
            if (!isset($data['users']) || !is_array($data['users'])) {
                throw new RuntimeException('Staging test users are unavailable.');
            }

            foreach (self::TEST_PLAYER_IDS as $legacyUserId) {
                if (!isset($data['users'][$legacyUserId]) || !is_array($data['users'][$legacyUserId])) {
                    throw new RuntimeException('Staging test player is not initialized.');
                }
                $before[$legacyUserId] = (int)($data['users'][$legacyUserId]['balance_match'] ?? 0);
                $data['users'][$legacyUserId]['balance_match'] = self::MATCH_BALANCE;
            }

            return $data;
        });

        $economy = new RuntimeEconomyRepository($this->config, $this->router);
        $synchronized = $economy->synchronize($snapshot);
        $audit = $economy->auditParity($snapshot);
        if (($synchronized['ok'] ?? false) !== true || ($audit['ok'] ?? false) !== true) {
            throw new RuntimeException('Staging test-player economy reset did not reach parity.');
        }

        $balances = [];
        foreach (self::TEST_PLAYER_IDS as $legacyUserId) {
            $balances[] = [
                'slot' => str_ends_with($legacyUserId, '_a') ? 'A' : 'B',
                'before' => (int)($before[$legacyUserId] ?? 0),
                'after' => self::MATCH_BALANCE,
            ];
        }

        return [
            'ok' => true,
            'service' => 'mini-games-world-staging-test-player-state-reset',
            'status' => 'reset',
            'match_balance' => self::MATCH_BALANCE,
            'players' => $balances,
            'economy_parity' => true,
            'production_changed' => false,
            'live_payments_used' => false,
        ];
    }

    private function assertAvailable(array $server): void
    {
        if (strtolower(trim((string)($this->config['environment'] ?? ''))) !== 'staging') {
            throw new RuntimeException('Staging test-player reset is unavailable.');
        }

        $baseUrl = rtrim(trim((string)($this->config['base_url'] ?? '')), '/');
        $baseScheme = strtolower((string)(parse_url($baseUrl, PHP_URL_SCHEME) ?: ''));
        $baseHost = strtolower((string)(parse_url($baseUrl, PHP_URL_HOST) ?: ''));
        $requestHost = strtolower(trim((string)($server['HTTP_HOST'] ?? '')));
        if (str_contains($requestHost, ':')) $requestHost = explode(':', $requestHost, 2)[0];

        if ($baseScheme !== 'https'
            || $baseHost !== self::STAGING_HOST
            || $requestHost !== self::STAGING_HOST) {
            throw new RuntimeException('Staging test-player reset host mismatch.');
        }

        if (!empty($this->config['external_payments_enabled'])) {
            throw new RuntimeException('Staging test-player reset refuses live payments.');
        }
        foreach (['payment_mode', 'telegram_stars_mode', 'google_play_billing_mode'] as $key) {
            if (strtolower(trim((string)($this->config[$key] ?? ''))) === 'live') {
                throw new RuntimeException('Staging test-player reset refuses live payments.');
            }
        }
    }
}
