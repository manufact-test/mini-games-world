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
            return $catalog->translate($key, $params);
        } catch (Throwable $error) {
            error_log('[MiniGamesWorld server localization] ' . $error->getMessage());
            return $emergencyFallback;
        }
    }
}
