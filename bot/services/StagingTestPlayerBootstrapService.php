<?php
declare(strict_types=1);

require_once __DIR__ . '/StagingTestAuthService.php';
require_once __DIR__ . '/UserService.php';

final class StagingTestPlayerBootstrapService
{
    private const STAGING_HOST = 'seashell-okapi-889488.hostingersite.com';

    public function __construct(private array $config) {}

    public function ensure(array $server): array
    {
        $this->assertAvailable($server);

        $storage = StorageFactory::createJson((string)($this->config['data_dir'] ?? (__DIR__ . '/../data')));
        $createdSlots = [];
        $existingSlots = [];

        $storage->transaction(function (array &$data) use (&$createdSlots, &$existingSlots): array {
            if (!isset($data['users']) || !is_array($data['users'])) {
                $data['users'] = [];
            }

            $users = new UserService($this->config);
            foreach (StagingTestAuthService::playerDefinitions() as $slot => $identity) {
                $slot = strtoupper(trim((string)$slot));
                $legacyUserId = trim((string)($identity['id'] ?? ''));
                if (!in_array($slot, ['A', 'B'], true)
                    || !in_array($legacyUserId, ['stg_test_player_a', 'stg_test_player_b'], true)) {
                    throw new RuntimeException('Staging test player bootstrap identity catalog is invalid.');
                }

                if (array_key_exists($legacyUserId, $data['users'])) {
                    if (!is_array($data['users'][$legacyUserId])) {
                        throw new RuntimeException('Staging test player bootstrap found malformed runtime user state.');
                    }
                    $existingSlots[] = $slot;
                    continue;
                }

                $users->ensureUser($data, $identity + [
                    'language_code' => 'ru',
                    'is_dev_user' => true,
                    'is_staging_test_user' => true,
                    'staging_test_slot' => $slot,
                ]);
                $createdSlots[] = $slot;
            }

            return $data;
        });

        sort($createdSlots, SORT_STRING);
        sort($existingSlots, SORT_STRING);

        return [
            'ok' => true,
            'service' => 'mini-games-world-staging-test-player-bootstrap',
            'created_slots' => $createdSlots,
            'existing_slots' => $existingSlots,
            'created_count' => count($createdSlots),
            'production_changed' => false,
            'live_payments_used' => false,
        ];
    }

    private function assertAvailable(array $server): void
    {
        if (strtolower(trim((string)($this->config['environment'] ?? ''))) !== 'staging') {
            throw new RuntimeException('Staging test player bootstrap is unavailable.');
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
            throw new RuntimeException('Staging test player bootstrap is unavailable.');
        }

        if (!empty($this->config['external_payments_enabled'])) {
            throw new RuntimeException('Staging test player bootstrap refuses live payments.');
        }
        foreach (['payment_mode', 'telegram_stars_mode', 'google_play_billing_mode'] as $key) {
            if (strtolower(trim((string)($this->config[$key] ?? ''))) === 'live') {
                throw new RuntimeException('Staging test player bootstrap refuses live payments.');
            }
        }
    }
}
