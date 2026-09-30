<?php
declare(strict_types=1);

require_once __DIR__ . '/AndroidAccountReauthService.php';

final class AccountReauthException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

final class AccountReauthGuard
{
    public const MAX_AGE_SECONDS = 5 * 60;
    public const CLOCK_SKEW_SECONDS = 60;

    public static function authorize(array $config, array $payload, ?int $now = null): array
    {
        $initData = trim((string)($payload['initData'] ?? ''));
        if (self::initDataIsFresh($initData, $now)) {
            $authenticated = (new AuthService($config))->getUserFromRequest([
                'initData'=>$initData,
                'sessionId'=>(string)($payload['sessionId'] ?? ''),
            ]);
            return self::assertMgwIdentity($authenticated);
        }

        // Android has no Telegram initData. A normal HttpOnly Android session is
        // sufficient for ordinary product use but deliberately not sufficient
        // for destructive/export actions. Those actions need a recent native
        // device-unlock + Keystore credential proof bound to this exact session.
        try {
            $authenticated = (new AuthService($config))->getUserFromRequest($payload);
        } catch (Throwable) {
            $authenticated = null;
        }

        if (is_array($authenticated)
            && strtolower(trim((string)($authenticated['mgw_identity_provider'] ?? ''))) === 'android_device') {
            $authenticated = self::assertMgwIdentity($authenticated);
            $sessionToken = trim((string)($_COOKIE[AndroidDeviceAuthService::COOKIE_NAME] ?? ''));

            try {
                $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
                if ($databaseConfig->enabled()) {
                    $database = PdoConnectionFactory::create($databaseConfig);
                    $reauth = new AndroidAccountReauthService($config, $database);
                    if ($reauth->hasRecentGrant($authenticated, $sessionToken)) {
                        return $authenticated;
                    }
                }
            } catch (Throwable) {
                // Fail closed. The caller receives the normal Android reauth
                // requirement rather than learning DB/session internals.
            }

            throw new AccountReauthException(
                'android_reauth_required',
                'Подтвердите действие разблокировкой устройства и повторите попытку.'
            );
        }

        throw new AccountReauthException(
            'reauth_required',
            'Для этого действия заново откройте MINI GAMES WORLD из Telegram и повторите попытку.'
        );
    }

    public static function initDataIsFresh(string $initData, ?int $now = null): bool
    {
        if ($initData === '') return false;
        parse_str($initData, $data);
        $authDate = filter_var($data['auth_date'] ?? null, FILTER_VALIDATE_INT);
        if ($authDate === false || $authDate <= 0) return false;
        $now ??= time();

        return $authDate <= $now + self::CLOCK_SKEW_SECONDS
            && $now - $authDate <= self::MAX_AGE_SECONDS;
    }

    private static function assertMgwIdentity(array $authenticated): array
    {
        $mgwId = trim((string)($authenticated['mgw_id'] ?? ''));
        if (!MgwIdGenerator::isValid($mgwId)) {
            throw new AccountReauthException('identity_unavailable', 'Не удалось подтвердить профиль MGW.');
        }
        return $authenticated;
    }
}
