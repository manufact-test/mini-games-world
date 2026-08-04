<?php
declare(strict_types=1);

final class StagingTestAuthService
{
    public const COOKIE_NAME = 'mgw_staging_test_session';

    private const STAGING_HOST = 'seashell-okapi-889488.hostingersite.com';
    private const SESSION_TTL_SECONDS = 900;
    private const MAX_REQUESTS_PER_SESSION = 10000;
    private const REGISTRY_FILE = 'sessions.json';

    private const PLAYERS = [
        'A' => [
            'id' => 'stg_test_player_a',
            'first_name' => 'Test Player A',
            'username' => 'mgw_test_player_a',
        ],
        'B' => [
            'id' => 'stg_test_player_b',
            'first_name' => 'Test Player B',
            'username' => 'mgw_test_player_b',
        ],
    ];

    public function __construct(private array $config) {}

    public function issue(string $slot, string $providedSecret, array $server): array
    {
        $this->assertAvailable($server);
        $expectedSecret = $this->configuredSecret();
        if ($providedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
            throw new RuntimeException('Staging test authorization denied.');
        }

        $slot = strtoupper(trim($slot));
        if (!isset(self::PLAYERS[$slot])) {
            throw new InvalidArgumentException('Unknown staging test-player slot.');
        }

        $now = time();
        $expiresAt = $now + self::SESSION_TTL_SECONDS;
        $token = 'mgwstg_' . $this->base64Url(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        $this->withRegistry(function (array &$registry) use ($slot, $tokenHash, $now, $expiresAt): void {
            $this->prune($registry, $now);
            foreach ($registry['sessions'] as $hash => $session) {
                if (is_array($session) && ($session['slot'] ?? null) === $slot) {
                    unset($registry['sessions'][$hash]);
                }
            }
            $registry['sessions'][$tokenHash] = [
                'slot' => $slot,
                'issued_at' => $now,
                'expires_at' => $expiresAt,
                'last_seen_at' => null,
                'request_count' => 0,
                'session_id_sha256' => null,
                'device_id_sha256' => null,
                'last_presence_lease_sha256' => null,
            ];
        });

        return [
            'token' => $token,
            'slot' => $slot,
            'expires_at' => $expiresAt,
            'expires_at_utc' => gmdate('c', $expiresAt),
            'ttl_seconds' => self::SESSION_TTL_SECONDS,
        ];
    }

    public function authenticate(array $payload, array $cookies, array $server): ?array
    {
        if (!$this->isExactStaging($server)) {
            return null;
        }

        $token = trim((string)($cookies[self::COOKIE_NAME] ?? ''));
        if ($token === '') {
            return null;
        }
        if (preg_match('/^mgwstg_[A-Za-z0-9_-]{40,80}$/', $token) !== 1) {
            throw new RuntimeException('Staging test session is invalid.');
        }
        $this->assertPaymentsDisabled();

        $sessionId = $this->boundedIdentifier($payload['sessionId'] ?? '', 'session');
        if ($sessionId === '') {
            throw new RuntimeException('Staging test session requires a device session ID.');
        }
        $deviceId = $this->boundedIdentifier($payload['deviceId'] ?? '', 'device', true);
        $presenceLeaseId = $this->boundedIdentifier($payload['presenceLeaseId'] ?? '', 'presence', true);

        $tokenHash = hash('sha256', $token);
        $identity = null;
        $now = time();

        $this->withRegistry(function (array &$registry) use (
            $tokenHash,
            $sessionId,
            $deviceId,
            $presenceLeaseId,
            $now,
            &$identity
        ): void {
            $this->prune($registry, $now);
            $session = $registry['sessions'][$tokenHash] ?? null;
            if (!is_array($session)) {
                throw new RuntimeException('Staging test session is expired or revoked.');
            }

            $slot = strtoupper(trim((string)($session['slot'] ?? '')));
            if (!isset(self::PLAYERS[$slot])) {
                throw new RuntimeException('Staging test session identity is invalid.');
            }
            if ((int)($session['expires_at'] ?? 0) < $now) {
                unset($registry['sessions'][$tokenHash]);
                throw new RuntimeException('Staging test session is expired.');
            }

            $sessionHash = hash('sha256', 'session|' . $sessionId);
            $boundSessionHash = trim((string)($session['session_id_sha256'] ?? ''));
            if ($boundSessionHash !== '' && !hash_equals($boundSessionHash, $sessionHash)) {
                throw new RuntimeException('Staging test session replay was rejected.');
            }
            $session['session_id_sha256'] = $sessionHash;

            if ($deviceId !== '') {
                $deviceHash = hash('sha256', 'device|' . $deviceId);
                $boundDeviceHash = trim((string)($session['device_id_sha256'] ?? ''));
                if ($boundDeviceHash !== '' && !hash_equals($boundDeviceHash, $deviceHash)) {
                    throw new RuntimeException('Staging test device mismatch.');
                }
                $session['device_id_sha256'] = $deviceHash;
            }

            if ($presenceLeaseId !== '') {
                $session['last_presence_lease_sha256'] = hash('sha256', 'presence|' . $presenceLeaseId);
            }

            $requestCount = max(0, (int)($session['request_count'] ?? 0)) + 1;
            if ($requestCount > self::MAX_REQUESTS_PER_SESSION) {
                unset($registry['sessions'][$tokenHash]);
                throw new RuntimeException('Staging test session request limit reached.');
            }
            $session['request_count'] = $requestCount;
            $session['last_seen_at'] = $now;
            $registry['sessions'][$tokenHash] = $session;

            $identity = self::PLAYERS[$slot] + [
                'language_code' => 'ru',
                'is_dev_user' => true,
                'is_staging_test_user' => true,
                'staging_test_slot' => $slot,
            ];
        });

        return is_array($identity) ? $identity : null;
    }

    public function revokeCurrent(array $cookies, array $server): bool
    {
        if (!$this->isExactStaging($server)) {
            return false;
        }
        $token = trim((string)($cookies[self::COOKIE_NAME] ?? ''));
        if ($token === '') {
            return false;
        }
        $tokenHash = hash('sha256', $token);
        $removed = false;
        $this->withRegistry(function (array &$registry) use ($tokenHash, &$removed): void {
            $removed = isset($registry['sessions'][$tokenHash]);
            unset($registry['sessions'][$tokenHash]);
        });
        return $removed;
    }

    public function cookieOptions(int $expiresAt): array
    {
        return [
            'expires' => $expiresAt,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ];
    }

    private function assertAvailable(array $server): void
    {
        if (!$this->isExactStaging($server)) {
            throw new RuntimeException('Staging test authorization is unavailable.');
        }
        $this->assertPaymentsDisabled();
        $this->configuredSecret();
    }

    private function isExactStaging(array $server): bool
    {
        if (strtolower(trim((string)($this->config['environment'] ?? ''))) !== 'staging') {
            return false;
        }
        $baseUrl = rtrim(trim((string)($this->config['base_url'] ?? '')), '/');
        $baseScheme = strtolower((string)(parse_url($baseUrl, PHP_URL_SCHEME) ?: ''));
        $baseHost = strtolower((string)(parse_url($baseUrl, PHP_URL_HOST) ?: ''));
        $requestHost = strtolower(trim((string)($server['HTTP_HOST'] ?? '')));
        if (str_contains($requestHost, ':')) {
            $requestHost = explode(':', $requestHost, 2)[0];
        }

        return $baseScheme === 'https'
            && $baseHost === self::STAGING_HOST
            && $requestHost === self::STAGING_HOST;
    }

    private function assertPaymentsDisabled(): void
    {
        if (!empty($this->config['external_payments_enabled'])) {
            throw new RuntimeException('Staging test authorization refuses live payments.');
        }
        foreach (['payment_mode', 'telegram_stars_mode', 'google_play_billing_mode'] as $key) {
            if (strtolower(trim((string)($this->config[$key] ?? ''))) === 'live') {
                throw new RuntimeException('Staging test authorization refuses live payments.');
            }
        }
    }

    private function configuredSecret(): string
    {
        $secret = trim((string)($this->config['staging_test_auth_secret'] ?? ''));
        if ($secret === '') {
            $secret = trim((string)($this->config['setup_secret'] ?? ''));
        }
        if (strlen($secret) < 32
            || $secret === 'CHANGE_ME_TO_LONG_RANDOM_SECRET'
            || str_contains(strtoupper($secret), 'CHANGE_ME')) {
            throw new RuntimeException('Staging test authorization secret is not configured safely.');
        }
        return $secret;
    }

    private function boundedIdentifier(mixed $value, string $label, bool $optional = false): string
    {
        $value = trim((string)$value);
        if ($value === '' && $optional) {
            return '';
        }
        if ($value === '' || strlen($value) > 120 || preg_match('/^[A-Za-z0-9._:-]+$/', $value) !== 1) {
            throw new RuntimeException('Invalid staging test ' . $label . ' identifier.');
        }
        return $value;
    }

    private function registryPath(): string
    {
        $dataDir = rtrim(trim((string)($this->config['data_dir'] ?? '')), '/\\');
        if ($dataDir === '') {
            throw new RuntimeException('Staging test authorization data directory is unavailable.');
        }
        return $dataDir . '/.runtime/staging-test-auth/' . self::REGISTRY_FILE;
    }

    private function withRegistry(Closure $callback): mixed
    {
        $path = $this->registryPath();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Staging test authorization registry is unavailable.');
        }

        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Staging test authorization registry cannot be opened.');
        }
        @chmod($path, 0600);

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Staging test authorization registry is busy.');
            }
            rewind($handle);
            $raw = stream_get_contents($handle);
            $registry = ['schema_version' => 1, 'sessions' => []];
            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    throw new RuntimeException('Staging test authorization registry is invalid.');
                }
                $registry['sessions'] = is_array($decoded['sessions'] ?? null) ? $decoded['sessions'] : [];
            }

            $result = $callback($registry);
            $registry['schema_version'] = 1;
            $json = json_encode($registry, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $json) === false || !fflush($handle)) {
                throw new RuntimeException('Staging test authorization registry cannot be written.');
            }
            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function prune(array &$registry, int $now): void
    {
        $sessions = is_array($registry['sessions'] ?? null) ? $registry['sessions'] : [];
        foreach ($sessions as $hash => $session) {
            if (!is_array($session) || (int)($session['expires_at'] ?? 0) < $now) {
                unset($sessions[$hash]);
            }
        }
        $registry['sessions'] = $sessions;
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
