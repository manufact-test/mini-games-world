<?php
declare(strict_types=1);

final class AccountIdentityService
{
    private const MAX_CREATE_ATTEMPTS = 4;
    private const PROVIDER_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,31}$/';

    public function __construct(
        private DatabaseConnectionInterface $database,
        private int $sessionTtlSec = 2592000
    ) {
        $this->sessionTtlSec = max(300, $this->sessionTtlSec);
    }

    /**
     * Current Telegram adapter. Telegram-specific field extraction stays here,
     * while account resolution itself is provider-neutral below.
     */
    public function resolveTelegramUser(array $telegramUser, string $sessionId): array
    {
        $subject = trim((string)($telegramUser['id'] ?? ''));
        if ($subject === '') {
            throw new RuntimeException('Telegram identity subject is missing.');
        }

        $provider = !empty($telegramUser['is_dev_user']) ? 'development' : 'telegram';
        $platform = $provider === 'telegram' ? 'telegram_web' : 'browser_dev';

        return $this->resolveProviderIdentity(
            $provider,
            $subject,
            $platform,
            [
                'display_name' => $telegramUser['first_name'] ?? 'Игрок',
                'username' => $telegramUser['username'] ?? null,
                'avatar_ref' => $telegramUser['photo_url'] ?? null,
            ],
            $sessionId
        );
    }

    /**
     * Canonical provider-neutral provider -> MGW account resolver.
     *
     * Provider adapters authenticate externally, then pass only their verified
     * provider identifier/subject plus normalized public profile metadata here.
     * This method never verifies provider credentials itself.
     */
    public function resolveProviderIdentity(
        string $provider,
        string $subject,
        string $platform,
        array $profile,
        string $sessionId
    ): array {
        $provider = $this->normalizeProvider($provider);
        $subject = $this->normalizeSubject($subject);
        $platform = $this->normalizePlatform($platform);
        $profile = [
            'display_name' => $this->normalizeText($profile['display_name'] ?? 'Игрок', 80, 'Игрок'),
            'username' => $this->normalizeNullableText($profile['username'] ?? null, 80),
            'avatar_ref' => $this->normalizeNullableText($profile['avatar_ref'] ?? null, 2048),
        ];

        for ($attempt = 1; $attempt <= self::MAX_CREATE_ATTEMPTS; $attempt++) {
            try {
                return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
                    $provider,
                    $subject,
                    $platform,
                    $profile,
                    $sessionId
                ): array {
                    $identity = $this->findIdentity($database, $provider, $subject, true);
                    $created = false;

                    if ($identity === null) {
                        $mgwId = $this->createAccount($database, $provider, $subject, $profile);
                        $created = true;
                    } else {
                        $mgwId = (string)$identity['mgw_id'];
                        $this->touchAccount($database, $mgwId, $provider, $subject, $profile);
                    }

                    $sessionRegistered = $this->registerSession(
                        $database,
                        $mgwId,
                        $provider,
                        $platform,
                        $sessionId
                    );

                    return [
                        'mgw_id' => $mgwId,
                        'provider' => $provider,
                        'provider_subject' => $subject,
                        'created' => $created,
                        'session_registered' => $sessionRegistered,
                    ];
                });
            } catch (PDOException $error) {
                if (!$this->isUniqueViolation($error) || $attempt === self::MAX_CREATE_ATTEMPTS) {
                    throw $error;
                }
            }
        }

        throw new RuntimeException('Unable to resolve the MGW account.');
    }

    public function findByIdentity(string $provider, string $subject): ?array
    {
        try {
            $provider = $this->normalizeProvider($provider);
            $subject = $this->normalizeSubject($subject);
        } catch (RuntimeException) {
            return null;
        }

        return $this->findIdentity($this->database, $provider, $subject, false);
    }

    private function createAccount(
        DatabaseConnectionInterface $database,
        string $provider,
        string $subject,
        array $profile
    ): string {
        $now = $this->timestamp();
        $mgwId = MgwIdGenerator::generate();
        $avatarProvider = $profile['avatar_ref'] !== null ? $provider : null;

        $database->execute(
            'INSERT INTO mgw_users (
                mgw_id, status, display_name, username, avatar_provider, avatar_external_ref,
                created_at_utc, updated_at_utc, last_seen_at_utc
             ) VALUES (
                :mgw_id, :status, :display_name, :username, :avatar_provider, :avatar_external_ref,
                :created_at, :updated_at, :last_seen_at
             )',
            [
                'mgw_id' => $mgwId,
                'status' => 'active',
                'display_name' => $profile['display_name'],
                'username' => $profile['username'],
                'avatar_provider' => $avatarProvider,
                'avatar_external_ref' => $profile['avatar_ref'],
                'created_at' => $now,
                'updated_at' => $now,
                'last_seen_at' => $now,
            ]
        );

        $database->execute(
            'INSERT INTO mgw_identities (
                mgw_id, provider, provider_subject, provider_username,
                linked_at_utc, last_authenticated_at_utc
             ) VALUES (
                :mgw_id, :provider, :provider_subject, :provider_username,
                :linked_at, :last_authenticated_at
             )',
            [
                'mgw_id' => $mgwId,
                'provider' => $provider,
                'provider_subject' => $subject,
                'provider_username' => $profile['username'],
                'linked_at' => $now,
                'last_authenticated_at' => $now,
            ]
        );

        return $mgwId;
    }

    private function touchAccount(
        DatabaseConnectionInterface $database,
        string $mgwId,
        string $provider,
        string $subject,
        array $profile
    ): void {
        $now = $this->timestamp();
        $parameters = [
            'mgw_id' => $mgwId,
            'display_name' => $profile['display_name'],
            'username' => $profile['username'],
            'updated_at' => $now,
            'last_seen_at' => $now,
        ];

        if ($profile['avatar_ref'] !== null) {
            $database->execute(
                'UPDATE mgw_users
                 SET display_name = :display_name,
                     username = :username,
                     avatar_provider = :avatar_provider,
                     avatar_external_ref = :avatar_external_ref,
                     updated_at_utc = :updated_at,
                     last_seen_at_utc = :last_seen_at
                 WHERE mgw_id = :mgw_id',
                $parameters + [
                    'avatar_provider' => $provider,
                    'avatar_external_ref' => $profile['avatar_ref'],
                ]
            );
        } else {
            $database->execute(
                'UPDATE mgw_users
                 SET display_name = :display_name,
                     username = :username,
                     updated_at_utc = :updated_at,
                     last_seen_at_utc = :last_seen_at
                 WHERE mgw_id = :mgw_id',
                $parameters
            );
        }

        $database->execute(
            'UPDATE mgw_identities
             SET provider_username = :provider_username,
                 last_authenticated_at_utc = :last_authenticated_at
             WHERE provider = :provider AND provider_subject = :provider_subject AND mgw_id = :mgw_id',
            [
                'provider_username' => $profile['username'],
                'last_authenticated_at' => $now,
                'provider' => $provider,
                'provider_subject' => $subject,
                'mgw_id' => $mgwId,
            ]
        );
    }

    private function registerSession(
        DatabaseConnectionInterface $database,
        string $mgwId,
        string $provider,
        string $platform,
        string $sessionId
    ): bool {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return false;
        }

        $sessionHash = hash('sha256', 'session|' . $sessionId);
        $deviceHash = hash('sha256', 'device|' . $sessionId);
        $lockClause = $database->driver() === 'sqlite' ? '' : ' FOR UPDATE';
        $existingSession = $database->fetchAll(
            'SELECT mgw_id FROM mgw_sessions WHERE session_key_hash = :session_key_hash' . $lockClause,
            ['session_key_hash' => $sessionHash]
        );

        if ($existingSession !== [] && (string)$existingSession[0]['mgw_id'] !== $mgwId) {
            throw new RuntimeException('Session ownership does not match the authenticated MGW account.');
        }

        $now = $this->timestamp();
        $deviceRows = $database->fetchAll(
            'SELECT device_id FROM mgw_devices
             WHERE mgw_id = :mgw_id AND device_key_hash = :device_key_hash' . $lockClause,
            ['mgw_id' => $mgwId, 'device_key_hash' => $deviceHash]
        );

        if ($deviceRows === []) {
            $database->execute(
                'INSERT INTO mgw_devices (
                    mgw_id, device_key_hash, platform, first_seen_at_utc, last_seen_at_utc
                 ) VALUES (
                    :mgw_id, :device_key_hash, :platform, :first_seen_at, :last_seen_at
                 )',
                [
                    'mgw_id' => $mgwId,
                    'device_key_hash' => $deviceHash,
                    'platform' => $platform,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]
            );
            $deviceRows = $database->fetchAll(
                'SELECT device_id FROM mgw_devices
                 WHERE mgw_id = :mgw_id AND device_key_hash = :device_key_hash',
                ['mgw_id' => $mgwId, 'device_key_hash' => $deviceHash]
            );
        } else {
            $database->execute(
                'UPDATE mgw_devices
                 SET platform = :platform, last_seen_at_utc = :last_seen_at
                 WHERE mgw_id = :mgw_id AND device_key_hash = :device_key_hash',
                [
                    'platform' => $platform,
                    'last_seen_at' => $now,
                    'mgw_id' => $mgwId,
                    'device_key_hash' => $deviceHash,
                ]
            );
        }

        if ($deviceRows === []) {
            throw new RuntimeException('Unable to register the MGW device.');
        }

        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . $this->sessionTtlSec . ' seconds')
            ->format('Y-m-d H:i:s.u');
        $deviceId = (int)$deviceRows[0]['device_id'];

        if ($existingSession === []) {
            $database->execute(
                'INSERT INTO mgw_sessions (
                    session_key_hash, mgw_id, device_id, provider,
                    issued_at_utc, last_seen_at_utc, expires_at_utc, revoked_at_utc
                 ) VALUES (
                    :session_key_hash, :mgw_id, :device_id, :provider,
                    :issued_at, :last_seen_at, :expires_at, NULL
                 )',
                [
                    'session_key_hash' => $sessionHash,
                    'mgw_id' => $mgwId,
                    'device_id' => $deviceId,
                    'provider' => $provider,
                    'issued_at' => $now,
                    'last_seen_at' => $now,
                    'expires_at' => $expiresAt,
                ]
            );
        } else {
            $database->execute(
                'UPDATE mgw_sessions
                 SET device_id = :device_id,
                     provider = :provider,
                     last_seen_at_utc = :last_seen_at,
                     expires_at_utc = :expires_at,
                     revoked_at_utc = NULL
                 WHERE session_key_hash = :session_key_hash AND mgw_id = :mgw_id',
                [
                    'device_id' => $deviceId,
                    'provider' => $provider,
                    'last_seen_at' => $now,
                    'expires_at' => $expiresAt,
                    'session_key_hash' => $sessionHash,
                    'mgw_id' => $mgwId,
                ]
            );
        }

        return true;
    }

    private function findIdentity(
        DatabaseConnectionInterface $database,
        string $provider,
        string $subject,
        bool $forUpdate
    ): ?array {
        $lockClause = $forUpdate && $database->driver() !== 'sqlite' ? ' FOR UPDATE' : '';
        $rows = $database->fetchAll(
            'SELECT i.mgw_id, i.provider, i.provider_subject, i.provider_username,
                    u.status, u.display_name, u.username, u.avatar_provider,
                    u.avatar_external_ref, u.created_at_utc, u.last_seen_at_utc
             FROM mgw_identities i
             INNER JOIN mgw_users u ON u.mgw_id = i.mgw_id
             WHERE i.provider = :provider AND i.provider_subject = :provider_subject' . $lockClause,
            ['provider' => $provider, 'provider_subject' => $subject]
        );

        return $rows[0] ?? null;
    }

    private function normalizeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (preg_match(self::PROVIDER_PATTERN, $provider) !== 1) {
            throw new RuntimeException('Identity provider is invalid.');
        }
        return $provider;
    }

    private function normalizePlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));
        if (preg_match(self::PROVIDER_PATTERN, $platform) !== 1) {
            throw new RuntimeException('Identity platform is invalid.');
        }
        return $platform;
    }

    private function normalizeSubject(string $subject): string
    {
        $subject = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $subject) ?? '');
        $length = function_exists('mb_strlen') ? mb_strlen($subject) : strlen($subject);
        if ($subject === '' || $length > 191) {
            throw new RuntimeException('Identity subject is invalid.');
        }
        return $subject;
    }

    private function isUniqueViolation(PDOException $error): bool
    {
        $sqlState = (string)$error->getCode();
        return $sqlState === '23000'
            || str_contains(strtolower($error->getMessage()), 'unique constraint')
            || str_contains(strtolower($error->getMessage()), 'duplicate entry');
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private function normalizeText(mixed $value, int $maxLength, string $fallback): string
    {
        $normalized = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$value) ?? '');
        if ($normalized === '') {
            return $fallback;
        }

        return function_exists('mb_substr')
            ? mb_substr($normalized, 0, $maxLength)
            : substr($normalized, 0, $maxLength);
    }

    private function normalizeNullableText(mixed $value, int $maxLength): ?string
    {
        $normalized = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)($value ?? '')) ?? '');
        if ($normalized === '') {
            return null;
        }

        return function_exists('mb_substr')
            ? mb_substr($normalized, 0, $maxLength)
            : substr($normalized, 0, $maxLength);
    }
}
