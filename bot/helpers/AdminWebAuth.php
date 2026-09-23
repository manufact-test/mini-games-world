<?php
declare(strict_types=1);

final class AdminWebAuthException extends RuntimeException
{
    public function __construct(private int $httpStatus, private string $publicMessage)
    {
        parent::__construct($publicMessage);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function publicMessage(): string
    {
        return $this->publicMessage;
    }
}

final class AdminWebAuth
{
    public const MAX_AGE_SECONDS = 15 * 60;
    public const CLOCK_SKEW_SECONDS = 60;

    public static function authorize(array $config, string $initData, ?int $now = null): array
    {
        if (!self::initDataIsFresh($initData, $now)) {
            throw new AdminWebAuthException(401, 'Сессия панели устарела. Откройте её заново из Telegram.');
        }

        $auth = new AuthService($config);
        $telegramUser = $auth->getTelegramUserFromInitData($initData, false);
        $admin = new AdminService($config);
        if (!$admin->isAdmin((string)($telegramUser['id'] ?? ''))) {
            throw new AdminWebAuthException(403, 'Недостаточно прав.');
        }

        return $telegramUser;
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
