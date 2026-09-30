<?php
declare(strict_types=1);

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

    public static function authorize(
        array $config,
        array $payload,
        ?int $now = null,
        ?AndroidDeviceAuthService $androidService = null
    ): array {
        $initData = trim((string)($payload['initData'] ?? ''));
        if (self::initDataIsFresh($initData, $now)) {
            $authenticated = (new AuthService($config))->getUserFromRequest([
                'initData'=>$initData,
                'sessionId'=>(string)($payload['sessionId'] ?? ''),
            ]);
            return self::requireCanonicalIdentity($authenticated);
        }

        $androidToken = trim((string)($_COOKIE[AndroidDeviceAuthService::COOKIE_NAME] ?? ''));
        if ($androidToken !== '') {
            $androidService ??= new AndroidDeviceAuthService($config);
            $recent = $androidService->authenticateRecentlyReauthenticatedCookie(
                $androidToken,
                self::MAX_AGE_SECONDS,
                $now
            );
            if (is_array($recent)) {
                return self::requireCanonicalIdentity($recent);
            }

            // An ordinary valid Android session proves account ownership for normal
            // product traffic, but it is intentionally NOT sufficient for export
            // or account deletion. Tell the client to invoke the narrow native
            // user-presence flow instead of weakening this boundary.
            if (is_array($androidService->authenticateCookie($androidToken))) {
                throw new AccountReauthException(
                    'android_reauth_required',
                    'Подтвердите это действие на устройстве Android и повторите попытку.'
                );
            }
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

    private static function requireCanonicalIdentity(array $authenticated): array
    {
        $mgwId = trim((string)($authenticated['mgw_id'] ?? ''));
        if (!MgwIdGenerator::isValid($mgwId)) {
            throw new AccountReauthException('identity_unavailable', 'Не удалось подтвердить профиль MGW.');
        }
        return $authenticated;
    }
}
