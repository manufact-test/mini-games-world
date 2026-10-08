<?php
declare(strict_types=1);

/**
 * Telegram-channel preference only. Full MGW cross-device locale ownership is
 * reserved for MVP-27.5. No account schema, ledger or game mutations.
 */
final class TelegramLocalePreference
{
    public static function fromUpdate(array $config, array $update): ?string
    {
        $actor = $update['callback_query']['from']
            ?? $update['message']['from']
            ?? $update['edited_message']['from']
            ?? null;
        $id = is_array($actor) ? (string)($actor['id'] ?? '') : '';
        return self::read($config, $id);
    }

    public static function read(array $config, string $telegramId): ?string
    {
        if (!preg_match('/^[0-9]{1,20}$/D', $telegramId)) return null;
        try {
            $db = StorageFactory::createJson((string)($config['data_dir'] ?? (dirname(__DIR__) . '/data')));
            $value = $db->readOnlySections(['users'], static function (array $data) use ($telegramId): string {
                return (string)($data['users'][$telegramId]['telegram_ui_locale'] ?? '');
            });
            return in_array($value, ['ru', 'en'], true) ? $value : null;
        } catch (Throwable $error) {
            // A missing preference never breaks authenticated Telegram ingress.
            error_log('[MiniGamesWorld Telegram locale] preference read unavailable');
            return null;
        }
    }

    public static function save(array $config, string $telegramId, string $locale): bool
    {
        if (!preg_match('/^[0-9]{1,20}$/D', $telegramId)
            || !in_array($locale, ['ru', 'en'], true)) return false;

        $db = StorageFactory::createJson((string)($config['data_dir'] ?? (dirname(__DIR__) . '/data')));
        return $db->transaction(static function (array &$data) use ($telegramId, $locale): bool {
            if (!is_array($data['users'][$telegramId] ?? null)) return false;
            // Update only the authenticated Telegram actor's existing runtime row.
            $data['users'][$telegramId]['telegram_ui_locale'] = $locale;
            return true;
        });
    }
}
