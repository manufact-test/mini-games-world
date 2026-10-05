<?php
declare(strict_types=1);

require_once __DIR__ . '/../localization/ServerLocalization.php';

final class AndroidAccountReauthException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $httpStatus = 409
    ) {
        parent::__construct($message);
    }
}

final class AndroidAccountReauthService
{
    public const CHALLENGE_TTL_SECONDS = 120;
    public const GRANT_TTL_SECONDS = 300;

    private const PROVIDER = 'android_device';
    private const CHALLENGE_PATTERN = '/^ar_[a-f0-9]{24}$/';
    private const SESSION_PATTERN = '/^ada_[a-f0-9]{64}$/';
    private const HOURLY_CHALLENGE_LIMIT = 10;

    public function __construct(
        private array $config,
        private DatabaseConnectionInterface $database
    ) {}

    public function enabled(): bool
    {
        if (strtolower(trim((string)($this->config['environment'] ?? ''))) !== 'staging') {
            return false;
        }
        $router = new RuntimeStorageRouter($this->config);
        return $router->enabled()
            && $router->routeFor('accounts') === RuntimeStorageRouter::DRIVER_DATABASE;
    }

    public function createChallenge(array $androidUser, string $sessionToken): array
    {
        $this->assertEnabled();
        $context = $this->context($androidUser, $sessionToken);
        $now = $this->now();
        $createdAt = $now->format('Y-m-d H:i:s.u');
        $expiresAt = $now->modify('+' . self::CHALLENGE_TTL_SECONDS . ' seconds')->format('Y-m-d H:i:s.u');
        $recentCutoff = $now->modify('-1 hour')->format('Y-m-d H:i:s.u');

        $recent = (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_android_reauth_challenges
             WHERE mgw_id=:mgw_id AND created_at_utc>=:recent_cutoff',
            ['mgw_id'=>$context['mgw_id'], 'recent_cutoff'=>$recentCutoff]
        );
        if ($recent >= self::HOURLY_CHALLENGE_LIMIT) {
            throw new AndroidAccountReauthException(
                'rate_limited',
                ServerLocalization::copy('server.account_chain.reauth.rate_limited', 'Too many verification attempts. Try again later.'),
                429
            );
        }

        $challengeId = 'ar_' . bin2hex(random_bytes(12));

        $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $context,
            $challengeId,
            $createdAt,
            $expiresAt
        ): void {
            $database->execute(
                "UPDATE mgw_android_reauth_challenges
                 SET challenge_status='cancelled', cancelled_at_utc=:cancelled_at
                 WHERE session_key_hash=:session_key_hash AND challenge_status='pending'",
                [
                    'cancelled_at'=>$createdAt,
                    'session_key_hash'=>$context['session_key_hash'],
                ]
            );

            $database->execute(
                'INSERT INTO mgw_android_reauth_challenges (
                    challenge_id, mgw_id, android_subject, session_key_hash,
                    challenge_status, created_at_utc, expires_at_utc,
                    confirmed_at_utc, grant_expires_at_utc, cancelled_at_utc
                 ) VALUES (
                    :challenge_id, :mgw_id, :android_subject, :session_key_hash,
                    :challenge_status, :created_at_utc, :expires_at_utc,
                    NULL, NULL, NULL
                 )',
                [
                    'challenge_id'=>$challengeId,
                    'mgw_id'=>$context['mgw_id'],
                    'android_subject'=>$context['android_subject'],
                    'session_key_hash'=>$context['session_key_hash'],
                    'challenge_status'=>'pending',
                    'created_at_utc'=>$createdAt,
                    'expires_at_utc'=>$expiresAt,
                ]
            );
        });

        return [
            'status'=>'pending',
            'challenge_id'=>$challengeId,
            'expires_at'=>$expiresAt,
            'native_url'=>'mgw://android-reauth?challenge=' . rawurlencode($challengeId),
        ];
    }

    public function confirmNative(
        string $challengeId,
        string $credential,
        string $remoteAddress
    ): array {
        $this->assertEnabled();
        $challengeId = $this->normalizeChallengeId($challengeId);

        try {
            $identity = (new AndroidDeviceAuthService($this->config, $this->database))
                ->verifyCredentialIdentity($credential, $remoteAddress);
        } catch (AndroidAuthRateLimitException|InvalidArgumentException $error) {
            throw $error;
        } catch (Throwable) {
            throw new AndroidAccountReauthException(
                'credential_mismatch',
                ServerLocalization::copy('server.account_chain.reauth.device_confirm_failed', 'This device could not be verified.'),
                403
            );
        }

        $result = $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $challengeId,
            $identity
        ): array {
            $row = $this->challengeById($database, $challengeId, true);
            $status = (string)($row['challenge_status'] ?? '');

            if ($status === 'confirmed') {
                if (!hash_equals((string)$row['mgw_id'], (string)$identity['mgw_id'])
                    || !hash_equals((string)$row['android_subject'], (string)$identity['provider_subject'])) {
                    throw new AndroidAccountReauthException(
                        'credential_mismatch',
                        ServerLocalization::copy('server.account_chain.reauth.device_confirm_failed', 'This device could not be verified.'),
                        403
                    );
                }
                return [
                    'status'=>'confirmed',
                    'grant_expires_at'=>(string)$row['grant_expires_at_utc'],
                ];
            }

            if ($status !== 'pending') {
                throw new AndroidAccountReauthException(
                    'challenge_unavailable',
                    ServerLocalization::copy('server.account_chain.reauth.challenge_unavailable', 'This verification attempt is no longer available.'),
                    409
                );
            }

            if ($this->isExpired((string)$row['expires_at_utc'])) {
                $database->execute(
                    "UPDATE mgw_android_reauth_challenges
                     SET challenge_status='expired'
                     WHERE challenge_id=:challenge_id AND challenge_status='pending'",
                    ['challenge_id'=>$challengeId]
                );
                return ['status'=>'expired'];
            }

            if (!hash_equals((string)$row['mgw_id'], (string)$identity['mgw_id'])
                || !hash_equals((string)$row['android_subject'], (string)$identity['provider_subject'])) {
                throw new AndroidAccountReauthException(
                    'credential_mismatch',
                    ServerLocalization::copy('server.account_chain.reauth.device_confirm_failed', 'This device could not be verified.'),
                    403
                );
            }

            $this->assertActiveSession(
                $database,
                (string)$row['mgw_id'],
                (string)$row['android_subject'],
                (string)$row['session_key_hash']
            );

            $now = $this->now();
            $confirmedAt = $now->format('Y-m-d H:i:s.u');
            $grantExpiresAt = $now->modify('+' . self::GRANT_TTL_SECONDS . ' seconds')
                ->format('Y-m-d H:i:s.u');

            $database->execute(
                "UPDATE mgw_android_reauth_challenges
                 SET challenge_status='confirmed',
                     confirmed_at_utc=:confirmed_at_utc,
                     grant_expires_at_utc=:grant_expires_at_utc
                 WHERE challenge_id=:challenge_id AND challenge_status='pending'",
                [
                    'confirmed_at_utc'=>$confirmedAt,
                    'grant_expires_at_utc'=>$grantExpiresAt,
                    'challenge_id'=>$challengeId,
                ]
            );

            return [
                'status'=>'confirmed',
                'grant_expires_at'=>$grantExpiresAt,
            ];
        });

        if (($result['status'] ?? '') === 'expired') {
            throw new AndroidAccountReauthException(
                'challenge_expired',
                ServerLocalization::copy('server.account_chain.reauth.expired', 'Verification expired. Repeat the action.'),
                410
            );
        }
        return $result;
    }

    public function hasRecentGrant(array $androidUser, string $sessionToken): bool
    {
        if (!$this->enabled()) return false;

        try {
            $context = $this->context($androidUser, $sessionToken);
        } catch (Throwable) {
            return false;
        }

        $now = $this->timestamp();
        $count = (int)$this->database->fetchValue(
            "SELECT COUNT(*) FROM mgw_android_reauth_challenges
             WHERE mgw_id=:mgw_id
               AND android_subject=:android_subject
               AND session_key_hash=:session_key_hash
               AND challenge_status='confirmed'
               AND grant_expires_at_utc>:now_utc",
            [
                'mgw_id'=>$context['mgw_id'],
                'android_subject'=>$context['android_subject'],
                'session_key_hash'=>$context['session_key_hash'],
                'now_utc'=>$now,
            ]
        );

        return $count > 0;
    }

    private function context(array $androidUser, string $sessionToken): array
    {
        if (strtolower(trim((string)($androidUser['mgw_identity_provider'] ?? ''))) !== self::PROVIDER) {
            throw new AndroidAccountReauthException(
                'android_auth_required',
                ServerLocalization::copy('server.account_chain.reauth.android_only', 'Verification is available only in the Android app.'),
                403
            );
        }

        $mgwId = strtoupper(trim((string)($androidUser['mgw_id'] ?? '')));
        if (!MgwIdGenerator::isValid($mgwId)) {
            throw new AndroidAccountReauthException(
                'identity_unavailable',
                ServerLocalization::copy('server.account_chain.reauth.profile_unavailable', 'The MGW profile could not be verified.'),
                401
            );
        }

        $sessionToken = trim($sessionToken);
        if (preg_match(self::SESSION_PATTERN, $sessionToken) !== 1) {
            throw new AndroidAccountReauthException(
                'android_session_missing',
                ServerLocalization::copy('server.account_chain.reauth.session_unavailable', 'The Android session is unavailable. Restart the app.'),
                401
            );
        }

        $sessionHash = hash('sha256', 'session|' . $sessionToken);
        $row = $this->assertActiveSession($this->database, $mgwId, '', $sessionHash);

        return [
            'mgw_id'=>$mgwId,
            'android_subject'=>(string)$row['provider_subject'],
            'session_key_hash'=>$sessionHash,
        ];
    }

    private function assertActiveSession(
        DatabaseConnectionInterface $database,
        string $mgwId,
        string $expectedSubject,
        string $sessionHash
    ): array {
        $rows = $database->fetchAll(
            "SELECT s.mgw_id, i.provider_subject
             FROM mgw_sessions s
             INNER JOIN mgw_identities i
               ON i.mgw_id=s.mgw_id AND i.provider=:identity_provider
             WHERE s.session_key_hash=:session_key_hash
               AND s.provider=:session_provider
               AND s.mgw_id=:mgw_id
               AND s.revoked_at_utc IS NULL
               AND s.expires_at_utc>:now_utc
             LIMIT 1",
            [
                'identity_provider'=>self::PROVIDER,
                'session_key_hash'=>$sessionHash,
                'session_provider'=>self::PROVIDER,
                'mgw_id'=>$mgwId,
                'now_utc'=>$this->timestamp(),
            ]
        );
        if ($rows === []) {
            throw new AndroidAccountReauthException(
                'android_session_missing',
                ServerLocalization::copy('server.account_chain.reauth.session_unavailable', 'The Android session is unavailable. Restart the app.'),
                401
            );
        }

        $row = $rows[0];
        if ($expectedSubject !== ''
            && !hash_equals($expectedSubject, (string)($row['provider_subject'] ?? ''))) {
            throw new AndroidAccountReauthException(
                'credential_mismatch',
                ServerLocalization::copy('server.account_chain.reauth.device_confirm_failed', 'This device could not be verified.'),
                403
            );
        }
        return $row;
    }

    private function challengeById(
        DatabaseConnectionInterface $database,
        string $challengeId,
        bool $forUpdate
    ): array {
        $lock = $forUpdate && $database->driver() !== 'sqlite' ? ' FOR UPDATE' : '';
        $rows = $database->fetchAll(
            'SELECT * FROM mgw_android_reauth_challenges WHERE challenge_id=:challenge_id' . $lock,
            ['challenge_id'=>$challengeId]
        );
        if ($rows === []) {
            throw new AndroidAccountReauthException(
                'challenge_not_found',
                ServerLocalization::copy('server.account_chain.reauth.challenge_not_found', 'Verification attempt was not found.'),
                404
            );
        }
        return $rows[0];
    }

    private function normalizeChallengeId(string $challengeId): string
    {
        $challengeId = trim($challengeId);
        if (preg_match(self::CHALLENGE_PATTERN, $challengeId) !== 1) {
            throw new AndroidAccountReauthException(
                'challenge_invalid',
                ServerLocalization::copy('server.account_chain.reauth.challenge_invalid', 'Invalid verification attempt.'),
                400
            );
        }
        return $challengeId;
    }

    private function isExpired(string $timestamp): bool
    {
        $value = strtotime(trim($timestamp) . ' UTC');
        return $value === false || $value <= time();
    }

    private function assertEnabled(): void
    {
        if (!$this->enabled()) {
            throw new AndroidAccountReauthException(
                'android_reauth_unavailable',
                ServerLocalization::copy('server.account_chain.reauth.unavailable', 'Android verification is temporarily unavailable.'),
                503
            );
        }
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function timestamp(): string
    {
        return $this->now()->format('Y-m-d H:i:s.u');
    }
}
