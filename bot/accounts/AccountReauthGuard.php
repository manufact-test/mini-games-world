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

    public static function authorize(array $config, array $payload, ?int $now = null): array
    {
        $initData = trim((string)($payload['initData'] ?? ''));
        if (!self::initDataIsFresh($initData, $now)) {
            throw new AccountReauthException(
                'reauth_required',
                'Для этого действия заново откройте MINI GAMES WORLD из Telegram и повторите попытку.'
            );
        }

        $authenticated = (new AuthService($config))->getUserFromRequest([
            'initData'=>$initData,
            'sessionId'=>(string)($payload['sessionId'] ?? ''),
        ]);
        $mgwId = trim((string)($authenticated['mgw_id'] ?? ''));
        if (!MgwIdGenerator::isValid($mgwId)) {
            throw new AccountReauthException('identity_unavailable', 'Не удалось подтвердить профиль MGW.');
        }

        return $authenticated;
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
}
