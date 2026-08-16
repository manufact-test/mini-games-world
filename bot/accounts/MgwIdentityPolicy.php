<?php
declare(strict_types=1);

final class MgwIdentityPolicy
{
    public const NICKNAME_TAKEN_ERROR = 'Этот ник уже занят, выберите другой';
    public const DEFAULT_AVATAR_ITEM_ID = 'starter-default-01';
    public const STARTER_AVATAR_ITEM_IDS = [
        'starter-default-01',
        'starter-default-02',
        'starter-default-03',
    ];
    public const SUPPORTED_LOCALES = ['ru'];

    public static function generateNickname(): string
    {
        return 'Player' . str_pad((string)random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
    }

    public static function normalizeNickname(mixed $value): string
    {
        $nickname = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$value) ?? '');
        $length = function_exists('mb_strlen') ? mb_strlen($nickname) : strlen($nickname);
        if ($length < 3 || $length > 24 || preg_match('/^[\p{L}\p{N}_-]+$/u', $nickname) !== 1) {
            throw new InvalidArgumentException('MGW profile update invalid: nickname');
        }
        return $nickname;
    }

    public static function normalizeAvatarItemId(mixed $value): string
    {
        $avatarItemId = trim((string)$value);
        if (!in_array($avatarItemId, self::STARTER_AVATAR_ITEM_IDS, true)) {
            throw new InvalidArgumentException('MGW profile update invalid: avatar');
        }
        return $avatarItemId;
    }

    public static function normalizeLocale(mixed $value): string
    {
        $locale = strtolower(trim((string)$value));
        if (!in_array($locale, self::SUPPORTED_LOCALES, true)) {
            throw new InvalidArgumentException('MGW profile update invalid: locale');
        }
        return $locale;
    }

    public static function isPublicIdentityProvider(string $provider): bool
    {
        return in_array(strtolower(trim($provider)), ['telegram', 'google', 'apple'], true);
    }

    public static function isUniqueViolation(Throwable $error): bool
    {
        if (!$error instanceof PDOException) return false;
        $state = (string)$error->getCode();
        $message = strtolower($error->getMessage());
        return in_array($state, ['23000', '23505'], true)
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'duplicate entry')
            || str_contains($message, 'unique violation');
    }
}
