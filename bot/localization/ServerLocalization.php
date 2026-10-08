<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/runtime/localization/LocalizationCatalog.php';

final class ServerLocalization
{
    private static bool $telegramUpdateBound = false;
    private static ?string $telegramUpdateLocale = null;

    /**
     * Use Telegram's authenticated update actor, not an incidental HTTP header.
     * This is update-scoped; account-wide preference remains MVP-27.5.
     */
    public static function bindTelegramUpdate(array $update): void
    {
        $actor = $update['callback_query']['from']
            ?? $update['message']['from']
            ?? $update['edited_message']['from']
            ?? null;
        $code = is_array($actor) ? (string)($actor['language_code'] ?? '') : '';
        $normalized = str_replace('_', '-', strtolower(trim($code)));
        $candidate = explode('-', $normalized, 2)[0] ?? '';
        self::$telegramUpdateLocale = in_array($candidate, ['ru', 'en'], true) ? $candidate : null;
        self::$telegramUpdateBound = true;
    }

    /** Explicit Telegram Mini App choice wins over the Telegram client language. */
    public static function preferAuthenticatedTelegramLocale(?string $locale): void
    {
        if (self::$telegramUpdateBound && in_array($locale, ['ru', 'en'], true)) {
            self::$telegramUpdateLocale = $locale;
        }
    }

    public static function copy(string $key, string $emergencyFallback, array $params = []): string
    {
        try {
            static $catalog = null;
            if (!$catalog instanceof LocalizationCatalog) {
                $catalog = new LocalizationCatalog(dirname(__DIR__, 2) . '/app/locales');
            }
            return $catalog->translate($key, $params, self::requestLocale($catalog));
        } catch (Throwable $error) {
            error_log('[MiniGamesWorld server localization] ' . $error->getMessage());
            return $emergencyFallback;
        }
    }

    private static function requestLocale(LocalizationCatalog $catalog): ?string
    {
        // A missing/unsupported Telegram language falls back to catalog RU.
        if (self::$telegramUpdateBound) return self::$telegramUpdateLocale;

        $raw = strtolower(trim((string)($_SERVER['HTTP_X_MGW_LOCALE'] ?? '')));
        if ($raw === '') return null;

        $normalized = str_replace('_', '-', $raw);
        $locale = explode('-', $normalized, 2)[0] ?? '';
        return in_array($locale, $catalog->supportedLocales(), true) ? $locale : null;
    }
}
