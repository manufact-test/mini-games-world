<?php
declare(strict_types=1);

require_once __DIR__ . '/AndroidAuthAttemptLimiter.php';

final class AndroidDeviceAuthService
{
    public const COOKIE_NAME = 'mgw_android_auth';
    private const PROVIDER = 'android_device';
    private const PLATFORM = 'android';
    private const CREDENTIAL_PATTERN = '/^[A-Za-z0-9_-]{43}$/';
    private const SESSION_PATTERN = '/^ada_[a-f0-9]{64}$/';

    private ?DatabaseConnectionInterface $resolvedDatabase = null;

    public function __construct(
        private array $config,
        private ?DatabaseConnectionInterface $database = null
    ) {}

    public function enabled(): bool
    {
        if (strtolower(trim((string)($this->config['environment'] ?? ''))) !== 'staging') {
            return false;
        }

        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) return false;

        $router = new RuntimeStorageRouter($this->config);
        return $router->enabled()
            && $router->routeFor('accounts') === RuntimeStorageRouter::DRIVER_DATABASE;
    }

    public function bootstrapCredential(
        string $credential,
        string $remoteAddress,
        string $existingSessionToken = ''
    ): array {
        $this->assertEnabled();
        $subject = $this->credentialSubject($credential);
        $database = $this->database();
        (new AndroidAuthAttemptLimiter($database, $this->config))->assertAllowed($remoteAddress, $subject);

        $existing = $this->sessionRecord($existingSessionToken);
        if (is_array($existing) && hash_equals($subject, (string)$existing['provider_subject'])) {
            $this->touchSession((string)$existing['session_key_hash']);
            return [
                'session_token'=>$existingSessionToken,
                'reused'=>true,
                'user'=>$this->projectUser($database, $existing),
            ];
        }

        $sessionToken = 'ada_' . bin2hex(random_bytes(32));
        $accounts = new AccountIdentityService(
            $database,
            $this->cookieTtlSec(),
            (string)($this->config['bot_token'] ?? '')
        );
        $identity = $accounts->resolveProviderIdentity(
            self::PROVIDER,
            $subject,
            self::PLATFORM,
            [],
            $sessionToken
        );
        $ownershipService = new RuntimeAccountOwnershipService($database);
        $ownership = $ownershipService->findByMgwId((string)$identity['mgw_id'])
            ?? $ownershipService->ensure(
                self::PROVIDER,
                $this->runtimeUserId($subject),
                (string)$identity['mgw_id']
            );
        $record = $this->identityRecord((string)$identity['mgw_id'], $subject);
        $record['account_ref'] = (string)$ownership['account_ref'];
        $record['legacy_user_id'] = (string)$ownership['legacy_user_id'];

        return [
            'session_token'=>$sessionToken,
            'reused'=>false,
            'user'=>$this->projectUser($database, $record),
        ];
    }

    public function authenticateCookie(string $sessionToken): ?array
    {
        if (!$this->enabled()) return null;
        $record = $this->sessionRecord($sessionToken);
        if (!is_array($record)) return null;

        $this->touchSession((string)$record['session_key_hash']);
        return $this->projectUser($this->database(), $record);
    }

    public function reauthenticateCredential(
        string $credential,
        string $sessionToken,
        string $remoteAddress
    ): array {
        $this->assertEnabled();
        $subject = $this->credentialSubject($credential);
        $database = $this->database();
        (new AndroidAuthAttemptLimiter($database, $this->config))->assertReauthAllowed($remoteAddress, $subject);

        $record = $this->sessionRecord($sessionToken);
        if (!is_array($record)
            || !hash_equals($subject, trim((string)($record['provider_subject'] ?? '')))) {
            throw new InvalidArgumentException('Android reauthentication identity does not match the active session.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowText = $now->format('Y-m-d H:i:s.u');
        $affected = $database->execute(
            'UPDATE mgw_sessions
             SET last_reauthenticated_at_utc=:reauthenticated_at_utc,
                 last_seen_at_utc=:last_seen_at_utc
             WHERE session_key_hash=:session_key_hash
               AND provider=:provider
               AND revoked_at_utc IS NULL
               AND expires_at_utc>:now_utc',
            [
                'reauthenticated_at_utc'=>$nowText,
                'last_seen_at_utc'=>$nowText,
                'session_key_hash'=>(string)$record['session_key_hash'],
                'provider'=>self::PROVIDER,
                'now_utc'=>$nowText,
            ]
        );
        if ($affected !== 1) {
            throw new RuntimeException('Android reauthentication session is no longer active.');
        }

        return [
            'user'=>$this->projectUser($database, $record),
            'reauthenticated_at_utc'=>$nowText,
        ];
    }

    public function authenticateRecentlyReauthenticatedCookie(
        string $sessionToken,
        int $maxAgeSeconds,
        ?int $now = null
    ): ?array {
        if (!$this->enabled()) return null;
        $record = $this->sessionRecord($sessionToken);
        if (!is_array($record)) return null;

        $rows = $this->database()->fetchAll(
            'SELECT last_reauthenticated_at_utc
             FROM mgw_sessions
             WHERE session_key_hash=:session_key_hash
               AND provider=:provider
               AND revoked_at_utc IS NULL
             LIMIT 1',
            [
                'session_key_hash'=>(string)$record['session_key_hash'],
                'provider'=>self::PROVIDER,
            ]
        );
        $lastText = trim((string)($rows[0]['last_reauthenticated_at_utc'] ?? ''));
        if ($lastText === '') return null;

        try {
            $last = new DateTimeImmutable($lastText, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }

        $now ??= time();
        $lastTs = $last->getTimestamp();
        $maxAgeSeconds = max(1, min(3600, $maxAgeSeconds));
        if ($lastTs > $now + 60 || $now - $lastTs > $maxAgeSeconds) return null;

        $this->touchSession((string)$record['session_key_hash']);
        return $this->projectUser($this->database(), $record);
    }

    public function cookieTtlSec(): int
    {
        return max(300, min(31536000, (int)($this->config['mgw_account_session_ttl_sec'] ?? 2592000)));
    }

    private function sessionRecord(string $sessionToken): ?array
    {
        $sessionToken = trim($sessionToken);
        if (preg_match(self::SESSION_PATTERN, $sessionToken) !== 1) return null;

        $database = $this->database();
        $sessionHash = hash('sha256', 'session|' . $sessionToken);
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $rows = $database->fetchAll(
            "SELECT s.session_key_hash, s.mgw_id, i.provider_subject,
                    u.nickname, u.display_name, u.equipped_avatar_item_id
             FROM mgw_sessions s
             INNER JOIN mgw_identities i
                ON i.mgw_id=s.mgw_id AND i.provider=:identity_provider
             INNER JOIN mgw_users u ON u.mgw_id=s.mgw_id
             WHERE s.session_key_hash=:session_key_hash
               AND s.provider=:session_provider
               AND s.revoked_at_utc IS NULL
               AND s.expires_at_utc>:now_utc
             LIMIT 1",
            [
                'identity_provider'=>self::PROVIDER,
                'session_key_hash'=>$sessionHash,
                'session_provider'=>self::PROVIDER,
                'now_utc'=>$now,
            ]
        );
        if ($rows === []) return null;

        $record = $rows[0];
        $ownershipService = new RuntimeAccountOwnershipService($database);
        $ownership = $ownershipService->findByMgwId((string)$record['mgw_id'])
            ?? $ownershipService->ensure(
                self::PROVIDER,
                $this->runtimeUserId((string)$record['provider_subject']),
                (string)$record['mgw_id']
            );
        $record['account_ref'] = (string)$ownership['account_ref'];
        $record['legacy_user_id'] = (string)$ownership['legacy_user_id'];
        return $record;
    }

    private function identityRecord(string $mgwId, string $subject): array
    {
        $rows = $this->database()->fetchAll(
            'SELECT mgw_id, nickname, display_name, equipped_avatar_item_id
             FROM mgw_users WHERE mgw_id=:mgw_id LIMIT 1',
            ['mgw_id'=>$mgwId]
        );
        if ($rows === []) throw new RuntimeException('Android MGW account is unavailable.');
        $record = $rows[0];
        $record['provider_subject'] = $subject;
        $record['session_key_hash'] = '';
        return $record;
    }

    private function projectUser(DatabaseConnectionInterface $database, array $record): array
    {
        $subject = trim((string)($record['provider_subject'] ?? ''));
        $mgwId = trim((string)($record['mgw_id'] ?? ''));
        $accountRef = trim((string)($record['account_ref'] ?? ''));
        $legacyUserId = trim((string)($record['legacy_user_id'] ?? ''));
        if ($subject === '' || $mgwId === '' || $accountRef === '' || $legacyUserId === '') {
            throw new RuntimeException('Android authenticated identity is incomplete.');
        }

        $nickname = trim((string)($record['nickname'] ?? $record['display_name'] ?? ''));
        if ($nickname === '') $nickname = 'Игрок';

        return [
            'id'=>$legacyUserId,
            'first_name'=>$nickname,
            'username'=>$nickname,
            'photo_url'=>'',
            'language_code'=>'ru',
            'is_dev_user'=>false,
            'mgw_id'=>$mgwId,
            'mgw_account_ref'=>$accountRef,
            'mgw_identity_provider'=>self::PROVIDER,
            'mgw_nickname'=>$nickname,
            'mgw_avatar_item_id'=>trim((string)($record['equipped_avatar_item_id'] ?? '')),
        ];
    }

    private function touchSession(string $sessionHash): void
    {
        if ($sessionHash === '') return;
        $this->database()->execute(
            'UPDATE mgw_sessions SET last_seen_at_utc=:last_seen_at
             WHERE session_key_hash=:session_key_hash AND provider=:provider',
            [
                'last_seen_at'=>(new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
                'session_key_hash'=>$sessionHash,
                'provider'=>self::PROVIDER,
            ]
        );
    }

    private function runtimeUserId(string $subject): string
    {
        return 'android_' . $subject;
    }

    private function credentialSubject(string $credential): string
    {
        $credential = trim($credential);
        if (preg_match(self::CREDENTIAL_PATTERN, $credential) !== 1) {
            throw new InvalidArgumentException('Android device credential is invalid.');
        }
        $encoded = strtr($credential, '-_', '+/') . '=';
        $raw = base64_decode($encoded, true);
        if (!is_string($raw) || strlen($raw) !== 32) {
            throw new InvalidArgumentException('Android device credential is invalid.');
        }
        return hash('sha256', $raw);
    }

    private function assertEnabled(): void
    {
        if (!$this->enabled()) {
            throw new RuntimeException('Android device authentication is unavailable.');
        }
    }

    private function database(): DatabaseConnectionInterface
    {
        if ($this->database instanceof DatabaseConnectionInterface) return $this->database;
        if ($this->resolvedDatabase instanceof DatabaseConnectionInterface) return $this->resolvedDatabase;

        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Android authentication database is unavailable.');
        }
        return $this->resolvedDatabase = PdoConnectionFactory::create($databaseConfig);
    }
}
