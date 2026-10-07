<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/runtime/localization/LocalizationCatalog.php';

final class ServerLocalization
{
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
        $raw = strtolower(trim((string)($_SERVER['HTTP_X_MGW_LOCALE'] ?? '')));
        if ($raw === '') return null;

        $normalized = str_replace('_', '-', $raw);
        $locale = explode('-', $normalized, 2)[0] ?? '';
        return in_array($locale, $catalog->supportedLocales(), true) ? $locale : null;
    }
}
